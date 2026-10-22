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

    /**
     * @param (callable(string): bool)|null $isPackageInstalled
     */
    public function __construct(?callable $isPackageInstalled = null)
    {
        $this->isPackageInstalled = $isPackageInstalled !== null
            ? $isPackageInstalled(...)
            : static fn (string $package): bool => class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
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
