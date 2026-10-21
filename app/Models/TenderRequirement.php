<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Requirement of a tender; knock-out requirements are pass/fail before scoring.
 */
#[Fillable(['tender_id', 'text', 'is_knockout'])]
class TenderRequirement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_knockout' => 'boolean'];
    }
}
