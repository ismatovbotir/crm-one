<?php

namespace App\Models;

use App\Enums\AgreementChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send of an agreement version through a channel (Spec 06 6.5 sub-tracking).
 *
 * @property AgreementChannel $channel
 */
#[Fillable([
    'agreement_id', 'version', 'channel', 'recipient_contact_id', 'recipient_user_id', 'recipient_email', 'locale',
    'share_token_hash', 'token_expires_at', 'sent_at', 'sent_by', 'viewed_at', 'downloaded_at', 'download_count', 'revoked_at',
])]
class AgreementDispatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AgreementChannel::class,
            'token_expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'downloaded_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function recipientContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'recipient_contact_id')->withTrashed();
    }
}
