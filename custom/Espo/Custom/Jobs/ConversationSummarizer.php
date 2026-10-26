<?php

namespace Espo\Custom\Jobs;

use Espo\Core\HttpClient\ClientFactory;
use Espo\Core\HttpClient\Options;
use Espo\Core\HttpClient\Protocol;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOption;
use GuzzleHttp\Psr7\Request;

class ConversationSummarizer implements Job
{
    private const ACTIVITY_FIELDS = [
        'Call' => ['dateStart', 'description', 'name'],
        'Meeting' => ['dateStart', 'description', 'name'],
        'Task' => ['dateStart', 'description', 'name'],
        'Email' => ['dateSent', 'bodyPlain', 'subject'],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private ClientFactory $clientFactory,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $accountId = $data->getTargetId();

        if (!$accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('Account', $accountId);

        if (!$account) {
            return;
        }

        $activities = $this->getLatestActivities($accountId);

        if ($activities === []) {
            return;
        }

        $prompt = $this->buildPrompt($activities);
        $summary = $this->requestProvider(
            'Groq',
            'https://api.groq.com/openai/v1/chat/completions',
            trim((string) getenv('GROQ_API_KEY')),
            trim((string) (getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b')),
            $prompt,
        );

        if (!$summary) {
            $summary = $this->requestProvider(
                'OpenRouter',
                'https://openrouter.ai/api/v1/chat/completions',
                trim((string) getenv('OPENROUTER_API_KEY')),
                trim((string) getenv('OPENROUTER_MODEL')),
                $prompt,
            );
        }

        if (!$summary) {
            $this->log->warning('Conversation summary unavailable: both AI providers failed or are not configured.');

            return;
        }

        $account->set('aiSummary', $summary);
        $this->entityManager->saveEntity($account, [SaveOption::SKIP_HOOKS => true]);
    }

    /** @return array<array{type: string, date: string, title: string, details: string}> */
    private function getLatestActivities(string $accountId): array
    {
        $activityList = [];

        foreach (self::ACTIVITY_FIELDS as $entityType => [$dateField, $detailsField, $titleField]) {
            $queryBuilder = $this->selectBuilderFactory
                ->create()
                ->from($entityType)
                ->buildQueryBuilder();

            $queryBuilder
                ->select(['id', 'createdAt', $dateField, $detailsField, $titleField])
                ->where([
                    'parentType' => 'Account',
                    'parentId' => $accountId,
                ])
                ->order($dateField, 'DESC')
                ->limit(0, 5);

            $rows = $this->entityManager
                ->getQueryExecutor()
                ->execute($queryBuilder->build())
                ->fetchAll() ?: [];

            foreach ($rows as $row) {
                $details = trim(strip_tags((string) ($row[$detailsField] ?? '')));
                $title = trim(strip_tags((string) ($row[$titleField] ?? '')));

                if ($details === '' && $title === '') {
                    continue;
                }

                $activityList[] = [
                    'type' => $entityType,
                    'date' => (string) ($row[$dateField] ?? $row['createdAt'] ?? ''),
                    'title' => mb_substr($title, 0, 250),
                    'details' => mb_substr($details, 0, 1600),
                ];
            }
        }

        usort(
            $activityList,
            static fn (array $a, array $b): int => strcmp($b['date'], $a['date'])
        );

        return array_slice($activityList, 0, 5);
    }

    /** @param array<array{type: string, date: string, title: string, details: string}> $activities */
    private function buildPrompt(array $activities): string
    {
        $lines = array_map(
            static fn (array $activity): string => sprintf(
                "%s | %s | %s\n%s",
                $activity['date'],
                $activity['type'],
                $activity['title'],
                $activity['details'],
            ),
            $activities,
        );

        return "Summarize these customer interactions in exactly 3 concise bullet points. " .
            "Use only information present below. Do not infer facts or include private data beyond the notes.\n\n" .
            implode("\n\n", $lines);
    }

    private function requestProvider(
        string $provider,
        string $url,
        string $apiKey,
        string $model,
        string $prompt,
    ): ?string {
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
                            'content' => 'You summarize CRM activity faithfully and concisely.',
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 400,
                ], JSON_THROW_ON_ERROR),
            );

            $response = $this->clientFactory
                ->create(new Options(protocols: [Protocol::https], timeout: 25, connectTimeout: 5))
                ->send($request);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                $this->log->warning("$provider conversation summary request failed with HTTP {$response->getStatusCode()}.");

                return null;
            }

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $summary = trim((string) ($payload['choices'][0]['message']['content'] ?? ''));

            return $summary !== '' ? $summary : null;
        } catch (\Throwable) {
            $this->log->warning("$provider conversation summary request failed.");

            return null;
        }
    }
}
