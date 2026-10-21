<?php

namespace Tests\Feature\Offers;

use App\Actions\Offers\ManageOptionGroups;
use App\Actions\Offers\RecordCustomerSelection;
use App\Actions\Offers\RemoveOfferLine;
use App\Actions\Offers\ReviseOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Offers\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 BR-18/BR-19, AC-23/AC-24: revisions copy stable keys, the previous version is
 * superseded only when the new one is sent, selections carry over when the option still exists.
 */
class OfferRevisionTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_revision_carries_over_selections_by_stable_key(): void
    {
        $rep = User::factory()->sales()->create();
        ['offer' => $v1, 'alternative' => $alt, 'optional' => $opt, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(SendOffer::class)->handle($v1, $rep, SelectionChannel::Email);
        app(RecordCustomerSelection::class)->handle($v1->fresh(), $rep, [$alt->id => [$lines['B']->id], $opt->id => [$lines['X']->id]], SelectionChannel::Phone, 'Call');

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $v1])->call('revise')->assertRedirect();

        $v2 = $v1->fresh()->versions()->last();
        $this->assertSame([2, $v1->number, $v1->id, OfferStatus::Draft], [$v2->version, $v2->number, $v2->previous_version_id, $v2->status]);
        $this->assertSame($v1->number.'/2', $v2->displayNumber());
        $this->assertSame(OfferStatus::PartiallySelected, $v1->fresh()->status, 'AC-24: creating the draft does not change v1.');
        $this->assertSame($v2->id, app(ReviseOffer::class)->handle($v1->fresh(), $rep)->id, 'A second revise returns the existing draft.');

        // v2: B keeps its stable key with a new price, C removed, X removed.
        $v2Lines = $v2->lines()->get()->keyBy('stable_key');
        app(UpdateOfferLine::class)->handle($v2Lines[$lines['B']->stable_key], $rep, ['unit_price_minor' => 85000]);
        app(RemoveOfferLine::class)->handle($v2Lines[$lines['C']->stable_key], $rep);
        app(RemoveOfferLine::class)->handle($v2Lines[$lines['X']->stable_key], $rep);

        app(SendOffer::class)->handle($v2->fresh(), $rep, SelectionChannel::Email);

        $v1->refresh();
        $v2->refresh();
        $this->assertSame([OfferStatus::Superseded, $v2->id], [$v1->status, $v1->superseded_by_id]);
        $this->assertSame(OfferStatus::PartiallySelected, $v2->status);
        $this->assertSame([$v2Lines[$lines['B']->stable_key]->id], $v2->selectedLineIds());
        $this->assertDatabaseHas('offer_selection_events', ['offer_id' => $v2->id, 'event' => 'carried_over', 'offer_line_id' => $v2Lines[$lines['B']->stable_key]->id]);
        $this->assertDatabaseHas('offer_selection_events', ['offer_id' => $v2->id, 'event' => 'carry_over_dropped']);
        $this->assertSame(95200 + 112000, $v2->selected_gross_minor, 'Carried choice is priced with the new version.');
        $this->assertSame(1, $v1->versions()->filter(fn ($version) => $version->status->isOpen())->count(), 'At most one open version per root.');
    }

    public function test_group_removed_in_the_new_version_drops_the_choice(): void
    {
        $rep = User::factory()->sales()->create();
        ['offer' => $v1, 'alternative' => $alt, 'lines' => $lines] = $this->fixtureOffer($rep);
        app(SendOffer::class)->handle($v1, $rep, SelectionChannel::Email);
        app(RecordCustomerSelection::class)->handle($v1->fresh(), $rep, [$alt->id => [$lines['A']->id]], SelectionChannel::Phone, 'Call');

        $v2 = app(ReviseOffer::class)->handle($v1->fresh(), $rep);
        app(ManageOptionGroups::class)->remove($v2->groups()->where('stable_key', $alt->stable_key)->firstOrFail(), $rep);
        app(SendOffer::class)->handle($v2->fresh(), $rep, SelectionChannel::Email);

        $this->assertSame(OfferStatus::Sent, $v2->fresh()->status);
        $this->assertSame([], $v2->fresh()->selectedLineIds());
    }
}
