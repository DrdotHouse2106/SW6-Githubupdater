<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\RepositoryReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GithubApiClientTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    public function testListsOnlyValidTags(): void
    {
        $requests = [];
        $client = new GithubApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = $url;

            return new MockResponse(json_encode([
                ['name' => 'v1.0.0', 'commit' => ['sha' => self::SHA]],
                ['name' => '../../evil', 'commit' => ['sha' => self::SHA]],
                ['name' => 'v2.0.0', 'commit' => ['sha' => 'invalid']],
            ], \JSON_THROW_ON_ERROR));
        }));

        $tags = $client->listTags(RepositoryReference::fromFullName('owner/repo'), null);

        static::assertCount(1, $tags);
        static::assertSame('v1.0.0', $tags[0]->name);
        static::assertSame('https://api.github.com/repos/owner/repo/tags?per_page=100', $requests[0]);
    }

    public function testTokenIsOnlySentToApiHost(): void
    {
        $seen = [];
        $client = new GithubApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen[$url] = implode("\n", $options['headers'] ?? []);

            if (str_starts_with($url, 'https://api.github.com/')) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://codeload.github.com/owner/repo/legacy.zip/' . self::SHA]]);
            }

            return new MockResponse('ZIPDATA');
        }));

        $target = tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $client->downloadZipball(RepositoryReference::fromFullName('owner/repo'), self::SHA, $target, 1024, 'ghp_secrettoken1234567890');

        static::assertSame('ZIPDATA', file_get_contents($target));
        unlink($target);

        $apiHeaders = $seen['https://api.github.com/repos/owner/repo/zipball/' . self::SHA];
        $codeloadHeaders = $seen['https://codeload.github.com/owner/repo/legacy.zip/' . self::SHA];
        static::assertStringContainsString('ghp_secrettoken1234567890', $apiHeaders);
        static::assertStringNotContainsString('ghp_secrettoken1234567890', $codeloadHeaders);
    }

    /**
     * Privates Repository: Token geht an die API, GitHub leitet mit einem
     * kurzlebigen Archiv-Schlüssel auf codeload weiter.
     */
    public function testDownloadFromPrivateRepository(): void
    {
        $seen = [];
        $client = new GithubApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen[$url] = implode("\n", $options['headers'] ?? []);

            if (str_starts_with($url, 'https://api.github.com/')) {
                if (!str_contains($seen[$url], 'Authorization: Bearer github_pat_private1234567890')) {
                    return new MockResponse('', ['http_code' => 404]);
                }

                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://codeload.github.com/owner/private/legacy.zip/' . self::SHA . '?token=SHORTLIVED']]);
            }

            return new MockResponse('PRIVATEZIP');
        }));

        $target = tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $client->downloadZipball(RepositoryReference::fromFullName('owner/private'), self::SHA, $target, 1024, 'github_pat_private1234567890');

        static::assertSame('PRIVATEZIP', file_get_contents($target));
        unlink($target);
        static::assertStringNotContainsString('github_pat_private1234567890', $seen['https://codeload.github.com/owner/private/legacy.zip/' . self::SHA . '?token=SHORTLIVED']);
    }

    public function testPrivateRepositoryWithoutTokenGivesHint(): void
    {
        $client = new GithubApiClient(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));

        $this->expectException(GithubUpdaterException::class);
        $this->expectExceptionMessageMatches('/GitHub-Token/');
        $client->listTags(RepositoryReference::fromFullName('owner/private'), null);
    }

    public function testBlocksRedirectToForeignHost(): void
    {
        $client = new GithubApiClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://evil.example/payload.zip']]),
        ]));

        $this->expectException(GithubUpdaterException::class);
        $client->downloadZipball(RepositoryReference::fromFullName('owner/repo'), self::SHA, tempnam(sys_get_temp_dir(), 'dh-ghu-'), 1024, null);
    }

    public function testBlocksRedirectToInternalAddress(): void
    {
        $client = new GithubApiClient(new MockHttpClient([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: http://169.254.169.254/latest/meta-data']]),
        ]));

        $this->expectException(GithubUpdaterException::class);
        $client->getDefaultBranch(RepositoryReference::fromFullName('owner/repo'), null);
    }

    public function testEnforcesDownloadLimit(): void
    {
        $client = new GithubApiClient(new MockHttpClient([
            new MockResponse(str_repeat('A', 2048)),
        ]));

        $target = tempnam(sys_get_temp_dir(), 'dh-ghu-');
        try {
            $this->expectException(GithubUpdaterException::class);
            $client->downloadZipball(RepositoryReference::fromFullName('owner/repo'), self::SHA, $target, 1024, null);
        } finally {
            @unlink($target);
        }
    }

    public function testComposerJsonMissingReturnsNull(): void
    {
        $client = new GithubApiClient(new MockHttpClient([new MockResponse('', ['http_code' => 404])]));

        static::assertNull($client->getComposerJson(RepositoryReference::fromFullName('owner/repo'), self::SHA, null));
    }

    public function testRejectsInvalidShaBeforeRequest(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static function (): never {
            throw new \LogicException('Es darf keine Anfrage gesendet werden.');
        }));

        $this->expectException(GithubUpdaterException::class);
        $client->getComposerJson(RepositoryReference::fromFullName('owner/repo'), 'main?x=1', null);
    }

    public function testRateLimitMessage(): void
    {
        $client = new GithubApiClient(new MockHttpClient([new MockResponse('', ['http_code' => 403])]));

        $this->expectException(GithubUpdaterException::class);
        $this->expectExceptionMessageMatches('/API-Limit/');
        $client->listTags(RepositoryReference::fromFullName('owner/repo'), null);
    }

    public function testRateLimitMessageNamesResetTimeAndToken(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static fn () => new MockResponse('{}', [
            'http_code' => 403,
            'response_headers' => ['X-RateLimit-Limit: 60', 'X-RateLimit-Remaining: 0', 'X-RateLimit-Reset: ' . mktime(14, 5, 0)],
        ])));

        try {
            $client->listTags(RepositoryReference::fromFullName('owner/repo'), null);
            static::fail('Exception erwartet');
        } catch (GithubUpdaterException $e) {
            static::assertStringContainsString('60 Anfragen pro Stunde ohne Token', $e->getMessage());
            static::assertStringContainsString('14:05 Uhr', $e->getMessage());
            static::assertStringContainsString('GitHub-Token', $e->getMessage());
        }
    }
}
