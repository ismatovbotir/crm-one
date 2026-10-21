<?php

namespace Tests\Feature\Api\OneC;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Accounting\ResolvePayment;
use App\Actions\Orders\ConfirmOrder;
use App\Enums\EntryType;
use App\Enums\IntegrationAbility;
use App\Enums\MatchState;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\AccountingEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 06 9.3 `POST /payments` (AC-35..AC-37): link by crm_id with 1C winning on differences,
 * exact auto-link, fuzzy possible duplicates, unmatched payers, deletion marks, idempotent batches.
 */
class InboundPaymentsTest extends TestCase
{
    use BuildsOrders, InteractsWithOneCApi, RefreshDatabase;

    private User $accountant;

    private Customer $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableInbound();
        $this->issueToken([IntegrationAbility::PaymentsWrite->value]);
        $this->accountant = User::factory()->accountant()->create();
        $rep = User::factory()->sales()->create();
        $this->customer = Customer::factory()->ownedBy($rep)->create(['external_id' => $this->guid('customer')]);
        $this->order = $this->simpleOrder($rep, '10', '100', $this->customer);
        app(ConfirmOrder::class)->handle($this->order, $rep);
        $this->order->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function record(string $seed, array $overrides = []): array
    {
        return [
            'id' => $this->guid('pay-'.$seed), 'crm_id' => null, 'number' => 'PP-'.$seed, 'direction' => 'incoming', 'kind' => 'receipt',
            'customer_id' => $this->customer->external_id, 'payer_name' => 'ALPHA TRADE MCHJ', 'amount' => '7000.00', 'currency' => 'UZS',
            'paid_on' => now('Asia/Tashkent')->toDateString(), 'method' => 'bank_transfer', 'reference_number' => 'R-'.$seed, 'purpose' => 'Oplata '.$this->order->number,
            'allocations' => [], 'posted' => true, 'is_deleted' => false, 'version' => 'v1', 'updated_at' => now()->toIso8601String(), ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function push(array $records, ?string $batchId = null): TestResponse
    {
        return $this->onec('POST', '/payments', ['batch_id' => $batchId ?? (string) Str::uuid(), 'mode' => 'delta', 'sent_at' => '2026-10-19T11:00:00+05:00', 'payments' => $records]);
    }

    private function crmPayment(int $amount, string $reference): Payment
    {
        return app(RecordPayment::class)->handle(['customer_id' => $this->customer->id, 'amount_minor' => $amount, 'currency' => 'UZS', 'paid_on' => now('Asia/Tashkent')->toDateString(), 'method' => 'cash', 'payer_name' => 'Buyer', 'reference_number' => $reference], $this->accountant);
    }

    public function test_a_new_1c_payment_is_confirmed_and_allocated_to_the_order(): void
    {
        $this->push([$this->record('new', ['allocations' => [['order_crm_id' => $this->order->uuid, 'amount' => '5000.00']]])])
            ->assertOk()->assertJsonPath('results.0.result', 'created');

        $payment = Payment::query()->sole();
        $this->assertSame([PaymentSource::Onec, PaymentStatus::Confirmed, 700_000, 500_000], [$payment->source, $payment->status, $payment->amount_minor, $payment->allocated_minor]);
        $this->assertSame(500_000, $this->order->fresh()->paid_minor);
        $this->assertTrue(AccountingEntry::query()->where('payment_id', $payment->id)->where('entry_type', EntryType::PaymentConfirmed)->where('source', 'onec')->exists());
    }

    /** AC-35 */
    public function test_crm_id_links_the_crm_payment_and_1c_wins_on_the_amount(): void
    {
        $crm = $this->crmPayment(800_000, 'R-1');
        app(AllocatePayment::class)->handle($crm, $this->order, 800_000, $this->accountant);

        $this->push([$this->record('link', ['crm_id' => $crm->uuid, 'amount' => '7000.00'])])->assertOk()->assertJsonPath('results.0.result', 'corrected');

        $crm->refresh();
        $this->assertSame(1, Payment::query()->count(), 'No second payment.');
        $this->assertSame([PaymentStatus::Confirmed, 700_000, $this->guid('pay-link')], [$crm->status, $crm->amount_minor, $crm->onec_guid]);
        $this->assertSame(MatchState::NeedsReallocation, $crm->match_state, 'Allocations above the new amount are flagged.');
        $this->assertSame(2, AccountingEntry::query()->where('payment_id', $crm->id)->where('entry_type', EntryType::OnecCorrection)->count());
    }

    /** AC-36 */
    public function test_exact_match_links_and_fuzzy_match_waits_as_possible_duplicate(): void
    {
        $exact = $this->crmPayment(700_000, 'R-exact');
        $fuzzy = $this->crmPayment(300_000, 'R-crm');

        $this->push([
            $this->record('exact', ['reference_number' => 'R-exact']),
            $this->record('fuzzy', ['amount' => '3000.00', 'reference_number' => 'R-other', 'paid_on' => now('Asia/Tashkent')->subDays(2)->toDateString()]),
        ])->assertOk()->assertJsonPath('results.0.result', 'updated')->assertJsonPath('results.1.result', 'possible_duplicate');

        $this->assertSame([PaymentStatus::Confirmed, MatchState::Linked], [$exact->fresh()->status, $exact->fresh()->match_state]);
        $duplicate = Payment::query()->where('onec_guid', $this->guid('pay-fuzzy'))->sole();
        $this->assertSame([MatchState::PossibleDuplicate, $fuzzy->id], [$duplicate->match_state, $duplicate->duplicate_of_id]);
        $this->assertFalse($duplicate->counts());

        app(ResolvePayment::class)->markSame($duplicate, $this->accountant);
        $this->assertSame([PaymentStatus::Confirmed, $this->guid('pay-fuzzy')], [$fuzzy->fresh()->status, $fuzzy->fresh()->onec_guid]);
        $this->assertSame(PaymentStatus::Voided, $duplicate->fresh()->status);
    }

    /** AC-37 */
    public function test_unknown_payer_waits_unmatched_until_linked(): void
    {
        $this->push([$this->record('stranger', ['customer_id' => $this->guid('nobody')])])->assertOk()->assertJsonPath('results.0.result', 'unmatched');
        $payment = Payment::query()->sole();
        $this->assertNull($payment->customer_id);

        try {
            app(AllocatePayment::class)->handle($payment, $this->order, 100_000, $this->accountant);
            $this->fail('Unmatched payments cannot be allocated.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('payment_unmatched', $exception->key);
        }

        app(ResolvePayment::class)->linkCustomer($payment, $this->customer, $this->accountant);
        app(AllocatePayment::class)->handle($payment->fresh(), $this->order, 100_000, $this->accountant);
        $this->assertSame(100_000, $this->order->fresh()->paid_minor);
    }

    public function test_deletion_mark_voids_and_reverses_allocations_and_batches_are_idempotent(): void
    {
        $batch = (string) Str::uuid();
        $record = $this->record('del', ['allocations' => [['order_crm_id' => $this->order->uuid, 'amount' => '7000.00']]]);
        $first = $this->push([$record], $batch)->assertOk();
        $this->push([$record], $batch)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJson($first->json());
        $this->assertSame(700_000, $this->order->fresh()->paid_minor);

        $this->push([[...$record, 'is_deleted' => true, 'updated_at' => now()->addMinute()->toIso8601String()]])->assertOk()->assertJsonPath('results.0.result', 'voided');

        $payment = Payment::query()->sole();
        $this->assertSame([PaymentStatus::Voided, 0], [$payment->status, $payment->allocated_minor]);
        $this->assertSame(0, $this->order->fresh()->paid_minor);
    }

    public function test_validation_and_abilities(): void
    {
        $this->push([['id' => 'not-a-guid', 'amount' => '-1', 'currency' => 'XXX']])->assertStatus(207)->assertJsonPath('results.0.result', 'failed');
        $this->issueToken([IntegrationAbility::PaymentsRead->value]);
        $this->push([$this->record('x')])->assertForbidden();
    }
}
