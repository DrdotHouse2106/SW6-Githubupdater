<?php declare(strict_types=1);

namespace DhGithubUpdater\ScheduledTask;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Service\UpdateChecker;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: CheckUpdatesTask::class)]
class CheckUpdatesTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly UpdateChecker $updateChecker,
        private readonly UpdaterConfig $config,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        if (!$this->config->isAutoCheckEnabled()) {
            return;
        }

        $this->updateChecker->run(Context::createDefaultContext(), $this->config->isUpdateNotificationEnabled());
    }
}
