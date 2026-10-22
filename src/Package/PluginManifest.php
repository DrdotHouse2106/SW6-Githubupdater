<?php declare(strict_types=1);

namespace DhGithubUpdater\Package;

use DhGithubUpdater\Exception\GithubUpdaterException;

/**
 * Validierte Kerndaten aus der composer.json eines Shopware-Plugins.
 */
final class PluginManifest
{
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';
    private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,99}$/';

    private function __construct(
        public readonly string $pluginName,
        public readonly string $pluginClass,
        public readonly ?string $version,
        public readonly ?string $shopwareConstraint,
        /** @var list<string> Composer-Pakete außerhalb von PHP, Erweiterungen und Shopware selbst */
        public readonly array $packageDependencies = [],
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GithubUpdaterException('Die composer.json des Plugins ist ungültig.', 422, $e);
        }

        if (!\is_array($data)) {
            throw new GithubUpdaterException('Die composer.json des Plugins ist ungültig.', 422);
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'shopware-platform-plugin') {
            throw new GithubUpdaterException('Das Repository enthält kein Shopware-6-Plugin (composer.json "type" muss "shopware-platform-plugin" sein).', 422);
        }

        $class = $data['extra']['shopware-plugin-class'] ?? null;
        if (!\is_string($class) || !preg_match(self::CLASS_PATTERN, $class)) {
            throw new GithubUpdaterException('Die Plugin-Klasse in der composer.json ist ungültig.', 422);
        }

        $segments = explode('\\', $class);
        $name = end($segments);
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new GithubUpdaterException('Der Plugin-Name ist ungültig.', 422);
        }

        $version = $data['version'] ?? null;
        if (!\is_string($version) || !preg_match('/^v?[0-9A-Za-z.+-]{1,64}$/', $version)) {
            $version = null;
        }

        $constraint = $data['require']['shopware/core'] ?? null;
        if (!\is_string($constraint) || \strlen($constraint) > 200) {
            $constraint = null;
        }

        $dependencies = [];
        foreach (\is_array($data['require'] ?? null) ? $data['require'] : [] as $package => $_) {
            $package = (string) $package;
            if (preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package)
                && !str_starts_with($package, 'shopware/')
                && \count($dependencies) < 50
            ) {
                $dependencies[] = $package;
            }
        }

        return new self($name, $class, $version, $constraint, $dependencies);
    }
}
