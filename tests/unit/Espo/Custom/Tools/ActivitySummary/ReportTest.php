<?php

namespace Espo\Custom\Tools\ActivitySummary;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder as QuerySelectBuilder;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    private Acl $acl;
    private SelectBuilderFactory $selectBuilderFactory;
    private EntityManager $entityManager;
    private QueryExecutor $queryExecutor;

    protected function setUp(): void
    {
        $this->acl = $this->createMock(Acl::class);
        $this->selectBuilderFactory = $this->createMock(SelectBuilderFactory::class);
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->queryExecutor = $this->createMock(QueryExecutor::class);

        $this->entityManager
            ->method('getQueryExecutor')
            ->willReturn($this->queryExecutor);
    }

    public function testForbiddenWhenScopeNotReadable(): void
    {
        $this->acl
            ->method('checkScope')
            ->willReturnCallback(function (string $scope, string $action) {
                if ($scope === 'Meeting') {
                    return false;
                }
                return true;
            });

        $report = new Report($this->acl, $this->selectBuilderFactory, $this->entityManager);

        $this->expectException(Forbidden::class);
        $report->run();
    }

    public function testEmptyReportWhenNoActivity(): void
    {
        $this->acl->method('checkScope')->willReturn(true);

        $queryResult = $this->createMock(PDOStatement::class);
        $queryResult->method('fetchAll')->willReturn([]);

        $this->queryExecutor
            ->method('execute')
            ->willReturn($queryResult);

        $queryBuilder = $this->createMock(QuerySelectBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('group')->willReturnSelf();
        $queryBuilder->method('build')->willReturn($this->createMock(Select::class));

        $selectBuilder = $this->createMock(SelectBuilder::class);
        $selectBuilder->method('from')->willReturnSelf();
        $selectBuilder->method('withStrictAccessControl')->willReturnSelf();
        $selectBuilder->method('buildQueryBuilder')->willReturn($queryBuilder);

        $this->selectBuilderFactory
            ->method('create')
            ->willReturn($selectBuilder);

        $report = new Report($this->acl, $this->selectBuilderFactory, $this->entityManager);
        $result = $report->run(10, new \DateTimeImmutable('2026-10-26 12:00:00'));

        $this->assertSame([], $result->dataList);
        $this->assertNotEmpty($result->from);
    }

    public function testDeterministicSecondarySortByAccountName(): void
    {
        $this->acl->method('checkScope')->willReturn(true);

        // Mock 3 activity queries (Call, Meeting, Email) then 1 account query
        $callResult = $this->createMock(PDOStatement::class);
        $callResult->method('fetchAll')->willReturn([
            ['parentId' => 'acc-beta', 'count' => 5],
            ['parentId' => 'acc-alpha', 'count' => 5],
            ['parentId' => 'acc-gamma', 'count' => 10],
        ]);

        $emptyResult = $this->createMock(PDOStatement::class);
        $emptyResult->method('fetchAll')->willReturn([]);

        $accountResult = $this->createMock(PDOStatement::class);
        $accountResult->method('fetchAll')->willReturn([
            ['id' => 'acc-beta', 'name' => 'Beta Corp'],
            ['id' => 'acc-alpha', 'name' => 'Alpha LLC'],
            ['id' => 'acc-gamma', 'name' => 'Gamma Industries'],
        ]);

        $this->queryExecutor
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                $callResult,
                $emptyResult,
                $emptyResult,
                $accountResult
            );

        $queryBuilder = $this->createMock(QuerySelectBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('group')->willReturnSelf();
        $queryBuilder->method('build')->willReturn($this->createMock(Select::class));

        $selectBuilder = $this->createMock(SelectBuilder::class);
        $selectBuilder->method('from')->willReturnSelf();
        $selectBuilder->method('withStrictAccessControl')->willReturnSelf();
        $selectBuilder->method('buildQueryBuilder')->willReturn($queryBuilder);

        $this->selectBuilderFactory
            ->method('create')
            ->willReturn($selectBuilder);

        $report = new Report($this->acl, $this->selectBuilderFactory, $this->entityManager);
        $result = $report->run(10, new \DateTimeImmutable('2026-10-26 12:00:00'));

        $this->assertCount(3, $result->dataList);

        // 1st: Gamma Industries (total = 10)
        $this->assertSame('acc-gamma', $result->dataList[0]['accountId']);
        $this->assertSame(10, $result->dataList[0]['total']);

        // 2nd: Alpha LLC (total = 5, sorted alphabetically before Beta Corp)
        $this->assertSame('acc-alpha', $result->dataList[1]['accountId']);
        $this->assertSame('Alpha LLC', $result->dataList[1]['accountName']);
        $this->assertSame(5, $result->dataList[1]['total']);

        // 3rd: Beta Corp (total = 5)
        $this->assertSame('acc-beta', $result->dataList[2]['accountId']);
        $this->assertSame('Beta Corp', $result->dataList[2]['accountName']);
        $this->assertSame(5, $result->dataList[2]['total']);
    }

    public function testCandidatePruningReducesAccountQuerySize(): void
    {
        $this->acl->method('checkScope')->willReturn(true);

        // Generate 30 accounts with activity
        $calls = [];
        for ($i = 1; $i <= 30; $i++) {
            $calls[] = ['parentId' => "acc-{$i}", 'count' => $i];
        }

        $callResult = $this->createMock(PDOStatement::class);
        $callResult->method('fetchAll')->willReturn($calls);

        $emptyResult = $this->createMock(PDOStatement::class);
        $emptyResult->method('fetchAll')->willReturn([]);

        $queriedAccountIds = [];
        $accountResult = $this->createMock(PDOStatement::class);
        $accountResult->method('fetchAll')->willReturnCallback(function () use (&$queriedAccountIds) {
            $rows = [];
            foreach ($queriedAccountIds as $id) {
                $rows[] = ['id' => $id, 'name' => "Account {$id}"];
            }
            return $rows;
        });

        $this->queryExecutor
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                $callResult,
                $emptyResult,
                $emptyResult,
                $accountResult
            );

        $queryBuilder = $this->createMock(QuerySelectBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('where')->willReturnCallback(function ($where) use (&$queriedAccountIds, $queryBuilder) {
            if (isset($where['id']) && is_array($where['id'])) {
                $queriedAccountIds = $where['id'];
            }
            return $queryBuilder;
        });
        $queryBuilder->method('group')->willReturnSelf();
        $queryBuilder->method('build')->willReturn($this->createMock(Select::class));

        $selectBuilder = $this->createMock(SelectBuilder::class);
        $selectBuilder->method('from')->willReturnSelf();
        $selectBuilder->method('withStrictAccessControl')->willReturnSelf();
        $selectBuilder->method('buildQueryBuilder')->willReturn($queryBuilder);

        $this->selectBuilderFactory
            ->method('create')
            ->willReturn($selectBuilder);

        $report = new Report($this->acl, $this->selectBuilderFactory, $this->entityManager);
        $result = $report->run(5, new \DateTimeImmutable('2026-10-26 12:00:00'));

        // For limit = 5, candidateLimit = max(20, 10) = 20.
        // It must NOT query all 30 accounts in the database!
        $this->assertLessThanOrEqual(20, count($queriedAccountIds));
        $this->assertCount(5, $result->dataList);
        $this->assertSame('acc-30', $result->dataList[0]['accountId']);
        $this->assertSame(30, $result->dataList[0]['total']);
    }
}
