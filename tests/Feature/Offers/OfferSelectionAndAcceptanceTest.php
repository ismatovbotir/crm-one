<?php

namespace Tests\Feature\Offers;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\SendOffer;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Offers\Show;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\OfferSelectionEvent;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferToOrderPayload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 BR-10..BR-17, AC-7, AC-13, AC-16..AC-19: choices recorded on behalf, optimistic lock,
 * acceptance blocked until min/max is satisfied, accepted snapshot, expiry, order hand-off.
 */
class OfferSelectionAndAcceptanceTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    /**
     * @return array{offer: Offer, alternative: OfferOptionGroup, optional: OfferOptionGroup, lines: array<string, OfferLine>, rep: User}
     */
    private function sentFixture(): array
    {
        $rep = User::factory()->sales()->create();
        $fixture = $this->fixtureOffer($rep);
        app(SendOffer::class)->handle($fixture['offer'], $rep, SelectionChannel::Email);
        $fixture['offer']->refresh();

        return [...$fixture, 'rep' => $rep];
    }

    /** AC-7 + BR-11 */
    public function test_choosing_another_alternative_replaces_the_previous_one_and_totals_follow(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'optional' => $opt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        $record = app(RecordCustomerSelection::class);

        $record->handle($offer, $rep, [$alt->id => [$lines['B']->id]], SelectionChannel::Phone, 'Called 10:15');
        $this->assertSame(OfferStatus::PartiallySelected, $offer->fresh()->status);

        $record->handle($offer->fresh(), $rep, [$alt->id => [$lines['C']->id]], SelectionChannel::Phone, 'Changed mind');
        $offer->refresh();
        $this->assertSame([$lines['C']->id], $offer->selectedLineIds());
        $this->assertSame([165000, 184800], [$offer->selected_net_minor, $offer->selected_gross_minor]);
        $this->assertTrue($offer->selection_complete);
        $this->assertSame([184800, 201600], [$offer->range_min_gross_minor, $offer->range_max_gross_minor]);

        $record->handle($offer, $rep, [$opt->id => [$lines['X']->id]], SelectionChannel::Phone, 'Wants installation');
        $offer->refresh();
        $this->assertSame(196000, $offer->selected_gross_minor);
        $this->assertSame([196000, 201600], [$offer->range_min_gross_minor, $offer->range_max_gross_minor]);

        $events = OfferSelectionEvent::query()->where('offer_id', $offer->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['selected', 'deselected', 'selected', 'selected'], $events);
        $this->assertSame(3, $offer->selection_revision);

        $this->expectException(OfferActionException::class);
        $record->handle($offer, $rep, [$alt->id => [$lines['A']->id, $lines['B']->id]], SelectionChannel::Phone, 'both');
    }

    /** AC-16 / BR-12 */
    public function test_channel_rules_note_and_evidence(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $offer])
            ->call('openPanel', 'selection')
            ->set("choices.{$alt->id}", (string) $lines['B']->id)
            ->set('channel', 'phone')
            ->call('saveSelection')
            ->assertHasErrors('note')
            ->set('channel', 'paper')
            ->call('saveSelection')
            ->assertHasErrors('evidence')
            ->set('evidence', 'Signed scan 18.10')
            ->call('saveSelection')
            ->assertHasNoErrors();

        $event = OfferSelectionEvent::query()->where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame([SelectionChannel::Paper, 'internal', 'Signed scan 18.10', $rep->id], [$event->channel, $event->actor, $event->evidence_note, $event->user_id]);
    }

    /** AC-15 (internal variant) */
    public function test_concurrent_saves_from_the_same_revision_conflict(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        $record = app(RecordCustomerSelection::class);

        $record->handle($offer, $rep, [$alt->id => [$lines['A']->id]], SelectionChannel::Email, 'mail', null, 0);

        try {
            $record->handle($offer, $rep, [$alt->id => [$lines['B']->id]], SelectionChannel::Email, 'mail', null, 0);
            $this->fail('A stale revision must be refused.');
        } catch (OfferActionException $exception) {
            $this->assertSame('selection_conflict', $exception->key);
        }

        $this->assertSame([$lines['A']->id], $offer->fresh()->selectedLineIds());
    }

    /** AC-13 */
    public function test_accept_is_blocked_until_every_group_is_satisfied(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $offer])
            ->call('openPanel', 'accept')
            ->assertSee('data-accept-blocked', false)
            ->set('channel', 'email')
            ->set('note', 'Confirmed by e-mail')
            ->call('accept')
            ->assertSee('Air compressor');

        $this->assertSame(OfferStatus::Sent, $offer->fresh()->status);

        try {
            app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'mail');
            $this->fail('Accept without the required choice must fail.');
        } catch (OfferActionException $exception) {
            $this->assertSame('choices_missing', $exception->key);
        }

        app(RecordCustomerSelection::class)->handle($offer->fresh(), $rep, [$alt->id => [$lines['C']->id]], SelectionChannel::Email, 'mail');
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'Signed reply');

        $offer->refresh();
        $this->assertSame(OfferStatus::Accepted, $offer->status);
        $this->assertSame([SelectionChannel::Email, $rep->id, $offer->content_hash], [$offer->accepted_via, $offer->accepted_by_user_id, $offer->accepted_hash]);
        $this->assertSame('2C', $offer->accepted_selection[$alt->stable_key]['lines'][0]['label']);
        $this->assertDatabaseHas('offer_selection_events', ['offer_id' => $offer->id, 'event' => 'accepted_with', 'offer_line_id' => $lines['C']->id]);
    }

    /** AC-18 */
    public function test_items_deleted_in_1c_block_acceptance(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        app(RecordCustomerSelection::class)->handle($offer, $rep, [$alt->id => [$lines['B']->id]], SelectionChannel::Email, 'mail');
        $lines['B']->item->forceFill(['external_deleted_at' => now()])->save();

        $this->expectException(OfferActionException::class);
        $this->expectExceptionMessage('Compressor premium');
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'mail');
    }

    /** AC-17 */
    public function test_expiry_at_the_end_of_the_validity_day_in_tashkent(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        $offer->forceFill(['valid_until' => '2026-11-20'])->save();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 23:59', 'Asia/Tashkent'));
        app(RecordCustomerSelection::class)->handle($offer->fresh(), $rep, [$alt->id => [$lines['A']->id]], SelectionChannel::Email, 'mail');
        Artisan::call('offers:expire');
        $this->assertSame(OfferStatus::PartiallySelected, $offer->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-21 00:01', 'Asia/Tashkent'));
        Artisan::call('offers:expire');
        Artisan::call('offers:expire');
        $offer->refresh();
        $this->assertSame(OfferStatus::Expired, $offer->status);
        $this->assertSame(1, $offer->transitions()->where('to_status', 'expired')->count(), 'Idempotent.');

        $this->expectException(OfferActionException::class);
        app(RecordCustomerSelection::class)->handle($offer, $rep, [$alt->id => [$lines['B']->id]], SelectionChannel::Email, 'mail');
    }

    /** AC-19 contract for Spec 06 */
    public function test_order_payload_contains_fixed_and_selected_lines_only_with_matching_totals(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'optional' => $opt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        app(RecordCustomerSelection::class)->handle($offer, $rep, [$alt->id => [$lines['C']->id], $opt->id => [$lines['Y']->id]], SelectionChannel::Meeting, 'Signed at the meeting');

        try {
            app(OfferToOrderPayload::class)->build($offer->fresh());
            $this->fail('Only accepted offers produce a payload.');
        } catch (OfferActionException $exception) {
            $this->assertSame('not_accepted', $exception->key);
        }

        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Meeting, 'Signed at the meeting');
        $offer->refresh();
        $payload = app(OfferToOrderPayload::class)->build($offer);

        $this->assertSame([$lines['fixed']->id, $lines['C']->id, $lines['Y']->id], array_column($payload['lines'], 'offer_line_id'));
        $this->assertSame(['1', '2C', '3B'], array_column($payload['lines'], 'option_label'));
        $this->assertSame('Air compressor', $payload['lines'][1]['group_title']);
        $this->assertSame([$offer->selected_net_minor, $offer->selected_vat_minor, $offer->selected_gross_minor], [$payload['totals']['net_minor'], $payload['totals']['vat_minor'], $payload['totals']['gross_minor']]);
        $this->assertSame($offer->id, $payload['offer']['offer_id']);
        $this->assertArrayHasKey('accountant_code', $payload['lines'][0]);
        $this->assertArrayHasKey('is_serialized', $payload['lines'][0]);
        $this->assertNotContains($lines['A']->id, array_column($payload['lines'], 'offer_line_id'));
        $this->assertSame(6, $offer->lines()->count(), 'Unselected options stay in the offer history.');
    }

    public function test_rejection_keeps_choices_and_cancel_needs_a_reason(): void
    {
        ['offer' => $offer, 'alternative' => $alt, 'lines' => $lines, 'rep' => $rep] = $this->sentFixture();
        app(RecordCustomerSelection::class)->handle($offer, $rep, [$alt->id => [$lines['A']->id]], SelectionChannel::Email, 'mail');

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $offer])
            ->call('openPanel', 'reject')
            ->call('reject')
            ->assertHasErrors('rejectReason')
            ->set('rejectReason', 'price')
            ->call('reject')
            ->assertHasNoErrors();

        $offer->refresh();
        $this->assertSame([OfferStatus::Rejected, 'price'], [$offer->status, $offer->reject_reason_code]);
        $this->assertSame([$lines['A']->id], $offer->selectedLineIds(), 'BR-17: choices kept for analytics.');

        $draft = $this->draftOffer($rep);
        Livewire::actingAs($rep)->test(Show::class, ['offer' => $draft])
            ->call('openPanel', 'cancel')
            ->call('cancel')
            ->assertHasErrors('comment')
            ->set('comment', 'Duplicate')
            ->call('cancel');
        $this->assertSame(OfferStatus::Cancelled, $draft->fresh()->status);
    }
}
