<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Exception\GithubUpdaterException;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\App\Lifecycle\AbstractAppLifecycle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginLifecycleService;
use Shopware\Storefront\Theme\ThemeService;

/**
 * Plugin-Update bzw. Installation in Schritten, damit keine einzelne Anfrage in ein Zeitlimit läuft:
 *
 *  1. Lebenszyklus (Migrationen, Theme-Einstellungen, Assets) – ohne Themes zu kompilieren.
 *     Shopware würde sonst in derselben Anfrage die Themes aller Verkaufskanäle kompilieren,
 *     bei mehreren Shops oft länger als PHP bzw. der Browser warten.
 *  2. Themes einzeln je Verkaufskanal kompilieren (je eine Anfrage, mit Fortschrittsanzeige).
 *
 * Ohne Storefront (reine API-Installationen) entfällt Schritt 2.
 */
class StepwiseLifecycle
{
    /**
     * @param EntityRepository<PluginCollection> $pluginRepository
     */
    public function __construct(
        private readonly PluginLifecycleService $lifecycle,
        private readonly EntityRepository $pluginRepository,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly ?ThemeService $themeService = null,
    ) {
    }

    /**
     * @return array{version: ?string, themeJobs: list<array{salesChannelId: string, themeId: string, name: string}>}
     */
    public function update(string $pluginName, Context $context): array
    {
        $plugin = $this->plugin($pluginName, $context);
        if ($plugin->getUpgradeVersion() !== null) {
            $context->addState(AbstractAppLifecycle::STATE_SKIP_THEME_COMPILATION);
            $this->lifecycle->updatePlugin($plugin, $context);
        }

        return [
            'version' => $this->plugin($pluginName, $context)->getVersion(),
            'themeJobs' => $this->themeJobsFor($this->plugin($pluginName, $context)),
        ];
    }

    /**
     * @return array{active: bool, themeJobs: list<array{salesChannelId: string, themeId: string, name: string}>}
     */
    public function installAndActivate(string $pluginName, Context $context): array
    {
        $context->addState(AbstractAppLifecycle::STATE_SKIP_THEME_COMPILATION);

        $plugin = $this->plugin($pluginName, $context);
        if ($plugin->getInstalledAt() === null) {
            $this->lifecycle->installPlugin($plugin, $context);
            $plugin = $this->plugin($pluginName, $context);
        }
        if (!$plugin->getActive()) {
            $this->lifecycle->activatePlugin($plugin, $context);
        }

        return [
            'active' => $this->plugin($pluginName, $context)->getActive(),
            'themeJobs' => $this->themeJobsFor($this->plugin($pluginName, $context)),
        ];
    }

    /**
     * Kompiliert das Theme genau eines Verkaufskanals.
     */
    public function compileTheme(string $salesChannelId, string $themeId, Context $context): void
    {
        if ($this->themeService === null) {
            throw new GithubUpdaterException('Ohne Storefront gibt es keine Themes zu kompilieren.', 400);
        }

        $known = $this->connection->fetchOne(
            'SELECT 1 FROM `theme_sales_channel` WHERE `sales_channel_id` = UNHEX(:sc) AND `theme_id` = UNHEX(:theme)',
            ['sc' => $salesChannelId, 'theme' => $themeId]
        );
        if ($known === false) {
            throw new GithubUpdaterException('Dieses Theme ist dem Verkaufskanal nicht zugeordnet.', 404);
        }

        $this->themeService->compileTheme($salesChannelId, $themeId, $context);
    }

    /**
     * Theme/Verkaufskanal-Paare, die neu kompiliert werden müssen – nur wenn das Plugin
     * Storefront-Dateien (SCSS/JS) oder ein Theme mitbringt. Geprüft wird auf der Festplatte, weil
     * Shopwares Liste der Storefront-Plugins ein frisch aktiviertes Plugin in dieser Anfrage noch nicht kennt.
     *
     * @return list<array{salesChannelId: string, themeId: string, name: string}>
     */
    public function themeJobsFor(PluginEntity $plugin): array
    {
        if ($this->themeService === null || !$plugin->getActive() || !self::hasStorefrontFiles((string) $plugin->getPath(), $this->projectDir)) {
            return [];
        }

        return $this->themeJobs();
    }

    /**
     * Bringt das Plugin (Pfad relativ zum Projekt oder absolut) Storefront-Dateien oder ein Theme mit?
     */
    public static function hasStorefrontFiles(string $pluginPath, string $projectDir): bool
    {
        if ($pluginPath === '') {
            return false;
        }
        $base = str_starts_with($pluginPath, '/') ? rtrim($pluginPath, '/') : rtrim($projectDir, '/') . '/' . trim($pluginPath, '/');

        foreach (['src/Resources', 'Resources'] as $resources) {
            if (is_dir("$base/$resources/app/storefront") || is_file("$base/$resources/theme.json")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{salesChannelId: string, themeId: string, name: string}>
     */
    public function themeJobs(): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(tsc.`sales_channel_id`)) AS sales_channel_id, LOWER(HEX(tsc.`theme_id`)) AS theme_id,
                        COALESCE(sct.`name`, LOWER(HEX(tsc.`sales_channel_id`))) AS name
                 FROM `theme_sales_channel` tsc
                 INNER JOIN `sales_channel` sc ON sc.`id` = tsc.`sales_channel_id`
                 LEFT JOIN `sales_channel_translation` sct ON sct.`sales_channel_id` = sc.`id` AND sct.`language_id` = sc.`language_id`
                 ORDER BY sc.`active` DESC, name'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DhGithubUpdater: Theme-Zuordnungen nicht lesbar.', ['exception' => $e]);

            return [];
        }

        return array_map(static fn (array $row): array => [
            'salesChannelId' => (string) $row['sales_channel_id'],
            'themeId' => (string) $row['theme_id'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    private function plugin(string $pluginName, Context $context): PluginEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', $pluginName))->setLimit(1);
        $plugin = $this->pluginRepository->search($criteria, $context)->first();
        if (!$plugin instanceof PluginEntity) {
            throw new GithubUpdaterException(\sprintf('Plugin „%s“ ist nicht bekannt.', $pluginName), 404);
        }

        return $plugin;
    }
}
