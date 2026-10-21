<?php

namespace Tests\Feature\Customers;

use App\Enums\DepartmentRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 01 section 3 permission matrix for customers, extended with department grants (Spec 03 4.9).
 * The customer is owned by a sales rep who is a member of the sales department.
 */
class CustomerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const ABILITIES = ['view', 'create', 'update', 'changeOwner', 'updateCommercialTerms', 'archive', 'manageContacts'];

    /**
     * Expected results in ABILITIES order.
     *
     * @return array<string, array{string, list<bool>}>
     */
    public static function matrix(): array
    {
        return [
            'admin' => ['admin', [true, true, true, true, true, true, true]],
            'manager' => ['manager', [true, true, true, true, true, true, true]],
            'sales owner' => ['owner', [true, true, true, false, false, false, true]],
            'other sales rep' => ['other_sales', [true, true, false, false, false, false, false]],
            'finance' => ['finance', [true, false, false, false, true, false, false]],
            'sales department head (staff)' => ['sales_head', [true, true, true, true, false, true, true]],
            'support viewer (staff, other department)' => ['support_viewer', [false, false, false, false, false, false, false]],
            'staff without department' => ['staff', [false, false, false, false, false, false, false]],
        ];
    }

    private function actor(string $key, User $owner): User
    {
        return match ($key) {
            'admin' => User::factory()->admin()->create(),
            'manager' => User::factory()->manager()->create(),
            'owner' => $owner,
            'other_sales' => User::factory()->sales()->create(),
            'finance' => User::factory()->finance()->create(),
            'sales_head' => User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create(),
            'support_viewer' => User::factory()->staff()->inDepartment('support', DepartmentRole::Viewer)->create(),
            'staff' => User::factory()->staff()->create(),
        };
    }

    /**
     * @param  list<bool>  $expected
     */
    #[DataProvider('matrix')]
    public function test_customer_permission_matrix(string $actorKey, array $expected): void
    {
        $owner = User::factory()->sales()->inDepartment('sales')->create();
        $customer = Customer::factory()->ownedBy($owner)->create();
        $actor = $this->actor($actorKey, $owner);

        foreach (self::ABILITIES as $index => $ability) {
            $allowed = $ability === 'create' ? $actor->can('create', Customer::class) : $actor->can($ability, $customer);

            $this->assertSame($expected[$index], $allowed, "{$actorKey} → {$ability}");
        }
    }

    public function test_only_admin_restores_archived_customers(): void
    {
        $customer = Customer::factory()->archived()->create();

        $this->assertTrue(User::factory()->admin()->create()->can('restore', $customer));
        $this->assertFalse(User::factory()->manager()->create()->can('restore', $customer));
        $this->assertFalse(User::factory()->admin()->create()->can('restore', Customer::factory()->create()), 'Nothing to restore on an active customer.');
    }

    public function test_archived_customers_are_read_only_for_everyone(): void
    {
        $customer = Customer::factory()->archived()->create();
        $admin = User::factory()->admin()->create();

        $this->assertTrue($admin->can('view', $customer));
        $this->assertFalse($admin->can('update', $customer));
        $this->assertFalse($admin->can('archive', $customer));
        $this->assertFalse($admin->can('delete', $customer));
    }
}
