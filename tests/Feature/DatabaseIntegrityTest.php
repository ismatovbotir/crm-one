<?php

namespace Tests\Feature;

use App\Actions\Customers\SaveContact;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\InboundBatch;
use App\Models\InboundBatchPayload;
use App\Models\NumberSequence;
use App\Models\Offer;
use App\Models\OfferApproval;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * DB review 2026-10 quick wins: DB-enforced invariants (N3), NumberSequence without gap locks
 * (F-12), raw 1C payload split from the batch row and pruned separately (S1/F-04), offers
 * evidence tables restrict deletion (F-13).
 */
class DatabaseIntegrityTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    public function test_only_one_primary_contact_per_customer_at_the_database_level(): void
    {
        $customer = Customer::factory()->create();
        $first = app(SaveContact::class)->handle($customer, ['first_name' => 'A', 'email' => 'a@x.uz', 'is_primary' => true]);
        $second = app(SaveContact::class)->handle($customer, ['first_name' => 'B', 'email' => 'b@x.uz', 'is_primary' => true]);

        $this->assertFalse($first->fresh()->is_primary, 'SaveContact swaps safely (old primary unset first).');
        $this->assertTrue($second->fresh()->is_primary);

        $this->expectException(QueryException::class);
        Contact::query()->whereKey($first->id)->update(['is_primary' => true]);
    }

    public function test_tax_id_is_unique_among_active_crm_customers_but_1c_duplicates_and_archived_are_allowed(): void
    {
        Customer::factory()->create(['tax_id' => '300000001']);
        Customer::factory()->archived()->create(['tax_id' => '300000001']);
        Customer::factory()->fromOneC()->create(['tax_id' => '300000001']);
        Customer::factory()->fromOneC()->create(['tax_id' => '300000001']);

        $this->expectException(QueryException::class);
        Customer::factory()->create(['tax_id' => '300000001']);
    }

    public function test_number_sequence_starts_and_increments_per_key_and_year(): void
    {
        $this->assertSame([1, 2], [NumberSequence::next('OF', 2027), NumberSequence::next('OF', 2027)]);
        $this->assertSame(1, NumberSequence::next('OR', 2027));
        $this->assertSame(1, NumberSequence::next('OF', 2028));
        $this->assertSame('OF-2027-00042', NumberSequence::format('OF', 2027, 42));
    }

    public function test_raw_payload_lives_in_its_own_table_and_is_pruned_without_losing_the_replay_row(): void
    {
        $this->assertFalse(Schema::hasColumn('inbound_batches', 'payload'));
        $this->enableInbound();
        $this->issueToken();

        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);

        $batch = InboundBatch::query()->sole();
        $this->assertSame($body['batch_id'], $batch->decodedPayload()['batch_id']);

        $this->token->forceFill(['expires_at' => now()->addYear()])->save();
        $this->travel(31)->days();
        Artisan::call('model:prune', ['--model' => [InboundBatchPayload::class, InboundBatch::class]]);

        $this->assertSame(0, InboundBatchPayload::query()->count());
        $this->assertNotNull($batch->fresh(), 'The batch row (hash, counts, response) is kept for a year.');
        $this->onec('POST', '/items', $body)->assertStatus(202)->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_offer_evidence_cannot_be_hard_deleted_with_the_offer(): void
    {
        $offer = Offer::factory()->create();
        OfferApproval::query()->create([
            'offer_id' => $offer->id, 'required_bp' => 800, 'owner_limit_bp' => 500, 'status' => 'pending',
            'requested_at' => now(), 'content_hash' => str_repeat('a', 64),
        ]);

        $this->expectException(QueryException::class);
        $offer->forceDelete();
    }
}
