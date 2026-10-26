<?php

namespace Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use stdClass;

class Service
{
    public function __construct(
        private RuleEngine $ruleEngine,
        private AiSuggester $aiSuggester,
    ) {}

    /**
     * Determine optimal reminders using AI when available, falling back to deterministic rules.
     */
    public function suggestTiming(
        string $entityType,
        ?string $name,
        ?string $description,
        ?string $dateStart,
        ?string $priority = null,
        bool $useAi = true,
        ?DateTimeImmutable $referenceTime = null,
        bool $enablePrepBuffer = true,
    ): stdClass {
        $ruleResult = $this->ruleEngine->evaluate(
            entityType: $entityType,
            dateStart: $dateStart,
            name: $name,
            description: $description,
            priority: $priority,
            referenceTime: $referenceTime,
            enablePrepBuffer: $enablePrepBuffer,
        );

        $leadTimeSeconds = $ruleResult['leadTimeSeconds'];

        if ($leadTimeSeconds <= 0) {
            return (object) [
                'reminders' => $ruleResult['reminders'],
                'source' => 'rules',
                'reason' => $ruleResult['reason'],
                'leadTimeSeconds' => $leadTimeSeconds,
            ];
        }

        if ($useAi) {
            $aiResult = $this->aiSuggester->suggest(
                entityType: $entityType,
                name: $name,
                description: $description,
                dateStart: $dateStart,
                priority: $priority,
                leadTimeSeconds: $leadTimeSeconds,
            );

            if ($aiResult !== null && $aiResult['reminders'] !== []) {
                return (object) [
                    'reminders' => $aiResult['reminders'],
                    'source' => 'ai',
                    'reason' => $aiResult['reason'],
                    'leadTimeSeconds' => $leadTimeSeconds,
                ];
            }
        }

        return (object) [
            'reminders' => $ruleResult['reminders'],
            'source' => 'rules',
            'reason' => $ruleResult['reason'],
            'leadTimeSeconds' => $leadTimeSeconds,
        ];
    }
}
