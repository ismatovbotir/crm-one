<?php

namespace Tests\Feature\Offers;

use App\Enums\DepartmentRole;
use App\Enums\OfferStatus;
use App\Livewire\Offers\Builder;
use App\Livewire\Offers\Index;
use App\Livewire\Offers\Show;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 section 4.1: visibility scopes (own + own customers' for sales, department for
 * heads, all for manager/finance/admin), read-only roles, and no IDOR on any offer route or
 * Livewire action.
 */
class OfferPolicyTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function offerRoutes(): array
    {
        return ['show' => ['offers.show'], 'edit' => ['offers.edit'], 'print' => ['offers.print']];
    }

    #[DataProvider('offerRoutes')]
    public function test_a_sales_rep_cannot_open_another_reps_offer(string $route): void
    {
        $owner = User::factory()->sales()->create();
        $intruder = User::factory()->sales()->create();
        $offer = $this->draftOffer($owner);

        $this->actingAs($intruder)->get(route($route, $offer))->assertForbidden();
        $this->actingAs($owner)->get(route($route, $offer))->assertOk();
    }

    public function test_livewire_actions_on_a_foreign_offer_are_refused(): void
    {
        $owner = User::factory()->sales()->create();
        $intruder = User::factory()->sales()->create();
        ['offer' => $offer, 'lines' => $lines] = $this->fixtureOffer($owner);

        Livewire::actingAs($intruder)->test(Show::class, ['offer' => $offer])->assertForbidden();
        Livewire::actingAs($intruder)->test(Builder::class, ['offer' => $offer])->assertForbidden();

        // A component mounted by the owner cannot be driven by someone else: every action re-authorises.
        $builder = Livewire::actingAs($owner)->test(Builder::class, ['offer' => $offer]);
        $this->actingAs($intruder);
        $builder->call('removeLine', $lines['fixed']->id)->assertForbidden();

        $show = Livewire::actingAs($owner)->test(Show::class, ['offer' => $offer]);
        $this->actingAs($intruder);
        $show->set('channel', 'email')->call('send')->assertForbidden();

        $this->assertNotNull($lines['fixed']->fresh());
        $this->assertSame(OfferStatus::Draft, $offer->fresh()->status);
    }

    public function test_index_lists_only_visible_offers(): void
    {
        $rep = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $mine = $this->draftOffer($rep);
        $foreign = $this->draftOffer($other);
        $ownCustomer = Customer::factory()->ownedBy($rep)->create();
        $linked = $this->draftOffer($other, $ownCustomer);

        Livewire::actingAs($rep)->test(Index::class)
            ->assertSee($mine->number)
            ->assertSee($linked->number)
            ->assertDontSee($foreign->number);

        $this->assertTrue($rep->can('view', $linked), 'Sales see offers of customers they own (linked).');
        $this->assertFalse($rep->can('update', $linked), 'Seeing is not editing.');
    }

    public function test_department_head_sees_and_manages_the_department(): void
    {
        $head = User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create();
        $member = User::factory()->sales()->inDepartment('sales')->create();
        $outsider = User::factory()->sales()->create();
        $offer = $this->draftOffer($member);
        $outside = $this->draftOffer($outsider);

        $this->assertTrue($head->can('view', $offer));
        $this->assertTrue($head->can('update', $offer));
        $this->assertFalse($head->can('view', $outside));
    }

    public function test_finance_and_admin_read_only_manager_everything(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);

        foreach ([User::factory()->finance()->create(), User::factory()->admin()->create()] as $reader) {
            $this->assertTrue($reader->can('view', $offer));
            $this->assertTrue($reader->can('print', $offer));
            $this->assertFalse($reader->can('update', $offer));
            $this->assertFalse($reader->can('create', Offer::class));
        }

        $manager = User::factory()->manager()->create();
        $this->assertTrue($manager->can('update', $offer));
        $this->assertTrue($manager->can('cancel', $offer));
    }

    public function test_withdrawing_a_sent_offer_needs_a_manager(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = Offer::factory()->ownedBy($rep)->status(OfferStatus::Sent)->create();

        $this->assertFalse($rep->can('cancel', $offer));
        $this->assertTrue(User::factory()->manager()->create()->can('cancel', $offer));
        $this->assertTrue($rep->can('recordDecision', $offer));
        $this->assertTrue($rep->can('revise', $offer));
    }

    public function test_staff_without_offer_rights_cannot_open_offers(): void
    {
        $logistics = User::factory()->staff()->inDepartment('logistics')->create();

        $this->actingAs($logistics)->get(route('offers.index'))->assertForbidden();
    }
}
