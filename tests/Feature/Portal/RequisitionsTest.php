<?php

namespace Tests\Feature\Portal;

use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Requisitions\ManageRequisition;
use App\Enums\RequisitionStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Portal\Catalog as PortalCatalogPage;
use App\Livewire\Portal\Requisitions as PortalRequisitions;
use App\Livewire\Portal\RequisitionShow;
use App\Models\CatalogVisibilityRule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\RequisitionUpdated;
use App\Services\Requisitions\Exceptions\RequisitionException;
use App\Services\Requisitions\PortalCatalog;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 10 acceptance criteria: price-free portal catalog with visibility rules, tenant isolation,
 * requester vs purchase-manager roles, the client-side flow (submit, return, approve, send),
 * merge/split, our side (map category lines, create offer) and offer-driven statuses.
 */
class RequisitionsTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $rep;

    private Customer $customer;

    private Category $printers;

    private Category $scales;

    private Item $printer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
        $this->customer = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS', 'portal_enabled' => true, 'name' => 'Client A']);
        $this->printers = Category::factory()->create(['name' => 'Printers']);
        $this->scales = Category::factory()->create(['name' => 'Scales']);
        $this->printer = Item::factory()->priced('9876543.21', 'UZS', false, '7654321.09')->create(['name' => 'Laser printer L1', 'category_id' => $this->printers->id, 'tax_rate_bp' => 1200]);
        Item::factory()->priced('1000.00')->create(['name' => 'Bench scale B2', 'category_id' => $this->scales->id]);
        ItemStock::factory()->create(['item_id' => $this->printer->id, 'warehouse_id' => Warehouse::factory()->create()->id, 'quantity' => '7', 'reserved' => '0', 'cost_minor' => 555444333, 'cost_currency' => 'UZS']);
    }

    private function portalUser(array $roles, string $email, ?Customer $customer = null): User
    {
        $customer ??= $this->customer;
        $customer->forceFill(['portal_enabled' => true])->save();

        return app(ManagePortalAccess::class)->grant($customer, $email, ucfirst(explode('@', $email)[0]), $roles, null, null, $this->rep)->user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user, 'portal');
        Auth::shouldUse('web');
        app(PortalContext::class)->set($user, $user->activeMembership());
    }

    private function assertNoPrices(string $content): void
    {
        foreach (['9876543', '987654321', '7654321', '765432109', '555444333', '5554443', 'sale_price', 'purchase_price', 'cost_minor', 'cost_currency'] as $needle) {
            $this->assertStringNotContainsString($needle, $content, "Price data leaked: {$needle}");
        }
    }

    /** AC-1 */
    public function test_catalog_and_requests_never_expose_prices(): void
    {
        Notification::fake();
        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $manager = $this->portalUser(['purchase_manager'], 'pm@client.uz');
        $this->actAs($requester);

        $catalog = $this->get(route('portal.catalog'))->assertOk()->assertSee('Laser printer L1')->assertSee(__('requisitions.availability.in_stock'));
        $this->assertNoPrices($catalog->getContent());

        Livewire::test(PortalCatalogPage::class)->set('quantities.'.$this->printer->id, '3')->call('addItem', $this->printer->id)->assertHasNoErrors();
        $requisition = Requisition::query()->sole();
        $this->assertSame('3.000', (string) $requisition->lines()->sole()->quantity);
        $show = $this->get(route('portal.requisitions.show', $requisition->uuid))->assertOk()->assertSee('Laser printer L1');
        $this->assertNoPrices($show->getContent());

        app(ManageRequisition::class)->submit($requisition, $requester);
        Notification::assertSentTo($manager, RequisitionUpdated::class, function (RequisitionUpdated $notification) use ($manager): bool {
            $this->assertNoPrices((string) json_encode($notification->toArray($manager)).$notification->toTelegram($manager));

            return true;
        });

        $this->assertSame([], array_intersect(PortalCatalog::ITEM_COLUMNS, ['sale_price_minor', 'purchase_price_minor']));
    }

    /** AC-2 */
    public function test_global_hiding_and_per_customer_rules_apply(): void
    {
        $catalog = app(PortalCatalog::class);
        $names = fn (): array => $catalog->items($this->customer)->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Bench scale B2', 'Laser printer L1'], $names());

        $this->scales->forceFill(['portal_visible' => false])->save();
        $this->assertSame(['Laser printer L1'], $names());

        CatalogVisibilityRule::query()->create(['partner_id' => $this->customer->id, 'category_id' => $this->scales->id, 'mode' => 'allow']);
        $this->assertSame(['Bench scale B2'], $names(), 'Allow overrides the global hide and switches to allow-list mode.');

        CatalogVisibilityRule::query()->delete();
        CatalogVisibilityRule::query()->create(['partner_id' => $this->customer->id, 'item_id' => $this->printer->id, 'mode' => 'deny']);
        $this->assertSame([], $names());

        $other = Customer::factory()->ownedBy($this->rep)->create();
        $this->assertSame(['Laser printer L1'], $catalog->items($other)->pluck('name')->all(), 'Rules are per customer.');

        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $this->expectException(RequisitionException::class);
        app(ManageRequisition::class)->addItemLine(app(ManageRequisition::class)->create($this->customer, $requester), $this->printer, '1', null, $requester);
    }

    /** AC-3, AC-4, AC-5, AC-8 */
    public function test_client_flow_roles_and_tenant_isolation(): void
    {
        Notification::fake();
        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $manager = $this->portalUser(['purchase_manager'], 'pm@client.uz');
        $viewer = $this->portalUser(['viewer'], 'viewer@client.uz');
        $manage = app(ManageRequisition::class);
        $requisition = $manage->create($this->customer, $requester, ['title' => 'Office printers']);
        $manage->addItemLine($requisition, $this->printer, '2', null, $requester);
        $manage->addCategoryLine($requisition, $this->scales, 'Scale up to 30 kg', '1', 'pcs', null, $requester);

        $this->actAs($viewer);
        Livewire::test(PortalRequisitions::class)->call('create')->assertForbidden();
        $this->get(route('portal.requisitions.show', $requisition->uuid))->assertNotFound();

        $this->actAs($requester);
        Livewire::test(RequisitionShow::class, ['requisition' => $requisition->uuid])->call('approve')->assertForbidden();
        Livewire::test(RequisitionShow::class, ['requisition' => $requisition->uuid])->call('submit')->assertHasNoErrors();
        $this->assertSame(RequisitionStatus::Submitted, $requisition->fresh()->status);
        Notification::assertSentTo($manager, RequisitionUpdated::class);

        $this->actAs($manager);
        Livewire::test(RequisitionShow::class, ['requisition' => $requisition->uuid])->set('decisionText', 'Please add the delivery address')->call('returnToRequester')->assertHasNoErrors();
        $this->assertSame(RequisitionStatus::Returned, $requisition->fresh()->status);
        Notification::assertSentTo($requester, RequisitionUpdated::class);

        $this->actAs($requester);
        Livewire::test(RequisitionShow::class, ['requisition' => $requisition->uuid])->set('header.delivery_location', 'Tashkent, Amir Temur 1')->call('saveHeader')->call('submit');
        $this->actAs($manager);
        Livewire::test(RequisitionShow::class, ['requisition' => $requisition->uuid])->set('quantities.'.$requisition->lines()->first()->id, '4')->call('saveQuantity', $requisition->lines()->first()->id)->call('approve')->call('send')->assertHasNoErrors();

        $fresh = $requisition->fresh();
        $this->assertSame(RequisitionStatus::Received, $fresh->status);
        $this->assertSame($this->rep->id, $fresh->owner_id);
        $this->assertSame('4.000', (string) $fresh->lines()->first()->quantity);
        Notification::assertSentTo($this->rep, RequisitionUpdated::class);
        $this->get(route('portal.requisitions.show', $requisition->uuid))->assertSee(__('requisitions.statuses_portal.received'));

        // Company B never sees company A's request.
        $companyB = Customer::factory()->ownedBy($this->rep)->create(['name' => 'Company B']);
        $outsider = $this->portalUser(['purchase_manager', 'portal_admin'], 'pm@b.uz', $companyB);
        $this->actAs($outsider);
        $this->get(route('portal.requisitions.show', $requisition->uuid))->assertNotFound();
        $this->get(route('portal.requisitions'))->assertOk()->assertDontSee($requisition->number);

    }

    public function test_our_side_sees_only_sent_requests_within_owner_scope(): void
    {
        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $manager = $this->portalUser(['purchase_manager'], 'pm@client.uz');
        $manage = app(ManageRequisition::class);
        $draft = $manage->create($this->customer, $requester, ['title' => 'Still internal']);
        $manage->addItemLine($draft, $this->printer, '1', null, $requester);
        $sent = $manage->create($this->customer, $requester, ['title' => 'Sent one']);
        $manage->addItemLine($sent, $this->printer, '1', null, $requester);
        $manage->submit($sent, $requester);
        $manage->sendToSupplier($sent->fresh(), $manager, false);

        $this->actingAs($this->rep)->get(route('requisitions.index'))->assertOk()->assertSee($sent->number)->assertDontSee($draft->number);
        $this->actingAs($this->rep)->get(route('requisitions.show', $sent))->assertOk()->assertSee('Sent one');
        $this->actingAs($this->rep)->get(route('requisitions.show', $draft))->assertForbidden();
        $this->actingAs(User::factory()->sales()->create())->get(route('requisitions.show', $sent))->assertForbidden();
        $this->assertFalse(User::factory()->admin()->create()->can('manage', $sent->fresh()));
    }

    /** AC-6 */
    public function test_merge_sums_items_and_split_moves_lines(): void
    {
        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $manager = $this->portalUser(['purchase_manager'], 'pm@client.uz');
        $manage = app(ManageRequisition::class);
        $first = $manage->create($this->customer, $requester);
        $manage->addItemLine($first, $this->printer, '2', null, $requester);
        $manage->submit($first, $requester);
        $second = $manage->create($this->customer, $requester);
        $manage->addItemLine($second, $this->printer, '3', null, $requester);
        $manage->addCategoryLine($second, $this->scales, 'Kitchen scale', '1', null, null, $requester);
        $manage->submit($second, $requester);

        $merged = $manage->merge(Requisition::query()->whereKey([$first->id, $second->id])->get(), $manager);
        $this->assertSame(RequisitionStatus::Approved, $merged->status);
        $this->assertSame(['5.000', '1.000'], $merged->lines()->pluck('quantity')->map(fn ($q) => (string) $q)->all());
        $this->assertSame(RequisitionStatus::Merged, $first->fresh()->status);
        $this->assertSame($merged->id, $second->fresh()->merged_into_id);

        $split = $manage->split($merged, [$merged->lines()->where('kind', 'category')->value('id')], $manager);
        $this->assertSame(1, $merged->lines()->count());
        $this->assertSame(1, $split->lines()->count());
        $this->assertSame($merged->id, $split->split_from_id);
        $this->assertSame(RequisitionStatus::Approved, $split->status);
    }

    /** AC-7 */
    public function test_offer_from_request_requires_mapping_and_drives_the_status(): void
    {
        Notification::fake();
        $requester = $this->portalUser(['requester'], 'req@client.uz');
        $manager = $this->portalUser(['purchase_manager'], 'pm@client.uz');
        $manage = app(ManageRequisition::class);
        $requisition = $manage->create($this->customer, $requester);
        $manage->addItemLine($requisition, $this->printer, '2', null, $requester);
        $categoryLine = $manage->addCategoryLine($requisition, $this->scales, 'Scale up to 30 kg', '1', null, null, $requester);
        $manage->submit($requisition, $requester);
        $manage->sendToSupplier($requisition->fresh(), $manager, false);

        try {
            $manage->createOffer($requisition->fresh(), $this->rep);
            $this->fail('Unmapped category lines block the offer.');
        } catch (RequisitionException $exception) {
            $this->assertSame('unmapped_lines', $exception->key);
        }

        $manage->mapLine($categoryLine, Item::query()->where('name', 'Bench scale B2')->sole(), $this->rep);
        $offer = $manage->createOffer($requisition->fresh(), $this->rep);

        $this->assertSame($requisition->id, $offer->requisition_id);
        $this->assertSame(RequisitionStatus::Quoting, $requisition->fresh()->status);
        $this->assertSame(2, $offer->lines()->count());
        $this->assertSame(987654321, (int) $offer->lines()->where('item_id', $this->printer->id)->value('unit_price_minor'));

        app(SendOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email);
        $this->assertSame(RequisitionStatus::Offered, $requisition->fresh()->status);
        Notification::assertSentTo($requester, RequisitionUpdated::class, fn (RequisitionUpdated $n): bool => $n->key === 'requisition_offered');

        app(AcceptOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email, 'ok');
        $this->assertSame(RequisitionStatus::Ordered, $requisition->fresh()->status);
    }
}
