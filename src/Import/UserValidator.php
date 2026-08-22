<?php

declare(strict_types=1);

namespace UserImport\Import;

/**
 * Validates normalised user rows and tracks in-file duplicate emails.
 *
 * The validator is stateful: it remembers every email it has accepted so
 * later occurrences are rejected as duplicates. Use one instance per file.
 * Values must already be normalised (trimmed, emails lowercased) - that is
 * what makes duplicate detection case-insensitive.
 */
final class UserValidator
{
    /** @var array<string, int> accepted email => line it was first seen on */
    private array $seen = [];

    /**
     * Validate one normalised row.
     *
     * @param int $line line number of the row in the source file
     * @param string $name normalised name
     * @param string $surname normalised surname
     * @param string $email normalised email
     * @return string|null a human-readable error, or null when the row is valid
     */
    public function validate(int $line, string $name, string $surname, string $email): ?string
    {
        $missing = [];
        if ($name === '') {
            $missing[] = 'name';
        }
        if ($surname === '') {
            $missing[] = 'surname';
        }
        if ($email === '') {
            $missing[] = 'email';
        }
        if ($missing !== []) {
            return 'missing ' . implode(', ', $missing);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return sprintf('invalid email address "%s"', $email);
        }

        if (isset($this->seen[$email])) {
            return sprintf('duplicate email (first seen on line %d)', $this->seen[$email]);
        }

        $this->seen[$email] = $line;

        return null;
    }
}
