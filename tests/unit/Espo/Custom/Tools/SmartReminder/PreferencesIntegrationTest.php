<?php

namespace tests\unit\Espo\Custom\Tools\SmartReminder;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Custom\Hooks\Common\SmartReminderHook;
use Espo\Custom\Tools\SmartReminder\RuleEngine;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

class PreferencesIntegrationTest extends TestCase
{
    private RuleEngine $ruleEngine;

    protected function setUp(): void
    {
        $this->ruleEngine = new RuleEngine();
    }

    public function testWhenSmartReminderDisabledInPreferencesHookDoesNotApply(): void
    {
        $preferences = $this->createMock(Entity::class);
        $preferences->method('has')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderMode', false],
            ['smartReminderPreparationBuffer', false],
        ]);
        $preferences->method('get')->willReturnMap([
            ['smartReminderEnabled', false], // Disabled in user settings
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('Preferences', 'user-1')
            ->willReturn($preferences);

        $hook = new SmartReminderHook($this->ruleEngine, $entityManager);

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('isNew')->willReturn(true);
        $entity->method('has')->with('reminders')->willReturn(false);
        $entity->method('get')->willReturnMap([
            ['dateStart', (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['assignedUserId', 'user-1'],
            ['applySmartReminders', false],
            ['name', 'Team Sync'],
            ['description', null],
            ['priority', 'Normal'],
        ]);

        $entity->expects($this->never())->method('set');

        $options = $this->createMock(SaveOptions::class);
        $hook->beforeSave($entity, $options);
    }

    public function testWhenSmartReminderModeDisabledInPreferencesHookDoesNotApply(): void
    {
        $preferences = $this->createMock(Entity::class);
        $preferences->method('has')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderMode', true],
            ['smartReminderPreparationBuffer', false],
        ]);
        $preferences->method('get')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderMode', 'disabled'],
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('Preferences', 'user-1')
            ->willReturn($preferences);

        $hook = new SmartReminderHook($this->ruleEngine, $entityManager);

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('isNew')->willReturn(true);
        $entity->method('has')->with('reminders')->willReturn(false);
        $entity->method('get')->willReturnMap([
            ['dateStart', (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['assignedUserId', 'user-1'],
            ['applySmartReminders', false],
            ['name', 'Team Sync'],
            ['description', null],
            ['priority', 'Normal'],
        ]);

        $entity->expects($this->never())->method('set');

        $options = $this->createMock(SaveOptions::class);
        $hook->beforeSave($entity, $options);
    }

    public function testWhenSmartReminderPreparationBufferDisabledBufferOmitted(): void
    {
        $preferences = $this->createMock(Entity::class);
        $preferences->method('has')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderMode', false],
            ['smartReminderPreparationBuffer', true],
        ]);
        $preferences->method('get')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderPreparationBuffer', false], // Disable prep buffer in user preferences
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('Preferences', 'user-1')
            ->willReturn($preferences);

        $hook = new SmartReminderHook($this->ruleEngine, $entityManager);

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('isNew')->willReturn(true);
        $entity->method('has')->with('reminders')->willReturn(false);
        $entity->method('get')->willReturnMap([
            ['dateStart', (new DateTimeImmutable('+8 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['assignedUserId', 'user-1'],
            ['applySmartReminders', false],
            ['name', 'Quarterly Presentation and Demo Review'],
            ['description', null],
            ['priority', 'Normal'],
        ]);

        $capturedReminders = [];
        $entity->expects($this->once())
            ->method('set')
            ->with(
                'reminders',
                $this->callback(function (array $reminders) use (&$capturedReminders): bool {
                    $capturedReminders = $reminders;
                    return true;
                })
            );

        $options = $this->createMock(SaveOptions::class);
        $hook->beforeSave($entity, $options);

        // Prep reminder (7200s) should NOT be present because user disabled smartReminderPreparationBuffer
        $seconds = array_map(static fn ($r) => $r->seconds, $capturedReminders);
        $this->assertNotContains(7200, $seconds);
    }

    public function testWhenApplySmartExplicitlyForcedPreferencesAreBypassed(): void
    {
        $preferences = $this->createMock(Entity::class);
        $preferences->method('has')->willReturnMap([
            ['smartReminderEnabled', true],
            ['smartReminderMode', false],
            ['smartReminderPreparationBuffer', false],
        ]);
        $preferences->method('get')->willReturnMap([
            ['smartReminderEnabled', false], // Disabled in user settings
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('Preferences', 'user-1')
            ->willReturn($preferences);

        $hook = new SmartReminderHook($this->ruleEngine, $entityManager);

        $entity = $this->createMock(Entity::class);
        $entity->method('getEntityType')->willReturn('Meeting');
        $entity->method('isNew')->willReturn(true);
        $entity->method('has')->with('reminders')->willReturn(false);
        $entity->method('get')->willReturnMap([
            ['dateStart', (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['assignedUserId', 'user-1'],
            ['applySmartReminders', true], // Forced
            ['name', 'Forced Sync'],
            ['description', null],
            ['priority', 'Normal'],
        ]);

        $entity->expects($this->once())->method('set')->with('reminders', $this->isType('array'));

        $options = $this->createMock(SaveOptions::class);
        $hook->beforeSave($entity, $options);
    }
}
