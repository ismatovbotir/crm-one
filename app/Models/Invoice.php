<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Enums\EsfStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\Locale;
use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Proforma issued by the CRM or a tax document registered from 1C / e-invoicing (Spec 06 7.10,
 * DEC-6). Issued documents never change (amounts, lines, number, date); only void and ESF data.
 *
 * @property InvoiceType $type
 * @property InvoiceStatus $status
 * @property EsfStatus|null $esf_status
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $due_on
 * @property list<array{line: int, name: string, code: string, mode: string}>|null $code_warnings
 */
#[Fillable([
    'type', 'status', 'source', 'number', 'issued_on', 'due_on', 'order_id', 'customer_id', 'agreement_id', 'currency',
    'prices_include_vat', 'net_minor', 'vat_minor', 'gross_minor', 'requested_amount_minor', 'bank_account_id',
    'document_locale', 'esf_id', 'esf_status', 'external_ref', 'attachment_id', 'period', 'issued_by', 'code_warnings',
])]
class Invoice extends Model implements HasDocumentLanguage
{
    use HasUuids, LogsActivity;

    public const IMMUTABLE = ['type', 'number', 'issued_on', 'order_id', 'customer_id', 'currency', 'net_minor', 'vat_minor', 'gross_minor', 'requested_amount_minor'];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getRawOriginal('status') !== InvoiceStatus::Draft->value && $invoice->isDirty(self::IMMUTABLE)) {
                throw new LogicException('Issued invoices are immutable; void and issue a new one.');
            }
        });
        static::deleting(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Draft ?: throw new LogicException('Issued invoices are never deleted.'));
    }

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
            'type' => InvoiceType::class,
            'status' => InvoiceStatus::class,
            'esf_status' => EsfStatus::class,
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'prices_include_vat' => 'boolean',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'gross_minor' => 'integer',
            'requested_amount_minor' => 'integer',
            'paid_minor' => 'integer',
            'code_warnings' => 'array',
            'void_requested_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
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
     * @return BelongsTo<CompanyBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'bank_account_id');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function money(?int $minor): ?Money
    {
        return $minor === null ? null : Money::of($minor, $this->currency);
    }

    /**
     * Invoices of orders the user may see (Spec 06 4.3 `invoices.view`).
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($query->qualifyColumn('order_id'), PermissionMap::applyScope(Order::query()->select('id'), $user, 'invoices.view'));
    }

    public function preferredDocumentLocale(): ?Locale
    {
        return Locale::fromAvailable($this->document_locale) ?? $this->customer?->preferredDocumentLocale();
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'paid_minor', 'code_warnings'];
    }
}
