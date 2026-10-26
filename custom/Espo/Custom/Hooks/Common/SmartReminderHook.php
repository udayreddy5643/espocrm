<?php

namespace Espo\Custom\Hooks\Common;

use Espo\Custom\Tools\SmartReminder\RuleEngine;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use stdClass;

class SmartReminderHook
{
    public static int $order = 8;

    private const SUPPORTED_ENTITIES = ['Meeting', 'Call', 'Task'];

    public function __construct(
        private RuleEngine $ruleEngine,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!in_array($entity->getEntityType(), self::SUPPORTED_ENTITIES, true)) {
            return;
        }

        $dateStart = $entity->get('dateStart');

        if ($dateStart === null || trim((string) $dateStart) === '') {
            return;
        }

        $applySmart = (bool) $entity->get('applySmartReminders');
        $hasExplicitReminders = $entity->has('reminders') &&
            $entity->get('reminders') !== null &&
            count((array) $entity->get('reminders')) > 0;

        // Do not override explicit user reminders unless explicitly requested
        if ($hasExplicitReminders && !$applySmart) {
            return;
        }

        // Only evaluate on new records, dateStart changes, or explicit trigger
        if (!$entity->isNew() && !$entity->isAttributeChanged('dateStart') && !$applySmart) {
            return;
        }

        $evaluation = $this->ruleEngine->evaluate(
            entityType: $entity->getEntityType(),
            dateStart: (string) $dateStart,
            name: $entity->get('name'),
            description: $entity->get('description'),
            priority: $entity->get('priority'),
        );

        if ($evaluation['reminders'] === []) {
            return;
        }

        $formatted = array_map(
            static function (array $item): stdClass {
                $obj = new stdClass();
                $obj->type = $item['type'];
                $obj->seconds = (int) $item['seconds'];

                return $obj;
            },
            $evaluation['reminders']
        );

        $entity->set('reminders', $formatted);
    }
}
