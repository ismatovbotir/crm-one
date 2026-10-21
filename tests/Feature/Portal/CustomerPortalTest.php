<?php

namespace Tests\Feature\Portal;

use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Offers\SendOffer;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Portal\Login;
use App\Livewire\Portal\OfferShow;
use App\Livewire\Portal\Users;
use App\Models\AuthEvent;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\PortalMembership;
use App\Models\User;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 03 (customer portal): OTP login with generic answers and limits, realm isolation, company
 * scoping (404), offer acceptance within the threshold, the last-admin guard.
 */
class CustomerPortalTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    /**
     * @param  list<string>  $roles
     */
    private function portalMember(?Customer $customer = null, array $roles = ['portal_admin'], string $email = 'buyer@client.uz', ?int $limit = null): PortalMembership
    {
        $customer ??= Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS']);
        $access = app(ManagePortalAccess::class);
        $access->configure($customer, true, $customer->portal_approval_threshold_minor);

        return $access->grant($customer->fresh(), $email, 'Client Buyer', $roles, null, $limit, $this->rep);
    }

    private function actAsPortal(PortalMembership $membership): User
    {
        $user = $membership->user->fresh();
        $this->actingAs($user, 'portal');
        Auth::shouldUse('portal');
        app(PortalContext::class)->set($user, $membership->fresh());

        return $user;
    }

    private function lastMailCode(): ?string
    {
        $mail = collect(app('mailer')->getSymfonyTransport()->messages())->last();

        if ($mail === null || preg_match('/\b(\d{6})\b/', strip_tags((string) $mail->getOriginalMessage()->getHtmlBody()), $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function sentOffer(Customer $customer, string $price = '1000.00'): Offer
    {
        $offer = $this->draftOffer($this->rep, $customer);
        $this->addLine($offer, $this->pricedItem($price));
        app(SendOffer::class)->handle($offer->refresh(), $this->rep, SelectionChannel::Email);

        return $offer->refresh();
    }

    public function test_email_code_signs_the_portal_user_in_on_the_portal_guard(): void
    {
        $membership = $this->portalMember();
        $mailsBefore = count(app('mailer')->getSymfonyTransport()->messages());

        Livewire::test(Login::class)->set('identifier', 'Buyer@Client.uz')->set('channel', 'email')->call('send')->assertSet('sent', true);
        $this->assertCount($mailsBefore + 1, app('mailer')->getSymfonyTransport()->messages());
        $code = $this->lastMailCode();
        $this->assertNotNull($code);

        Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->set('sent', true)->set('code', $code)->call('verify')->assertRedirect(route('portal.dashboard'));

        $this->assertTrue(Auth::guard('portal')->check());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertSame($membership->user_id, Auth::guard('portal')->id());
        $this->assertTrue(AuthEvent::query()->where('event', 'login')->where('user_id', $membership->user_id)->exists());
    }

    public function test_unknown_identifiers_get_the_same_answer_and_no_mail(): void
    {
        $this->portalMember();
        $mailsBefore = count(app('mailer')->getSymfonyTransport()->messages());

        $known = Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->call('send');
        $mailsAfterKnown = count(app('mailer')->getSymfonyTransport()->messages());
        $unknown = Livewire::test(Login::class)->set('identifier', 'nobody@client.uz')->call('send');
        $staff = Livewire::test(Login::class)->set('identifier', $this->rep->email)->call('send');

        $this->assertSame($mailsBefore + 1, $mailsAfterKnown);
        $this->assertCount($mailsAfterKnown, app('mailer')->getSymfonyTransport()->messages());

        foreach ([$known, $unknown, $staff] as $component) {
            $component->assertSet('sent', true)->assertSee(__('portal.login.sent_generic'));
        }
    }

    public function test_wrong_codes_are_rejected_and_attempts_are_limited(): void
    {
        $this->portalMember();
        Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->call('send');
        $code = $this->lastMailCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 10; $i++) {
            Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->set('code', $wrong)->call('verify')->assertHasErrors('code');
        }

        // Even the right code is refused once the identifier is locked.
        Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->set('code', $code)->call('verify')->assertHasErrors('code');
        $this->assertFalse(Auth::guard('portal')->check());
    }

    public function test_deactivated_members_cannot_request_codes(): void
    {
        $membership = $this->portalMember();
        app(ManagePortalAccess::class)->deactivate($membership, $this->rep);
        $mailsBefore = count(app('mailer')->getSymfonyTransport()->messages());

        Livewire::test(Login::class)->set('identifier', 'buyer@client.uz')->call('send');

        $this->assertCount($mailsBefore, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_guests_are_sent_to_the_portal_login(): void
    {
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
        $this->get(route('portal.login'))->assertOk()->assertSee(__('portal.login.title'));
    }

    public function test_realms_are_isolated(): void
    {
        $membership = $this->portalMember();

        // A portal user never reaches internal screens.
        $this->actingAs($membership->user->fresh(), 'portal');
        Auth::shouldUse('web'); // each real request starts on the default (staff) guard
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('portal.dashboard'))->assertOk();

        // A staff session cannot open the portal.
        Auth::guard('portal')->logout();
        $this->actingAs($this->rep);
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    }

    public function test_another_companys_offer_is_not_found(): void
    {
        $mine = $this->portalMember();
        $other = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS']);
        $foreign = $this->sentOffer($other);
        $own = $this->sentOffer($mine->customer);

        $this->actingAs($mine->user->fresh(), 'portal');

        $this->get(route('portal.offers.show', $own))->assertOk()->assertSee($own->displayNumber());
        $this->get(route('portal.offers.show', $foreign))->assertNotFound();
        $this->get(route('portal.offers.print', $foreign))->assertNotFound();
        $this->get(route('portal.offers.index'))->assertOk()->assertDontSee($foreign->displayNumber());
        $this->assertSame(OfferStatus::Viewed, $own->fresh()->status);
    }

    public function test_viewer_and_finance_roles_do_not_see_offer_drafts_or_users_page(): void
    {
        $member = $this->portalMember(roles: ['viewer']);
        $draft = $this->draftOffer($this->rep, $member->customer);
        $this->actingAs($member->user->fresh(), 'portal');

        $this->get(route('portal.offers.show', $draft))->assertNotFound();
        $this->get(route('portal.users'))->assertForbidden();
        $this->get(route('portal.invoices.index'))->assertForbidden();
    }

    public function test_buyer_accepts_within_the_threshold_and_needs_an_approver_above_it(): void
    {
        $customer = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS', 'portal_approval_threshold_minor' => 150000]);
        $buyer = $this->portalMember($customer, ['buyer']);
        $small = $this->sentOffer($customer, '1000.00');
        $large = $this->sentOffer($customer, '5000.00');
        $this->actAsPortal($buyer);

        Livewire::test(OfferShow::class, ['offer' => $large])->call('accept')->assertSee(__('portal.offers.above_limit'));
        $this->assertNotSame(OfferStatus::Accepted, $large->fresh()->status);

        Livewire::test(OfferShow::class, ['offer' => $small])->call('accept');
        $this->assertSame(OfferStatus::Accepted, $small->fresh()->status);
        $this->assertTrue(AuthEvent::query()->where('event', 'offer_accepted')->where('user_id', $buyer->user_id)->exists());
    }

    public function test_viewer_cannot_accept(): void
    {
        $viewer = $this->portalMember(roles: ['viewer']);
        $offer = $this->sentOffer($viewer->customer);
        $this->actAsPortal($viewer);

        Livewire::test(OfferShow::class, ['offer' => $offer])->call('accept')->assertForbidden();
        $this->assertNotSame(OfferStatus::Accepted, $offer->fresh()->status);
    }

    public function test_the_last_portal_admin_cannot_be_deactivated_by_a_portal_user(): void
    {
        $admin = $this->portalMember();
        $this->actAsPortal($admin);

        Livewire::test(Users::class)->call('deactivate', $admin->id)->assertSee(__('portal.users.errors.last_admin'));
        $this->assertTrue($admin->fresh()->is_active);

        Livewire::test(Users::class)->set('form.email', $this->rep->email)->set('form.name', 'Staff')->set('form.roles', ['buyer'])->call('grant')->assertHasErrors('email');
    }

    public function test_one_email_cannot_hold_access_to_two_companies(): void
    {
        $this->portalMember();
        $other = Customer::factory()->ownedBy($this->rep)->create();

        $this->expectException(ValidationException::class);
        $this->portalMember($other);
    }

    public function test_deactivation_ends_an_open_session_on_the_next_request(): void
    {
        $membership = $this->portalMember();
        $this->actingAs($membership->user->fresh(), 'portal');
        $this->get(route('portal.dashboard'))->assertOk();

        PortalMembership::query()->whereKey($membership->id)->update(['is_active' => false]);

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
        $this->assertFalse(Auth::guard('portal')->check());
    }
}
