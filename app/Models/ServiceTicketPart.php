<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spare part used on a service ticket (Spec 09): catalogue item or free description, quantity and
 * price (only billed outside warranty). Recorded, not reserved from stock.
 */
#[Fillable(['service_ticket_id', 'item_id', 'description', 'quantity', 'unit_price_minor', 'created_by'])]
class ServiceTicketPart extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
