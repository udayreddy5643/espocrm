<?php

namespace tests\unit\Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Custom\Hooks\Common\SmartReminderHook;
use Espo\Custom\Tools\SmartReminder\RuleEngine;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use stdClass;

class SmartReminderHookTest extends TestCase
{
    private SmartReminderHook $hook;
    private RuleEngine $ruleEngine;

    protected function setUp(): void
    {
        $this->ruleEngine = new RuleEngine();
        $this->hook = new SmartReminderHook($this->ruleEngine);
    }

    public function testUnsupportedEntityTypeIsIgnored(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Account');
        $entity->expects($this->never())->method('set');

        $options = $this->createMock(SaveOptions::class);
        $this->hook->beforeSave($entity, $options);
    }

    public function testEmptyStartDateIsIgnored(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('get')->with('dateStart')->willReturn(null);
        $entity->expects($this->never())->method('set');

        $options = $this->createMock(SaveOptions::class);
        $this->hook->beforeSave($entity, $options);
    }

    public function testPreservesExistingUserReminders(): void
    {
        $existing = [(object) ['type' => 'Popup', 'seconds' => 300]];

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('get')->willReturnMap([
            ['dateStart', '2026-10-27 10:00:00'],
            ['applySmartReminders', false],
            ['reminders', $existing],
        ]);
        $entity->method('has')->with('reminders')->willReturn(true);
        $entity->expects($this->never())->method('set');

        $options = $this->createMock(SaveOptions::class);
        $this->hook->beforeSave($entity, $options);
    }

    public function testAppliesSmartRemindersOnNewMeeting(): void
    {
        $futureDate = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('isNew')->willReturn(true);
        $entity->method('has')->with('reminders')->willReturn(false);
        $entity->method('get')->willReturnMap([
            ['dateStart', $futureDate],
            ['applySmartReminders', false],
            ['name', 'Project Kickoff and Presentation'],
            ['description', 'Initial kickoff review'],
            ['priority', 'High'],
        ]);

        $entity->expects($this->once())
            ->method('set')
            ->with(
                'reminders',
                $this->callback(function (array $reminders): bool {
                    $this->assertNotEmpty($reminders);
                    foreach ($reminders as $r) {
                        $this->assertInstanceOf(stdClass::class, $r);
                        $this->assertObjectHasProperty('type', $r);
                        $this->assertObjectHasProperty('seconds', $r);
                        $this->assertIsInt($r->seconds);
                    }
                    return true;
                })
            );

        $options = $this->createMock(SaveOptions::class);
        $this->hook->beforeSave($entity, $options);
    }

    public function testForcesSmartRemindersWhenFlagPresent(): void
    {
        $futureDate = (new DateTimeImmutable('+4 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Call');
        $entity->method('isNew')->willReturn(false);
        $entity->method('isAttributeChanged')->with('dateStart')->willReturn(false);
        $entity->method('has')->with('reminders')->willReturn(true);
        $entity->method('get')->willReturnMap([
            ['dateStart', $futureDate],
            ['applySmartReminders', true], // Explicit force flag
            ['reminders', [(object) ['type' => 'Popup', 'seconds' => 300]]],
            ['name', 'Client Call'],
            ['description', null],
            ['priority', 'Normal'],
        ]);

        $entity->expects($this->once())
            ->method('set')
            ->with('reminders', $this->isType('array'));

        $options = $this->createMock(SaveOptions::class);
        $this->hook->beforeSave($entity, $options);
    }
}
