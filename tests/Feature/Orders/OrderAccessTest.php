<?php

namespace Tests\Feature\Orders;

use App\Enums\AgreementStatus;
use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Livewire\Agreements\Index as AgreementIndex;
use App\Livewire\Orders\Index as OrderIndex;
use App\Livewire\Orders\Show as OrderShow;
use App\Models\Agreement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 4.2/4.3 matrix (AC-39 subset for orders and agreements), IDOR on routes and prints,
 * order print in uz/ru/en (AC-43).
 */
class OrderAccessTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: bool}>
     */
    public static function roles(): array
    {
        // role => [sees others' orders, may confirm others', may prepare agreements, may approve agreements, may confirm signatures]
        return [
            'sales' => ['sales', false, false, false, false],
            'manager' => ['manager', true, true, true, true],
            'accountant' => ['accountant', true, false, false, true],
            'finance' => ['finance', true, false, true, true],
            'admin' => ['admin', false, false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix(string $role, bool $seesOthers, bool $confirmsOthers, bool $prepares, bool $confirmsSigned): void
    {
        $user = User::factory()->role(UserRole::from($role))->create();
        $order = Order::factory()->create();
        $agreement = Agreement::factory()->status(AgreementStatus::SentForSignature)->create(['customer_id' => $order->customer_id]);

        $this->assertSame($seesOthers, $user->can('view', $order));
        $this->assertSame($confirmsOthers, $user->can('confirm', $order));
        $this->assertSame($seesOthers, $user->can('view', $agreement));
        $this->assertSame($prepares || $role === 'accountant' || $role === 'finance', $user->can('create', Agreement::class));
        $this->assertSame($confirmsSigned, $user->can('confirmSigned', $agreement));
        // Spec 06 section 31: sales and managers may enter manual orders; nobody else (admin excluded).
        $this->assertSame(in_array($role, ['sales', 'manager'], true), $user->can('create', Order::class));
        $this->assertFalse($user->can('delete', $order));
    }

    public function test_sales_see_only_their_own_orders_and_get_403_on_others(): void
    {
        $rep = User::factory()->sales()->create();
        $mine = Order::factory()->ownedBy($rep)->create();
        $other = Order::factory()->create();

        Livewire::actingAs($rep)->test(OrderIndex::class)->assertSee($mine->number)->assertDontSee($other->number);
        $this->actingAs($rep)->get(route('orders.show', $mine))->assertOk();
        $this->actingAs($rep)->get(route('orders.show', $other))->assertForbidden();
        $this->actingAs($rep)->get(route('orders.print', $other))->assertForbidden();
    }

    public function test_sales_head_sees_the_department_and_logistics_sees_all(): void
    {
        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $colleague = User::factory()->staff()->inDepartment('sales')->create();
        $theirs = Order::factory()->ownedBy($colleague)->create();
        $outside = Order::factory()->create();
        $logistics = User::factory()->staff()->inDepartment('logistics')->create();

        $this->assertTrue($head->can('view', $theirs));
        $this->assertFalse($head->can('view', $outside));
        $this->assertTrue($logistics->can('view', $outside));
        $this->assertFalse($logistics->can('confirm', $outside));
    }

    public function test_agreement_list_is_scoped_like_the_policy(): void
    {
        $rep = User::factory()->sales()->create();
        $mine = Order::factory()->ownedBy($rep)->create();
        $own = Agreement::factory()->create(['customer_id' => $mine->customer_id, 'order_id' => $mine->id, 'kind' => 'order']);
        $foreign = Agreement::factory()->create();

        Livewire::actingAs($rep)->test(AgreementIndex::class)->assertSee($own->number)->assertDontSee($foreign->number);
        $this->actingAs($rep)->get(route('agreements.show', $foreign))->assertForbidden();
        $this->actingAs($rep)->get(route('agreements.print', $foreign))->assertForbidden();
    }

    public function test_order_prints_in_every_language(): void
    {
        $rep = User::factory()->sales()->create();
        ['order' => $order] = $this->acceptedFixtureOrder($rep);

        foreach (['uz' => 'Buyurtma tasdig‘i', 'ru' => 'Подтверждение заказа', 'en' => 'Order confirmation'] as $lang => $title) {
            $this->actingAs($rep)->get(route('orders.print', ['order' => $order, 'lang' => $lang]))
                ->assertOk()
                ->assertSee($title)
                ->assertSee('2C')
                ->assertSee('data-amount-in-words', false)
                ->assertDontSee('documents.', false);
        }
    }

    public function test_order_screen_renders_for_an_accountant_with_codes(): void
    {
        $rep = User::factory()->sales()->create();
        ['order' => $order] = $this->acceptedFixtureOrder($rep);
        $accountant = User::factory()->accountant()->create();

        Livewire::actingAs($accountant)->test(OrderShow::class, ['order' => $order])
            ->assertSee($order->number)
            ->assertDontSee('data-action="confirm"', false)
            ->set('showCodes', true)
            ->assertSee(__('orders.lines.class_code'))
            ->set('tab', 'agreement')
            ->assertSet('tab', 'other')
            ->assertSee('data-agreement-tab', false)
            ->assertSee(__('orders.history.from_offer', ['value' => $order->offer->displayNumber()]));
    }

    /** Standard document tabs (2026-10-20): summary above, Header | Items | Other. */
    public function test_order_detail_has_header_items_and_other_tabs(): void
    {
        $rep = User::factory()->sales()->create();
        ['order' => $order] = $this->acceptedFixtureOrder($rep);

        Livewire::actingAs($rep)->test(OrderShow::class, ['order' => $order])
            ->assertSet('tab', 'items')
            ->assertSeeHtml('data-document-summary')
            ->assertSeeHtml('data-order-flow')
            ->assertSeeHtml('data-order-lines')
            ->assertSeeHtml('data-tab-count')
            ->assertDontSeeHtml('data-order-header')
            ->set('tab', 'header')
            ->assertSeeHtml('data-order-header')
            ->assertSeeHtml('data-order-source')
            ->assertDontSeeHtml('data-order-lines')
            ->set('tab', 'other')
            ->assertSeeHtml('data-deliveries-tab')
            ->assertSeeHtml('data-agreement-tab')
            ->assertSeeHtml('data-timeline');

        foreach (['lines' => 'items', 'payments' => 'other', 'deliveries' => 'other', 'timeline' => 'other', 'x' => 'items'] as $old => $new) {
            Livewire::actingAs($rep)->withQueryParams(['tab' => $old])->test(OrderShow::class, ['order' => $order])->assertSet('tab', $new);
        }
    }
}
