# User Import

Imports users from a CSV file into PostgreSQL. There are two front ends, a CLI
and a small React UI, and they share one core: parsing, normalising, validating
and inserting all live in `src/`, and both entry points call the same
`ImportService`.

```
React UI (Vite) --> HTTP API (public/index.php) --\
                                                  +--> src/ core --> PostgreSQL
CLI (user_upload.php) ----------------------------/
```

## Requirements

- PHP 8.3+ with the `pdo_pgsql` and `mbstring` extensions
- Composer
- Docker for PostgreSQL, or any PostgreSQL 14+ you point `.env` at
- Node.js 20+ and npm, for the web UI only

## Setup

```bash
composer install
docker compose up -d          # PostgreSQL 16 on localhost:5432
cp .env.example .env          # defaults match docker-compose.yml
php user_upload.php --create_table
```

`.env` holds `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`. Real
environment variables take precedence over the file, and the defaults match the
Docker container, so a fresh clone only needs the copy step.

## CLI usage

```bash
php user_upload.php --file users.csv              # validate + import
php user_upload.php --file users.csv --dry_run    # validate only, no DB needed
php user_upload.php --create_table                # drop + recreate the users table
php user_upload.php --help
```

`--dry_run` and `--dry-run` are both accepted, likewise
`--create_table`/`--create-table`, and `--file` takes either a space or an
equals sign.

Against the provided `users.csv`:

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

Run it a second time and it inserts 0 and reports 41 skipped.

Exit codes: 0 success, 1 usage error, 2 file error, 3 database error. Unknown
options abort with the usage message instead of being ignored.

Note that `--create_table` drops any existing `users` table before recreating
it. `--dry_run` runs the same pipeline with the insert step skipped, so its
report matches what a real run would do.

## Web UI

Terminal 1, the API:

```bash
composer serve    # php -S localhost:8000 -t public public/index.php
```

Terminal 2, the UI dev server, which proxies `/api` to PHP:

```bash
cd frontend
npm install
npm run dev       # open the printed localhost URL
```

Pick a CSV, review the per-row table, then import. Invalid rows are highlighted
with their line number and reason, and the import button is disabled when there
is nothing valid to insert. For production the UI would be built with
`npm run build` and served alongside the API; the dev proxy keeps this exercise
to two commands.

### HTTP API

| Endpoint | Body | Success response |
|---|---|---|
| `POST /api/validate` | multipart `file` | `{ found, valid, invalid, records: [{line, name, surname, email, status, error?}] }` |
| `POST /api/import` | multipart `file` | the same, plus `{ inserted, skippedExisting }` |

Errors return `{ "error": "message" }` with 400 for a bad upload, 404 for an
unknown path, 405 for a known endpoint with the wrong method (plus an
`Allow: POST` header), 422 for an unparseable CSV and 500 when the database is
unavailable.

## Assumptions and design decisions

1. **One core, two thin adapters.** The brief asks for the import logic to be
   shared, so `user_upload.php` and `public/index.php` only read input and
   format output. Everything else is `UserImport\Import\ImportService` and its
   collaborators in `src/`.

2. No framework. Two endpoints and four CLI flags did not seem to warrant one,
   so Composer's PSR-4 autoloader is the only infrastructure here.

3. **The web flow is stateless.** `POST /api/validate` returns a preview;
   `POST /api/import` takes the file again and re-parses and re-validates it
   server-side before inserting. The preview payload is for display only and is
   never trusted. Files this size make the second upload cheap.

4. Email validation is trim, lowercase, then
   `filter_var(..., FILTER_VALIDATE_EMAIL)`. I preferred PHP's RFC-based filter
   to a hand-rolled regex; it rejects the malformed shapes in the sample data
   (`bad@@example.com`, `missing@`, `john@example.com@example.com`).

5. Capitalisation uses `mb_convert_case(..., MB_CASE_TITLE, 'UTF-8')`, so
   `mary-jane` becomes `Mary-Jane` and `élodie` becomes `Élodie`. O'Brien and
   McDonald come out as `O'brien` and `Mcdonald`; handling those properly needs
   a locale-aware name library, which I left out of scope.

6. **Duplicate policy.** Within a file the first occurrence of an email wins and
   later rows are rejected with the line number of the first. Detection is
   case-insensitive because emails are lowercased before validation. Against the
   database, `INSERT ... ON CONFLICT (email) DO NOTHING` skips emails that
   already exist, and those are counted separately from new inserts. A row
   rejected for some other reason does not reserve its email.

7. Schema:

   ```sql
   CREATE TABLE users (
       id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
       name TEXT NOT NULL,
       surname TEXT NOT NULL,
       email TEXT NOT NULL UNIQUE,
       created_at TIMESTAMPTZ NOT NULL DEFAULT now()
   );
   ```

   Emails are lowercased before insert, so a plain `UNIQUE` gives
   case-insensitive uniqueness without needing `CITEXT`.

8. **Errors are split by scope.** A missing file, an empty file or a wrong header
   fails immediately. A bad row is marked invalid and the import carries on,
   since one broken line should not abort a 10,000-row file. Inserts run in a
   single transaction.

9. Style is PSR-12 with `declare(strict_types=1)`, full parameter and return
   types, short array syntax, no closing tag and PHPDoc on the public API.
   `composer lint` runs PHP_CodeSniffer over `src/`, `tests/`, `public/` and
   `user_upload.php`. The two entry points are exempt from
   `PSR1.Files.SideEffects` by filename in `phpcs.xml`, since both declare
   helpers and then run them.

10. Row numbers count CSV records, not physical lines. `fgetcsv()` reads one
    record per call, so a field containing a quoted newline spans several lines
    in an editor but reports as a single number. For `users.csv` the two are
    identical; `CsvParserTest` pins the difference with an embedded-newline
    fixture.

11. The whole report is held in memory. The parser is a generator, but
    `ImportService::validate()` collects every row because both consumers need
    the complete set: the API serialises them all for the preview table, and the
    CLI summarises across them. Peak memory therefore scales with the file. A
    genuinely large import would batch rows to the database and return counts
    plus a bounded sample of failures.

## Testing

```bash
composer check      # phpcs, then phpunit
```

- `CsvParserTest` covers record numbering (including a quoted-newline record
  spanning two physical lines), blank lines, BOM tolerance, missing and empty
  files, wrong headers and wrong column counts.
- `UserNormalizerTest` covers capitalisation (hyphenated, accented, multibyte),
  lowercasing and trimming.
- `UserValidatorTest` covers every invalid shape in the sample data, plus
  case-insensitive duplicate detection and its edge cases.
- `ImportServiceTest` runs the full pipeline over the real `users.csv` and
  asserts the 41 valid / 8 invalid split, the per-line messages and the insert
  wiring against a fake repository.
- `UserRepositoryTest` is an integration test against the Docker database:
  create table, import, re-import with everything skipped, rebuild. It skips
  itself when PostgreSQL is unreachable so the unit suite runs without Docker.

Use `vendor/bin/phpunit --fail-on-skipped` to confirm the database test actually
ran. Without it a misconfigured environment reports green while never touching
PostgreSQL.

The React layer uploads a file and renders the response, so it has no component
tests of its own; the behaviour it displays is covered above.

## Dependencies

Runtime is PHP 8.3+ with `pdo_pgsql` and `mbstring`, plus PostgreSQL 16 from the
Docker image. The only Composer packages are `phpunit/phpunit` ^11.5 and
`squizlabs/php_codesniffer` ^3.11, both dev-only. The frontend uses `react` and
`react-dom`, with `vite` and `@vitejs/plugin-react` for the build.

## Licence

MIT, see [LICENSE](LICENSE).
