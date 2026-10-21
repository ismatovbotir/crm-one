<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\DataScope;
use App\Enums\Locale;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\AgreementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Agreement (Spec 06 Part B): order, framework or addendum, generated from a template version
 * with a frozen variables snapshot. Sent text is stored per locale in agreement_versions and
 * never changes; status only through TransitionAgreement.
 *
 * @property AgreementStatus $status
 * @property AgreementKind $kind
 * @property array<string, mixed> $variables
 * @property array<string, bool>|null $clause_options
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $signed_on
 */
#[Fillable([
    'number', 'kind', 'status', 'order_id', 'customer_id', 'parent_agreement_id', 'renewal_of_id', 'template_id',
    'template_version', 'document_locale', 'secondary_locale', 'variables', 'clause_options', 'is_non_standard',
    'non_standard_reasons', 'version', 'starts_on', 'ends_on', 'auto_renew', 'notice_days', 'value_minor', 'currency',
    'prepared_by', 'our_signatory_id',
])]
class Agreement extends Model implements HasDocumentLanguage
{
    /** @use HasFactory<AgreementFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'kind' => AgreementKind::class,
            'variables' => 'array',
            'clause_options' => 'array',
            'non_standard_reasons' => 'array',
            'reminders_sent' => 'array',
            'is_non_standard' => 'boolean',
            'auto_renew' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'signed_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'customer_signed_uploaded_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'value_minor' => 'integer',
            'version' => 'integer',
            'template_version' => 'integer',
            'notice_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<AgreementTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class);
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_agreement_id');
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_id');
    }

    /**
     * @return BelongsTo<CompanySignatory, $this>
     */
    public function ourSignatory(): BelongsTo
    {
        return $this->belongsTo(CompanySignatory::class, 'our_signatory_id');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function signedAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'signed_attachment_id');
    }

    /**
     * @return HasMany<AgreementVersion, $this>
     */
    public function renderedVersions(): HasMany
    {
        return $this->hasMany(AgreementVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasMany<AgreementDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(AgreementDispatch::class)->latest('sent_at')->latest('id');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->latest('created_at')->latest('id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest('id');
    }

    /**
     * Languages the agreement is printed in: the document language, then the optional second one.
     *
     * @return list<string>
     */
    public function printLocales(): array
    {
        return array_values(array_unique(array_filter([$this->document_locale, $this->secondary_locale])));
    }

    /** Stored text of the current version in a locale (sent agreements), or null. */
    public function renderedHtml(string $locale): ?AgreementVersion
    {
        return $this->renderedVersions()->where('version', $this->version)->where('locale', $locale)->first();
    }

    /**
     * Agreements the user may see (Spec 06 4.3 `agreements.view`): sales see agreements of the
     * orders they own and framework agreements of customers they own.
     *
     * @param  Builder<Agreement>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $owners = match (PermissionMap::scopeFor($user, 'agreements.view')) {
            DataScope::All => null,
            DataScope::Department => [$user->id, ...PermissionMap::colleagueIds($user)],
            DataScope::Own, DataScope::Linked => [$user->id],
            null => [],
        };

        if ($owners === null) {
            return;
        }

        // Same rule as AgreementPolicy: the order owner for order agreements, else the customer owner.
        $query->where(fn (Builder $scoped) => $scoped
            ->whereIn($query->qualifyColumn('order_id'), Order::query()->select('id')->whereIn('owner_id', $owners))
            ->orWhere(fn (Builder $framework) => $framework->whereNull($query->qualifyColumn('order_id'))
                ->whereIn($query->qualifyColumn('customer_id'), Customer::withTrashed()->select('id')->whereIn('owner_id', $owners))));
    }

    public function preferredDocumentLocale(): ?Locale
    {
        return Locale::fromAvailable($this->document_locale) ?? $this->customer?->preferredDocumentLocale();
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return in_array('status', $changedKeys, true) ? 'status_changed' : 'updated';
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'variables', 'reminders_sent', 'active_order_key'];
    }
}
