<?php

namespace Espo\Custom\Tools\ActivitySummary;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;
use stdClass;

class Report
{
    public function __construct(
        private Acl $acl,
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityManager $entityManager
    ) {}

    /**
     * Return visible Account activity totals for the last 30 days.
     * Each activity query uses Espo's strict-access query builder.
     *
     * @throws Forbidden
     */
    public function run(int $limit = 10, ?\DateTimeImmutable $now = null): stdClass
    {
        foreach (['Account', 'Call', 'Meeting', 'Email'] as $scope) {
            if (!$this->acl->checkScope($scope, Table::ACTION_READ)) {
                throw new Forbidden();
            }
        }

        $limit = max(1, min($limit, 100));
        $reference = $now ?? new \DateTimeImmutable();
        $from = $reference->modify('-30 days')->format('Y-m-d H:i:s');
        $to = $reference->format('Y-m-d H:i:s');
        $countMap = [];

        $this->collectCounts($countMap, 'Call', 'dateStart', $from, $to);
        $this->collectCounts($countMap, 'Meeting', 'dateStart', $from, $to);
        $this->collectCounts($countMap, 'Email', 'dateSent', $from, $to);

        if ($countMap === []) {
            return (object) ['from' => $from, 'dataList' => []];
        }

        // Performance Optimization:
        // Pre-aggregate total activity counts and sort candidate account IDs in memory.
        // Instead of querying all accounts in $countMap (which could contain thousands of IDs),
        // we extract only the top candidate IDs with a buffer (2x limit, min 20) to handle
        // any deleted or inaccessible records without unbounded SQL WHERE IN lists.
        $candidateScores = [];
        foreach ($countMap as $accountId => $counts) {
            $candidateScores[$accountId] = ($counts['Call'] ?? 0) + ($counts['Meeting'] ?? 0) + ($counts['Email'] ?? 0);
        }

        arsort($candidateScores);
        $candidateLimit = max(20, $limit * 2);
        $topCandidateIds = array_slice(array_keys($candidateScores), 0, $candidateLimit);

        $accountQuery = $this->selectBuilderFactory
            ->create()
            ->from('Account')
            ->withStrictAccessControl()
            ->buildQueryBuilder();

        $accountQuery
            ->select(['id', 'name'])
            ->where(['id' => $topCandidateIds]);

        $accountRows = $this->entityManager
            ->getQueryExecutor()
            ->execute($accountQuery->build())
            ->fetchAll() ?: [];

        $dataList = [];

        foreach ($accountRows as $account) {
            $id = $account['id'];
            if (!isset($countMap[$id])) {
                continue;
            }

            $counts = $countMap[$id];
            $counts['accountId'] = $id;
            $counts['accountName'] = (string) ($account['name'] ?? '');
            $counts['total'] = ($counts['Call'] ?? 0) + ($counts['Meeting'] ?? 0) + ($counts['Email'] ?? 0);
            $dataList[] = $counts;
        }

        // Deterministic sort: Primary by total DESC, Secondary by accountName ASC
        usort($dataList, static function (array $a, array $b): int {
            if ($b['total'] !== $a['total']) {
                return $b['total'] <=> $a['total'];
            }

            return strcmp((string) $a['accountName'], (string) $b['accountName']);
        });

        return (object) [
            'from' => $from,
            'dataList' => array_slice($dataList, 0, $limit),
        ];
    }

    /** @param array<string, array{Call: int, Meeting: int, Email: int}> $countMap */
    private function collectCounts(
        array &$countMap,
        string $entityType,
        string $dateField,
        string $from,
        string $to
    ): void
    {
        $queryBuilder = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->withStrictAccessControl()
            ->buildQueryBuilder();

        $queryBuilder
            ->select(['parentId', ['COUNT:id', 'count']])
            ->where([
                'parentType' => 'Account',
                $dateField . '>=' => $from,
                $dateField . '<=' => $to,
            ])
            ->group('parentId');

        $rows = $this->entityManager
            ->getQueryExecutor()
            ->execute($queryBuilder->build())
            ->fetchAll() ?: [];

        foreach ($rows as $row) {
            if (empty($row['parentId'])) {
                continue;
            }

            $countMap[$row['parentId']] ??= ['Call' => 0, 'Meeting' => 0, 'Email' => 0];
            $countMap[$row['parentId']][$entityType] = (int) $row['count'];
        }
    }
}
