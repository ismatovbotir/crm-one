<?php

namespace Tests\Feature\Offers;

use App\Actions\Offers\DecideOfferApproval;
use App\Actions\Offers\SendOffer;
use App\Actions\Offers\SubmitOfferForApproval;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\ApprovalStatus;
use App\Enums\DepartmentRole;
use App\Enums\OfferStatus;
use App\Enums\OptionGroupKind;
use App\Enums\SelectionChannel;
use App\Livewire\Offers\Show;
use App\Models\OfferVersion;
use App\Models\User;
use App\Services\Offers\Delivery\OfferDelivery;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 AC-10..AC-12 and 6.1: worst-case discount approval, approval bound to the content
 * hash, explicit send with published snapshot, delivery stub, status history.
 */
class OfferApprovalAndSendTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_default_limits_per_role(): void
    {
        $rules = app(DiscountRules::class);

        $this->assertSame(500, $rules->limitFor(User::factory()->sales()->create()));
        $this->assertSame(1000, $rules->limitFor(User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create()));
        $this->assertSame(2500, $rules->limitFor(User::factory()->manager()->create()));
        $this->assertSame(10000, $rules->limitFor(User::factory()->staff()->inDepartment('management', DepartmentRole::Head)->create()));
        $this->assertSame(0, $rules->limitFor(User::factory()->admin()->create()));
    }

    /** AC-10, AC-12 */
    public function test_a_non_recommended_option_above_the_limit_requires_approval_by_someone_else(): void
    {
        $rep = User::factory()->sales()->inDepartment('sales')->create();
        $head = User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create();
        ['offer' => $offer, 'lines' => $lines] = $this->fixtureOffer($rep);

        app(UpdateOfferLine::class)->handle($lines['C'], $rep, ['discount_bp' => 800]);
        $offer->refresh();

        $this->assertSame(800, $offer->max_discount_bp, 'Worst case counts the non-recommended option.');
        $this->assertTrue(app(DiscountRules::class)->requiresApproval($offer));

        try {
            app(SendOffer::class)->handle($offer, $rep, SelectionChannel::Email);
            $this->fail('Sending without approval must be refused.');
        } catch (OfferActionException $exception) {
            $this->assertSame('approval_required', $exception->key);
        }

        app(SubmitOfferForApproval::class)->handle($offer, $rep);
        $offer->refresh();
        $this->assertSame(OfferStatus::PendingApproval, $offer->status);
        $approval = $offer->approvals()->firstOrFail();
        $this->assertSame([800, 500, $offer->content_hash], [$approval->required_bp, $approval->owner_limit_bp, $approval->content_hash]);
        $this->assertSame('2C', $approval->reasons[0]['label'], 'Block 2 (the alternative group), option C.');

        $this->assertFalse($rep->can('approve', $offer), 'Nobody approves their own offer.');
        $this->assertFalse(User::factory()->admin()->create()->can('approve', $offer), 'Admin cannot approve.');
        $this->assertFalse(User::factory()->finance()->create()->can('approve', $offer));
        $this->assertTrue($head->can('approve', $offer));

        app(DecideOfferApproval::class)->approve($offer, $head, 'ok');
        $offer->refresh();
        $this->assertSame(OfferStatus::Approved, $offer->status);
        $this->assertSame($offer->content_hash, $offer->approved_hash);

        app(SendOffer::class)->handle($offer, $rep, SelectionChannel::Email);
        $this->assertSame(OfferStatus::Sent, $offer->fresh()->status);
    }

    public function test_a_head_cannot_approve_above_their_own_limit(): void
    {
        $rep = User::factory()->sales()->inDepartment('sales')->create();
        $head = User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create();
        $manager = User::factory()->manager()->create();
        ['offer' => $offer, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(UpdateOfferLine::class)->handle($lines['fixed'], $rep, ['discount_bp' => 1500]);
        app(SubmitOfferForApproval::class)->handle($offer->refresh(), $rep);

        $this->assertFalse($head->can('approve', $offer->refresh()));
        $this->assertTrue($manager->can('approve', $offer));
    }

    /** AC-11 */
    public function test_editing_an_approved_offer_returns_it_to_draft_and_invalidates_the_approval(): void
    {
        $rep = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();
        ['offer' => $offer, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(UpdateOfferLine::class)->handle($lines['A'], $rep, ['discount_bp' => 700]);
        app(SubmitOfferForApproval::class)->handle($offer->refresh(), $rep);
        app(DecideOfferApproval::class)->approve($offer->refresh(), $manager);

        app(UpdateOfferLine::class)->handle($lines['A']->refresh(), $rep, ['quantity' => '2']);

        $offer->refresh();
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertNull($offer->approved_hash);
        $this->assertSame(ApprovalStatus::Invalidated, $offer->approvals()->firstOrFail()->status);
        $this->assertDatabaseHas('status_transitions', ['subject_id' => $offer->id, 'from_status' => 'approved', 'to_status' => 'draft', 'reason' => 'approval_invalidated']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'offer', 'subject_id' => $offer->id, 'event' => 'approval_invalidated']);
    }

    public function test_return_needs_a_comment_and_goes_back_to_draft(): void
    {
        $rep = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();
        ['offer' => $offer, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(UpdateOfferLine::class)->handle($lines['A'], $rep, ['discount_bp' => 700]);
        app(SubmitOfferForApproval::class)->handle($offer->refresh(), $rep);

        Livewire::actingAs($manager)->test(Show::class, ['offer' => $offer])
            ->call('openPanel', 'return')
            ->call('returnToDraft')
            ->assertHasErrors('comment')
            ->set('comment', 'Too generous')
            ->call('returnToDraft')
            ->assertHasNoErrors();

        $this->assertSame(OfferStatus::Draft, $offer->fresh()->status);
        $this->assertSame(ApprovalStatus::Returned, $offer->approvals()->firstOrFail()->status);
    }

    public function test_send_writes_the_published_snapshot_and_calls_the_delivery_stub(): void
    {
        $delivery = Mockery::mock(OfferDelivery::class);
        $delivery->shouldReceive('deliver')->once();
        $this->app->instance(OfferDelivery::class, $delivery);

        $rep = User::factory()->sales()->create();
        ['offer' => $offer] = $this->fixtureOffer($rep);

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $offer])
            ->call('openPanel', 'send')
            ->call('send')
            ->assertHasErrors('channel')
            ->set('channel', 'email')
            ->call('send')
            ->assertHasNoErrors();

        $offer->refresh();
        $this->assertSame(OfferStatus::Sent, $offer->status);
        $this->assertSame(SelectionChannel::Email, $offer->sent_channel);
        $version = OfferVersion::query()->where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame($offer->content_hash, $version->content_hash);
        $this->assertSame('Air compressor', $version->payload['blocks'][1]['title']);
        $this->assertStringNotContainsString('SECRET-MARGIN-NOTE', json_encode($version->payload), 'Internal notes are not in the published snapshot.');
        $this->assertDatabaseHas('status_transitions', ['subject_id' => $offer->id, 'from_status' => 'draft', 'to_status' => 'sent', 'user_id' => $rep->id]);

        // Sent offers are immutable.
        $this->expectException(OfferActionException::class);
        app(UpdateOfferLine::class)->handle($offer->lines()->firstOrFail(), $rep, ['quantity' => '5']);
    }

    /** V1 / V2 blockers stop submit and send. */
    public function test_invalid_offers_cannot_be_sent(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $group = $this->addGroup($offer, OptionGroupKind::Alternative);
        $this->addLine($offer, $this->pricedItem('10.00'), '1', $group);

        try {
            app(SendOffer::class)->handle($offer->refresh(), $rep, SelectionChannel::Email);
            $this->fail('An alternative group with one option must block sending.');
        } catch (OfferActionException $exception) {
            $this->assertSame('validation_blockers', $exception->key);
        }

        $this->assertSame(OfferStatus::Draft, $offer->fresh()->status);
        $codes = array_column(app(OfferValidator::class)->validate($offer->fresh()), 'code');
        $this->assertContains('V2', $codes);
    }
}
