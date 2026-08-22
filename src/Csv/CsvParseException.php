<?php

declare(strict_types=1);

namespace UserImport\Csv;

/**
 * Thrown when a CSV file cannot be parsed at all: missing/unreadable file,
 * empty file, or a header that does not match the expected columns.
 *
 * Row-level problems (wrong column count on a data row) are NOT exceptions;
 * they surface as invalid rows so one bad line never aborts a whole import.
 */
final class CsvParseException extends \RuntimeException
{
}
