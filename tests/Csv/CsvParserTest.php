<?php

declare(strict_types=1);

namespace UserImport\Tests\Csv;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use UserImport\Csv\CsvParseException;
use UserImport\Csv\CsvParser;

#[CoversClass(CsvParser::class)]
final class CsvParserTest extends TestCase
{
    private CsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CsvParser();
    }

    /**
     * Absolute path to a fixture file.
     */
    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    public function testYieldsRowsWithFileLineNumbersAndRawValues(): void
    {
        $rows = iterator_to_array($this->parser->parse(self::fixture('valid.csv')), false);

        self::assertCount(2, $rows, 'blank line must be skipped, not counted');

        self::assertSame(2, $rows[0]->line);
        self::assertSame('john', $rows[0]->name);
        self::assertSame('smith', $rows[0]->surname);
        self::assertSame('JOHN@example.com', $rows[0]->email, 'parser must not normalise values');
        self::assertNull($rows[0]->error);

        // The blank line was line 3; the quoted row is line 4.
        self::assertSame(4, $rows[1]->line);
        self::assertSame('mary-jane', $rows[1]->name);
        self::assertSame('o brien', $rows[1]->surname);
    }

    public function testNumbersCountCsvRecordsNotPhysicalLines(): void
    {
        $rows = iterator_to_array($this->parser->parse(self::fixture('multiline.csv')), false);

        self::assertCount(2, $rows);

        // The first record's name cell holds a quoted newline, so the record
        // occupies physical lines 2-3 of the file.
        self::assertSame("Jo\nhn", $rows[0]->name);
        self::assertSame(2, $rows[0]->line);

        // The second record starts on physical line 4 but is record 3.
        self::assertSame('Jane', $rows[1]->name);
        self::assertSame(3, $rows[1]->line, 'numbering counts records, not physical lines');
    }

    public function testMissingFileThrowsBeforeIteration(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('Cannot read file');

        $this->parser->parse(self::fixture('does_not_exist.csv'));
    }

    public function testEmptyFileThrows(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('is empty');

        $this->parser->parse(self::fixture('empty.csv'));
    }

    public function testWrongHeaderThrows(): void
    {
        $this->expectException(CsvParseException::class);
        $this->expectExceptionMessage('expected "name,surname,email"');

        $this->parser->parse(self::fixture('bad_header.csv'));
    }

    public function testHeaderOnlyFileYieldsNoRows(): void
    {
        $rows = iterator_to_array($this->parser->parse(self::fixture('header_only.csv')), false);

        self::assertSame([], $rows);
    }

    public function testWrongColumnCountIsFlaggedPerRowWithoutAbortingTheFile(): void
    {
        $rows = iterator_to_array($this->parser->parse(self::fixture('wrong_columns.csv')), false);

        self::assertCount(3, $rows);

        self::assertSame('expected 3 columns, found 2', $rows[0]->error);
        self::assertSame(2, $rows[0]->line);
        self::assertNull($rows[0]->name);

        self::assertSame('expected 3 columns, found 4', $rows[1]->error);

        // The valid row after the broken ones must still come through.
        self::assertNull($rows[2]->error);
        self::assertSame('ok', $rows[2]->name);
        self::assertSame(4, $rows[2]->line);
    }

    public function testUtf8ByteOrderMarkInHeaderIsTolerated(): void
    {
        $rows = iterator_to_array($this->parser->parse(self::fixture('bom.csv')), false);

        self::assertCount(1, $rows);
        self::assertSame('bom', $rows[0]->name);
    }
}
