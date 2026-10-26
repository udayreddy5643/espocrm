<?php

namespace Espo\Custom\Tools\SmartReminder;

use Espo\Core\HttpClient\ClientFactory;
use Espo\Core\HttpClient\Options;
use Espo\Core\HttpClient\Protocol;
use Espo\Core\Utils\Log;
use GuzzleHttp\Psr7\Request;
use Throwable;

class AiSuggester
{
    private const ALLOWED_CHANNELS = ['Popup', 'Email'];

    public function __construct(
        private ClientFactory $clientFactory,
        private Log $log,
    ) {}

    /**
     * Request timing suggestion from external AI provider (Groq, fallback OpenRouter).
     *
     * @return ?array{reminders: array<array{type: string, seconds: int}>, reason: string}
     */
    public function suggest(
        string $entityType,
        ?string $name,
        ?string $description,
        ?string $dateStart,
        ?string $priority = null,
        int $leadTimeSeconds = 0,
    ): ?array {
        $groqKey = trim((string) getenv('GROQ_API_KEY'));
        $openRouterKey = trim((string) getenv('OPENROUTER_API_KEY'));

        if ($groqKey === '' && $openRouterKey === '') {
            return null;
        }

        $prompt = $this->buildPrompt($entityType, $name, $description, $dateStart, $priority, $leadTimeSeconds);

        if ($groqKey !== '') {
            $model = trim((string) (getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b'));
            $result = $this->queryProvider(
                'Groq',
                'https://api.groq.com/openai/v1/chat/completions',
                $groqKey,
                $model,
                $prompt,
                $leadTimeSeconds,
            );

            if ($result !== null) {
                return $result;
            }
        }

        if ($openRouterKey !== '') {
            $model = trim((string) getenv('OPENROUTER_MODEL'));
            $result = $this->queryProvider(
                'OpenRouter',
                'https://openrouter.ai/api/v1/chat/completions',
                $openRouterKey,
                $model,
                $prompt,
                $leadTimeSeconds,
            );

            if ($result !== null) {
                return $result;
            }
        }

        $this->log->warning('Smart reminder AI timing suggestion unavailable: providers failed.');

        return null;
    }

    private function buildPrompt(
        string $entityType,
        ?string $name,
        ?string $description,
        ?string $dateStart,
        ?string $priority,
        int $leadTimeSeconds,
    ): string {
        $titleClean = mb_substr(strip_tags((string) $name), 0, 250);
        $descClean = mb_substr(strip_tags((string) $description), 0, 1000);
        $leadMinutes = (int) round($leadTimeSeconds / 60);

        return "Activity Type: {$entityType}\n" .
            "Title: {$titleClean}\n" .
            "Description: {$descClean}\n" .
            "Start Time: {$dateStart}\n" .
            "Priority: {$priority}\n" .
            "Lead Time: {$leadMinutes} minutes ({$leadTimeSeconds} seconds)\n\n" .
            "Suggest optimal reminders for this event. Allowed channels: 'Popup', 'Email'.\n" .
            "Offsets must not exceed {$leadTimeSeconds} seconds.\n" .
            "Return valid JSON only in this exact format:\n" .
            "{\"reminders\": [{\"type\": \"Popup\", \"seconds\": 1800}], \"reason\": \"30m popup before sync\"}";
    }

    /**
     * @return ?array{reminders: array<array{type: string, seconds: int}>, reason: string}
     */
    private function queryProvider(
        string $provider,
        string $url,
        string $apiKey,
        string $model,
        string $prompt,
        int $leadTimeSeconds,
    ): ?array {
        if ($apiKey === '' || $model === '') {
            return null;
        }

        try {
            $request = new Request(
                'POST',
                $url,
                [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                json_encode([
                    'model' => $model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are an intelligent CRM scheduling assistant. Analyze the activity context and respond strictly in valid JSON without Markdown blocks.',
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 250,
                ], JSON_THROW_ON_ERROR),
            );

            $response = $this->clientFactory
                ->create(new Options(protocols: [Protocol::https], timeout: 15, connectTimeout: 4))
                ->send($request);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->log->warning("{$provider} smart reminder request failed with HTTP {$response->getStatusCode()}.");

                return null;
            }

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $content = trim((string) ($payload['choices'][0]['message']['content'] ?? ''));

            return $this->parseAndValidateResponse($content, $leadTimeSeconds);
        } catch (Throwable) {
            $this->log->warning("{$provider} smart reminder timing request failed.");

            return null;
        }
    }

    /**
     * @return ?array{reminders: array<array{type: string, seconds: int}>, reason: string}
     */
    public function parseAndValidateResponse(string $raw, int $leadTimeSeconds): ?array
    {
        if ($raw === '') {
            return null;
        }

        // Clean any Markdown code fence fences if model returned them
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $clean = preg_replace('/\s*```$/', '', (string) $clean);
        $clean = trim((string) $clean);

        try {
            $data = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !isset($data['reminders']) || !is_array($data['reminders'])) {
            return null;
        }

        $validatedReminders = [];
        $seen = [];

        foreach ($data['reminders'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            $seconds = $item['seconds'] ?? null;

            if (!in_array($type, self::ALLOWED_CHANNELS, true)) {
                continue;
            }

            if (!is_int($seconds) && (!is_string($seconds) || !ctype_digit($seconds))) {
                continue;
            }

            $secondsInt = (int) $seconds;

            if ($secondsInt < 0) {
                continue;
            }

            if ($leadTimeSeconds > 0 && $secondsInt > $leadTimeSeconds) {
                continue;
            }

            $key = $type . ':' . $secondsInt;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $validatedReminders[] = [
                'type' => $type,
                'seconds' => $secondsInt,
            ];
        }

        if ($validatedReminders === []) {
            return null;
        }

        usort($validatedReminders, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $reason = trim((string) ($data['reason'] ?? 'AI-suggested timing based on activity context'));

        return [
            'reminders' => array_slice($validatedReminders, 0, 3),
            'reason' => $reason !== '' ? $reason : 'AI-suggested timing based on activity context',
        ];
    }
}
