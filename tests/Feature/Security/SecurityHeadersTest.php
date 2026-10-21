<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * App\Http\Middleware\SecurityHeaders: CSP with a per-request nonce and the companion headers.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staffPages')]
    public function test_pages_render_with_a_nonce_based_policy(string $uri): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get($uri)->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $match);

        $scriptSrc = collect(explode(';', $policy))->first(fn (string $part): bool => str_starts_with(trim($part), 'script-src '));
        $this->assertStringNotContainsString("'unsafe-inline'", (string) $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-eval'", (string) $scriptSrc, 'Livewire runs the CSP-safe Alpine build (livewire.csp_safe).');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);

        // Every inline <style>/<script> on the page carries this request's nonce.
        $html = $response->getContent();
        $this->assertSame(
            preg_match_all('/<(style|script)\b(?![^>]*\bsrc=)[^>]*>/', $html),
            preg_match_all('/<(style|script)\b(?![^>]*\bsrc=)[^>]*nonce="'.$match[1].'"[^>]*>/', $html),
            'An inline <style>/<script> is missing the CSP nonce',
        );
        $this->assertStringContainsString('id="theme-overrides" nonce="'.$match[1].'"', $html);
    }

    public static function staffPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'settings' => ['/settings/appearance'],
            'customers' => ['/customers'],
            'items' => ['/items'],
            'print' => ['/print/sample'],
        ];
    }

    public function test_nonce_changes_per_request(): void
    {
        $first = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $second = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_companion_headers_are_sent(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_sent_only_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->get(route('login'))->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_print_button_has_no_inline_event_handler(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/print/sample')->assertOk()->assertDontSee('onclick=', false);
    }
}
