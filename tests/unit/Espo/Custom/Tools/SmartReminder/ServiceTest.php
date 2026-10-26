<?php

namespace tests\unit\Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Custom\Tools\SmartReminder\AiSuggester;
use Espo\Custom\Tools\SmartReminder\RuleEngine;
use Espo\Custom\Tools\SmartReminder\Service;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    private RuleEngine $ruleEngine;
    private DateTimeImmutable $refTime;

    protected function setUp(): void
    {
        $this->ruleEngine = new RuleEngine();
        $this->refTime = new DateTimeImmutable('2026-10-26 10:00:00', new DateTimeZone('UTC'));
    }

    public function testSuggestTimingFallsBackToRulesWhenAiReturnsNull(): void
    {
        $aiMock = $this->createMock(AiSuggester::class);
        $aiMock->expects($this->once())
            ->method('suggest')
            ->willReturn(null);

        $service = new Service($this->ruleEngine, $aiMock);

        $result = $service->suggestTiming(
            entityType: 'Meeting',
            name: 'Quick check-in',
            description: null,
            dateStart: '2026-10-26 14:00:00', // 4h later
            useAi: true,
            referenceTime: $this->refTime
        );

        $this->assertSame('rules', $result->source);
        $this->assertNotEmpty($result->reminders);
        $this->assertSame(14400, $result->leadTimeSeconds);
    }

    public function testSuggestTimingUsesAiWhenAvailable(): void
    {
        $aiMock = $this->createMock(AiSuggester::class);
        $aiMock->expects($this->once())
            ->method('suggest')
            ->willReturn([
                'reminders' => [
                    ['type' => 'Popup', 'seconds' => 900],
                ],
                'reason' => 'AI detected brief standup needs only 15m alert',
            ]);

        $service = new Service($this->ruleEngine, $aiMock);

        $result = $service->suggestTiming(
            entityType: 'Meeting',
            name: 'Morning Standup',
            description: '15m sync',
            dateStart: '2026-10-26 14:00:00',
            useAi: true,
            referenceTime: $this->refTime
        );

        $this->assertSame('ai', $result->source);
        $this->assertCount(1, $result->reminders);
        $this->assertSame(900, $result->reminders[0]['seconds']);
        $this->assertSame('AI detected brief standup needs only 15m alert', $result->reason);
    }

    public function testSuggestTimingBypassesAiWhenUseAiIsFalse(): void
    {
        $aiMock = $this->createMock(AiSuggester::class);
        $aiMock->expects($this->never())->method('suggest');

        $service = new Service($this->ruleEngine, $aiMock);

        $result = $service->suggestTiming(
            entityType: 'Meeting',
            name: 'Planning',
            description: null,
            dateStart: '2026-10-26 14:00:00',
            useAi: false,
            referenceTime: $this->refTime
        );

        $this->assertSame('rules', $result->source);
        $this->assertNotEmpty($result->reminders);
    }

    public function testPastEventDoesNotCallAi(): void
    {
        $aiMock = $this->createMock(AiSuggester::class);
        $aiMock->expects($this->never())->method('suggest');

        $service = new Service($this->ruleEngine, $aiMock);

        $result = $service->suggestTiming(
            entityType: 'Meeting',
            name: 'Past meeting',
            description: null,
            dateStart: '2026-10-26 09:00:00',
            useAi: true,
            referenceTime: $this->refTime
        );

        $this->assertSame('rules', $result->source);
        $this->assertSame([], $result->reminders);
    }
}
