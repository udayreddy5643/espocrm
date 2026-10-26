<?php

namespace tests\unit\Espo\Custom\Tools\SmartReminder;

use Espo\Core\HttpClient\ClientFactory;
use Espo\Core\Utils\Log;
use Espo\Custom\Tools\SmartReminder\AiSuggester;
use PHPUnit\Framework\TestCase;

class AiSuggesterTest extends TestCase
{
    private AiSuggester $suggester;

    protected function setUp(): void
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $log = $this->createMock(Log::class);

        $this->suggester = new AiSuggester($clientFactory, $log);
    }

    public function testNoApiKeyReturnsNull(): void
    {
        putenv('GROQ_API_KEY=');
        putenv('OPENROUTER_API_KEY=');

        $result = $this->suggester->suggest(
            entityType: 'Meeting',
            name: 'Sync',
            description: 'Weekly team sync',
            dateStart: '2026-10-27 10:00:00',
            priority: 'Normal',
            leadTimeSeconds: 3600
        );

        $this->assertNull($result);
    }

    public function testParseAndValidateResponseValidJson(): void
    {
        $json = json_encode([
            'reminders' => [
                ['type' => 'Popup', 'seconds' => 900],
                ['type' => 'Email', 'seconds' => 3600],
            ],
            'reason' => '1 hour email and 15 min popup alert',
        ]);

        $parsed = $this->suggester->parseAndValidateResponse($json, 7200);

        $this->assertNotNull($parsed);
        $this->assertCount(2, $parsed['reminders']);
        $this->assertSame(3600, $parsed['reminders'][0]['seconds']); // Sorted descending
        $this->assertSame('Email', $parsed['reminders'][0]['type']);
        $this->assertSame(900, $parsed['reminders'][1]['seconds']);
        $this->assertSame('Popup', $parsed['reminders'][1]['type']);
        $this->assertSame('1 hour email and 15 min popup alert', $parsed['reason']);
    }

    public function testParseAndValidateResponseWithMarkdownFences(): void
    {
        $raw = "```json\n" . json_encode([
            'reminders' => [
                ['type' => 'Popup', 'seconds' => 1800],
            ],
            'reason' => '30 minutes popup',
        ]) . "\n```";

        $parsed = $this->suggester->parseAndValidateResponse($raw, 3600);

        $this->assertNotNull($parsed);
        $this->assertCount(1, $parsed['reminders']);
        $this->assertSame(1800, $parsed['reminders'][0]['seconds']);
    }

    public function testParseAndValidateResponseFiltersInvalidChannels(): void
    {
        $json = json_encode([
            'reminders' => [
                ['type' => 'SMS', 'seconds' => 300], // Invalid channel
                ['type' => 'Popup', 'seconds' => 600], // Valid channel
            ],
            'reason' => 'Testing filter',
        ]);

        $parsed = $this->suggester->parseAndValidateResponse($json, 3600);

        $this->assertNotNull($parsed);
        $this->assertCount(1, $parsed['reminders']);
        $this->assertSame('Popup', $parsed['reminders'][0]['type']);
        $this->assertSame(600, $parsed['reminders'][0]['seconds']);
    }

    public function testParseAndValidateResponseFiltersOffsetsExceedingLeadTime(): void
    {
        $json = json_encode([
            'reminders' => [
                ['type' => 'Email', 'seconds' => 86400], // Exceeds lead time (3600)
                ['type' => 'Popup', 'seconds' => 600],   // Within lead time
            ],
            'reason' => 'Testing lead time bounds',
        ]);

        $parsed = $this->suggester->parseAndValidateResponse($json, 3600);

        $this->assertNotNull($parsed);
        $this->assertCount(1, $parsed['reminders']);
        $this->assertSame(600, $parsed['reminders'][0]['seconds']);
    }

    public function testParseAndValidateResponseMalformedJson(): void
    {
        $this->assertNull($this->suggester->parseAndValidateResponse('invalid-not-json', 3600));
        $this->assertNull($this->suggester->parseAndValidateResponse('', 3600));
    }

    public function testParseAndValidateResponseMissingRemindersKey(): void
    {
        $json = json_encode(['data' => 'no-reminders-here']);
        $this->assertNull($this->suggester->parseAndValidateResponse($json, 3600));
    }
}
