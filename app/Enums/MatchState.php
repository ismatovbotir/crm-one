<?php

namespace App\Enums;

/**
 * Duplicate matching of 1C payments against CRM-recorded ones (Spec 06 9.3).
 */
enum MatchState: string
{
    case PossibleDuplicate = 'possible_duplicate';
    case Linked = 'linked';
    case Distinct = 'distinct';
    case NeedsReallocation = 'needs_reallocation';

    public function label(): string
    {
        return __('accounting.match_states.'.$this->value);
    }
}
