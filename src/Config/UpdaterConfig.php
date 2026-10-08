<?php declare(strict_types=1);

namespace DhGithubUpdater\Config;

use DhGithubUpdater\Security\RepositoryAllowList;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typisierter Zugriff auf die Plugin-Konfiguration.
 */
class UpdaterConfig
{
    private const PREFIX = 'DhGithubUpdater.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getToken(): ?string
    {
        $token = trim((string) $this->systemConfigService->getString(self::PREFIX . 'githubToken'));

        return $token !== '' && preg_match('/^[A-Za-z0-9_]{20,255}$/', $token) ? $token : null;
    }

    public function getAllowList(): RepositoryAllowList
    {
        return new RepositoryAllowList($this->systemConfigService->getString(self::PREFIX . 'allowedRepositories'));
    }

    public function isBranchInstallAllowed(): bool
    {
        return $this->systemConfigService->getBool(self::PREFIX . 'allowBranches');
    }

    public function isAdminOnly(): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'adminOnly');

        return $value === null || (bool) $value;
    }

    public function getMaxDownloadBytes(): int
    {
        $mb = $this->systemConfigService->getInt(self::PREFIX . 'maxDownloadSizeMb');

        return max(1, min($mb > 0 ? $mb : 50, 500)) * 1024 * 1024;
    }

    public function getMaxTagsToCheck(): int
    {
        $count = $this->systemConfigService->getInt(self::PREFIX . 'maxTagsToCheck');

        return max(1, min($count > 0 ? $count : 15, 50));
    }
}
