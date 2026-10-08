<?php declare(strict_types=1);

namespace DhGithubUpdater;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class DhGithubUpdater extends Plugin
{
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `dh_github_updater_source`');
    }

    public function executeComposerCommands(): bool
    {
        return false;
    }
}
