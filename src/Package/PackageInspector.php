<?php declare(strict_types=1);

namespace DhGithubUpdater\Package;

use Composer\InstalledVersions;

/**
 * Erkennt typische Gründe, warum ein aus GitHub geladenes Plugin nach der
 * Installation nicht vollständig funktioniert. Es wird nichts blockiert –
 * die Hinweise werden in der Administration angezeigt.
 */
class PackageInspector
{
    public const ADMIN_NOT_BUILT = 'adminNotBuilt';
    public const STOREFRONT_NOT_BUILT = 'storefrontNotBuilt';
    public const MISSING_DEPENDENCIES = 'missingDependencies';

    /**
     * @var \Closure(string): bool
     */
    private readonly \Closure $isPackageInstalled;

    /** @var array<string, true>|null */
    private ?array $installedNames = null;

    /**
     * @param (callable(string): bool)|null $isPackageInstalled
     */
    public function __construct(?callable $isPackageInstalled = null, private readonly ?string $projectDir = null)
    {
        $this->isPackageInstalled = $isPackageInstalled !== null
            ? $isPackageInstalled(...)
            : fn (string $package): bool => $this->isInstalled($package);
    }

    /**
     * Liest vendor/composer/installed.json direkt – so sind auch Pakete sichtbar, die Shopware gerade
     * erst per Composer nachinstalliert hat (der Opcache kann installed.php noch alt vorhalten).
     */
    private function isInstalled(string $package): bool
    {
        if ($this->installedNames === null) {
            $this->installedNames = [];
            $file = $this->projectDir !== null ? $this->projectDir . '/vendor/composer/installed.json' : null;
            $data = $file !== null && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $packages = \is_array($data) ? ($data['packages'] ?? $data) : [];
            foreach (\is_array($packages) ? $packages : [] as $entry) {
                if (\is_array($entry) && \is_string($entry['name'] ?? null)) {
                    $this->installedNames[strtolower($entry['name'])] = true;
                }
            }
        }

        if (isset($this->installedNames[strtolower($package)])) {
            return true;
        }

        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @return list<array{code: string, params: array<string, string>}>
     */
    public function inspect(string $pluginDir, PluginManifest $manifest): array
    {
        $warnings = [];
        $resources = $pluginDir . '/src/Resources';

        if ($this->hasEntry($resources . '/app/administration/src') && !is_dir($resources . '/public/administration')) {
            $warnings[] = ['code' => self::ADMIN_NOT_BUILT, 'params' => []];
        }

        if ($this->hasEntry($resources . '/app/storefront/src') && !is_dir($resources . '/app/storefront/dist')) {
            $warnings[] = ['code' => self::STOREFRONT_NOT_BUILT, 'params' => []];
        }

        if (!is_dir($pluginDir . '/vendor')) {
            $missing = array_values(array_filter(
                $manifest->packageDependencies,
                fn (string $package): bool => !($this->isPackageInstalled)($package),
            ));
            if ($missing !== []) {
                $warnings[] = ['code' => self::MISSING_DEPENDENCIES, 'params' => ['packages' => implode(' ', $missing)]];
            }
        }

        return $warnings;
    }

    /**
     * Welche der Composer-Pakete fehlen im Shop (Stand dieses Requests)?
     *
     * @param list<string> $packages
     *
     * @return list<string>
     */
    public function missingPackages(array $packages): array
    {
        return array_values(array_filter(
            $packages,
            fn (string $package): bool => !($this->isPackageInstalled)($package),
        ));
    }

    private function hasEntry(string $sourceDir): bool
    {
        foreach (['main.js', 'main.ts'] as $file) {
            if (is_file($sourceDir . '/' . $file)) {
                return true;
            }
        }

        return false;
    }
}
