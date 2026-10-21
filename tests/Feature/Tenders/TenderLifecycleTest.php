<?php

namespace Tests\Feature\Tenders;

use App\Actions\Tenders\AwardTender;
use App\Actions\Tenders\EvaluateTender;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\SubmitBid;
use App\Actions\Tenders\TenderWorkflow;
use App\Enums\AwardStatus;
use App\Enums\BidStatus;
use App\Enums\DepartmentRole;
use App\Enums\InvitationStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\TenderStatus;
use App\Models\PurchaseOrder;
use App\Models\StatusTransition;
use App\Models\Tender;
use App\Models\TenderBidLot;
use App\Models\TenderCriterion;
use App\Models\TenderDecision;
use App\Models\User;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use LogicException;
use Tests\Concerns\BuildsTenders;
use Tests\TestCase;

/**
 * Spec 03 6.1 / BR-T.1-T.11: transport and purchase tenders end to end through the Actions.
 */
class TenderLifecycleTest extends TestCase
{
    use BuildsTenders, RefreshDatabase;

    public function test_transport_tender_from_draft_to_contracted(): void
    {
        $owner = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $tender = $this->transportTender($owner);

        $this->assertMatchesRegularExpression('/^TT-\d{4}-\d{5}$/', $tender->number);
        $this->publish($tender, $owner, $head);
        $tender->refresh();
        $this->assertSame(TenderStatus::Published, $tender->status);

        [$a, $b, $c] = $tender->invitations()->get()->all();
        $this->transportBid($tender, $a, $owner, '30000000');
        $this->transportBid($tender, $b, $owner, '28000000', transitDays: 14);
        $this->transportBid($tender, $c, $owner, '35000000', transitDays: 8);
        $this->assertSame(InvitationStatus::Submitted, $a->fresh()->status);

        $tender = $this->closeAfterDeadline($tender);
        $this->assertSame(TenderStatus::Evaluation, $tender->status);
        $this->assertNotNull($tender->opened_at);

        // Without a committee the owner scores the manual criterion.
        $evaluate = app(EvaluateTender::class);
        $manual = TenderCriterion::query()->where('tender_id', $tender->id)->where('method', 'manual')->sole();
        $this->assertSame([['key' => 'scores_missing', 'params' => ['criterion' => $manual->displayLabel()]]], $evaluate->recompute($tender));

        foreach (TenderBidLot::query()->get() as $bidLot) {
            $evaluate->score($bidLot, $manual, '8', null, $owner);
        }

        $ranked = TenderBidLot::query()->orderBy('rank')->get();
        $this->assertSame([1, 2, 3], $ranked->pluck('rank')->all());
        $winner = $ranked->first();

        app(AwardTender::class)->propose($tender->fresh(), [$tender->lots()->first()->id => ['bid_lot_id' => $winner->id]], $owner);
        $this->assertSame(TenderStatus::AwardPendingApproval, $tender->fresh()->status);
        $award = $tender->awards()->sole();
        $this->assertSame(['head'], $award->required_levels, 'Below the CEO threshold, rank 1, 3 bids: L1 only.');

        try {
            app(AwardTender::class)->decide($award, $owner, true, null, 'password');
            $this->fail('The proposer never approves (maker ≠ checker).');
        } catch (TenderActionException $exception) {
            $this->assertSame('not_approver', $exception->key);
        }

        app(AwardTender::class)->decide($award, $head, true, 'Cheapest compliant', 'password');
        $tender->refresh();
        $this->assertSame(TenderStatus::Awarded, $tender->status);
        $this->assertSame(BidStatus::Awarded, $winner->bid->fresh()->status);
        $this->assertSame(2, $tender->bids()->where('status', BidStatus::NotAwarded)->count());

        app(AwardTender::class)->recordAcceptance($award->fresh(), true, 'Carrier confirmed by phone at 10:30, truck booked.', $owner);
        $this->assertSame(TenderStatus::Contracted, $tender->fresh()->status);
        $this->assertSame(AwardStatus::Accepted, $award->fresh()->status);

        $statuses = StatusTransition::query()->where('subject_type', 'tender')->where('subject_id', $tender->id)->pluck('to_status')->all();
        $this->assertSame(['draft', 'pending_approval', 'approved', 'published', 'closed', 'evaluation', 'award_pending_approval', 'awarded', 'contracted'], $statuses);
        $this->assertSame(2, TenderDecision::query()->where('tender_id', $tender->id)->count());
    }

    /** AC-25 */
    public function test_purchase_award_creates_exactly_one_purchase_order(): void
    {
        $owner = $this->purchaser();
        $head = $this->purchaser(DepartmentRole::Head);
        $tender = $this->publish($this->purchaseTender($owner), $owner, $head);
        $item = $tender->lots()->first()->items()->first();
        $invitation = $tender->invitations()->first();

        app(SubmitBid::class)->handle($invitation, [
            'currency' => 'UZS',
            'valid_until' => now()->addDays(90)->toDateString(),
            'payment_days' => 30,
            'conditions_accepted' => true,
            'lots' => [$item->lot_id => ['lead_time_days' => 20, 'lines' => [$item->id => ['unit_price' => '1250000.50', 'offered_brand' => 'TP-Link', 'offered_model' => 'AX55']]]],
            'source' => ['received_via' => 'email', 'received_at' => now()->subMinute(), 'sender_name' => 'Sales Manager'],
        ], $owner, [UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n%quote\n")]);

        $tender = $this->closeAfterDeadline($tender);
        $bidLot = TenderBidLot::query()->sole();
        $this->assertSame(1250000500, $bidLot->total_minor, '10 × 12 500 005.0 tiyin, half-up per line.');

        app(AwardTender::class)->propose($tender, [$item->lot_id => ['bid_lot_id' => $bidLot->id]], $owner);
        $award = $tender->awards()->sole();
        $this->assertSame(['ceo'], $award->required_levels, 'Fewer than 2 valid bids routes to the CEO.');

        app(AwardTender::class)->decide($award, $this->ceo(), true, null);
        app(AwardTender::class)->recordAcceptance($award->fresh(), true, 'Supplier accepted by e-mail on the same day.', $owner);

        $po = PurchaseOrder::query()->sole();
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertSame(1250000500, $po->subtotal_minor);
        $this->assertSame(['TP-Link', '10.000', 125000050], [$po->lines->first()->offered_brand, (string) $po->lines->first()->quantity, $po->lines->first()->unit_price_minor]);
        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{5}$/', $po->number);

        $again = app(AwardTender::class)->createPurchaseOrder($award->fresh(), $owner);
        $this->assertSame($po->id, $again->id);
        $this->assertSame(1, PurchaseOrder::query()->count());
        $this->assertSame(TenderStatus::Contracted, $tender->fresh()->status);
    }

    /** AC-24 + user decision: non-rank-1 needs a justification and goes to the CEO. */
    public function test_rank_two_needs_justification_and_the_ceo(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        [$a, $b] = $tender->invitations()->get()->all();
        $this->transportBid($tender, $a, $owner, '10000000');
        $this->transportBid($tender, $b, $owner, '11000000');
        $tender = $this->closeAfterDeadline($tender);
        $this->scoreManual($tender, $owner);
        $second = TenderBidLot::query()->where('rank', 2)->sole();
        $lotId = $tender->lots()->first()->id;

        try {
            app(AwardTender::class)->propose($tender->fresh(), [$lotId => ['bid_lot_id' => $second->id]], $owner);
            $this->fail('Rank 2 without justification must fail.');
        } catch (TenderActionException $exception) {
            $this->assertSame('justification_required', $exception->key);
        }

        app(AwardTender::class)->propose($tender->fresh(), [$lotId => ['bid_lot_id' => $second->id, 'justification' => 'Better insurance cover']], $owner);
        $award = $tender->awards()->sole();
        $this->assertSame(['ceo'], $award->required_levels);

        $head = $this->logistics(DepartmentRole::Head);
        $this->assertFalse($head->can('decideAward', $tender->fresh()), 'The head cannot decide a CEO-level award.');
        $ceo = $this->ceo();
        $this->assertTrue($ceo->can('decideAward', $tender->fresh()));

        app(AwardTender::class)->decide($award, $ceo, false, 'Ask carrier B for a better price first', 'password');
        $this->assertSame(TenderStatus::Evaluation, $tender->fresh()->status);
        $this->assertSame(AwardStatus::Rejected, $award->fresh()->status);
        $this->assertNull($award->fresh()->active_lot_id);
    }

    /** User decision 2026-10-18: above 60 000 000 UZS the CEO decides; above T2 finance co-approves after. */
    public function test_award_above_the_ceo_threshold_and_t2(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        [$a, $b] = $tender->invitations()->get()->all();
        $this->transportBid($tender, $a, $owner, '1200000000');
        $this->transportBid($tender, $b, $owner, '1300000000');
        $tender = $this->closeAfterDeadline($tender);
        $this->scoreManual($tender, $owner);

        app(AwardTender::class)->propose($tender->fresh(), [$tender->lots()->first()->id => ['bid_lot_id' => TenderBidLot::query()->where('rank', 1)->value('id')]], $owner);
        $award = $tender->awards()->sole();
        $this->assertSame(['ceo', 'finance'], $award->required_levels);

        $finance = User::factory()->finance()->create();

        try {
            app(AwardTender::class)->decide($award, $finance, true, null, 'password');
            $this->fail('Finance co-approves only after the CEO.');
        } catch (TenderActionException $exception) {
            $this->assertSame('finance_after_ceo', $exception->key);
        }

        app(AwardTender::class)->decide($award, $this->ceo(), true, null, 'password');
        $this->assertSame(TenderStatus::AwardPendingApproval, $tender->fresh()->status);
        app(AwardTender::class)->decide($award->fresh(), $finance, true, null, 'password');
        $this->assertSame(TenderStatus::Awarded, $tender->fresh()->status);
    }

    public function test_publication_approval_routing_and_admin_never_approves(): void
    {
        $owner = $this->logistics();
        $tender = $this->transportTender($owner, ['budget_minor' => 7_000_000_000, 'budget_currency' => 'UZS']);
        $workflow = app(TenderWorkflow::class);
        $acks = collect($workflow->runCheck($tender))->where('level', 'warning')->mapWithKeys(fn ($i) => [CompletenessChecker::issueKey($i) => 'ok'])->all();
        $workflow->submitForApproval($tender, $owner, $acks);

        $head = $this->logistics(DepartmentRole::Head);
        $admin = User::factory()->admin()->create();
        $this->assertFalse($head->can('approvePublication', $tender->fresh()), 'Budget above 60M: CEO only.');
        $this->assertFalse($admin->can('approvePublication', $tender->fresh()));
        $this->assertFalse($owner->can('approvePublication', $tender->fresh()));

        try {
            $workflow->decidePublication($tender->fresh(), $head, true, null);
            $this->fail('Head cannot approve above the CEO threshold.');
        } catch (TenderActionException $exception) {
            $this->assertSame('not_approver', $exception->key);
        }

        try {
            $workflow->decidePublication($tender->fresh(), $this->ceo(), false, '');
            $this->fail('Rejection needs a comment.');
        } catch (TenderActionException $exception) {
            $this->assertSame('comment_required', $exception->key);
        }

        $workflow->decidePublication($tender->fresh(), $this->ceo(), false, 'Add a fourth carrier');
        $this->assertSame(TenderStatus::Draft, $tender->fresh()->status);
        $this->assertSame('reject', TenderDecision::query()->sole()->decision);
        $this->expectException(LogicException::class);
        TenderDecision::query()->sole()->delete();
    }

    /** AC-26 */
    public function test_addendum_extends_a_close_deadline_and_flags_submitted_bids(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        $bid = $this->transportBid($tender, $tender->invitations()->first(), $owner, '1000000');

        $this->travelTo($tender->fresh()->bids_due_at->subHours(10));
        app(TenderWorkflow::class)->addAddendum($tender->fresh(), $owner, 'Pickup address corrected', 'Origin: Hadımköy gate 3', null);

        $tender->refresh();
        $this->assertSame(2, $tender->version);
        $this->assertTrue($tender->bids_due_at->equalTo(now()->addHours(48)->startOfMinute()));
        $this->assertTrue($bid->fresh()->needs_confirmation);
        $this->assertSame(1, $tender->addenda()->count());
    }

    public function test_cancel_and_retender_copies_the_draft_without_bids(): void
    {
        $owner = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $tender = $this->publish($this->transportTender($owner), $owner, $head);
        $this->transportBid($tender, $tender->invitations()->first(), $owner, '1000000');

        $this->assertFalse($owner->can('cancel', $tender->fresh()), 'After publishing only the head cancels.');
        $this->assertTrue($head->can('cancel', $tender->fresh()));
        app(TenderWorkflow::class)->cancel($tender->fresh(), $head, 'Customer postponed the order');

        $copy = app(TenderWorkflow::class)->retender($tender->fresh(), $owner);
        $this->assertSame(TenderStatus::Draft, $copy->status);
        $this->assertSame($tender->id, $copy->parent_tender_id);
        $this->assertSame(2, $copy->round);
        $this->assertSame(3, $copy->invitations()->count());
        $this->assertSame(0, $copy->bids()->count());
        $this->assertSame('Istanbul', $copy->lots()->first()->transportSpec->origin_city);
        $this->assertNotSame($tender->number, $copy->number);
    }

    public function test_structure_is_locked_after_submission(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));

        $this->expectExceptionObject(new TenderActionException('not_draft'));
        app(ManageTender::class)->setCriteria($tender->fresh(), [['code' => 'cost', 'weight_bp' => 10000, 'method' => 'auto_lowest_cost']]);
    }

    protected function scoreManual(Tender $tender, User $user): void
    {
        $manual = TenderCriterion::query()->where('tender_id', $tender->id)->where('method', 'manual')->first();

        foreach (TenderBidLot::query()->get() as $bidLot) {
            app(EvaluateTender::class)->score($bidLot, $manual, '7', null, $user);
        }
    }
}
