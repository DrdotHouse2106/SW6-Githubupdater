<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Package\PluginManifest;
use DhGithubUpdater\Package\SafeZipExtractor;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Legt Plugin-Dateien in custom/plugins ab.
 *
 * - Entpackt wird zunächst in ein eigenes Staging-Verzeichnis
 *   (custom/plugins/.dh-github-updater, wird von Shopware ignoriert).
 * - Zielpfade werden ausschließlich aus dem validierten Plugin-Namen gebildet
 *   und vor jeder Operation per realpath() gegen custom/plugins geprüft.
 * - Updates tauschen das Verzeichnis per rename() aus und stellen bei einem
 *   Fehler den vorherigen Stand wieder her.
 */
class PluginDeployer
{
    private const STAGING_DIR = '.dh-github-updater';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $projectDir,
        private readonly SafeZipExtractor $extractor,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function stage(string $zipFile): StagedPackage
    {
        $stagingRoot = $this->getStagingRoot();
        $path = $stagingRoot . '/pkg-' . bin2hex(random_bytes(12));
        $this->filesystem->mkdir($path, 0775);

        try {
            $this->extractor->extract($zipFile, $path);

            $composerFile = $path . '/composer.json';
            if (!is_file($composerFile) || is_link($composerFile) || filesize($composerFile) > 512 * 1024) {
                throw new GithubUpdaterException('Im Archiv wurde keine composer.json im Hauptverzeichnis gefunden.', 422);
            }

            $manifest = PluginManifest::fromJson((string) file_get_contents($composerFile));
        } catch (\Throwable $e) {
            $this->filesystem->remove($path);

            throw $e;
        }

        return new StagedPackage($path, $manifest);
    }

    /**
     * Neu-Installation: Das Zielverzeichnis darf noch nicht existieren.
     */
    public function deployNew(StagedPackage $package): void
    {
        $target = $this->getTargetPath($package->manifest->pluginName);

        if (file_exists($target) || is_link($target)) {
            throw new GithubUpdaterException(\sprintf('Das Verzeichnis custom/plugins/%s existiert bereits.', $package->manifest->pluginName), 409);
        }

        if (!@rename($package->path, $target)) {
            throw new GithubUpdaterException('Das Plugin konnte nicht nach custom/plugins verschoben werden (Dateirechte prüfen).', 500);
        }
    }

    /**
     * Update: Tauscht ein bestehendes Plugin-Verzeichnis aus.
     *
     * @return string Pfad der Sicherung des alten Standes
     */
    public function replaceExisting(StagedPackage $package, string $currentPluginPath): string
    {
        $target = $this->getTargetPath($package->manifest->pluginName);

        if (is_link($target) || !is_dir($target)) {
            throw new GithubUpdaterException('Das Plugin liegt nicht als reguläres Verzeichnis in custom/plugins und kann nicht aktualisiert werden.', 409);
        }

        $current = realpath($this->projectDir . '/' . ltrim($currentPluginPath, '/'));
        if ($current === false || $current !== realpath($target)) {
            throw new GithubUpdaterException('Das Plugin wird nicht aus custom/plugins geladen (z. B. per Composer installiert) und kann hier nicht aktualisiert werden.', 409);
        }

        $backup = $this->getStagingRoot() . '/backup-' . $package->manifest->pluginName . '-' . bin2hex(random_bytes(6));

        if (!@rename($target, $backup)) {
            throw new GithubUpdaterException('Das bestehende Plugin-Verzeichnis konnte nicht gesichert werden (Dateirechte prüfen).', 500);
        }

        if (!@rename($package->path, $target)) {
            @rename($backup, $target);

            throw new GithubUpdaterException('Das neue Plugin-Verzeichnis konnte nicht aktiviert werden. Der vorherige Stand wurde wiederhergestellt.', 500);
        }

        return $backup;
    }

    public function restoreBackup(string $backup, string $pluginName): void
    {
        $target = $this->getTargetPath($pluginName);
        $this->assertInStaging($backup);

        $this->filesystem->remove($target);
        $this->filesystem->rename($backup, $target);
    }

    public function discard(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        $this->assertInStaging($path);
        $this->filesystem->remove($path);
    }

    private function getTargetPath(string $pluginName): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $pluginName)) {
            throw new GithubUpdaterException('Ungültiger Plugin-Name.', 422);
        }

        $pluginsDir = $this->getPluginsDir();
        $target = $pluginsDir . '/' . $pluginName;

        // Zusätzliche Absicherung: Das Elternverzeichnis muss exakt custom/plugins sein.
        if (\dirname($target) !== $pluginsDir) {
            throw new GithubUpdaterException('Ungültiger Zielpfad.', 422);
        }

        return $target;
    }

    private function getPluginsDir(): string
    {
        $pluginsDir = realpath($this->projectDir . '/custom/plugins');
        if ($pluginsDir === false || !is_dir($pluginsDir) || !is_writable($pluginsDir)) {
            throw new GithubUpdaterException('Das Verzeichnis custom/plugins existiert nicht oder ist nicht beschreibbar.', 500);
        }

        return $pluginsDir;
    }

    private function getStagingRoot(): string
    {
        $root = $this->getPluginsDir() . '/' . self::STAGING_DIR;

        if (is_link($root)) {
            throw new GithubUpdaterException('Das Staging-Verzeichnis ist ungültig.', 500);
        }

        if (!is_dir($root)) {
            $this->filesystem->mkdir($root, 0770);
        }

        return $root;
    }

    private function assertInStaging(string $path): void
    {
        $root = realpath($this->getStagingRoot());
        $real = realpath($path);

        if ($root === false || $real === false || !str_starts_with($real, $root . '/')) {
            throw new GithubUpdaterException('Ungültiger Pfad.', 500);
        }
    }
}
