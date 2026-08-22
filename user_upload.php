<?php

/**
 * CLI entry point for the CSV user import.
 *
 * Thin adapter over the shared UserImport core: parses arguments, wires up
 * the ImportService, prints the report. All import logic lives in src/.
 *
 * Exit codes: 0 success, 1 usage error, 2 file error, 3 database error.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use UserImport\Config;
use UserImport\Csv\CsvParseException;
use UserImport\Csv\CsvParser;
use UserImport\Db\UserRepository;
use UserImport\Import\ImportReport;
use UserImport\Import\ImportService;
use UserImport\Import\UserNormalizer;

exit(main(array_slice($argv, 1)));

/**
 * Run the command.
 *
 * @param string[] $args command line arguments, program name removed
 * @return int exit code
 */
function main(array $args): int
{
    try {
        $options = parse_args($args);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL . PHP_EOL);
        print_usage(STDERR);

        return 1;
    }

    if ($options['help']) {
        print_usage(STDOUT);

        return 0;
    }

    try {
        $repository = null;
        if ($options['create-table'] || ($options['file'] !== null && !$options['dry-run'])) {
            $repository = UserRepository::connect(Config::fromEnvironment());
        }

        if ($options['create-table']) {
            $repository->createTable();
            fwrite(STDOUT, 'Users table dropped and recreated.' . PHP_EOL);
        }

        if ($options['file'] === null) {
            return 0;
        }

        $service = new ImportService(new CsvParser(), new UserNormalizer(), $repository);
        $report = $options['dry-run']
            ? $service->validate($options['file'])
            : $service->import($options['file']);

        print_report($report, $options['file'], $options['dry-run']);

        return 0;
    } catch (CsvParseException $exception) {
        fwrite(STDERR, 'File error: ' . $exception->getMessage() . PHP_EOL);

        return 2;
    } catch (PDOException $exception) {
        fwrite(STDERR, 'Database error: ' . $exception->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Is PostgreSQL running? Try: docker compose up -d' . PHP_EOL);

        return 3;
    }
}

/**
 * Parse command line arguments.
 *
 * Strict on purpose: an unknown option aborts instead of being silently
 * ignored, so a typo can never run a different command than intended.
 *
 * Multi-word flags are accepted in both spellings (--dry_run and --dry-run,
 * --create_table and --create-table) so the command line works whichever
 * convention the caller reaches for.
 *
 * @param string[] $args arguments to parse
 * @return array{file: ?string, 'create-table': bool, 'dry-run': bool, help: bool}
 * @throws InvalidArgumentException on unknown/missing/conflicting arguments
 */
function parse_args(array $args): array
{
    $options = ['file' => null, 'create-table' => false, 'dry-run' => false, 'help' => false];

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        switch (true) {
            case $arg === '--help':
                $options['help'] = true;
                break;
            case $arg === '--create_table':
            case $arg === '--create-table':
                $options['create-table'] = true;
                break;
            case $arg === '--dry_run':
            case $arg === '--dry-run':
                $options['dry-run'] = true;
                break;
            case $arg === '--file':
                $i++;
                if (!isset($args[$i])) {
                    throw new InvalidArgumentException('--file requires a path argument');
                }
                $options['file'] = $args[$i];
                break;
            case str_starts_with($arg, '--file='):
                $options['file'] = substr($arg, strlen('--file='));
                break;
            default:
                throw new InvalidArgumentException(sprintf('Unknown option "%s"', $arg));
        }
    }

    if ($options['help']) {
        return $options;
    }
    if ($options['file'] === null && !$options['create-table']) {
        throw new InvalidArgumentException('Nothing to do: pass --file <csv> and/or --create-table');
    }
    if ($options['dry-run'] && $options['file'] === null) {
        throw new InvalidArgumentException('--dry-run requires --file');
    }
    if ($options['dry-run'] && $options['create-table']) {
        throw new InvalidArgumentException('--dry-run makes no changes and cannot be combined with --create-table');
    }

    return $options;
}

/**
 * Print the outcome of a validation or import run.
 *
 * @param ImportReport $report processed report
 * @param string $file path as given on the command line
 * @param bool $dryrun whether the insert phase was skipped
 */
function print_report(ImportReport $report, string $file, bool $dryrun): void
{
    fwrite(STDOUT, sprintf(
        '%s: %d rows found, %d valid, %d invalid%s',
        $file,
        $report->found(),
        $report->validCount(),
        $report->invalidCount(),
        PHP_EOL,
    ));

    $invalid = $report->invalidRecords();
    if ($invalid !== []) {
        fwrite(STDOUT, PHP_EOL . 'Invalid rows:' . PHP_EOL);
        foreach ($invalid as $record) {
            fwrite(STDOUT, sprintf('  line %d: %s%s', $record->line, $record->error, PHP_EOL));
        }
    }

    fwrite(STDOUT, PHP_EOL);
    if ($dryrun) {
        fwrite(STDOUT, 'DRY RUN - no changes made.' . PHP_EOL);
    } else {
        fwrite(STDOUT, sprintf('Inserted: %d%s', $report->inserted, PHP_EOL));
        fwrite(STDOUT, sprintf('Skipped (already in database): %d%s', $report->skipped, PHP_EOL));
    }
}

/**
 * Print usage information.
 *
 * @param resource $stream STDOUT for --help, STDERR for usage errors
 */
function print_usage($stream): void
{
    fwrite($stream, <<<USAGE
        Usage: php user_upload.php [options]

        Options:
          --file <csv>     validate the CSV file and import valid rows into PostgreSQL
          --dry_run        with --file: validate and report only, no database changes
          --create_table   drop and recreate the users table (WARNING: deletes existing data);
                           can be combined with --file to rebuild before importing
          --help           show this help

        --dry_run/--dry-run and --create_table/--create-table are interchangeable.
        --file also accepts --file=<csv>.

        Database settings are read from environment variables / .env
        (see .env.example). Exit codes: 0 ok, 1 usage, 2 file error, 3 database error.

        USAGE . PHP_EOL);
}
