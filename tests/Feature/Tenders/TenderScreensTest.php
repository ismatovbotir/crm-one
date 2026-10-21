<?php

namespace Tests\Feature\Tenders;

use App\Actions\Tenders\AwardTender;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\SubmitBid;
use App\Enums\CounterpartyStatus;
use App\Enums\DepartmentRole;
use App\Enums\OrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Livewire\Counterparties\Index as CounterpartyIndex;
use App\Livewire\Counterparties\Show as CounterpartyShow;
use App\Livewire\Orders\Show as OrderShow;
use App\Livewire\PurchaseOrders\Show as PurchaseOrderShow;
use App\Livewire\Tenders\BidBoard;
use App\Livewire\Tenders\Comparison;
use App\Livewire\Tenders\Evaluation;
use App\Livewire\Tenders\Index;
use App\Livewire\Tenders\LotEditor;
use App\Livewire\Tenders\Show;
use App\Models\Counterparty;
use App\Models\PurchaseOrder;
use App\Models\Tender;
use App\Models\TenderBidLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\BuildsTenders;
use Tests\TestCase;

/**
 * Screens of the tender engine (Spec 03 section 9): lists and access per department, the wizard,
 * the sealed bid board (AC-21 in HTML), evaluation and award through the components, the notice
 * print (AC-27, AC-28), counterparties, the transport request from an order, purchase orders.
 */
class TenderScreensTest extends TestCase
{
    use BuildsOrders, BuildsTenders, RefreshDatabase;

    /** AC-15 */
    public function test_lists_follow_department_rights(): void
    {
        $logistics = $this->logistics();
        $this->actingAs($logistics)->get(route('tenders.transport.index'))->assertOk()->assertSee(__('nav.tenders_transport'));
        $this->get(route('tenders.purchase.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertSee(route('tenders.transport.index'), false)->assertDontSee(route('tenders.purchase.index'), false);

        Livewire::test(Index::class, ['type' => 'transport'])
            ->set('creating', true)
            ->set('form.title', 'Shanghai → Tashkent rail')
            ->call('create')
            ->assertRedirect();
        $tender = Tender::query()->sole();
        $this->assertSame($logistics->id, $tender->owner_id);

        $this->assertFalse($logistics->can('create', [Tender::class, 'purchase']));
        $this->actingAs($this->purchaser())->get(route('tenders.purchase.index'))->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get(route('tenders.show', $tender))->assertForbidden();
    }

    public function test_sales_raises_a_transport_request_and_logistics_takes_it(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $order->forceFill(['status' => OrderStatus::Confirmed, 'delivery_address' => 'Samarkand, depot 4'])->save();

        Livewire::actingAs($rep)->test(OrderShow::class, ['order' => $order])->call('requestTransport')->assertRedirect();
        $tender = Tender::query()->sole();
        $this->assertNull($tender->owner_id);
        $this->assertSame($rep->id, $tender->requested_by);
        $this->assertSame('Samarkand, depot 4', $tender->lots()->first()->transportSpec->destination_address);
        $this->assertSame('order', $tender->sources()->value('source_type'));

        $this->actingAs($rep)->get(route('tenders.show', $tender))->assertOk()->assertDontSee('data-action="take"', false);

        $logistics = $this->logistics();
        Livewire::actingAs($logistics)->test(Show::class, ['tender' => $tender])->call('takeOwnership')->assertHasNoErrors();
        $this->assertSame($logistics->id, $tender->fresh()->owner_id);
        $this->actingAs($rep)->get(route('tenders.show', $tender))->assertOk();
    }

    public function test_wizard_saves_header_spec_and_shows_the_completeness_panel(): void
    {
        $owner = $this->logistics();
        $tender = app(ManageTender::class)->create(TenderType::Transport, ['title' => 'Draft'], $owner);

        Livewire::actingAs($owner)->test(Show::class, ['tender' => $tender])
            ->assertSee(__('tenders.completeness.title'))
            ->assertSeeHtml('data-issue="R"')
            ->set('header.payment_terms', '100% after delivery')
            ->set('header.budget', '50000000')
            ->set('header.bids_due_at', now()->addDays(10)->format('Y-m-d\TH:i'))
            ->set('header.clarification_deadline_at', now()->addDays(6)->format('Y-m-d\TH:i'))
            ->call('saveHeader')
            ->assertHasNoErrors();

        $tender->refresh();
        $this->assertSame(5_000_000_000, $tender->budget_minor);
        $this->assertSame('100% after delivery', $tender->payment_terms);

        Livewire::actingAs($owner)->test(LotEditor::class, ['tenderId' => $tender->id])
            ->set('spec.origin_country', 'tr')
            ->set('spec.hs_codes', '851762, 8471300000')
            ->set('spec.cargo_value', '50000')
            ->set('spec.cargo_currency', 'USD')
            ->set('spec.transshipment_allowed', '0')
            ->call('saveSpec')
            ->assertHasNoErrors();

        $spec = $tender->lots()->first()->transportSpec;
        $this->assertSame(['TR', ['851762', '8471300000'], 5_000_000, false], [$spec->origin_country, $spec->hs_codes, $spec->cargo_value_minor, $spec->transshipment_allowed]);

        $this->actingAs($this->logistics())->get(route('tenders.show', $tender))->assertOk()->assertDontSee('data-action="save-header"', false);
    }

    /** AC-21 at the HTML level; AC-27, AC-28 print. */
    public function test_bid_board_is_sealed_and_the_notice_hides_internal_fields(): void
    {
        $owner = $this->logistics();
        $enterer = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $tender = $this->transportTender($owner, ['budget_minor' => 123_456_700, 'budget_currency' => 'UZS', 'incumbent_rate_minor' => 98_765_400]);
        $tender = $this->publish($tender, $owner, $head);
        $invitation = $tender->invitations()->first();
        $invitation->counterparty->update(['preferred_locale' => 'ru']);
        $this->transportBid($tender, $invitation, $enterer, '27182818');

        Livewire::actingAs($head)->test(BidBoard::class, ['tenderId' => $tender->id])
            ->assertSee($invitation->counterparty->name)
            ->assertDontSee('27 182 818')->assertDontSee('27,182,818')->assertDontSeeHtml('data-bid-content');
        Livewire::actingAs($enterer)->test(BidBoard::class, ['tenderId' => $tender->id])->assertSeeHtml('data-bid-content');

        $notice = $this->actingAs($owner)->get(route('tenders.notice', ['tender' => $tender, 'invitation' => $invitation->id]))->assertOk();
        $notice->assertSee('Извещение о тендере')->assertSee('UTC+5')->assertSee($invitation->counterparty->name);
        $notice->assertDontSee('1 234 567')->assertDontSee('987 654')->assertDontSee('Mehmet');

        $this->closeAfterDeadline($tender);
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(BidBoard::class, ['tenderId' => $tender->id])->assertDontSeeHtml('data-bid-content');
        Livewire::actingAs($head)->test(BidBoard::class, ['tenderId' => $tender->id])->assertSeeHtml('data-bid-content');
    }

    public function test_evaluation_and_award_through_the_components(): void
    {
        Storage::fake('local');
        $owner = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $tender = $this->publish($this->transportTender($owner), $owner, $head);
        [$a, $b] = $tender->invitations()->get()->all();
        $this->transportBid($tender, $a, $owner, '1000', 'USD');
        $this->transportBid($tender, $b, $owner, '15000000');
        $tender = $this->closeAfterDeadline($tender, null);

        $finance = User::factory()->finance()->create();
        Livewire::actingAs($finance)->test(Evaluation::class, ['tenderId' => $tender->id])
            ->assertSee(__('tenders.problems.fx_missing', ['currency' => 'USD']))
            ->set('fx.USD', '12650')
            ->call('saveFx')
            ->assertHasNoErrors();
        $this->assertSame('12650.000000', $tender->fresh()->fx_rates['USD']['rate']);

        $component = Livewire::actingAs($owner)->test(Evaluation::class, ['tenderId' => $tender->id]);

        foreach (TenderBidLot::query()->get() as $bidLot) {
            $criterion = $tender->criteria()->where('method', 'manual')->value('id');
            $component->set('scores.'.$bidLot->id.':'.$criterion, '8')->call('saveScore', $bidLot->id, $criterion);
        }

        $component->call('propose')->assertHasNoErrors();
        $this->assertSame(TenderStatus::AwardPendingApproval, $tender->fresh()->status);
        $award = $tender->awards()->sole();

        $this->actingAs($head)->get(route('approvals.index'))->assertOk()->assertSee($tender->number);
        Livewire::actingAs($head)->test(Comparison::class, ['tender' => $tender])->set('reviewed', true)->set('password', 'password')->call('decide', $award->id, true)->assertHasNoErrors();
        $this->assertSame(TenderStatus::Awarded, $tender->fresh()->status);

        Livewire::actingAs($owner)->test(Evaluation::class, ['tenderId' => $tender->id])
            ->set('acceptanceNotes.'.$award->id, 'Confirmed by phone with the dispatcher at 11:00')
            ->call('recordAcceptance', $award->id, true);
        $this->assertSame(TenderStatus::Contracted, $tender->fresh()->status);
    }

    public function test_counterparty_screens(): void
    {
        Storage::fake('local');
        $user = $this->logistics(DepartmentRole::Head);

        Livewire::actingAs($user)->test(CounterpartyIndex::class)
            ->set('form.name', 'Silk Road Logistics')
            ->set('form.is_carrier', true)
            ->call('create')
            ->assertRedirect();
        $carrier = Counterparty::query()->sole();
        $this->assertSame(CounterpartyStatus::Prospect, $carrier->status);

        Livewire::actingAs($user)->test(CounterpartyShow::class, ['counterparty' => $carrier])
            ->set('contact.name', 'Aziz')
            ->set('contact.email', 'aziz@example.com')
            ->call('saveContact')
            ->set('document.type', 'cmr_insurance')
            ->set('document.valid_until', now()->addYear()->toDateString())
            ->set('documentFile', UploadedFile::fake()->createWithContent('cmr.pdf', "%PDF-1.4\n%cmr\n"))
            ->call('addDocument')
            ->assertHasNoErrors();

        $document = $carrier->documents()->sole();
        $this->assertNotNull($document->attachment);

        Livewire::actingAs($user)->test(CounterpartyShow::class, ['counterparty' => $carrier])
            ->call('verifyDocument', $document->id)
            ->set('statusTarget', 'qualified')
            ->call('changeStatus');
        $this->assertSame(CounterpartyStatus::Qualified, $carrier->fresh()->status);
        $this->actingAs($user)->get(route('attachments.download', $document->attachment))->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get(route('counterparties.index'))->assertForbidden();
    }

    public function test_purchase_order_screen_confirms_and_prints(): void
    {
        $owner = $this->purchaser();
        $tender = $this->publish($this->purchaseTender($owner), $owner, $this->purchaser(DepartmentRole::Head));
        $item = $tender->lots()->first()->items()->first();
        app(SubmitBid::class)->handle($tender->invitations()->first(), [
            'currency' => 'UZS', 'valid_until' => now()->addDays(90)->toDateString(), 'conditions_accepted' => true, 'payment_days' => 30,
            'lots' => [$item->lot_id => ['lead_time_days' => 10, 'lines' => [$item->id => ['unit_price' => '100000']]]],
            'source' => ['received_via' => 'phone', 'received_at' => now()->subMinute(), 'sender_name' => 'Supplier', 'note' => 'Price confirmed by phone call today.'],
        ], $owner);
        $tender = $this->closeAfterDeadline($tender);
        app(AwardTender::class)->propose($tender, [$item->lot_id => ['bid_lot_id' => TenderBidLot::query()->value('id')]], $owner);
        app(AwardTender::class)->decide($tender->awards()->sole(), $this->ceo(), true, null);
        app(AwardTender::class)->recordAcceptance($tender->awards()->sole(), true, 'Supplier confirmed the order by e-mail.', $owner);
        $po = PurchaseOrder::query()->sole();

        Livewire::actingAs($owner)->test(PurchaseOrderShow::class, ['purchaseOrder' => $po])
            ->set('onecNumber', 'ZP-000123')
            ->call('saveOnecNumber')
            ->call('confirm');
        $this->assertSame([PurchaseOrderStatus::Confirmed, 'ZP-000123'], [$po->fresh()->status, $po->fresh()->onec_number]);
        $this->actingAs($owner)->get(route('purchase-orders.print', ['purchaseOrder' => $po, 'lang' => 'uz']))->assertOk()->assertSee('Yetkazib beruvchiga buyurtma');
        $this->actingAs(User::factory()->sales()->create())->get(route('purchase-orders.show', $po))->assertForbidden();
    }
}
