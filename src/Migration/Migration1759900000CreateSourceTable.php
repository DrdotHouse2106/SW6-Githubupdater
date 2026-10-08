<?php declare(strict_types=1);

namespace DhGithubUpdater\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Speichert, aus welchem GitHub-Repository ein Plugin stammt und welcher
 * Stand (Tag/Branch + Commit) zuletzt installiert wurde.
 */
class Migration1759900000CreateSourceTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1759900000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `dh_github_updater_source` (
                `id` BINARY(16) NOT NULL,
                `plugin_id` BINARY(16) NOT NULL,
                `repository` VARCHAR(140) NOT NULL,
                `installed_ref` VARCHAR(255) NULL,
                `installed_ref_type` VARCHAR(16) NULL,
                `installed_commit` CHAR(40) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.dh_github_updater_source.plugin_id` (`plugin_id`),
                CONSTRAINT `fk.dh_github_updater_source.plugin_id` FOREIGN KEY (`plugin_id`)
                    REFERENCES `plugin` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
