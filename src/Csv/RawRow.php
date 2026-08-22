<?php

declare(strict_types=1);

namespace UserImport\Csv;

/**
 * One data row as read from the CSV file, before normalisation or validation.
 *
 * When the row is structurally broken (wrong number of columns) the field
 * values are null and $error describes the problem.
 */
final class RawRow
{
    /**
     * @param int $line 1-based CSV record number (the header is record 1). This
     *                   equals the physical file line unless a field contains a
     *                   quoted newline, in which case one record spans several lines.
     * @param string|null $name raw name cell, untrimmed
     * @param string|null $surname raw surname cell, untrimmed
     * @param string|null $email raw email cell, untrimmed
     * @param string|null $error structural problem with this row, if any
     */
    public function __construct(
        public readonly int $line,
        public readonly ?string $name,
        public readonly ?string $surname,
        public readonly ?string $email,
        public readonly ?string $error = null,
    ) {
    }
}
