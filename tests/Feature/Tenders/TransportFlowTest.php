<?php

namespace Tests\Feature\Tenders;

use App\Actions\Tenders\AwardTender;
use App\Actions\Tenders\CreateTransportTenderFromPurchaseOrder;
use App\Actions\Tenders\EvaluateTender;
use App\Actions\Tenders\ManagePurchaseOrder;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\SubmitBid;
use App\Enums\BidChannel;
use App\Enums\DepartmentRole;
use App\Enums\InvitationStatus;
use App\Enums\TenderStatus;
use App\Http\Controllers\TenderPrintController;
use App\Livewire\BidLink\Page;
use App\Livewire\Tenders\Comparison;
use App\Models\ActivityLog;
use App\Models\Counterparty;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderBidLot;
use App\Models\TenderComparison;
use App\Models\TenderDecision;
use App\Models\TenderInvitationLink;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\BidLinks;
use App\Services\Tenders\ComparisonBuilder;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\Exceptions\TenderActionException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\BuildsTenders;
use Tests\TestCase;

/**
 * Spec 08: purchase order → transport tender prefill (AC-1..AC-3), unique bid links (AC-8..AC-16),
 * normalisation and the frozen comparison (AC-17..AC-21), CEO/L1 decision with step-up (AC-22, AC-23).
 */
class TransportFlowTest extends TestCase
{
    use BuildsTenders, RefreshDatabase;

    private function confirmedPo(User $buyer, string $incoterm = 'FCA'): PurchaseOrder
    {
        $manage = app(ManagePurchaseOrder::class);
        $supplier = Counterparty::factory()->supplier()->create(['name' => 'Istanbul Network Ltd']);
        $po = $manage->create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'incoterm' => $incoterm, 'incoterm_place' => 'Istanbul'], $buyer);
        $item = Item::factory()->create(['name' => 'Switch 48p', 'hs_code' => '8517620009', 'gross_weight_kg' => '12.5', 'net_weight_kg' => '11', 'volume_m3' => '0.08']);
        $manage->saveLine($po, null, ['item_id' => $item->id, 'quantity' => '100', 'unit_price' => '350']);
        $manage->saveLine($po, null, ['name' => 'Rack cabinets', 'quantity' => '4', 'unit_price' => '900', 'hs_code' => '9403200009', 'gross_weight_kg' => '480', 'volume_m3' => '6', 'packages_count' => '4']);
        $manage->update($po->fresh(), [
            'destination_warehouse_id' => Warehouse::factory()->create(['name' => 'Tashkent main'])->id,
            'readiness_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
            'required_delivery_date' => CarbonImmutable::now()->addDays(30)->toDateString(),
            'pickup_country' => 'TR', 'pickup_city' => 'Istanbul', 'pickup_address' => 'Hadımköy 12',
            'documents_available' => ['invoice', 'packing_list', 'origin_certificate'],
        ]);

        return $manage->confirm($po->fresh(), $buyer);
    }

    /** AC-1, AC-2, AC-3 */
    public function test_purchase_order_prefills_one_transport_tender(): void
    {
        $buyer = $this->purchaser();
        $po = $this->confirmedPo($buyer);
        $this->assertSame(3_860_000, $po->subtotal_minor, 'USD 35 000 + 3 600 = 38 600.00');
        $this->assertSame(['master', 'packing_list'], $po->lines->pluck('weight_source')->all());
        $this->assertSame('1250.000', (string) $po->lines->first()->gross_weight_kg, 'Item master 12.5 kg × 100.');

        $this->assertTrue($buyer->can('createTransportTender', $po));
        $tender = app(CreateTransportTenderFromPurchaseOrder::class)->handle($po, $buyer);
        $spec = $tender->lots()->first()->transportSpec;

        $this->assertNull($tender->owner_id, 'Raised by purchase: a request logistics takes over.');
        $this->assertSame(['TR', 'Istanbul', 'Hadımköy 12', 'UZ'], [$spec->origin_country, $spec->origin_city, $spec->origin_address, $spec->destination_country]);
        $this->assertSame(['8517620009', '9403200009'], $spec->hs_codes);
        $this->assertSame(['1730.000', '14.000'], [(string) $spec->gross_kg, (string) $spec->volume_m3], 'Lines: 1250 + 480 kg; 8 + 6 m³.');
        $this->assertSame([3_860_000, 'USD', 'FCA', 'Istanbul'], [$spec->cargo_value_minor, $spec->cargo_currency, $spec->incoterm, $spec->incoterm_place]);
        $this->assertSame($po->readiness_date->toDateString(), $spec->pickup_from->toDateString());
        $this->assertSame($po->readiness_date->addDays(3)->toDateString(), $spec->pickup_to->toDateString());
        $this->assertSame(['invoice', 'packing_list', 'origin_certificate'], $spec->required_documents);
        $this->assertTrue($tender->sources()->where('source_type', 'purchase_order')->where('source_id', $po->id)->exists());
        $this->assertTrue($buyer->can('view', $tender), 'Purchase sees tenders sourced from own POs.');

        $logistics = $this->logistics();
        app(ManageTender::class)->takeOwnership($tender, $logistics);
        $codes = collect(app(CompletenessChecker::class)->check($tender->fresh()))->where('level', 'warning')->pluck('code')->all();
        $this->assertContains('N25', $codes);
        $this->assertContains('N27', $codes);

        try {
            app(CreateTransportTenderFromPurchaseOrder::class)->handle($po->fresh(), $buyer);
            $this->fail('One active tender per PO.');
        } catch (TenderActionException $exception) {
            $this->assertSame('po_tender_exists', $exception->key);
        }

        $ddp = $this->confirmedPo($buyer, 'DDP');
        $this->assertFalse($buyer->can('createTransportTender', $ddp));
    }

    public function test_confirm_needs_pickup_when_the_buyer_has_a_leg(): void
    {
        $buyer = $this->purchaser();
        $manage = app(ManagePurchaseOrder::class);
        $po = $manage->create(['supplier_id' => Counterparty::factory()->supplier()->create()->id, 'currency' => 'USD', 'incoterm' => 'EXW', 'incoterm_place' => 'Shenzhen', 'destination_warehouse_id' => Warehouse::factory()->create()->id], $buyer);
        $manage->saveLine($po, null, ['name' => 'Cable', 'quantity' => '10', 'unit_price' => '1.5']);

        $this->expectExceptionObject(new TenderActionException('po_pickup_missing'));
        $manage->confirm($po->fresh(), $buyer);
    }

    /** AC-8, AC-9, AC-10, AC-11, AC-13, AC-14, AC-15, AC-16 */
    public function test_bid_link_lifecycle(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        [$a, $b] = $tender->invitations()->with('counterparty')->get()->all();
        $this->transportBid($tender, $b, $owner, '987654');

        $first = app(BidLinks::class)->issue($a, $owner, 'copy');
        $this->assertSame(43, strlen($first['token']));
        $this->assertSame(hash('sha256', $first['token']), $first['link']->token_hash);
        $this->assertFalse(ActivityLog::query()->get()->contains(fn ($log) => str_contains((string) json_encode($log->properties), $first['token'])));

        $second = app(BidLinks::class)->issue($a->fresh(), $owner, 'email');
        $this->assertSame(1, TenderInvitationLink::query()->where('tender_invitation_id', $a->id)->whereNull('revoked_at')->count());
        $this->get(route('bid.show', ['token' => $first['token']]))->assertNotFound();
        $this->get(route('bid.show', ['token' => str_repeat('x', 43)]))->assertNotFound();

        $mail = collect(app('mailer')->getSymfonyTransport()->messages())->last();
        $this->assertStringContainsString(route('bid.show', ['token' => $second['token']]), (string) $mail->getOriginalMessage()->getHtmlBody());

        $response = $this->get(route('bid.show', ['token' => $second['token']]))->assertOk();
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertSee($tender->number)->assertDontSee($b->counterparty->name)->assertDontSee('987 654');
        $this->assertSame(InvitationStatus::Opened, $a->fresh()->status);

        $page = Livewire::withQueryParams([])->test(Page::class, ['token' => $second['token']]);
        $page->set('form.lots.'.$tender->lots()->first()->id.'.lines.freight.amount', '1500')
            ->set('form.lots.'.$tender->lots()->first()->id.'.transit_days', '9')
            ->set('form.lots.'.$tender->lots()->first()->id.'.downtime_rate', '100')
            ->set('form.currency', 'USD')
            ->set('form.conditions_accepted', true)
            ->call('submit');
        $this->assertSame(0, TenderBid::query()->where('invitation_id', $a->id)->count(), 'Verification first (AC-11).');

        $page->call('sendCode');
        preg_match('/\b(\d{6})\b/', strip_tags((string) collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage()->getHtmlBody()), $match);
        $page->set('code', $match[1] === '000000' ? '111111' : '000000')->call('verify')->assertHasErrors('code');
        $page->set('code', $match[1])->call('verify')->assertHasNoErrors();
        $page->call('submit')->assertHasNoErrors();

        $bid = TenderBid::query()->where('invitation_id', $a->id)->sole();
        $this->assertSame([BidChannel::Link, null, $second['link']->id], [$bid->channel, $bid->entered_by, $bid->link_id]);
        $this->assertSame(150_000, $bid->lots->first()->total_minor, 'Server recomputed totals.');

        // AC-15: the same submission id never creates a second version.
        $again = app(SubmitBid::class)->fromLink($second['link']->fresh(), ['currency' => 'USD', 'valid_until' => now()->addDays(60)->toDateString(), 'conditions_accepted' => true, 'lots' => [$tender->lots()->first()->id => ['transit_days' => 9, 'downtime_rate' => '1', 'lines' => ['freight' => ['inclusion' => 'included', 'amount' => '1']]]]], $bid->submission_uuid);
        $this->assertSame($bid->id, $again->id);

        // After the deadline: read-only during the grace period, then 404 (AC-12).
        $this->travelTo($tender->fresh()->bids_due_at->addMinute());
        $this->get(route('bid.show', ['token' => $second['token']]))->assertOk()->assertSee(__('bid_link.closed'));
        $this->travelTo($tender->fresh()->bids_due_at->addHours(73));
        $this->get(route('bid.show', ['token' => $second['token']]))->assertNotFound();
    }

    public function test_a_carrier_declines_through_its_link(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner, ['link_verification_required' => false]), $owner, $this->logistics(DepartmentRole::Head));
        $invitation = $tender->invitations()->first();
        $token = app(BidLinks::class)->issue($invitation, $owner, 'copy')['token'];

        Livewire::test(Page::class, ['token' => $token])->set('declineReason', 'No trucks that week')->call('decline');
        $this->assertSame(InvitationStatus::Declined, $invitation->fresh()->status);
    }

    /** AC-17 (seller-scope), AC-18 (determinism, immutability), AC-21, AC-22, AC-23, AC-20 (CSV, print) */
    public function test_comparison_snapshot_and_decision_with_step_up(): void
    {
        $owner = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $tender = $this->transportTender($owner);
        $spec = $tender->lots()->first()->transportSpec;
        app(ManageTender::class)->updateTransportSpec($tender->lots()->first(), ['charges_payer' => [...$spec->charges_payer, 'brokerage' => 'seller']]);
        $tender = $this->publish($tender, $owner, $head);
        [$a, $b] = $tender->invitations()->with('counterparty')->get()->all();
        $a->counterparty->update(['name' => '=cmd()']);
        $this->transportBid($tender, $a, $owner, '20000000', extraCharges: ['customs_brokerage' => ['inclusion' => 'included', 'amount' => '3000000']]);
        $this->transportBid($tender, $b, $owner, '22000000');

        $this->actingAs($head)->get(route('tenders.comparison', $tender))->assertForbidden();
        $tender = $this->closeAfterDeadline($tender);
        $this->scoreManualCriterion($tender, $owner);

        $rowA = TenderBidLot::query()->whereHas('bid', fn ($q) => $q->where('invitation_id', $a->id))->sole();
        $this->assertSame('seller_scope', collect($rowA->breakdown['components'])->firstWhere('code', 'customs_brokerage')['kind']);

        $built = app(ComparisonBuilder::class)->build($tender->fresh());
        $this->assertSame($built['hash'], app(ComparisonBuilder::class)->build($tender->fresh())['hash'], 'Deterministic (BR-E.2).');
        $this->assertContains('F13', collect($built['core']['lots'][0]['rows'])->firstWhere('counterparty', '=cmd()')['flags']);
        $this->assertContains('F9', $built['core']['lots'][0]['rows'][0]['flags']);

        app(AwardTender::class)->propose($tender->fresh(), [$tender->lots()->first()->id => ['bid_lot_id' => TenderBidLot::query()->where('rank', 1)->value('id')]], $owner);
        $snapshot = TenderComparison::query()->sole();
        $this->assertSame(TenderComparison::STATUS_SUBMITTED, $snapshot->status);
        $this->assertSame('l1', $tender->fresh()->decision_level);

        $component = Livewire::actingAs($head)->test(Comparison::class, ['tender' => $tender])
            ->assertSee(__('tenders.comparison.decision_title'))
            ->call('decide', $tender->awards()->sole()->id, true)
            ->assertHasErrors('reviewed');

        $component->set('reviewed', true)->set('password', 'wrong')->call('decide', $tender->awards()->sole()->id, true);
        $this->assertSame(TenderStatus::AwardPendingApproval, $tender->fresh()->status, 'Wrong password refused.');

        $component->set('reviewed', true)->set('password', 'password')->call('decide', $tender->awards()->sole()->id, true);
        $this->assertSame(TenderStatus::Awarded, $tender->fresh()->status);
        $decision = TenderDecision::query()->where('subject', 'award')->sole();
        $this->assertSame([$snapshot->id, $snapshot->payload_hash, 'password'], [$decision->comparison_id, $decision->comparison_hash, $decision->confirmed_via]);
        $this->assertSame(TenderComparison::STATUS_DECIDED, $snapshot->fresh()->status);

        try {
            $snapshot->fresh()->update(['payload' => ['core' => []]]);
            $this->fail('Snapshots are immutable.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $csv = $this->actingAs($owner)->get(route('tenders.comparison.csv', $tender))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=cmd()", $csv);
        $this->assertSame("'+1", TenderPrintController::csvSafe('+1'));
        $this->get(route('tenders.comparison.print', ['tender' => $tender, 'lang' => 'ru']))->assertOk()->assertSee('Сравнительный отчёт по перевозке');
        $this->actingAs(User::factory()->admin()->create())->get(route('tenders.comparison', $tender))->assertForbidden();
    }

    /** AC-22: above 60M UZS landed or non-rank-1 → CEO. */
    public function test_decision_routing_amounts(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        [$a, $b, $c] = $tender->invitations()->get()->all();
        $this->transportBid($tender, $a, $owner, '40000000');
        $this->transportBid($tender, $b, $owner, '80000000');
        $this->transportBid($tender, $c, $owner, '85000000');
        $tender = $this->closeAfterDeadline($tender);
        $this->scoreManualCriterion($tender, $owner);
        $router = app(ApprovalRouter::class);

        $this->assertSame(['head'], array_map(fn ($l) => $l->value, $router->awardLevels($tender, 4_000_000_000, 1, 3)['levels']));
        $this->assertSame(['ceo'], array_map(fn ($l) => $l->value, $router->awardLevels($tender, 8_000_000_000, 1, 3)['levels']));
        $this->assertSame(['ceo'], array_map(fn ($l) => $l->value, $router->awardLevels($tender, 4_000_000_000, 2, 3)['levels']));
    }

    private function scoreManualCriterion(Tender $tender, User $user): void
    {
        $manual = $tender->criteria()->where('method', 'manual')->first();

        foreach (TenderBidLot::query()->get() as $bidLot) {
            app(EvaluateTender::class)->score($bidLot, $manual, '7', null, $user);
        }
    }
}
