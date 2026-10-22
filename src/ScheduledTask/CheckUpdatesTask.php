<?php declare(strict_types=1);

namespace DhGithubUpdater\ScheduledTask;

use DhGithubUpdater\Service\UpdateChecker;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class CheckUpdatesTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'dh_github_updater.check_updates';
    }

    public static function getDefaultInterval(): int
    {
        return UpdateChecker::INTERVAL;
    }
}
