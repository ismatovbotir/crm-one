<?php

namespace App\Services\OneC\Inbound;

use App\Models\InboundBatch;

/**
 * Per-batch processing outcome (Spec 02 7.3): counters, up to 1 000 error entries, and what a
 * full-snapshot part has seen (for the finalizer).
 */
final class ProcessingResult
{
    /** @var array{created: int, updated: int, unchanged: int, stale: int, deactivated: int, failed: int} */
    public array $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'deactivated' => 0, 'failed' => 0];

    /** @var list<array{section: string, index: int|null, id: string|null, code: string, message: string, level: string}> */
    public array $errors = [];

    /** @var array<string, true> leaf sections that carried at least one record */
    public array $sectionsSeen = [];

    /** @var array<string, true> 1C GUIDs of warehouses touched */
    public array $warehouseIds = [];

    public function count(string $outcome): void
    {
        $this->counts[$outcome]++;
    }

    public function fail(string $section, ?int $index, ?string $id, string $code, string $message): void
    {
        $this->counts['failed']++;
        $this->addError($section, $index, $id, $code, $message, 'error');
    }

    public function warn(string $section, ?int $index, ?string $id, string $code, string $message): void
    {
        $this->addError($section, $index, $id, $code, $message, 'warning');
    }

    protected function addError(string $section, ?int $index, ?string $id, string $code, string $message, string $level): void
    {
        if (count($this->errors) < InboundBatch::MAX_ERRORS) {
            $this->errors[] = ['section' => $section, 'index' => $index, 'id' => $id, 'code' => $code, 'message' => $message, 'level' => $level];
        }
    }

    public function processedRecords(): int
    {
        return $this->counts['created'] + $this->counts['updated'] + $this->counts['unchanged'] + $this->counts['stale'];
    }
}
