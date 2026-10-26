<?php

namespace Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class RuleEngine
{
    public const TYPE_POPUP = 'Popup';
    public const TYPE_EMAIL = 'Email';

    public const PREP_KEYWORD_REGEX = '/\b(prep|preparation|presentation|demo|review|contract|interview|pitch|kickoff|board)\b/i';

    private const ALLOWED_OFFSETS = [
        0,
        300,
        600,
        900,
        1800,
        3600,
        7200,
        10800,
        14400,
        18000,
        21600,
        28800,
        43200,
        86400,
        172800,
    ];

    /**
     * Evaluate scheduling context and return recommended reminders with reasoning.
     *
     * @return array{
     *   reminders: array<array{type: string, seconds: int}>,
     *   reason: string,
     *   leadTimeSeconds: int
     * }
     */
    public function evaluate(
        string $entityType,
        ?string $dateStart,
        ?string $name = null,
        ?string $description = null,
        ?string $priority = null,
        ?DateTimeImmutable $referenceTime = null,
    ): array {
        $reference = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($dateStart === null || trim($dateStart) === '') {
            return [
                'reminders' => [],
                'reason' => 'No start date provided; cannot schedule reminders.',
                'leadTimeSeconds' => 0,
            ];
        }

        $startDate = $this->parseDateTime($dateStart);

        if (!$startDate) {
            return [
                'reminders' => [],
                'reason' => 'Invalid start date format.',
                'leadTimeSeconds' => 0,
            ];
        }

        $leadTimeSeconds = $startDate->getTimestamp() - $reference->getTimestamp();

        if ($leadTimeSeconds <= 0) {
            return [
                'reminders' => [],
                'reason' => 'Event is already starting or in the past; no advance reminders scheduled.',
                'leadTimeSeconds' => $leadTimeSeconds,
            ];
        }

        $reminders = [];
        $reasons = [];
        $isHighPriority = in_array(strtolower((string) $priority), ['high', 'urgent'], true);
        $hasPrepKeywords = $this->hasPreparationKeywords($name, $description);

        // Rule 1: Lead-time based baseline
        if ($leadTimeSeconds <= 600) {
            // Under 10 minutes: immediate alert at start time
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 0];
            $reasons[] = 'Starts in <=10m (immediate popup)';
        } elseif ($leadTimeSeconds <= 1800) {
            // 10 to 30 minutes: 5 minutes before
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 300];
            $reasons[] = 'Starts in <=30m (5m advance popup)';
        } elseif ($leadTimeSeconds <= 3600) {
            // 30 to 60 minutes: 10 minutes before
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 600];
            $reasons[] = 'Starts in <=1h (10m advance popup)';
        } elseif ($leadTimeSeconds <= 86400) {
            // Same day (1h to 24h): 30 minutes before
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 1800];
            $reasons[] = 'Same-day event (30m advance popup)';

            if ($leadTimeSeconds >= 14400 && $isHighPriority) {
                // High priority same-day: 2 hours advance email + 1 hour popup
                $reminders[] = ['type' => self::TYPE_EMAIL, 'seconds' => 7200];
                $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 3600];
                $reasons[] = 'High-priority same-day escalation (2h email + 1h popup)';
            }
        } else {
            // Advance event (> 24 hours): Multi-tier
            $reminders[] = ['type' => self::TYPE_EMAIL, 'seconds' => 86400];
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 1800];
            $reasons[] = 'Advance event (1d email + 30m popup)';

            if ($isHighPriority && $leadTimeSeconds >= 172800) {
                // High priority multi-day: 2 days advance email
                $reminders[] = ['type' => self::TYPE_EMAIL, 'seconds' => 172800];
                $reasons[] = 'High-priority multi-day alert (2d email)';
            }
        }

        // Rule 2: Preparation cue rule
        if ($hasPrepKeywords && $leadTimeSeconds >= 14400) {
            $reminders[] = ['type' => self::TYPE_POPUP, 'seconds' => 7200];
            $reasons[] = 'Preparation/agenda buffer detected (2h prep popup)';
        }

        // Rule 3: Priority elevation channel coverage
        if ($isHighPriority) {
            $hasEmail = false;
            foreach ($reminders as $r) {
                if ($r['type'] === self::TYPE_EMAIL) {
                    $hasEmail = true;
                    break;
                }
            }

            if (!$hasEmail && $leadTimeSeconds >= 7200) {
                $reminders[] = ['type' => self::TYPE_EMAIL, 'seconds' => min(3600, $leadTimeSeconds - 600)];
                $reasons[] = 'Priority escalation guaranteed email channel';
            }
        }

        $sanitized = $this->sanitize($reminders, $leadTimeSeconds);

        return [
            'reminders' => $sanitized,
            'reason' => implode('; ', $reasons),
            'leadTimeSeconds' => $leadTimeSeconds,
        ];
    }

    private function hasPreparationKeywords(?string $name, ?string $description): bool
    {
        $haystack = trim(($name ?? '') . ' ' . ($description ?? ''));

        if ($haystack === '') {
            return false;
        }

        return (bool) preg_match(self::PREP_KEYWORD_REGEX, $haystack);
    }

    /**
     * @param array<array{type: string, seconds: int}> $reminders
     * @return array<array{type: string, seconds: int}>
     */
    private function sanitize(array $reminders, int $leadTimeSeconds): array
    {
        $seen = [];
        $result = [];

        foreach ($reminders as $reminder) {
            $type = $reminder['type'] ?? '';
            $seconds = (int) ($reminder['seconds'] ?? 0);

            if (!in_array($type, [self::TYPE_POPUP, self::TYPE_EMAIL], true)) {
                continue;
            }

            if ($seconds > $leadTimeSeconds) {
                continue;
            }

            $roundedSeconds = $this->findClosestAllowedOffset($seconds);

            if ($roundedSeconds > $leadTimeSeconds) {
                continue;
            }

            $key = $type . ':' . $roundedSeconds;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = [
                'type' => $type,
                'seconds' => $roundedSeconds,
            ];
        }

        // Sort descending by seconds (earliest alerts first), then by channel
        usort($result, static function (array $a, array $b): int {
            if ($a['seconds'] !== $b['seconds']) {
                return $b['seconds'] <=> $a['seconds'];
            }

            return strcmp($a['type'], $b['type']);
        });

        // Cap at 3 reminders per EspoCRM standard best practices
        return array_slice($result, 0, 3);
    }

    private function findClosestAllowedOffset(int $seconds): int
    {
        if (in_array($seconds, self::ALLOWED_OFFSETS, true)) {
            return $seconds;
        }

        $best = 0;
        $bestDiff = PHP_INT_MAX;

        foreach (self::ALLOWED_OFFSETS as $offset) {
            $diff = abs($offset - $seconds);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $offset;
            }
        }

        return $best;
    }

    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
