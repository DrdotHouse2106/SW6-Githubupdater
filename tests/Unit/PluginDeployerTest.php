<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Package\SafeZipExtractor;
use DhGithubUpdater\Service\PluginDeployer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class PluginDeployerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/dh-ghu-project-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/custom/plugins', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testInstallsNewPlugin(): void
    {
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        $package = $deployer->stage($this->createPluginZip('MyPlugin', '1.0.0'));

        static::assertSame('MyPlugin', $package->manifest->pluginName);
        static::assertStringContainsString('/custom/plugins/.dh-github-updater/', $package->path);

        $deployer->deployNew($package);

        static::assertFileExists($this->projectDir . '/custom/plugins/MyPlugin/composer.json');
        static::assertDirectoryDoesNotExist($package->path);
    }

    public function testRefusesToOverwriteExistingDirectory(): void
    {
        mkdir($this->projectDir . '/custom/plugins/MyPlugin');
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        $package = $deployer->stage($this->createPluginZip('MyPlugin', '1.0.0'));

        $this->expectException(GithubUpdaterException::class);
        $deployer->deployNew($package);
    }

    public function testReplacesExistingPluginAndKeepsBackup(): void
    {
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        $deployer->deployNew($deployer->stage($this->createPluginZip('MyPlugin', '1.0.0')));

        $package = $deployer->stage($this->createPluginZip('MyPlugin', '2.0.0'));
        $backup = $deployer->replaceExisting($package, 'custom/plugins/MyPlugin/');

        static::assertStringContainsString('"2.0.0"', (string) file_get_contents($this->projectDir . '/custom/plugins/MyPlugin/composer.json'));
        static::assertStringContainsString('"1.0.0"', (string) file_get_contents($backup . '/composer.json'));

        $deployer->restoreBackup($backup, 'MyPlugin');
        static::assertStringContainsString('"1.0.0"', (string) file_get_contents($this->projectDir . '/custom/plugins/MyPlugin/composer.json'));
    }

    public function testRefusesUpdateOfPluginOutsideCustomPlugins(): void
    {
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        $deployer->deployNew($deployer->stage($this->createPluginZip('MyPlugin', '1.0.0')));
        mkdir($this->projectDir . '/vendor/acme/my-plugin', 0777, true);

        $package = $deployer->stage($this->createPluginZip('MyPlugin', '2.0.0'));

        $this->expectException(GithubUpdaterException::class);
        $deployer->replaceExisting($package, 'vendor/acme/my-plugin');
    }

    public function testRefusesSymlinkedPluginDirectory(): void
    {
        mkdir($this->projectDir . '/elsewhere');
        symlink($this->projectDir . '/elsewhere', $this->projectDir . '/custom/plugins/MyPlugin');
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        $package = $deployer->stage($this->createPluginZip('MyPlugin', '2.0.0'));

        $this->expectException(GithubUpdaterException::class);
        $deployer->replaceExisting($package, 'custom/plugins/MyPlugin');
    }

    public function testDiscardOnlyDeletesInsideStaging(): void
    {
        mkdir($this->projectDir . '/important');
        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());

        $this->expectException(GithubUpdaterException::class);
        try {
            $deployer->discard($this->projectDir . '/important');
        } finally {
            static::assertDirectoryExists($this->projectDir . '/important');
        }
    }

    public function testStagingIsCleanedUpForInvalidPackage(): void
    {
        $file = $this->projectDir . '/invalid.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('root/composer.json', json_encode(['type' => 'library'], \JSON_THROW_ON_ERROR));
        $zip->close();

        $deployer = new PluginDeployer($this->projectDir, new SafeZipExtractor());
        try {
            $deployer->stage($file);
            static::fail('Exception erwartet');
        } catch (GithubUpdaterException) {
        }

        $remaining = glob($this->projectDir . '/custom/plugins/.dh-github-updater/*') ?: [];
        static::assertSame([], $remaining);
    }

    private function createPluginZip(string $name, string $version): string
    {
        $file = $this->projectDir . '/' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('owner-repo-sha/composer.json', json_encode([
            'type' => 'shopware-platform-plugin',
            'version' => $version,
            'require' => ['shopware/core' => '~6.6.0'],
            'extra' => ['shopware-plugin-class' => 'Acme\\' . $name],
        ], \JSON_THROW_ON_ERROR));
        $zip->addFromString('owner-repo-sha/src/' . $name . '.php', '<?php');
        $zip->close();

        return $file;
    }
}
