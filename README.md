# User Import

A small PHP application that imports users from a CSV file into PostgreSQL, with
two front ends over one shared core:

- a **CLI** (`user_upload.php`) for scripted imports, and
- a **web UI** (React) that previews the file, then imports it.

The parsing, normalisation, validation and insert logic lives once, in `src/`,
and both entry points call the same `ImportService` — so the command line and
the browser can never disagree about what a valid row is.

```
React UI (Vite) --> HTTP API (public/index.php, thin router) --\
                                                                +--> src/ core --> PostgreSQL
CLI (user_upload.php) -----------------------------------------/
```

## Requirements

- PHP 8.3+ with the `pdo_pgsql` and `mbstring` extensions
- Composer
- Docker (for PostgreSQL) — or any PostgreSQL 14+ you point `.env` at
- Node.js 20+ / npm (web UI only)

## Setup

```bash
composer install
docker compose up -d          # PostgreSQL 16 on localhost:5432
cp .env.example .env          # defaults already match docker-compose.yml
php user_upload.php --create_table
```

`.env` holds the database settings (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
`DB_PASSWORD`). Real environment variables take precedence over the file, and
the defaults match the Docker container, so on a fresh clone the copy step is
all that's needed.

## CLI usage

```bash
php user_upload.php --file users.csv              # validate + import
php user_upload.php --file users.csv --dry_run    # validate only, no DB needed
php user_upload.php --create_table                # drop + recreate the users table
php user_upload.php --help
```

`--dry_run`/`--dry-run` and `--create_table`/`--create-table` are accepted
interchangeably, and `--file` takes either `--file users.csv` or
`--file=users.csv`, so the command works whichever convention you reach for.

Example run against the provided `users.csv`:

```
users.csv: 49 rows found, 41 valid, 8 invalid

Invalid rows:
  line 42: invalid email address "invalid-email"
  line 43: invalid email address "missing@"
  line 44: duplicate email (first seen on line 2)
  line 45: duplicate email (first seen on line 2)
  line 46: missing name
  line 47: missing surname
  line 48: missing email
  line 49: invalid email address "bad@@example.com"

Inserted: 41
Skipped (already in database): 0
```

Running the same import again inserts 0 and reports 41 skipped — rows whose
email already exists are never duplicated.

Exit codes: `0` success · `1` usage error · `2` file error · `3` database error.
Unknown options abort with a usage message rather than being silently ignored.

`--create_table` **drops** any existing `users` table before recreating it, and
says so in `--help`. `--dry_run` runs the exact same pipeline with the insert
phase skipped, so its report always matches what a real run would do.

Database credentials come from the environment / `.env` rather than connection
flags, so the same command works unchanged in a shell, a container and CI.

## Web UI

Terminal 1 — API:

```bash
php -S localhost:8000 -t public public/index.php
```

Terminal 2 — UI dev server (proxies `/api` to the PHP server):

```bash
cd frontend
npm install
npm run dev       # open the printed localhost URL
```

The flow is Upload → Preview → Import → Result: pick a CSV, review the
per-row validation table (valid and invalid rows visually distinct, with line
numbers and reasons), then import. The import button shows how many rows will
be inserted and is disabled when there are none.

For production the UI would be built to static files (`npm run build`) and
served alongside the API; the dev-proxy setup is deliberate for this exercise.

### HTTP API

| Endpoint | Body | Success response |
|---|---|---|
| `POST /api/validate` | multipart `file` | `{ found, valid, invalid, records: [{line, name, surname, email, status, error?}] }` |
| `POST /api/import` | multipart `file` | the same, plus `{ inserted, skippedExisting }` |

Errors use proper status codes — `400` bad upload, `404` unknown path, `405`
known endpoint with the wrong method (with an `Allow: POST` header), `422`
unparseable CSV, `500` database unavailable — each with `{ "error": "message" }`.

## Assumptions & design decisions

1. **One core, two thin adapters.** The brief emphasises sharing the import
   logic between the UI and the CLI, so that is the architecture's first rule:
   `user_upload.php` and `public/index.php` only parse input and format output.
   Everything else — parsing, normalising, validating, inserting — is
   `UserImport\Import\ImportService` and friends in `src/`.

2. **No framework.** The app is a router with two endpoints and a CLI with four
   flags; plain PHP with Composer's PSR-4 autoloader keeps every line of it
   readable in one sitting. A framework would add indirection without adding
   maintainability at this size.

3. **Stateless two-step web flow.** `POST /api/validate` returns a preview;
   `POST /api/import` takes the file again and **re-parses and re-validates it
   server-side** before inserting. The client's preview payload is display-only
   — never trusted — and the server holds no session state between the two
   calls. The files are small, so re-uploading costs nothing.

4. **Email validation** is trim → lowercase → `filter_var(...,
   FILTER_VALIDATE_EMAIL)`. PHP's RFC-based filter is used instead of a
   hand-rolled regex on purpose: it is well-tested, and it rejects the
   malformed shapes in the sample data (`bad@@example.com`, `missing@`,
   `john@example.com@example.com`).

5. **Capitalisation** uses `mb_convert_case(..., MB_CASE_TITLE, 'UTF-8')`, so
   `mary-jane` → `Mary-Jane` and accented names (`élodie` → `Élodie`) work.
   Special-cased surnames like O'Brien or McDonald come out as `O'brien` /
   `Mcdonald` — handling those correctly needs a locale-aware name library and
   is a conscious scope limit here.

6. **Duplicate policy.** Within a file, the first occurrence of an email wins;
   later rows are rejected as `duplicate email (first seen on line N)`.
   Detection is case-insensitive because emails are lowercased before
   validation. Against the database, `INSERT ... ON CONFLICT (email) DO
   NOTHING` skips rows whose email already exists, and the report counts them
   separately from new inserts. A row rejected for other reasons does not
   reserve its email — only accepted rows do.

7. **Schema.**

   ```sql
   CREATE TABLE users (
       id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
       name TEXT NOT NULL,
       surname TEXT NOT NULL,
       email TEXT NOT NULL UNIQUE,
       created_at TIMESTAMPTZ NOT NULL DEFAULT now()
   );
   ```

   Emails are lowercased before insert, so the plain `UNIQUE` constraint gives
   case-insensitive uniqueness without needing the `CITEXT` extension.

8. **Error philosophy.** File-level problems (missing file, empty file, wrong
   header) fail fast with a clear message. Row-level problems (a line with the
   wrong number of columns, a bad email) mark that row invalid and continue —
   one broken line should not abort a 10,000-row import. Database inserts run
   in a single transaction: either every new row lands or none do.

9. **Coding style** is PSR-12 with the habits Moodle asks of new code:
   `declare(strict_types=1)` everywhere, full parameter and return types,
   short array syntax, no closing PHP tag, PHPDoc on the public API. This is
   enforced rather than asserted — `composer lint` runs PHP_CodeSniffer over
   `src/`, `tests/`, `public/` and `user_upload.php`, and exits non-zero on a
   violation. The only exemption is `PSR1.Files.SideEffects` for the two entry
   points, which by definition both declare helpers and execute them; it is
   excluded by filename in `phpcs.xml` so the rule still applies everywhere else.

10. **Row numbers count CSV records, not physical lines.** `fgetcsv()` consumes
    one record per call, so a field containing a quoted newline spans several
    file lines but is reported as a single number. For `users.csv` — and any
    file without embedded newlines — the two are identical, which is why the
    report reads naturally against the file in an editor. `CsvParserTest`
    locks the distinction in with an embedded-newline fixture.

11. **The whole report is held in memory.** The parser is a generator and
    streams, but `ImportService::validate()` collects every row into an
    `ImportReport` because both consumers need the complete set: the API
    serialises every row for the preview table, and the CLI prints a summary
    over all of them. Peak memory is therefore proportional to the file, not
    constant. For the file sizes this exercise targets that is the right
    trade; a genuinely large import would stream rows to the database in
    batches and return counts plus a bounded sample of failures instead.

## Testing

```bash
vendor/bin/phpunit
```

- `CsvParserTest` — record numbering (including a quoted-newline record that
  spans two physical lines), blank lines, BOM tolerance, missing/empty files,
  wrong headers, wrong column counts.
- `UserNormalizerTest` — capitalisation (hyphenated, accented, multibyte),
  lowercasing, trimming.
- `UserValidatorTest` — every invalid shape in the sample data, plus
  case-insensitive duplicate detection and its edge cases.
- `ImportServiceTest` — the full pipeline over the real `users.csv`, asserting
  the exact 41 valid / 8 invalid breakdown, the per-line error messages, and
  the insert wiring (against a fake repository).
- `UserRepositoryTest` — integration against the Docker PostgreSQL: create
  table, import, re-import (everything skipped), rebuild. Skips itself cleanly
  when the database is unreachable, so the unit suite passes without Docker.

Both gates run locally in one command:

```bash
composer check      # phpcs (PSR-12) then phpunit
```

Run `vendor/bin/phpunit --fail-on-skipped` to make sure the database
integration test actually ran rather than skipping itself: `UserRepositoryTest`
skips cleanly when PostgreSQL is unreachable, so without that flag a
misconfigured environment would report green while never touching the
database.

The React layer holds almost no logic — it uploads a file and renders the
API's response — so it has no component tests; the behaviour it displays is
covered by the PHP tests above.

## Dependencies

Runtime: PHP ≥ 8.3 (`pdo_pgsql`, `mbstring`), PostgreSQL 16 (Docker image).
PHP dev: `phpunit/phpunit` ^11.5 and `squizlabs/php_codesniffer` ^3.11 — the
only Composer packages.
Frontend: `react`, `react-dom`; dev-only: `vite`, `@vitejs/plugin-react`.

## Licence

MIT — see [LICENSE](LICENSE).
