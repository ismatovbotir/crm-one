<?php

namespace Tests\Feature\Agreements;

use App\Actions\Agreements\RenewAgreement;
use App\Actions\Agreements\RunAgreementLifecycle;
use App\Actions\Agreements\TerminateAgreement;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\LinkFrameworkAgreement;
use App\Enums\AgreementStatus;
use App\Enums\HoldReason;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 BR-AG.3/8/9, AC-17, AC-18: activation, expiry reminders once per stage, expiry,
 * renewal, framework link, termination.
 */
class AgreementLifecycleTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    /** AC-17 */
    public function test_reminders_are_sent_once_per_stage_and_the_agreement_expires(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Tashkent'));
        $agreement = Agreement::factory()->status(AgreementStatus::Active)->create(['starts_on' => '2025-11-01', 'ends_on' => '2026-11-17']);

        app(RunAgreementLifecycle::class)->handle();
        app(RunAgreementLifecycle::class)->handle();
        $agreement->refresh();

        $this->assertSame(['expiry_90', 'expiry_60', 'expiry_30'], $agreement->reminders_sent);
        $this->assertSame(1, $agreement->activities()->where('event', 'expiry_reminder')->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-18 01:00', 'Asia/Tashkent'));
        Artisan::call('agreements:lifecycle');

        $this->assertSame(AgreementStatus::Expired, $agreement->fresh()->status);
    }

    /** AC-17 renewal */
    public function test_a_signed_renewal_retires_the_old_agreement(): void
    {
        $this->setUpCompany();
        $this->seedAgreementTemplates();
        $accountant = User::factory()->accountant()->create();
        $customer = $this->agreementReadyCustomer(User::factory()->sales()->create());
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Tashkent'));
        $old = Agreement::factory()->status(AgreementStatus::Active)->create([
            'customer_id' => $customer->id, 'template_id' => AgreementTemplate::query()->where('code', 'framework-standard')->value('id'),
            'starts_on' => '2025-10-20', 'ends_on' => '2026-10-19',
        ]);

        $renewal = app(RenewAgreement::class)->handle($old, $accountant);
        $this->assertSame('2026-10-20', $renewal->starts_on->toDateString());
        $this->assertSame($old->id, $renewal->renewal_of_id);
        $renewal->forceFill(['status' => AgreementStatus::Signed, 'signed_on' => '2026-10-18'])->save();

        $this->expectsNoSecondRenewal($old, $accountant);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 00:10', 'Asia/Tashkent'));
        app(RunAgreementLifecycle::class)->handle();

        $this->assertSame(AgreementStatus::Active, $renewal->fresh()->status);
        $this->assertSame(AgreementStatus::Renewed, $old->fresh()->status);
        $this->assertSame($renewal->id, $old->fresh()->renewed_by_id);
    }

    private function expectsNoSecondRenewal(Agreement $old, User $user): void
    {
        try {
            app(RenewAgreement::class)->handle($old, $user);
            $this->fail('Only one renewal at a time.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('renewal_exists', $exception->key);
        }
    }

    /** BR-AG.3 + AC-18 */
    public function test_framework_link_satisfies_the_requirement_and_blocks_termination_while_orders_are_open(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $framework = Agreement::factory()->status(AgreementStatus::Active)->create(['customer_id' => $order->customer_id, 'notice_days' => 30]);
        $order->forceFill(['hold_reasons' => [['reason' => 'agreement_unsigned', 'set_at' => now()->toIso8601String(), 'overridden_at' => null, 'overridden_by' => null, 'override_reason' => null]], 'on_hold' => true])->save();

        app(LinkFrameworkAgreement::class)->handle($order, $framework);
        $order->refresh();
        $this->assertTrue($order->hasBindingAgreement());
        $this->assertNotContains(HoldReason::AgreementUnsigned, $order->activeHolds());

        $finance = User::factory()->finance()->create();

        try {
            app(TerminateAgreement::class)->handle($framework, $finance, now()->addDays(40)->toDateString(), 'customer_request', 'Moving to tenders');
            $this->fail('Open orders must block termination.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('open_orders', $exception->key);
            $this->assertStringContainsString($order->number, $exception->getMessage());
        }

        app(CancelOrder::class)->handle($order, User::factory()->manager()->create(), 'Moved');

        try {
            app(TerminateAgreement::class)->handle($framework, $finance, now()->addDays(5)->toDateString(), 'customer_request', 'Too early');
            $this->fail('The notice period applies.');
        } catch (AgreementActionException $exception) {
            $this->assertSame('notice_period', $exception->key);
        }

        app(TerminateAgreement::class)->handle($framework, $finance, now()->addDays(5)->toDateString(), 'mutual', 'Both sides agree');
        $this->assertSame(AgreementStatus::Terminated, $framework->fresh()->status);
        $this->assertFalse(User::factory()->accountant()->create()->can('terminate', Agreement::factory()->status(AgreementStatus::Active)->create()));
    }
}
