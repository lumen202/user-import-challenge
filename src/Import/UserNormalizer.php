<?php

declare(strict_types=1);

namespace UserImport\Import;

/**
 * Normalises raw CSV field values into their canonical stored form.
 *
 * All fields are trimmed. Names are title-cased (multibyte-safe, so
 * hyphenated and accented names work); emails are lowercased so uniqueness
 * checks and the database UNIQUE constraint are effectively case-insensitive.
 */
final class UserNormalizer
{
    /**
     * Normalise a name or surname: trim, then title-case.
     *
     * "mary-jane" becomes "Mary-Jane"; "MÜLLER" becomes "Müller".
     *
     * @param string $value raw field value
     * @return string normalised value
     */
    public function normalizeName(string $value): string
    {
        return mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Normalise an email address: trim, then lowercase.
     *
     * @param string $value raw field value
     * @return string normalised value
     */
    public function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }
}
