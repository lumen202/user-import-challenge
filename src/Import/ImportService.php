<?php

declare(strict_types=1);

namespace UserImport\Import;

use UserImport\Csv\CsvParser;
use UserImport\Db\UserRepository;

/**
 * Orchestrates the full pipeline: parse -> normalise -> validate -> insert.
 *
 * This is the single entry point shared by the CLI and the HTTP API, so both
 * fronts behave identically by construction. validate() needs no database;
 * import() runs the exact same validation and then inserts the valid rows.
 */
final class ImportService
{
    /**
     * @param CsvParser $parser CSV reader
     * @param UserNormalizer $normalizer field normaliser
     * @param UserRepository|null $repository persistence, or null for validate-only use
     */
    public function __construct(
        private readonly CsvParser $parser,
        private readonly UserNormalizer $normalizer,
        private readonly ?UserRepository $repository = null,
    ) {
    }

    /**
     * Parse, normalise and validate a CSV file without touching the database.
     *
     * @param string $path path to the CSV file
     * @return ImportReport report with per-row outcomes; insert counts unset
     * @throws \UserImport\Csv\CsvParseException when the file itself is unusable
     */
    public function validate(string $path): ImportReport
    {
        $validator = new UserValidator(); // Fresh instance: duplicate state is per-run.
        $records = [];

        foreach ($this->parser->parse($path) as $row) {
            if ($row->error !== null) {
                $records[] = new ImportRecord($row->line, '', '', '', ImportRecord::STATUS_INVALID, $row->error);
                continue;
            }

            $name = $this->normalizer->normalizeName((string) $row->name);
            $surname = $this->normalizer->normalizeName((string) $row->surname);
            $email = $this->normalizer->normalizeEmail((string) $row->email);

            $error = $validator->validate($row->line, $name, $surname, $email);
            $records[] = new ImportRecord(
                $row->line,
                $name,
                $surname,
                $email,
                $error === null ? ImportRecord::STATUS_VALID : ImportRecord::STATUS_INVALID,
                $error,
            );
        }

        return new ImportReport($records);
    }

    /**
     * Validate the file and insert the valid rows into the database.
     *
     * @param string $path path to the CSV file
     * @return ImportReport validation report plus inserted/skipped counts
     * @throws \LogicException when no repository was provided
     * @throws \UserImport\Csv\CsvParseException when the file itself is unusable
     * @throws \PDOException when the database rejects the insert
     */
    public function import(string $path): ImportReport
    {
        if ($this->repository === null) {
            throw new \LogicException('ImportService was built without a repository; imports are unavailable.');
        }

        $report = $this->validate($path);
        $result = $this->repository->insertUsers($report->validRecords());

        return $report->withInsertResult($result['inserted'], $result['skipped']);
    }
}
