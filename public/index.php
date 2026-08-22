<?php

/**
 * HTTP API entry point - a thin router over the shared UserImport core.
 *
 * Endpoints:
 *   POST /api/validate  multipart "file" -> validation report (no DB writes)
 *   POST /api/import    multipart "file" -> re-validates server-side, inserts valid rows
 *
 * Anything else is 404; a known endpoint with the wrong method is 405.
 *
 * Run for development with:
 *   php -S localhost:8000 -t public public/index.php
 */

declare(strict_types=1);

// Serve static files directly when running under the PHP built-in server.
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($requested)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use UserImport\Config;
use UserImport\Csv\CsvParseException;
use UserImport\Csv\CsvParser;
use UserImport\Db\UserRepository;
use UserImport\Import\ImportService;
use UserImport\Import\UserNormalizer;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

match (true) {
    $method === 'POST' && $path === '/api/validate' => handle_upload(withimport: false),
    $method === 'POST' && $path === '/api/import' => handle_upload(withimport: true),
    // A known endpoint reached with the wrong verb is 405, not 404: the
    // resource exists, the method does not apply to it.
    $path === '/api/validate' || $path === '/api/import' => respond(
        405,
        ['error' => sprintf('Method %s not allowed - use POST.', $method)],
        ['Allow' => 'POST'],
    ),
    default => respond(404, ['error' => 'Not found']),
};

/**
 * Validate (and optionally import) an uploaded CSV file.
 *
 * The import endpoint re-parses and re-validates the file server-side; the
 * client's preview is display-only and never trusted.
 *
 * @param bool $withimport whether to run the insert phase after validation
 */
function handle_upload(bool $withimport): void
{
    $upload = $_FILES['file'] ?? null;
    if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        respond(400, ['error' => 'No file uploaded - send the CSV as multipart field "file".']);
    }
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        respond(400, ['error' => sprintf('Upload failed (PHP upload error %d).', $upload['error'])]);
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        respond(400, ['error' => 'Invalid upload.']);
    }

    try {
        $repository = $withimport ? UserRepository::connect(Config::fromEnvironment()) : null;
        $service = new ImportService(new CsvParser(), new UserNormalizer(), $repository);

        $report = $withimport
            ? $service->import($upload['tmp_name'])
            : $service->validate($upload['tmp_name']);

        respond(200, $report->toArray());
    } catch (CsvParseException $exception) {
        // The message mentions the server-side temp path; the reason is what matters.
        $reason = strip_tmp_path($exception->getMessage(), $upload['tmp_name']);
        respond(422, ['error' => 'Could not parse CSV: ' . $reason]);
    } catch (PDOException) {
        respond(500, ['error' => 'Database error - is PostgreSQL running? (docker compose up -d)']);
    }
}

/**
 * Replace the server temp path in a message with the generic "uploaded file".
 *
 * @param string $message exception message
 * @param string $tmppath the upload's temp path
 * @return string
 */
function strip_tmp_path(string $message, string $tmppath): string
{
    return str_replace(sprintf('"%s"', $tmppath), 'uploaded file', $message);
}

/**
 * Emit a JSON response and stop.
 *
 * @param int $status HTTP status code
 * @param array<string, mixed> $body response payload
 * @param array<string, string> $headers extra response headers, name => value
 */
function respond(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header(sprintf('%s: %s', $name, $value));
    }
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
