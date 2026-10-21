<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\ChangeSaleType;
use App\Actions\Orders\UpdateOrderDetails;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 section 31: orders start on creation (no confirmation); details and the sale type stay
 * editable until packaging starts, and a sale type change regenerates the payment schedule.
 */
class StartOrderTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_order_starts_on_creation_and_stays_editable_until_packaging(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertGreaterThan(0, $order->scheduleItems()->whereNull('superseded_at')->count());

        app(UpdateOrderDetails::class)->handle($order, ['delivery_address' => 'Chilonzor 5']);
        $this->assertSame('Chilonzor 5', $order->fresh()->delivery_address);

        app(ChangeSaleType::class)->handle($order->fresh(), SaleType::Retail, $rep);
        $active = $order->scheduleItems()->whereNull('superseded_at')->get();
        $this->assertSame([10000], $active->pluck('percent_bp')->filter()->values()->all(), 'Retail: the schedule becomes 100 % prepayment.');
        $this->assertFalse($order->fresh()->agreement_required);

        $order->fresh()->forceFill(['started_at' => now()])->save();
        $this->expectExceptionObject(new OrderActionException('details_locked'));
        app(UpdateOrderDetails::class)->handle($order->fresh(), ['delivery_address' => 'Yunusobod 1']);
    }
}
