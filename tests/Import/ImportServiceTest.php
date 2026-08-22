<?php

declare(strict_types=1);

namespace UserImport\Tests\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UserImport\Csv\CsvParser;
use UserImport\Db\UserRepository;
use UserImport\Import\ImportRecord;
use UserImport\Import\ImportService;
use UserImport\Import\UserNormalizer;
use UserImport\Tests\Doubles\RecordingRepository;

/**
 * Full pipeline over the real sample file - the acceptance numbers live here.
 */
#[CoversClass(ImportService::class)]
final class ImportServiceTest extends TestCase
{
    private static function samplefile(): string
    {
        return dirname(__DIR__, 2) . '/users.csv';
    }

    private static function service(?UserRepository $repository = null): ImportService
    {
        return new ImportService(new CsvParser(), new UserNormalizer(), $repository);
    }

    public function testSampleFileProducesTheExpectedBreakdown(): void
    {
        $report = self::service()->validate(self::samplefile());

        self::assertSame(49, $report->found());
        self::assertSame(41, $report->validCount());
        self::assertSame(8, $report->invalidCount());
    }

    public function testSampleFileInvalidRowsCarryLineNumbersAndReasons(): void
    {
        $report = self::service()->validate(self::samplefile());

        $errors = [];
        foreach ($report->invalidRecords() as $record) {
            $errors[$record->line] = $record->error;
        }

        self::assertSame('invalid email address "invalid-email"', $errors[42]);
        self::assertSame('invalid email address "missing@"', $errors[43]);
        self::assertSame('duplicate email (first seen on line 2)', $errors[44]);
        self::assertSame('duplicate email (first seen on line 2)', $errors[45], 'dedup must be case-insensitive');
        self::assertSame('missing name', $errors[46]);
        self::assertSame('missing surname', $errors[47]);
        self::assertSame('missing email', $errors[48]);
        self::assertSame('invalid email address "bad@@example.com"', $errors[49]);
    }

    public function testSampleFileValuesAreNormalised(): void
    {
        $report = self::service()->validate(self::samplefile());
        $records = $report->validRecords();

        // Line 2: john,smith,JOHN.SMITH@example.com
        self::assertSame('John', $records[0]->name);
        self::assertSame('Smith', $records[0]->surname);
        self::assertSame('john.smith@example.com', $records[0]->email);

        // Line 50: padded " spaces@example.com " must be valid after trimming.
        $last = end($records);
        self::assertSame(50, $last->line);
        self::assertSame('spaces@example.com', $last->email);
    }

    public function testImportInsertsExactlyTheValidRecords(): void
    {
        $repository = new RecordingRepository();
        $report = self::service($repository)->import(self::samplefile());

        self::assertCount(41, $repository->received);
        self::assertContainsOnlyInstancesOf(ImportRecord::class, $repository->received);
        self::assertSame(41, $report->inserted);
        self::assertSame(0, $report->skipped);
    }

    public function testImportReportsRowsSkippedAsAlreadyExisting(): void
    {
        $repository = new RecordingRepository(pretendexisting: 5);
        $report = self::service($repository)->import(self::samplefile());

        self::assertSame(36, $report->inserted);
        self::assertSame(5, $report->skipped);
    }

    public function testImportWithoutRepositoryIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        self::service()->import(self::samplefile());
    }

    public function testReportSerialisesForTheApi(): void
    {
        $report = self::service()->validate(self::samplefile());
        $data = $report->toArray();

        self::assertSame(49, $data['found']);
        self::assertSame(41, $data['valid']);
        self::assertSame(8, $data['invalid']);
        self::assertCount(49, $data['records']);
        self::assertArrayNotHasKey('inserted', $data, 'no insert phase ran');

        $first = $data['records'][0];
        self::assertSame(['line' => 2, 'name' => 'John', 'surname' => 'Smith',
            'email' => 'john.smith@example.com', 'status' => 'valid'], $first);
    }
}
