<?php

declare(strict_types=1);

namespace UserImport;

/**
 * Database connection settings, read from the environment.
 *
 * Real environment variables win; a .env file in the project root fills the
 * gaps; the remaining defaults match docker-compose.yml so a fresh clone
 * works without any configuration.
 */
final class Config
{
    /**
     * @param string $host database host
     * @param int $port database port
     * @param string $dbname database name
     * @param string $user database user
     * @param string $password database password
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $dbname,
        public readonly string $user,
        public readonly string $password,
    ) {
    }

    /**
     * Build settings from the environment, optionally topped up from a .env file.
     *
     * @param string|null $envfile path to a .env file, or null to use <project>/.env
     * @return self
     */
    public static function fromEnvironment(?string $envfile = null): self
    {
        $envfile ??= dirname(__DIR__) . '/.env';
        $filevalues = is_readable($envfile) ? self::parseEnvFile($envfile) : [];

        $get = static function (string $key, string $default) use ($filevalues): string {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return $value;
            }

            return $filevalues[$key] ?? $default;
        };

        return new self(
            $get('DB_HOST', '127.0.0.1'),
            (int) $get('DB_PORT', '5432'),
            $get('DB_NAME', 'userimport'),
            $get('DB_USER', 'userimport'),
            $get('DB_PASSWORD', 'userimport'),
        );
    }

    /**
     * PDO data source name for this configuration.
     *
     * @return string
     */
    public function dsn(): string
    {
        return sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->dbname);
    }

    /**
     * Minimal KEY=VALUE parser - enough for the five settings this app uses.
     *
     * @param string $path readable .env file
     * @return array<string, string>
     */
    private static function parseEnvFile(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }
}
