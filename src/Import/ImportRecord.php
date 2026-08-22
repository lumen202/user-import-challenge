<?php

declare(strict_types=1);

namespace UserImport\Import;

/**
 * One processed CSV row: its normalised values plus the validation outcome.
 */
final class ImportRecord
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';

    /**
     * @param int $line 1-based CSV record number, as reported by the parser
     * @param string $name normalised name ('' when the row was structurally broken)
     * @param string $surname normalised surname
     * @param string $email normalised email
     * @param string $status one of the STATUS_* constants
     * @param string|null $error validation error for invalid records
     */
    public function __construct(
        public readonly int $line,
        public readonly string $name,
        public readonly string $surname,
        public readonly string $email,
        public readonly string $status,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Whether this record passed validation.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->status === self::STATUS_VALID;
    }

    /**
     * Shape used by the HTTP API and the frontend preview table.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        $data = [
            'line' => $this->line,
            'name' => $this->name,
            'surname' => $this->surname,
            'email' => $this->email,
            'status' => $this->status,
        ];
        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        return $data;
    }
}
