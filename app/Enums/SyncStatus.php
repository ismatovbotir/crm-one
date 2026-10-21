<?php

namespace App\Enums;

/**
 * Outbound 1C sync state of a CRM customer (Spec 02 section 6.1).
 */
enum SyncStatus: string
{
    case NotSynced = 'not_synced';
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case Conflict = 'conflict';

    public function label(): string
    {
        return __('integration.sync_status.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
