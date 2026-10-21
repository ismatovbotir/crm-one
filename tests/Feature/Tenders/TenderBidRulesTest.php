<?php

namespace Tests\Feature\Tenders;

use App\Actions\Tenders\EvaluateTender;
use App\Actions\Tenders\SubmitBid;
use App\Enums\BidStatus;
use App\Enums\DepartmentRole;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\TenderBid;
use App\Models\TenderBidLine;
use App\Models\TenderBidLot;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenders;
use Tests\TestCase;

/**
 * BR-T.4/T.5/T.8/T.14: deadline in a locked transaction, revisions, evidence of employee-entered
 * bids, sealing until opening (AC-20, AC-21), landed cost by hand (AC-23).
 */
class TenderBidRulesTest extends TestCase
{
    use BuildsTenders, RefreshDatabase;

    /** AC-20 */
    public function test_late_bids_are_rejected_and_logged_revisions_supersede(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        $invitation = $tender->invitations()->first();
        $first = $this->transportBid($tender, $invitation, $owner, '1000000');
        $this->assertSame(64, strlen((string) $first->payload_hash));

        try {
            $this->transportBid($tender, $invitation, $owner, '900000');
            $this->fail('A revision needs a reason.');
        } catch (TenderActionException $exception) {
            $this->assertSame('correction_reason_required', $exception->key);
        }

        $second = $this->transportBid($tender, $invitation, $owner, '900000', header: ['correction_reason' => 'Carrier lowered the rate']);
        $this->assertSame([2, BidStatus::Superseded], [$second->version, $first->fresh()->status]);
        $this->assertNotSame($first->payload_hash, $second->payload_hash);

        $this->travelTo($tender->fresh()->bids_due_at->addSecond());

        try {
            $this->transportBid($tender, $tender->invitations()->skip(1)->first(), $owner, '800000');
            $this->fail('Late bid must be refused.');
        } catch (TenderActionException $exception) {
            $this->assertSame('deadline_passed', $exception->key);
        }

        $this->assertTrue(ActivityLog::query()->where('event', 'late_bid_rejected')->exists());
    }

    public function test_employee_entered_bids_need_evidence(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        $invitation = $tender->invitations()->first();
        $lot = $tender->lots()->first();
        $base = [
            'currency' => 'UZS', 'valid_until' => now()->addDays(60)->toDateString(), 'conditions_accepted' => true,
            'lots' => [$lot->id => ['transit_days' => 9, 'downtime_rate' => '0', 'lines' => ['freight' => ['inclusion' => 'included', 'amount' => '100']]]],
        ];

        $cases = [
            'evidence_required' => ['received_via' => 'email', 'received_at' => now()->subMinute(), 'sender_name' => 'X'],
            'evidence_time' => ['received_via' => 'phone', 'received_at' => now()->addHour(), 'sender_name' => 'X', 'note' => str_repeat('n', 30)],
            'evidence_sender' => ['received_via' => 'phone', 'received_at' => now()->subMinute(), 'sender_name' => '', 'note' => str_repeat('n', 30)],
        ];

        foreach ($cases as $key => $source) {
            try {
                app(SubmitBid::class)->handle($invitation, [...$base, 'source' => $source], $owner);
                $this->fail("Expected {$key}");
            } catch (TenderActionException $exception) {
                $this->assertSame($key, $exception->key);
            }
        }

        try {
            app(SubmitBid::class)->handle($invitation, [...$base, 'currency' => 'CNY', 'source' => $cases['evidence_time']], $owner);
            $this->fail('Currency not accepted.');
        } catch (TenderActionException $exception) {
            $this->assertSame('currency_not_accepted', $exception->key);
        }

        $this->assertSame(0, TenderBid::query()->count());
    }

    /** AC-21 + Spec 08 BR-B.4 + security audit (admin never). */
    public function test_bid_content_is_sealed_until_opening(): void
    {
        $owner = $this->logistics();
        $colleague = $this->logistics();
        $head = $this->logistics(DepartmentRole::Head);
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $admin->departments()->attach(Department::query()->where('code', 'logistics')->value('id'), ['role' => 'head', 'is_primary' => false]);
        $admin->unsetRelation('departments');
        $sales = User::factory()->sales()->create();

        $tender = $this->publish($this->transportTender($owner), $owner, $head);
        $bid = $this->transportBid($tender, $tender->invitations()->first(), $colleague, '31415926');

        $this->assertTrue($colleague->can('viewContent', $bid), 'The enterer sees the bid they typed.');

        foreach ([$owner, $head, $manager, $admin, $sales] as $user) {
            $this->assertFalse($user->can('viewContent', $bid->fresh()), $user->role?->value.' must not see sealed content.');
            $this->assertFalse($user->can('viewBids', $tender->fresh()));
        }

        // Amounts never reach the activity log before opening.
        $this->assertFalse(ActivityLog::query()->get()->contains(fn (ActivityLog $log): bool => str_contains((string) json_encode($log->properties), '3141592600') || str_contains((string) json_encode($log->properties), '31415926')));

        $this->closeAfterDeadline($tender);

        foreach ([$owner, $head, $manager] as $user) {
            $this->assertTrue($user->can('viewContent', $bid->fresh()));
        }

        $this->assertFalse($admin->can('viewContent', $bid->fresh()), 'Admin never reads bid content, even with a logistics membership.');
        $this->assertFalse($sales->can('viewContent', $bid->fresh()));
    }

    /** AC-23: landed cost and rank match a hand-computed fixture. */
    public function test_landed_cost_matches_the_hand_computed_fixture(): void
    {
        $owner = $this->logistics();
        $tender = $this->publish($this->transportTender($owner), $owner, $this->logistics(DepartmentRole::Head));
        [$a, $b] = $tender->invitations()->get()->all();

        // A: USD 2 000 freight, fuel not included (estimate 1 000 000 UZS), downtime USD 100/day, 10 days.
        $this->transportBid($tender, $a, $owner, '2000', 'USD', 10, '100', ['fuel' => ['inclusion' => 'not_included']]);
        // B: UZS 30 000 000 freight, fuel n/a, downtime 1 500 000 UZS/day, 12 days.
        $this->transportBid($tender, $b, $owner, '30000000', 'UZS', 12, '1500000', ['fuel' => ['inclusion' => 'na']]);

        $tender = $this->closeAfterDeadline($tender);
        $problems = app(EvaluateTender::class)->recompute($tender);
        $this->assertContains('estimate_missing', array_column($problems, 'key'));
        $this->assertNull(TenderBidLot::query()->whereHas('bid', fn ($q) => $q->where('currency', 'USD'))->value('landed_cost_minor'));

        $fuel = TenderBidLine::query()->where('charge_code', 'fuel')->where('inclusion', 'not_included')->sole();
        app(EvaluateTender::class)->setEstimate($fuel, '1000000', $owner);

        $usd = TenderBidLot::query()->whereHas('bid', fn ($q) => $q->where('currency', 'USD'))->sole();
        $uzs = TenderBidLot::query()->whereHas('bid', fn ($q) => $q->where('currency', 'UZS'))->sole();

        // freight 2 530 000 000 + fuel estimate 100 000 000 + downtime 126 500 000
        // + carrying 63 250 000 000 × 24 % × 10 / 365 = 415 890 410.96 → 415 890 411 (tiyin)
        $this->assertSame(3_172_390_411, $usd->landed_cost_minor);
        $this->assertTrue($usd->uses_estimates);
        // 3 000 000 000 + 150 000 000 + 63 250 000 000 × 24 % × 12 / 365 = 499 068 493.15 → 499 068 493
        $this->assertSame(3_649_068_493, $uzs->landed_cost_minor);
        $this->assertSame('10.000', $usd->breakdown['scores']['cost']);
        $this->assertSame(bcdiv(bcmul('10', '3172390411', 6), '3649068493', 3), $uzs->breakdown['scores']['cost']);
    }
}
