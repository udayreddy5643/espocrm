<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl\Table;
use Espo\Core\Api\Request;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Custom\Tools\SmartReminder\Service;
use stdClass;

class SmartReminder extends Record
{
    /**
     * Suggest optimal reminders and timing based on activity attributes and AI context.
     *
     * @throws Forbidden
     * @throws BadRequest
     */
    public function postActionSuggestTiming(Request $request): stdClass
    {
        $data = $request->getParsedBody();

        $entityType = is_object($data)
            ? (string) ($data->entityType ?? 'Meeting')
            : (string) ($data['entityType'] ?? 'Meeting');

        if (!in_array($entityType, ['Meeting', 'Call', 'Task'], true)) {
            throw new BadRequest("Unsupported entityType '{$entityType}' for smart reminders.");
        }

        if (!$this->acl->checkScope($entityType, Table::ACTION_READ)) {
            throw new Forbidden("Access denied for scope {$entityType}.");
        }

        $name = is_object($data) ? ($data->name ?? null) : ($data['name'] ?? null);
        $description = is_object($data) ? ($data->description ?? null) : ($data['description'] ?? null);
        $dateStart = is_object($data) ? ($data->dateStart ?? null) : ($data['dateStart'] ?? null);
        $priority = is_object($data) ? ($data->priority ?? null) : ($data['priority'] ?? null);

        if ($dateStart === null || trim((string) $dateStart) === '') {
            throw new BadRequest('dateStart parameter is required.');
        }

        // Resolve authenticated user preferences
        $smartEnabled = true;
        $userMode = 'ai';
        $enablePrepBuffer = true;

        try {
            $entityManager = $this->getEntityManager();
            $userId = $this->user->getId();

            if ($entityManager && $userId) {
                $preferences = $entityManager->getEntityById('Preferences', $userId);
                if ($preferences) {
                    if ($preferences->has('smartReminderEnabled')) {
                        $smartEnabled = (bool) $preferences->get('smartReminderEnabled');
                    }
                    if ($preferences->has('smartReminderMode')) {
                        $userMode = (string) $preferences->get('smartReminderMode');
                    }
                    if ($preferences->has('smartReminderPreparationBuffer')) {
                        $enablePrepBuffer = (bool) $preferences->get('smartReminderPreparationBuffer');
                    }
                }
            }
        } catch (\Throwable) {
            // Fallback to defaults
        }

        // Allow request body to explicitly override enablePrepBuffer
        $explicitPrepBuffer = is_object($data)
            ? ($data->enablePrepBuffer ?? null)
            : ($data['enablePrepBuffer'] ?? null);

        if ($explicitPrepBuffer !== null) {
            $enablePrepBuffer = (bool) $explicitPrepBuffer;
        }

        // Determine useAi: check explicit request override, otherwise adhere to user preferences
        $hasExplicitUseAi = is_object($data) ? isset($data->useAi) : isset($data['useAi']);

        if ($hasExplicitUseAi) {
            $useAi = is_object($data) ? (bool) $data->useAi : (bool) $data['useAi'];
        } else {
            // If user configured "rules" or disabled in preferences, don't run AI
            $useAi = ($userMode === 'ai' && $smartEnabled);
        }

        /** @var Service $service */
        $service = $this->injectableFactory->create(Service::class);

        return $service->suggestTiming(
            entityType: $entityType,
            name: $name !== null ? (string) $name : null,
            description: $description !== null ? (string) $description : null,
            dateStart: (string) $dateStart,
            priority: $priority !== null ? (string) $priority : null,
            useAi: $useAi,
            enablePrepBuffer: $enablePrepBuffer,
        );
    }

    private function getEntityManager(): ?\Espo\ORM\EntityManager
    {
        try {
            return $this->injectableFactory->create(\Espo\ORM\EntityManager::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
