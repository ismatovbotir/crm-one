<?php

namespace Tests\Feature\Tenders;

use App\Actions\Counterparties\ManageCounterparty;
use App\Actions\Tenders\ManageTender;
use App\Actions\Tenders\ManageTenderParticipants;
use App\Actions\Tenders\TenderWorkflow;
use App\Enums\CounterpartyDocumentType;
use App\Enums\CounterpartyStatus;
use App\Enums\DepartmentRole;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Counterparty;
use App\Models\StatusTransition;
use App\Services\Tenders\CompletenessChecker;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenders;
use Tests\TestCase;

/**
 * BR-T.6 completeness (AC-17, AC-18, AC-19), purchase P-codes, counterparty qualification.
 */
class CompletenessCheckTest extends TestCase
{
    use BuildsTenders, RefreshDatabase;

    /**
     * @return list<string>
     */
    private function codes(array $issues, string $level = 'blocker'): array
    {
        return array_values(array_unique(array_column(array_filter($issues, fn ($i) => $i['level'] === $level), 'code')));
    }

    /** AC-17 / AC-18 */
    public function test_transport_blockers_name_the_missing_rfq_fields(): void
    {
        $owner = $this->logistics();
        $tender = $this->transportTender($owner);
        $manage = app(ManageTender::class);
        $lot = $tender->lots()->first();
        $checker = app(CompletenessChecker::class);
        $this->assertSame([], $this->codes($checker->check($tender->fresh())));

        $manage->updateTransportSpec($lot, ['hs_codes' => [], 'incoterm_place' => null, 'charges_payer' => null, 'transshipment_allowed' => null]);
        $codes = $this->codes($checker->check($tender->fresh()));

        foreach (['N1', 'N2', 'N3', 'N6'] as $code) {
            $this->assertContains($code, $codes);
        }

        try {
            app(TenderWorkflow::class)->submitForApproval($tender->fresh(), $owner);
            $this->fail('Blockers prevent submission.');
        } catch (TenderActionException $exception) {
            $this->assertSame('has_blockers', $exception->key);
        }

        $manage->updateTransportSpec($lot->fresh(), ['hs_codes' => ['851762'], 'incoterm_place' => 'Istanbul', 'charges_payer' => array_fill_keys(config('tenders.vocab.payer_charges'), 'buyer'), 'transshipment_allowed' => false]);
        $this->assertSame([], $this->codes($checker->check($tender->fresh())), 'Explicit "no" answers the tri-state question.');
    }

    public function test_dates_hazmat_and_weights(): void
    {
        $owner = $this->logistics();
        $tender = $this->transportTender($owner);
        app(ManageTender::class)->updateTransportSpec($tender->lots()->first(), [
            'readiness_date' => $this->day(20), 'is_dangerous' => true, 'un_number' => null, 'gross_kg' => '45000', 'net_kg' => '46000',
        ]);
        $issues = app(CompletenessChecker::class)->check($tender->fresh());
        $keys = array_column($issues, 'key');

        foreach (['n12_readiness', 'n13_dg', 'n14_net', 'n14_overweight'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    /** AC-19 */
    public function test_warnings_need_a_comment_each(): void
    {
        $owner = $this->logistics();
        $tender = $this->transportTender($owner, [], carriers: 2);
        app(ManageTender::class)->updateTransportSpec($tender->lots()->first(), ['force_majeure' => null]);
        $workflow = app(TenderWorkflow::class);
        $warnings = $this->codes($workflow->runCheck($tender->fresh()), 'warning');
        $this->assertSame(['N18', 'N10'], $warnings);

        try {
            $workflow->submitForApproval($tender->fresh(), $owner, ['N18' => 'Only two carriers serve this lane']);
            $this->fail('Every warning needs a comment.');
        } catch (TenderActionException $exception) {
            $this->assertSame('warnings_unacknowledged', $exception->key);
            $this->assertStringContainsString('N10:1', $exception->getMessage());
        }

        $workflow->submitForApproval($tender->fresh(), $owner, ['N18' => 'Only two carriers serve this lane', 'N10:1' => 'Covered by the frame contract']);
        $tender->refresh();
        $this->assertSame(TenderStatus::PendingApproval, $tender->status);
        $this->assertSame('Covered by the frame contract', $tender->acknowledged_warnings['N10:1']);
        $this->assertStringContainsString('Only two carriers serve this lane', (string) StatusTransition::query()->where('to_status', 'pending_approval')->value('reason'));
    }

    public function test_invitees_below_three_block_above_t1_and_committee_size(): void
    {
        $owner = $this->logistics();
        $tender = $this->transportTender($owner, ['budget_minor' => 20_000_000_000, 'budget_currency' => 'UZS'], carriers: 2);
        $issues = app(CompletenessChecker::class)->check($tender->fresh());
        $this->assertContains('N18', $this->codes($issues));
        $this->assertContains('committee_size', array_column($issues, 'key'));
    }

    public function test_purchase_codes(): void
    {
        $owner = $this->purchaser();
        $tender = $this->purchaseTender($owner, ['incoterm' => null, 'is_import' => true]);
        $manage = app(ManageTender::class);
        $manage->saveItem($tender->lots()->first(), null, ['name' => 'Cable', 'unit_code' => 'pcs', 'unit_precision' => 0, 'quantity' => '2.5']);
        $codes = $this->codes(app(CompletenessChecker::class)->check($tender->fresh()));

        foreach (['P1', 'P2', 'P3', 'P4'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_invitations_respect_role_and_status(): void
    {
        $owner = $this->logistics();
        $tender = app(ManageTender::class)->create(TenderType::Transport, ['title' => 'Lane'], $owner);
        $participants = app(ManageTenderParticipants::class);

        foreach ([[Counterparty::factory()->supplier()->create(), 'counterparty_wrong_role'], [Counterparty::factory()->carrier()->status(CounterpartyStatus::Blacklisted)->create(), 'counterparty_cannot_bid']] as [$counterparty, $key]) {
            try {
                $participants->invite($tender, $counterparty, $owner);
                $this->fail($key);
            } catch (TenderActionException $exception) {
                $this->assertSame($key, $exception->key);
            }
        }
    }

    public function test_qualification_needs_a_verified_document_and_expiry_requalifies(): void
    {
        $user = $this->logistics(DepartmentRole::Head);
        $carrier = Counterparty::factory()->carrier()->status(CounterpartyStatus::Prospect)->create();
        $manage = app(ManageCounterparty::class);

        try {
            $manage->changeStatus($carrier, CounterpartyStatus::Qualified, null, $user);
            $this->fail('Needs a verified document.');
        } catch (TenderActionException $exception) {
            $this->assertSame('qualification_needs_document', $exception->key);
        }

        $document = $manage->addDocument($carrier, CounterpartyDocumentType::CmrInsurance, 'CMR-1', $this->day(5), null, $user);
        $manage->verifyDocument($document, $user);
        $manage->changeStatus($carrier->fresh(), CounterpartyStatus::Qualified, null, $user);
        $this->assertSame(CounterpartyStatus::Qualified, $carrier->fresh()->status);

        $this->travel(7)->days();
        $this->assertSame(1, $manage->expireDocuments());
        $this->assertSame(CounterpartyStatus::PendingQualification, $carrier->fresh()->status);

        $this->expectExceptionObject(new TenderActionException('reason_required'));
        $manage->changeStatus($carrier->fresh(), CounterpartyStatus::Suspended, '', $user);
    }
}
