<?php

declare(strict_types=1);

namespace UserImport\Db;

use UserImport\Config;
use UserImport\Import\ImportRecord;

/**
 * PostgreSQL persistence for imported users.
 *
 * Emails are lowercased by the normaliser before they get here, so the plain
 * UNIQUE constraint on email is effectively case-insensitive without CITEXT.
 */
class UserRepository
{
    /**
     * @param \PDO $pdo open connection to the database
     */
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Open a connection from configuration and wrap it in a repository.
     *
     * @param Config $config connection settings
     * @return self
     * @throws \PDOException when the database is unreachable or credentials are wrong
     */
    public static function connect(Config $config): self
    {
        $pdo = new \PDO($config->dsn(), $config->user, $config->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        return new self($pdo);
    }

    /**
     * Drop and recreate the users table.
     *
     * Destructive by design: the brief asks for a create/rebuild option.
     */
    public function createTable(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS users');
        $this->pdo->exec(
            'CREATE TABLE users (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name TEXT NOT NULL,
                surname TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )'
        );
    }

    /**
     * Insert validated records, skipping emails that already exist.
     *
     * Runs in a single transaction: either every new row lands or none do.
     * rowCount() is 1 for an insert and 0 when ON CONFLICT skipped the row,
     * which is what makes the inserted/skipped split reliable.
     *
     * @param ImportRecord[] $records validated records to insert
     * @return array{inserted: int, skipped: int}
     * @throws \PDOException when the insert fails (transaction is rolled back)
     */
    public function insertUsers(array $records): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, surname, email) VALUES (:name, :surname, :email)
             ON CONFLICT (email) DO NOTHING'
        );

        $inserted = 0;
        $skipped = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($records as $record) {
                $statement->execute([
                    'name' => $record->name,
                    'surname' => $record->surname,
                    'email' => $record->email,
                ]);
                if ($statement->rowCount() === 1) {
                    $inserted++;
                } else {
                    $skipped++;
                }
            }
            $this->pdo->commit();
        } catch (\PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }
}
