<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 02 section 11.1 sync columns on 1C-mastered (or shared) tables. Rows are matched to 1C only
 * by `external_id` (lowercase GUID). The inbound API that fills these columns is a later increment.
 *
 * @mixin Model
 */
trait SyncsWithOneC
{
    public function initializeSyncsWithOneC(): void
    {
        $this->mergeCasts([
            'external_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'external_deleted_at' => 'datetime',
        ]);
    }

    public function isLinkedToOneC(): bool
    {
        return filled($this->getAttribute('external_id'));
    }

    public function isDeletedInOneC(): bool
    {
        return $this->getAttribute('external_deleted_at') !== null;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeNotDeletedInOneC(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('external_deleted_at'));
    }
}
