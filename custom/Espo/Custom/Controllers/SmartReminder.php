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
        $useAi = is_object($data)
            ? (!isset($data->useAi) || (bool) $data->useAi)
            : (!isset($data['useAi']) || (bool) $data['useAi']);

        if ($dateStart === null || trim((string) $dateStart) === '') {
            throw new BadRequest('dateStart parameter is required.');
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
        );
    }
}
