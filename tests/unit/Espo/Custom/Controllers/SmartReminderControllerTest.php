<?php

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\CreateParamsFetcher;
use Espo\Core\Record\DeleteParamsFetcher;
use Espo\Core\Record\FindParamsFetcher;
use Espo\Core\Record\ReadParamsFetcher;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParamsFetcher;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\SmartReminder\Service;
use Espo\Entities\Preferences;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use stdClass;

class SmartReminderControllerTest extends TestCase
{
    private Acl $acl;
    private User $user;
    private InjectableFactory $injectableFactory;
    private EntityManager $entityManager;
    private Service $service;

    protected function setUp(): void
    {
        $this->acl = $this->createMock(Acl::class);
        $this->user = $this->createMock(User::class);
        $this->injectableFactory = $this->createMock(InjectableFactory::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->service = $this->createMock(Service::class);
    }

    private function createController(): SmartReminder
    {
        return new SmartReminder(
            $this->createMock(SearchParamsFetcher::class),
            $this->createMock(CreateParamsFetcher::class),
            $this->createMock(ReadParamsFetcher::class),
            $this->createMock(UpdateParamsFetcher::class),
            $this->createMock(DeleteParamsFetcher::class),
            $this->createMock(ServiceContainer::class),
            $this->createMock(FindParamsFetcher::class),
            $this->createMock(Config::class),
            $this->user,
            $this->acl,
            $this->injectableFactory
        );
    }

    public function testUnsupportedEntityTypeThrowsBadRequest(): void
    {
        $this->acl->method('checkScope')->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'entityType' => 'Account', // Invalid for smart reminders
            'dateStart' => '2026-10-30 14:00:00',
        ]);

        $controller = $this->createController();

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Unsupported entityType 'Account'");
        $controller->postActionSuggestTiming($request);
    }

    public function testMissingDateStartThrowsBadRequest(): void
    {
        $this->acl->method('checkScope')->willReturn(true);

        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'entityType' => 'Meeting',
            'name' => 'Demo',
            // dateStart missing
        ]);

        $controller = $this->createController();

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('dateStart parameter is required');
        $controller->postActionSuggestTiming($request);
    }

    public function testForbiddenWhenScopeNotReadable(): void
    {
        $this->acl->method('checkScope')->willReturn(false);

        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'entityType' => 'Meeting',
            'dateStart' => '2026-10-30 14:00:00',
        ]);

        $controller = $this->createController();

        $this->expectException(Forbidden::class);
        $controller->postActionSuggestTiming($request);
    }

    public function testUserPreferenceRulesModeDisablesAiByDefault(): void
    {
        $this->acl->method('checkScope')->willReturn(true);
        $this->user->method('getId')->willReturn('user-rules-only');

        $preferences = $this->createMock(Preferences::class);
        $preferences->method('has')->willReturnCallback(fn ($prop) => in_array($prop, ['smartReminderEnabled', 'smartReminderMode', 'smartReminderPreparationBuffer'], true));
        $preferences->method('get')->willReturnCallback(function ($prop) {
            return match ($prop) {
                'smartReminderEnabled' => true,
                'smartReminderMode' => 'rules', // Mode set to rules
                'smartReminderPreparationBuffer' => false,
                default => null,
            };
        });

        $this->entityManager
            ->method('getEntityById')
            ->with('Preferences', 'user-rules-only')
            ->willReturn($preferences);

        $this->injectableFactory
            ->method('create')
            ->willReturnCallback(function (string $class) {
                if ($class === EntityManager::class) {
                    return $this->entityManager;
                }
                if ($class === Service::class) {
                    return $this->service;
                }
                throw new \RuntimeException("Unexpected class {$class}");
            });

        $expectedResult = (object) [
            'reminders' => [['type' => 'Popup', 'seconds' => 1800]],
            'source' => 'rules',
            'reason' => 'Rules evaluated',
            'leadTimeSeconds' => 7200,
        ];

        // Service::suggestTiming MUST be called with useAi = false and enablePrepBuffer = false
        $this->service
            ->expects($this->once())
            ->method('suggestTiming')
            ->with(
                'Meeting',
                'Client Sync',
                null,
                '2026-10-30 14:00:00',
                null,
                false, // useAi is FALSE because preference is rules
                $this->anything(),
                false  // enablePrepBuffer is FALSE because preference is false
            )
            ->willReturn($expectedResult);

        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'entityType' => 'Meeting',
            'name' => 'Client Sync',
            'dateStart' => '2026-10-30 14:00:00',
            // useAi not explicitly specified -> must inherit from user preferences
        ]);

        $controller = $this->createController();
        $response = $controller->postActionSuggestTiming($request);

        $this->assertSame('rules', $response->source);
    }

    public function testExplicitUseAiOverridesUserPreference(): void
    {
        $this->acl->method('checkScope')->willReturn(true);
        $this->user->method('getId')->willReturn('user-rules-only');

        $preferences = $this->createMock(Preferences::class);
        $preferences->method('has')->willReturn(true);
        $preferences->method('get')->willReturnCallback(fn ($prop) => match ($prop) {
            'smartReminderEnabled' => true,
            'smartReminderMode' => 'rules',
            'smartReminderPreparationBuffer' => true,
            default => null,
        });

        $this->entityManager
            ->method('getEntityById')
            ->willReturn($preferences);

        $this->injectableFactory
            ->method('create')
            ->willReturnCallback(function (string $class) {
                if ($class === EntityManager::class) {
                    return $this->entityManager;
                }
                if ($class === Service::class) {
                    return $this->service;
                }
                throw new \RuntimeException("Unexpected class {$class}");
            });

        $expectedResult = (object) [
            'reminders' => [['type' => 'Popup', 'seconds' => 1800]],
            'source' => 'ai',
            'reason' => 'AI recommended',
            'leadTimeSeconds' => 7200,
        ];

        // Client explicitly sent useAi = true, overriding preference
        $this->service
            ->expects($this->once())
            ->method('suggestTiming')
            ->with(
                'Meeting',
                'Demo',
                null,
                '2026-10-30 14:00:00',
                null,
                true, // Explicit useAi override
                $this->anything(),
                true
            )
            ->willReturn($expectedResult);

        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'entityType' => 'Meeting',
            'name' => 'Demo',
            'dateStart' => '2026-10-30 14:00:00',
            'useAi' => true, // Explicit override
        ]);

        $controller = $this->createController();
        $response = $controller->postActionSuggestTiming($request);

        $this->assertSame('ai', $response->source);
    }
}
