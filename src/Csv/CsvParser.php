<?php

declare(strict_types=1);

namespace UserImport\Csv;

/**
 * Reads a users CSV file and yields its data rows with record numbers.
 *
 * The number counts CSV records, not physical file lines: a field containing
 * a quoted newline spans several lines but is one record. For files without
 * quoted newlines - including the provided users.csv - the two coincide.
 *
 * File-level problems (missing file, empty file, wrong header) throw a
 * CsvParseException immediately from parse(). Row-level problems (wrong
 * column count) are reported on the RawRow instead, so a single bad line
 * never aborts the rest of the file.
 */
final class CsvParser
{
    /** @var string[] The exact header the file must start with. */
    private const EXPECTED_HEADER = ['name', 'surname', 'email'];

    /**
     * Parse the CSV file at the given path.
     *
     * File-level checks run eagerly, so this throws before iteration starts.
     *
     * @param string $path path to the CSV file
     * @return iterable<RawRow> data rows in file order, numbered from 2
     * @throws CsvParseException when the file is missing, unreadable, empty or has a wrong header
     */
    public function parse(string $path): iterable
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CsvParseException(sprintf('Cannot read file "%s".', $path));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CsvParseException(sprintf('Cannot open file "%s".', $path));
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            throw new CsvParseException(sprintf('File "%s" is empty.', $path));
        }

        // Tolerate a UTF-8 byte order mark before the first header cell.
        $first = (string) $header[0];
        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            $header[0] = substr($first, 3);
        }

        $normalised = array_map(static fn(?string $cell): string => strtolower(trim((string) $cell)), $header);
        if ($normalised !== self::EXPECTED_HEADER) {
            fclose($handle);
            throw new CsvParseException(sprintf(
                'Unexpected header "%s" - expected "%s".',
                implode(',', array_map(static fn(?string $cell): string => (string) $cell, $header)),
                implode(',', self::EXPECTED_HEADER),
            ));
        }

        return $this->rows($handle);
    }

    /**
     * Yield the remaining data rows and close the handle when done.
     *
     * fgetcsv() consumes one whole record per call, so $line counts records.
     *
     * @param resource $handle open file handle positioned after the header
     * @return \Generator<int, RawRow>
     */
    private function rows($handle): \Generator
    {
        try {
            $line = 1; // The header was record 1, so data starts at 2.
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;

                // fgetcsv() returns [null] for a blank line - skip those silently.
                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) !== 3) {
                    yield new RawRow($line, null, null, null, sprintf('expected 3 columns, found %d', count($cells)));
                    continue;
                }

                yield new RawRow($line, (string) $cells[0], (string) $cells[1], (string) $cells[2]);
            }
        } finally {
            fclose($handle);
        }
    }
}
