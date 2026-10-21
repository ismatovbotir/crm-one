<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transport RFQ of one lot (Spec 03 7.3; field list = transport-tender skill section 2).
 * Tri-state booleans stay null until answered (N5, N6, N13). Written only by UpdateTransportSpec,
 * which whitelists the editable columns.
 *
 * @property CarbonImmutable|null $readiness_date
 * @property CarbonImmutable|null $pickup_from
 * @property CarbonImmutable|null $pickup_to
 * @property CarbonImmutable|null $delivery_from
 * @property CarbonImmutable|null $delivery_to
 * @property list<string>|null $hs_codes
 * @property array<string, string>|null $charges_payer
 * @property list<string>|null $required_documents
 * @property list<string>|null $driver_vehicle_documents
 * @property list<string>|null $avoid_countries
 */
#[Guarded(['id', 'lot_id', 'created_at', 'updated_at'])]
class TenderTransportSpec extends Model
{
    /** Columns edited through the wizard (everything except keys and timestamps). */
    public const EDITABLE = [
        'origin_country', 'origin_city', 'origin_address', 'origin_postcode', 'origin_lat', 'origin_lng',
        'destination_country', 'destination_city', 'destination_address', 'destination_postcode', 'destination_lat', 'destination_lng',
        'border_crossing', 'border_policy', 'clearance_place', 'avoid_countries', 'sanctions_reviewed',
        'readiness_date', 'pickup_from', 'pickup_to', 'delivery_from', 'delivery_to', 'max_transit_days',
        'mode', 'load_type', 'vehicle_type', 'units_count', 'transshipment_allowed', 'partial_allowed',
        'cargo_description', 'hs_codes', 'gross_kg', 'net_kg', 'volume_m3', 'packages_count', 'package_type', 'pallets_count',
        'pallet_type', 'dimensions', 'stackable', 'cargo_value_minor', 'cargo_currency', 'is_dangerous', 'un_number', 'adr_class',
        'packing_group', 'is_temperature_controlled', 'temp_min_c', 'temp_max_c', 'is_oversize', 'oversize_notes', 'special_handling',
        'loading_method', 'loading_equipment', 'loaded_by', 'unloading_method', 'unloading_equipment', 'unloaded_by',
        'free_loading_hours', 'free_customs_hours', 'free_unloading_hours', 'container_owner', 'container_free_days',
        'container_return_location', 'incoterm', 'incoterm_place', 'export_clearance_by', 'import_clearance_by',
        'importer_of_record', 'charges_payer', 'insurance_by', 'insured_value_minor', 'insurance_cover',
        'required_documents', 'driver_vehicle_documents', 'tracking_requirement', 'penalties', 'late_penalty_bp_per_day',
        'penalty_cap_bp', 'fuel_adjustment', 'force_majeure', 'site_contacts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'avoid_countries' => 'array',
            'sanctions_reviewed' => 'boolean',
            'readiness_date' => 'immutable_date',
            'pickup_from' => 'immutable_date',
            'pickup_to' => 'immutable_date',
            'delivery_from' => 'immutable_date',
            'delivery_to' => 'immutable_date',
            'max_transit_days' => 'integer',
            'units_count' => 'integer',
            'transshipment_allowed' => 'boolean',
            'partial_allowed' => 'boolean',
            'hs_codes' => 'array',
            'gross_kg' => 'decimal:3',
            'net_kg' => 'decimal:3',
            'volume_m3' => 'decimal:3',
            'packages_count' => 'integer',
            'pallets_count' => 'integer',
            'stackable' => 'boolean',
            'cargo_value_minor' => 'integer',
            'is_dangerous' => 'boolean',
            'is_temperature_controlled' => 'boolean',
            'temp_min_c' => 'decimal:1',
            'temp_max_c' => 'decimal:1',
            'is_oversize' => 'boolean',
            'free_loading_hours' => 'integer',
            'free_customs_hours' => 'integer',
            'free_unloading_hours' => 'integer',
            'container_free_days' => 'integer',
            'charges_payer' => 'array',
            'insured_value_minor' => 'integer',
            'required_documents' => 'array',
            'driver_vehicle_documents' => 'array',
            'late_penalty_bp_per_day' => 'integer',
            'penalty_cap_bp' => 'integer',
            'prefilled_from' => 'array',
            'purchase_order_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TenderLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(TenderLot::class, 'lot_id');
    }

    public function isCrossBorder(): bool
    {
        return filled($this->origin_country) && filled($this->destination_country) && $this->origin_country !== $this->destination_country;
    }

    /** Import into Uzbekistan (destination UZ, origin abroad). */
    public function isImport(): bool
    {
        return $this->isCrossBorder() && $this->destination_country === 'UZ';
    }

    public function isContainer(): bool
    {
        return in_array($this->load_type, ['fcl', 'lcl'], true) || $this->container_owner !== null;
    }
}
