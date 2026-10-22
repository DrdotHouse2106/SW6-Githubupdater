<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GitRef;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Package\CompatibilityChecker;
use DhGithubUpdater\Package\PluginManifest;

/**
 * „Verbindung testen“: prüft ein Repository Schritt für Schritt, ohne etwas
 * herunterzuladen oder zu installieren, und liefert pro Punkt ein verständliches
 * Ergebnis (ok / Warnung / Fehler / übersprungen).
 */
class ConnectionTester
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly GithubApiClient $client,
        private readonly VersionResolver $versionResolver,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly UpdaterConfig $config,
    ) {
    }

    /**
     * @param string|null $expectedPluginName Name des installierten Plugins, mit dem verknüpft werden soll
     * @param string|null $expectedPluginClass Plugin-Klasse des installierten Plugins
     * @param string|null $installedVersion Installierte Version (nur für die Anzeige)
     *
     * @return array{repository: ?string, ok: bool, checks: list<array{key: string, status: string, message: string}>}
     */
    public function test(
        string $repositoryInput,
        ?string $expectedPluginName = null,
        ?string $expectedPluginClass = null,
        ?string $installedVersion = null,
    ): array {
        $checks = [];
        $add = static function (string $key, string $status, string $message) use (&$checks): void {
            $checks[] = ['key' => $key, 'status' => $status, 'message' => $message];
        };
        $skipRemaining = static function (array $keys) use ($add): void {
            foreach ($keys as $key) {
                $add($key, self::SKIPPED, 'Übersprungen, weil ein vorheriger Schritt fehlgeschlagen ist.');
            }
        };

        // 1. Eingabe
        try {
            $repository = RepositoryReference::fromUserInput($repositoryInput);
        } catch (GithubUpdaterException $e) {
            $add('format', self::ERROR, $e->getMessage());
            $skipRemaining(['allowList', 'access', 'plugin', 'releases', 'compatibility']);

            return $this->result(null, $checks);
        }
        $add('format', self::OK, \sprintf('Repository „%s“ erkannt.', $repository->getFullName()));

        // 2. Erlaubte Repositories
        $allowList = $this->config->getAllowList();
        if (!$allowList->isAllowed($repository)) {
            $add('allowList', self::ERROR, 'Dieses Repository steht nicht in der Liste „Erlaubte Repositories“ der Plugin-Konfiguration.');
        } else {
            $add('allowList', self::OK, $allowList->isRestricted()
                ? 'Repository ist laut „Erlaubte Repositories“ zugelassen.'
                : 'Keine Einschränkung konfiguriert – alle Repositories sind erlaubt (empfohlen: „Erlaubte Repositories“ pflegen).');
        }

        // 3. Erreichbarkeit (öffentlich oder mit Token)
        $token = $this->config->getToken();
        try {
            $defaultBranch = $this->client->getDefaultBranch($repository, $token);
        } catch (GithubUpdaterException $e) {
            $add('access', self::ERROR, $e->getMessage());
            $skipRemaining(['plugin', 'releases', 'compatibility']);

            return $this->result($repository->getFullName(), $checks);
        }
        $add('access', self::OK, $token === null
            ? 'Repository ist erreichbar (ohne Token – GitHub erlaubt dann 60 Anfragen pro Stunde).'
            : 'Repository ist mit dem hinterlegten Token erreichbar.');

        // 4. composer.json des Standard-Branches und Abgleich mit dem installierten Plugin
        try {
            $this->checkPlugin($repository, $defaultBranch, $token, $expectedPluginName, $expectedPluginClass, $add);

            // 5. Tags/Releases
            $tags = array_values(array_filter(
                $this->client->listTags($repository, $token),
                static fn (GitRef $tag): bool => CompatibilityChecker::isParsableVersion($tag->name),
            ));
            if ($tags === []) {
                $add('releases', self::WARNING, $this->config->isBranchInstallAllowed()
                    ? 'Keine Releases (Tags wie „v1.0.0“) gefunden – es wird aus Branches installiert. Für feste Versionen auf GitHub ein Release anlegen.'
                    : 'Keine Releases (Tags wie „v1.0.0“) gefunden. Auf GitHub ein Release anlegen oder in der Konfiguration „Installation aus Branches“ erlauben.');
            } else {
                $add('releases', self::OK, \sprintf('%d Release(s)/Tag(s) gefunden.', \count($tags)));
            }

            // 6. Kompatible Versionen für die laufende Shopware-Version
            $candidates = $this->versionResolver->listCompatible($repository);
        } catch (GithubUpdaterException $e) {
            $add('compatibility', self::ERROR, $e->getMessage());

            return $this->result($repository->getFullName(), $checks);
        }

        $shopwareVersion = $this->compatibilityChecker->getShopwareVersion();
        if ($candidates === []) {
            $add('compatibility', $tags === [] && !$this->config->isBranchInstallAllowed() ? self::WARNING : self::ERROR, \sprintf(
                'Keine mit Shopware %s kompatible Version gefunden. In der composer.json muss „require.shopware/core“ diese Version erlauben.',
                $shopwareVersion,
            ));
        } else {
            $newest = $candidates[0]->toArray();
            $message = \sprintf(
                '%d kompatible Version(en) für Shopware %s, neueste: %s.',
                \count($candidates),
                $shopwareVersion,
                $newest['label'],
            );
            if ($installedVersion !== null && $newest['version'] !== null) {
                $comparison = CompatibilityChecker::compare($newest['version'], $installedVersion);
                $message .= $comparison !== null && $comparison > 0
                    ? \sprintf(' Installiert ist %s – ein Update ist verfügbar.', $installedVersion)
                    : \sprintf(' Installiert ist %s.', $installedVersion);
            }
            $add('compatibility', self::OK, $message);
        }

        return $this->result($repository->getFullName(), $checks);
    }

    /**
     * @param callable(string, string, string): void $add
     */
    private function checkPlugin(
        RepositoryReference $repository,
        ?string $defaultBranch,
        ?string $token,
        ?string $expectedPluginName,
        ?string $expectedPluginClass,
        callable $add,
    ): void {
        $branch = null;
        foreach ($this->client->listBranches($repository, $token) as $candidate) {
            if ($candidate->name === $defaultBranch) {
                $branch = $candidate;
                break;
            }
        }

        $json = $branch !== null ? $this->client->getComposerJson($repository, $branch->commitSha, $token) : null;
        if ($json === null || $json === '') {
            $add('plugin', self::ERROR, 'Im Standard-Branch wurde keine composer.json im Hauptverzeichnis gefunden.');

            return;
        }

        try {
            $manifest = PluginManifest::fromJson($json);
        } catch (GithubUpdaterException $e) {
            $add('plugin', self::ERROR, $e->getMessage());

            return;
        }

        if ($expectedPluginName !== null && $manifest->pluginName !== $expectedPluginName) {
            $add('plugin', self::ERROR, \sprintf(
                'Das Repository enthält das Plugin „%s“, verknüpft werden soll aber „%s“.',
                $manifest->pluginName,
                $expectedPluginName,
            ));

            return;
        }

        if ($expectedPluginClass !== null && ltrim($manifest->pluginClass, '\\') !== ltrim($expectedPluginClass, '\\')) {
            $add('plugin', self::ERROR, \sprintf(
                'Die Plugin-Klasse im Repository (%s) passt nicht zum installierten Plugin (%s).',
                $manifest->pluginClass,
                $expectedPluginClass,
            ));

            return;
        }

        $add('plugin', self::OK, \sprintf(
            'composer.json gültig: Plugin „%s“%s.',
            $manifest->pluginName,
            $expectedPluginName !== null ? ', passt zum installierten Plugin' : '',
        ));
    }

    /**
     * @param list<array{key: string, status: string, message: string}> $checks
     *
     * @return array{repository: ?string, ok: bool, checks: list<array{key: string, status: string, message: string}>}
     */
    private function result(?string $repository, array $checks): array
    {
        $ok = true;
        foreach ($checks as $check) {
            if ($check['status'] === self::ERROR) {
                $ok = false;
            }
        }

        return ['repository' => $repository, 'ok' => $ok, 'checks' => $checks];
    }
}
