<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Package\CompatibilityChecker;
use DhGithubUpdater\Package\PluginManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PluginManifestTest extends TestCase
{
    public function testParsesValidManifest(): void
    {
        $manifest = PluginManifest::fromArray([
            'type' => 'shopware-platform-plugin',
            'version' => '2.1.0',
            'require' => ['shopware/core' => '~6.6.0 || ~6.7.0'],
            'extra' => ['shopware-plugin-class' => 'Frosh\\Tools\\FroshTools'],
        ]);

        static::assertSame('FroshTools', $manifest->pluginName);
        static::assertSame('2.1.0', $manifest->version);
    }

    /**
     * Verhindert u. a. die Command-Injection/Path-Traversal des Originals,
     * bei dem der Plugin-Name ungeprüft aus der composer.json übernommen wurde.
     */
    #[DataProvider('maliciousClasses')]
    public function testRejectsMaliciousPluginClass(string $class): void
    {
        $this->expectException(GithubUpdaterException::class);

        PluginManifest::fromArray([
            'type' => 'shopware-platform-plugin',
            'extra' => ['shopware-plugin-class' => $class],
        ]);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function maliciousClasses(): iterable
    {
        yield ['Foo\\Bar; rm -rf /'];
        yield ['Foo\\$(id)'];
        yield ['Foo\\../../public'];
        yield ['Foo\\Bar`id`'];
        yield ['Foo\\'];
        yield [''];
    }

    public function testRejectsNonPluginType(): void
    {
        $this->expectException(GithubUpdaterException::class);
        PluginManifest::fromArray(['type' => 'library', 'extra' => ['shopware-plugin-class' => 'A\\B']]);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(GithubUpdaterException::class);
        PluginManifest::fromJson('{invalid');
    }

    #[DataProvider('compatibility')]
    public function testCompatibility(string $shopwareVersion, ?string $constraint, bool $expected): void
    {
        $manifest = PluginManifest::fromArray([
            'type' => 'shopware-platform-plugin',
            'require' => $constraint !== null ? ['shopware/core' => $constraint] : [],
            'extra' => ['shopware-plugin-class' => 'A\\B'],
        ]);

        static::assertSame($expected, (new CompatibilityChecker($shopwareVersion))->isCompatible($manifest));
    }

    /**
     * @return iterable<array{string, ?string, bool}>
     */
    public static function compatibility(): iterable
    {
        yield ['6.6.10.4', '~6.6.0 || ~6.7.0', true];
        yield ['6.7.2.0', '~6.6.0', false];
        yield ['6.6.10.4', '^6.6', true];
        yield ['6.6.10.4', '>=6.6.11', false];
        yield ['6.6.9999999.9999999-dev', '~6.6.0', true];
        yield ['6.6.10.4', '6.6.*', true];
        // Das Original hat hier per str_contains fälschlich "kompatibel" gemeldet.
        yield ['6.6.1.0', '6.6.10.*', false];
        yield ['6.6.10.4', null, false];
        yield ['6.6.10.4', 'kaputt', false];
    }

    public function testVersionCompare(): void
    {
        static::assertSame(1, CompatibilityChecker::compare('v2.10.0', '2.9.1'));
        static::assertSame(0, CompatibilityChecker::compare('1.0', 'v1.0.0'));
        static::assertNull(CompatibilityChecker::compare('main', '1.0.0'));
    }
}
