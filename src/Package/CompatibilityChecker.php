<?php declare(strict_types=1);

namespace DhGithubUpdater\Package;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Prüft mit composer/semver, ob ein Plugin zur laufenden Shopware-Version passt.
 */
class CompatibilityChecker
{
    private readonly string $shopwareVersion;

    public function __construct(string $shopwareVersion)
    {
        // Entwicklungsversionen wie "6.6.9999999.9999999-dev" auf die Basisversion reduzieren.
        if (preg_match('/^(\d+\.\d+\.\d+(?:\.\d+)?)/', $shopwareVersion, $matches)) {
            $shopwareVersion = $matches[1];
        }

        $this->shopwareVersion = $shopwareVersion;
    }

    public function getShopwareVersion(): string
    {
        return $this->shopwareVersion;
    }

    public function isCompatible(PluginManifest $manifest): bool
    {
        if ($manifest->shopwareConstraint === null) {
            return false;
        }

        try {
            return Semver::satisfies($this->shopwareVersion, $manifest->shopwareConstraint);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Vergleicht zwei Versionsangaben; liefert null, wenn eine davon nicht interpretierbar ist.
     */
    public static function compare(string $a, string $b): ?int
    {
        $parser = new VersionParser();

        try {
            $normalizedA = $parser->normalize(ltrim($a, 'vV'));
            $normalizedB = $parser->normalize(ltrim($b, 'vV'));
        } catch (\Throwable) {
            return null;
        }

        return version_compare($normalizedA, $normalizedB);
    }

    public static function isParsableVersion(string $version): bool
    {
        try {
            (new VersionParser())->normalize(ltrim($version, 'vV'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
