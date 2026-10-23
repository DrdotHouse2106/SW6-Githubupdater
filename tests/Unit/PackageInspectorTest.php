<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Package\PackageInspector;
use DhGithubUpdater\Package\PluginManifest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class PackageInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dh-ghu-inspect-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/src/Resources/app/administration/src', 0777, true);
        mkdir($this->dir . '/src/Resources/app/storefront/src', 0777, true);
        touch($this->dir . '/src/Resources/app/administration/src/main.js');
        touch($this->dir . '/src/Resources/app/storefront/src/main.js');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testReportsUnbuiltSourcesAndMissingDependencies(): void
    {
        $inspector = new PackageInspector(static fn (string $package): bool => $package === 'symfony/var-dumper');

        $codes = $inspector->inspect($this->dir, $this->manifest());

        static::assertSame(
            [PackageInspector::ADMIN_NOT_BUILT, PackageInspector::STOREFRONT_NOT_BUILT, PackageInspector::MISSING_DEPENDENCIES],
            array_column($codes, 'code'),
        );
        static::assertSame('acme/sdk', $codes[2]['params']['packages']);
    }

    public function testBuiltPackageWithVendorHasNoWarnings(): void
    {
        mkdir($this->dir . '/src/Resources/public/administration', 0777, true);
        mkdir($this->dir . '/src/Resources/app/storefront/dist', 0777, true);
        mkdir($this->dir . '/vendor');

        static::assertSame([], (new PackageInspector(static fn (): bool => false))->inspect($this->dir, $this->manifest()));
    }

    private function manifest(): PluginManifest
    {
        return PluginManifest::fromArray([
            'type' => 'shopware-platform-plugin',
            'extra' => ['shopware-plugin-class' => 'Acme\\MyPlugin'],
            'require' => ['php' => '>=8.2', 'ext-curl' => '*', 'shopware/core' => '~6.7.0', 'acme/sdk' => '^1.0', 'symfony/var-dumper' => '*'],
        ]);
    }

    public function testReadsFreshlyInstalledPackagesFromInstalledJson(): void
    {
        mkdir($this->dir . '/vendor/composer', 0777, true);
        file_put_contents($this->dir . '/vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'Acme/SDK'], ['name' => 'other/lib']]]));

        $inspector = new PackageInspector(null, $this->dir);

        static::assertSame(['missing/pkg'], $inspector->missingPackages(['acme/sdk', 'missing/pkg']));
    }
}
