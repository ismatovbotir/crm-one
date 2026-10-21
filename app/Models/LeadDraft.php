<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Server-side autosave of the lead capture form, one per user (Spec 04 section 16).
 */
#[Fillable(['user_id', 'payload'])]
class LeadDraft extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
