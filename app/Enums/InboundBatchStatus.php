<?php

namespace App\Enums;

/**
 * Lifecycle of a batch pushed by 1C (Spec 02 section 6.2):
 * received -> queued -> processing -> processed | partial | failed; rejected is terminal (intake 422).
 */
enum InboundBatchStatus: string
{
    case Received = 'received';
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('integration.batch_status.'.$this->value);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Processed, self::Partial, self::Failed, self::Rejected], true);
    }
}
