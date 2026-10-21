<?php

namespace Tests\Feature\Service;

use App\Actions\Service\ManageServiceTicket;
use App\Enums\AssetSource;
use App\Enums\DepartmentRole;
use App\Livewire\Service\Assets;
use App\Livewire\Service\AssetShow;
use App\Livewire\Service\Tickets;
use App\Livewire\Service\TicketShow;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\Equipment\AssetRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 09 §7 (user decision 2026-10-20): sales read the service tickets and equipment of the
 * customers they own (sales head: department) — list and detail, no manage actions; other owners'
 * records are neither listed nor openable (IDOR).
 */
class SalesServiceAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: CustomerAsset, 1: ServiceTicket}
     */
    private function ticketFor(User $owner, string $serial): array
    {
        $customer = Customer::factory()->create(['owner_id' => $owner->id, 'name' => 'Client of '.$owner->name]);
        $asset = app(AssetRegister::class)->register($customer, ['serial' => $serial, 'model_name' => 'Printer'], $owner, AssetSource::StaffRegistered);
        $ticket = app(ManageServiceTicket::class)->open($asset, ['problem' => 'Paper jam'], $owner, 'staff');

        return [$asset, $ticket];
    }

    public function test_sales_see_only_their_customers_tickets_and_equipment_without_manage_actions(): void
    {
        $rep = User::factory()->sales()->create(['name' => 'Rep A']);
        $other = User::factory()->sales()->create(['name' => 'Rep B']);
        [$ownAsset, $ownTicket] = $this->ticketFor($rep, 'SRV-001');
        [$foreignAsset, $foreignTicket] = $this->ticketFor($other, 'SRV-002');

        Livewire::actingAs($rep)->test(Tickets::class)->set('queue', 'all')->assertSee($ownTicket->number)->assertDontSee($foreignTicket->number);
        Livewire::actingAs($rep)->test(Assets::class)->assertSee('SRV-001')->assertDontSee('SRV-002');

        $this->actingAs($rep)->get(route('service.tickets.show', $ownTicket))->assertOk();
        $this->actingAs($rep)->get(route('service.tickets.show', $foreignTicket))->assertForbidden();
        $this->actingAs($rep)->get(route('service.assets.show', $foreignAsset))->assertForbidden();

        Livewire::actingAs($rep)->test(TicketShow::class, ['ticket' => $ownTicket])
            ->assertViewHas('canManage', false)
            ->set('target', 'accepted')->call('moveTicket')->assertForbidden();
        Livewire::actingAs($rep)->test(AssetShow::class, ['asset' => $ownAsset])->assertViewHas('canManage', false);
        $this->assertFalse($rep->can('manage', $ownTicket));
    }

    public function test_sales_head_sees_the_department_and_admin_still_reads_all(): void
    {
        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $member = User::factory()->staff()->inDepartment('sales')->create();
        $outsider = User::factory()->sales()->create();
        [, $memberTicket] = $this->ticketFor($member, 'SRV-101');
        [, $outsiderTicket] = $this->ticketFor($outsider, 'SRV-102');

        Livewire::actingAs($head)->test(Tickets::class)->set('queue', 'all')->assertSee($memberTicket->number)->assertDontSee($outsiderTicket->number);
        $this->assertTrue($head->can('view', $memberTicket));
        $this->assertFalse($head->can('view', $outsiderTicket));

        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Tickets::class)->set('queue', 'all')->assertSee($memberTicket->number)->assertSee($outsiderTicket->number);
        $this->assertFalse($admin->can('manage', $memberTicket), 'Admin stays read-only.');
        $this->actingAs($member)->get(route('dashboard'))->assertOk()->assertDontSee(__('dashboard.tiles.service_overdue'));
    }
}
