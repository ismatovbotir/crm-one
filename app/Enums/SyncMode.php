<?php

namespace App\Enums;

/**
 * Delta (only the records sent) or full snapshot (everything for the feed and scope), Spec 02 section 7.4.
 */
enum SyncMode: string
{
    case Delta = 'delta';
    case Full = 'full';

    public function label(): string
    {
        return __('integration.sync_mode.'.$this->value);
    }
}
