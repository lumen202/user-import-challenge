<?php

declare(strict_types=1);

namespace UserImport\Tests\Doubles;

use UserImport\Db\UserRepository;
use UserImport\Import\ImportRecord;

/**
 * Fake repository: records what would be inserted, pretends some rows existed.
 */
final class RecordingRepository extends UserRepository
{
    /** @var ImportRecord[] */
    public array $received = [];

    /**
     * @param int $pretendexisting how many rows to report as already existing
     */
    public function __construct(private readonly int $pretendexisting = 0)
    {
        // Deliberately no parent call: this fake never touches PDO.
    }

    public function insertUsers(array $records): array
    {
        $this->received = $records;

        return [
            'inserted' => count($records) - $this->pretendexisting,
            'skipped' => $this->pretendexisting,
        ];
    }
}
