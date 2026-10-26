<?php

namespace Espo\Custom\Hooks\Common;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\Job\Data;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Custom\Jobs\ConversationSummarizer;

class ActivityConversationSummary
{
    private const ACTIVITY_TYPES = ['Call', 'Meeting', 'Task', 'Email'];

    public function __construct(private JobSchedulerFactory $jobSchedulerFactory) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!in_array($entity->getEntityType(), self::ACTIVITY_TYPES, true)) {
            return;
        }

        if ($entity->get('parentType') !== 'Account') {
            return;
        }

        $accountId = $entity->get('parentId');

        if (!$accountId) {
            return;
        }

        $this->jobSchedulerFactory->create()
            ->setClassName(ConversationSummarizer::class)
            ->setGroup('conversation-summary-' . $accountId)
            ->setData(Data::create()->withTargetId($accountId))
            ->schedule();
    }
}
