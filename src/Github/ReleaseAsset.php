<?php declare(strict_types=1);

namespace DhGithubUpdater\Github;

/**
 * Ein ZIP-Anhang eines GitHub-Releases (z. B. das fertig gebaute Plugin-Paket des Herstellers).
 */
final class ReleaseAsset
{
    private const NAME_PATTERN = '/^[A-Za-z0-9._+-]{1,200}\.zip$/i';

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $size,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromApi(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $size = $data['size'] ?? null;
        $state = $data['state'] ?? 'uploaded';

        if (!\is_int($id) || $id <= 0 || !\is_string($name) || !preg_match(self::NAME_PATTERN, $name) || !\is_int($size) || $state !== 'uploaded') {
            return null;
        }

        return new self($id, $name, $size);
    }

    /**
     * Wählt das Plugin-Paket aus den Anhängen eines Releases:
     * 1. exakt "<PluginName>.zip", 2. beginnt mit dem Plugin-Namen, 3. einziger ZIP-Anhang.
     *
     * @param list<self> $assets
     */
    public static function select(array $assets, string $pluginName): ?self
    {
        $lower = strtolower($pluginName);

        foreach ($assets as $asset) {
            if (strtolower($asset->name) === $lower . '.zip') {
                return $asset;
            }
        }

        foreach ($assets as $asset) {
            if (str_starts_with(strtolower($asset->name), $lower)) {
                return $asset;
            }
        }

        return \count($assets) === 1 ? $assets[0] : null;
    }
}
