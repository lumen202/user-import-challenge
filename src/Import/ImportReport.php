<?php

declare(strict_types=1);

namespace UserImport\Import;

/**
 * Immutable result of processing one CSV file.
 *
 * All counts are derived from the single $records list, so the summary can
 * never disagree with the rows it summarises. After the validation phase
 * $inserted and $skipped are null; import runs produce a copy with the
 * insert results filled in via withInsertResult().
 */
final class ImportReport
{
    /**
     * @param ImportRecord[] $records all processed rows in file order
     * @param int|null $inserted rows inserted into the database, null before/without an insert phase
     * @param int|null $skipped rows skipped because the email already existed in the database
     */
    public function __construct(
        public readonly array $records,
        public readonly ?int $inserted = null,
        public readonly ?int $skipped = null,
    ) {
    }

    /**
     * Total number of data rows found in the file.
     *
     * @return int
     */
    public function found(): int
    {
        return count($this->records);
    }

    /**
     * Number of rows that passed validation.
     *
     * @return int
     */
    public function validCount(): int
    {
        return count($this->validRecords());
    }

    /**
     * Number of rows that failed validation.
     *
     * @return int
     */
    public function invalidCount(): int
    {
        return $this->found() - $this->validCount();
    }

    /**
     * Rows that passed validation, in file order.
     *
     * @return ImportRecord[]
     */
    public function validRecords(): array
    {
        return array_values(array_filter($this->records, static fn(ImportRecord $record): bool => $record->isValid()));
    }

    /**
     * Rows that failed validation, in file order.
     *
     * @return ImportRecord[]
     */
    public function invalidRecords(): array
    {
        return array_values(array_filter($this->records, static fn(ImportRecord $record): bool => !$record->isValid()));
    }

    /**
     * Copy of this report with the insert phase results attached.
     *
     * @param int $inserted rows inserted
     * @param int $skipped rows skipped as already existing
     * @return self
     */
    public function withInsertResult(int $inserted, int $skipped): self
    {
        return new self($this->records, $inserted, $skipped);
    }

    /**
     * Shape used by the HTTP API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'found' => $this->found(),
            'valid' => $this->validCount(),
            'invalid' => $this->invalidCount(),
            'records' => array_map(static fn(ImportRecord $record): array => $record->toArray(), $this->records),
        ];
        if ($this->inserted !== null) {
            $data['inserted'] = $this->inserted;
            $data['skippedExisting'] = $this->skipped;
        }

        return $data;
    }
}
