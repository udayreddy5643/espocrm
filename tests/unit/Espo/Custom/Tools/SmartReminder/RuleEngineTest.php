<?php

namespace tests\unit\Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Custom\Tools\SmartReminder\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase
{
    private RuleEngine $engine;
    private DateTimeImmutable $refTime;

    protected function setUp(): void
    {
        $this->engine = new RuleEngine();
        $this->refTime = new DateTimeImmutable('2026-10-26 10:00:00', new DateTimeZone('UTC'));
    }

    public function testEmptyOrInvalidDate(): void
    {
        $res1 = $this->engine->evaluate('Meeting', null, referenceTime: $this->refTime);
        $this->assertSame([], $res1['reminders']);
        $this->assertSame(0, $res1['leadTimeSeconds']);

        $res2 = $this->engine->evaluate('Meeting', 'invalid-date', referenceTime: $this->refTime);
        $this->assertSame([], $res2['reminders']);
    }

    public function testPastEvent(): void
    {
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 09:00:00',
            referenceTime: $this->refTime
        );

        $this->assertSame([], $res['reminders']);
        $this->assertLessThan(0, $res['leadTimeSeconds']);
        $this->assertStringContainsString('past', $res['reason']);
    }

    public function testImmediateNoticeUnder10Minutes(): void
    {
        // 5 minutes from refTime (300 seconds lead time)
        $res = $this->engine->evaluate(
            entityType: 'Call',
            dateStart: '2026-10-26 10:05:00',
            referenceTime: $this->refTime
        );

        $this->assertCount(1, $res['reminders']);
        $this->assertSame('Popup', $res['reminders'][0]['type']);
        $this->assertSame(0, $res['reminders'][0]['seconds']);
    }

    public function testShortNotice10To30Minutes(): void
    {
        // 20 minutes from refTime (1200 seconds lead time)
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 10:20:00',
            referenceTime: $this->refTime
        );

        $this->assertCount(1, $res['reminders']);
        $this->assertSame('Popup', $res['reminders'][0]['type']);
        $this->assertSame(300, $res['reminders'][0]['seconds']); // 5 min before
    }

    public function testShortNotice30To60Minutes(): void
    {
        // 45 minutes from refTime (2700 seconds lead time)
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 10:45:00',
            referenceTime: $this->refTime
        );

        $this->assertCount(1, $res['reminders']);
        $this->assertSame('Popup', $res['reminders'][0]['type']);
        $this->assertSame(600, $res['reminders'][0]['seconds']); // 10 min before
    }

    public function testSameDayStandardNotice(): void
    {
        // 4 hours from refTime (14400 seconds lead time)
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 14:00:00',
            referenceTime: $this->refTime
        );

        $this->assertCount(1, $res['reminders']);
        $this->assertSame('Popup', $res['reminders'][0]['type']);
        $this->assertSame(1800, $res['reminders'][0]['seconds']); // 30 min before
    }

    public function testSameDayHighPriorityEscalation(): void
    {
        // 6 hours from refTime with High priority
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 16:00:00',
            priority: 'High',
            referenceTime: $this->refTime
        );

        // Should include email alert + popup
        $types = array_column($res['reminders'], 'type');
        $this->assertContains('Email', $types);
        $this->assertContains('Popup', $types);
    }

    public function testAdvanceNoticeMultiDay(): void
    {
        // 3 days from refTime
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-29 10:00:00',
            referenceTime: $this->refTime
        );

        // Standard advance: 1 day Email (86400s) + 30m Popup (1800s)
        $this->assertGreaterThanOrEqual(2, count($res['reminders']));
        $this->assertSame('Email', $res['reminders'][0]['type']);
        $this->assertSame(86400, $res['reminders'][0]['seconds']);
        $this->assertSame('Popup', $res['reminders'][1]['type']);
        $this->assertSame(1800, $res['reminders'][1]['seconds']);
    }

    public function testAdvanceNoticeHighPriorityIncludesTwoDayEmail(): void
    {
        // 4 days from refTime, Urgent priority
        $res = $this->engine->evaluate(
            entityType: 'Task',
            dateStart: '2026-10-30 10:00:00',
            priority: 'Urgent',
            referenceTime: $this->refTime
        );

        $offsets = array_column($res['reminders'], 'seconds');
        $this->assertContains(172800, $offsets); // 2 days before
    }

    public function testPreparationKeywordBuffer(): void
    {
        // 8 hours lead time with "presentation review" in name
        $res = $this->engine->evaluate(
            entityType: 'Meeting',
            dateStart: '2026-10-26 18:00:00',
            name: 'Quarterly Executive Presentation and Demo',
            referenceTime: $this->refTime
        );

        $offsets = array_column($res['reminders'], 'seconds');
        $this->assertContains(7200, $offsets); // 2 hours prep popup
        $this->assertStringContainsString('Preparation', $res['reason']);
    }
}
