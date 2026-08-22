<?php

declare(strict_types=1);

namespace UserImport\Tests\Db;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UserImport\Config;
use UserImport\Csv\CsvParser;
use UserImport\Db\UserRepository;
use UserImport\Import\ImportRecord;
use UserImport\Import\ImportService;
use UserImport\Import\UserNormalizer;

/**
 * Integration test against a real PostgreSQL (docker compose up -d).
 *
 * Skips itself when the database is unreachable so the unit suite stays
 * green on machines without Docker. Uses a scratch table name-space via the
 * regular users table - it drops and recreates it, so do not run against a
 * database whose users table you care about.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryTest extends TestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        try {
            $this->repository = UserRepository::connect(Config::fromEnvironment());
        } catch (\PDOException $exception) {
            self::markTestSkipped('PostgreSQL unavailable: ' . $exception->getMessage());
        }

        $this->repository->createTable();
    }

    private static function record(string $name, string $surname, string $email): ImportRecord
    {
        return new ImportRecord(0, $name, $surname, $email, ImportRecord::STATUS_VALID);
    }

    public function testInsertsNewRowsAndSkipsExistingOnesOnReimport(): void
    {
        $records = [
            self::record('John', 'Smith', 'john.smith@example.com'),
            self::record('Jane', 'Doe', 'jane.doe@example.com'),
        ];

        $first = $this->repository->insertUsers($records);
        self::assertSame(['inserted' => 2, 'skipped' => 0], $first);

        // The once-then-again check: the same rows a second time must all skip.
        $second = $this->repository->insertUsers($records);
        self::assertSame(['inserted' => 0, 'skipped' => 2], $second);
    }

    public function testCreateTableRebuildsFromScratch(): void
    {
        $this->repository->insertUsers([self::record('John', 'Smith', 'john.smith@example.com')]);

        $this->repository->createTable();

        $result = $this->repository->insertUsers([self::record('John', 'Smith', 'john.smith@example.com')]);
        self::assertSame(['inserted' => 1, 'skipped' => 0], $result, 'rebuild must drop previous rows');
    }

    public function testFullPipelineAgainstTheSampleFile(): void
    {
        $service = new ImportService(new CsvParser(), new UserNormalizer(), $this->repository);

        $report = $service->import(dirname(__DIR__, 2) . '/users.csv');

        self::assertSame(41, $report->inserted);
        self::assertSame(0, $report->skipped);

        $rerun = $service->import(dirname(__DIR__, 2) . '/users.csv');
        self::assertSame(0, $rerun->inserted);
        self::assertSame(41, $rerun->skipped);
    }
}
