<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Package\CompatibilityChecker;
use DhGithubUpdater\Service\ConnectionTester;
use DhGithubUpdater\Service\VersionResolver;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectionTesterTest extends TestCase
{
    private const SHA_MAIN = '1111111111111111111111111111111111111111';
    private const SHA_V1 = '2222222222222222222222222222222222222222';
    private const SHA_V11 = '3333333333333333333333333333333333333333';
    private const PLUGIN_CLASS = 'Nova\\Search\\NovaSearch';

    public function testRejectsInvalidInputAndSkipsTheRest(): void
    {
        $result = $this->tester([])->test('nicht/gültig/zu/viel');

        static::assertFalse($result['ok']);
        static::assertSame('error', $this->check($result, 'format')['status']);
        static::assertSame('skipped', $this->check($result, 'access')['status']);
        static::assertSame('skipped', $this->check($result, 'compatibility')['status']);
    }

    public function testFullSuccessWithUpdateHint(): void
    {
        $result = $this->tester($this->repository())->test('https://github.com/owner/repo', 'NovaSearch', self::PLUGIN_CLASS, '1.0.0');

        static::assertTrue($result['ok'], json_encode($result, \JSON_UNESCAPED_UNICODE) ?: '');
        static::assertSame('owner/repo', $result['repository']);
        static::assertSame('ok', $this->check($result, 'access')['status']);
        static::assertStringContainsString('passt zum installierten Plugin', $this->check($result, 'plugin')['message']);
        static::assertStringContainsString('2 Release', $this->check($result, 'releases')['message']);
        $compatibility = $this->check($result, 'compatibility');
        static::assertSame('ok', $compatibility['status']);
        static::assertStringContainsString('neueste: v1.1.0', $compatibility['message']);
        static::assertStringContainsString('Update ist verfügbar', $compatibility['message']);
    }

    public function testAllowListViolationIsReportedButOtherChecksRun(): void
    {
        $result = $this->tester($this->repository(), ['allowedRepositories' => 'someone-else/*'])->test('owner/repo');

        static::assertFalse($result['ok']);
        static::assertSame('error', $this->check($result, 'allowList')['status']);
        static::assertSame('ok', $this->check($result, 'access')['status']);
    }

    public function testNotFoundMentionsToken(): void
    {
        $result = $this->tester(['/repos/owner/repo' => new MockResponse('{}', ['http_code' => 404])])->test('owner/repo');

        $access = $this->check($result, 'access');
        static::assertSame('error', $access['status']);
        static::assertStringContainsString('Token', $access['message']);
        static::assertSame('skipped', $this->check($result, 'plugin')['status']);
    }

    public function testInvalidTokenAndRateLimitAreExplained(): void
    {
        $token = ['githubToken' => str_repeat('a', 40)];

        $invalid = $this->tester(['/repos/owner/repo' => new MockResponse('{}', ['http_code' => 401])], $token)->test('owner/repo');
        static::assertStringContainsString('Token ist ungültig', $this->check($invalid, 'access')['message']);

        $limited = $this->tester(['/repos/owner/repo' => new MockResponse('{}', ['http_code' => 403])])->test('owner/repo');
        static::assertStringContainsString('API-Limit', $this->check($limited, 'access')['message']);
    }

    public function testWrongPluginIsDetected(): void
    {
        $result = $this->tester($this->repository())->test('owner/repo', 'SwagPayPal', 'Swag\\PayPal\\SwagPayPal');

        static::assertFalse($result['ok']);
        $plugin = $this->check($result, 'plugin');
        static::assertSame('error', $plugin['status']);
        static::assertStringContainsString('„NovaSearch“', $plugin['message']);
        static::assertStringContainsString('„SwagPayPal“', $plugin['message']);
    }

    public function testNoReleasesGivesActionableHint(): void
    {
        $routes = $this->repository();
        $routes['/repos/owner/repo/tags'] = new MockResponse('[]');

        $result = $this->tester($routes)->test('owner/repo');

        static::assertTrue($result['ok'], 'Fehlende Releases sind eine Warnung, kein Fehler');
        $releases = $this->check($result, 'releases');
        static::assertSame('warning', $releases['status']);
        static::assertStringContainsString('Release anlegen', $releases['message']);
        static::assertStringContainsString('Installation aus Branches', $releases['message']);
        static::assertSame('warning', $this->check($result, 'compatibility')['status']);
    }

    public function testIncompatibleShopwareVersionIsAnError(): void
    {
        $routes = $this->repository('~6.6.0');

        $result = $this->tester($routes)->test('owner/repo');

        $compatibility = $this->check($result, 'compatibility');
        static::assertSame('error', $compatibility['status']);
        static::assertStringContainsString('Shopware 6.7.15', $compatibility['message']);
    }

    public function testMissingComposerJson(): void
    {
        $routes = $this->repository();
        $routes['/repos/owner/repo/contents/composer.json?ref=' . self::SHA_MAIN] = new MockResponse('', ['http_code' => 404]);

        $result = $this->tester($routes)->test('owner/repo');

        static::assertSame('error', $this->check($result, 'plugin')['status']);
        static::assertStringContainsString('keine composer.json', $this->check($result, 'plugin')['message']);
    }

    /**
     * @return array<string, MockResponse>
     */
    private function repository(string $constraint = '~6.7.0'): array
    {
        $composer = static fn (string $version): MockResponse => new MockResponse(json_encode([
            'name' => 'nova/nova-search',
            'type' => 'shopware-platform-plugin',
            'version' => $version,
            'require' => ['shopware/core' => $constraint],
            'extra' => ['shopware-plugin-class' => self::PLUGIN_CLASS],
        ], \JSON_THROW_ON_ERROR));

        return [
            '/repos/owner/repo' => new MockResponse('{"default_branch":"main"}'),
            '/repos/owner/repo/branches' => new MockResponse(json_encode([['name' => 'main', 'commit' => ['sha' => self::SHA_MAIN]]], \JSON_THROW_ON_ERROR)),
            '/repos/owner/repo/tags' => new MockResponse(json_encode([
                ['name' => 'v1.0.0', 'commit' => ['sha' => self::SHA_V1]],
                ['name' => 'v1.1.0', 'commit' => ['sha' => self::SHA_V11]],
            ], \JSON_THROW_ON_ERROR)),
            '/repos/owner/repo/contents/composer.json?ref=' . self::SHA_MAIN => $composer('1.1.0'),
            '/repos/owner/repo/contents/composer.json?ref=' . self::SHA_V1 => $composer('1.0.0'),
            '/repos/owner/repo/contents/composer.json?ref=' . self::SHA_V11 => $composer('1.1.0'),
        ];
    }

    /**
     * @param array<string, MockResponse> $routes Pfad (ohne ?per_page=…) → Antwort
     * @param array<string, mixed> $config
     */
    private function tester(array $routes, array $config = []): ConnectionTester
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($routes): MockResponse {
            $path = (string) preg_replace('#^https://api\.github\.com#', '', $url);
            $path = (string) preg_replace('#[?&]per_page=\d+$#', '', $path);

            if (!isset($routes[$path])) {
                return new MockResponse('{}', ['http_code' => 404]);
            }

            // MockResponse kann nur einmal gelesen werden – für Wiederholungen klonen
            return clone $routes[$path];
        });

        $systemConfig = new class($config) extends SystemConfigService {
            /** @param array<string, mixed> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function get(string $key, ?string $salesChannelId = null): mixed
            {
                return $this->values[substr($key, strrpos($key, '.') + 1)] ?? null;
            }

            public function getString(string $key, ?string $salesChannelId = null): string
            {
                $value = $this->get($key);

                return \is_string($value) ? $value : '';
            }

            public function getBool(string $key, ?string $salesChannelId = null): bool
            {
                return (bool) $this->get($key);
            }

            public function getInt(string $key, ?string $salesChannelId = null): int
            {
                return (int) $this->get($key);
            }
        };

        $config = new UpdaterConfig($systemConfig);
        $client = new GithubApiClient($http);
        $checker = new CompatibilityChecker('6.7.15.1');

        return new ConnectionTester($client, new VersionResolver($client, $checker, $config, new ArrayCachePool()), $checker, $config);
    }

    /**
     * @param array{checks: list<array{key: string, status: string, message: string}>} $result
     *
     * @return array{key: string, status: string, message: string}
     */
    private function check(array $result, string $key): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        static::fail(\sprintf('Prüfpunkt „%s“ fehlt.', $key));
    }
}

/**
 * Minimaler PSR-6-Cache für die Tests.
 */
class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItemInterface> */
    private array $items = [];

    public function getItem(string $key): CacheItemInterface
    {
        return $this->items[$key] ?? new ArrayCacheItem($key);
    }

    /** @return iterable<string, CacheItemInterface> */
    public function getItems(array $keys = []): iterable
    {
        return array_map($this->getItem(...), $keys);
    }

    public function hasItem(string $key): bool
    {
        return isset($this->items[$key]);
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if ($item instanceof ArrayCacheItem) {
            $item->hit = true;
        }
        $this->items[$item->getKey()] = $item;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}

class ArrayCacheItem implements CacheItemInterface
{
    public bool $hit = false;

    private mixed $value = null;

    public function __construct(private readonly string $key)
    {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        return $this;
    }

    public function expiresAfter(\DateInterval|int|null $time): static
    {
        return $this;
    }
}
