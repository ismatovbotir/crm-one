<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Branded, translated HTTP error pages (job 10 follow-up): no scripts, nonce on every inline style
 * under the CSP, title and message from lang/{uz,ru,en}/errors.php.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_error-test/{code}', fn (int $code) => abort($code));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function codes(): array
    {
        return ['403' => [403], '404' => [404], '419' => [419], '429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('codes')]
    public function test_error_page_is_branded_translated_and_script_free(int $code): void
    {
        $response = $this->get('/_error-test/'.$code)->assertStatus($code);
        $html = (string) $response->getContent();

        $response->assertSee(__('errors.'.$code.'.title'))->assertSee(__('errors.'.$code.'.message'))->assertSee('data-error-page="'.$code.'"', false);
        $this->assertDoesNotMatchRegularExpression('/<script\b/i', $html, 'Error pages carry no scripts.');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
        $this->assertSame(
            preg_match_all('/<style\b/i', $html),
            preg_match_all('/<style\b[^>]*nonce="'.($nonce[1] ?? 'none').'"/i', $html),
            'Every inline <style> carries the request nonce.',
        );
    }

    public function test_unknown_url_and_forbidden_screen_use_the_branded_pages(): void
    {
        $missing = $this->get('/no-such-page-anywhere')->assertNotFound()->assertSee(__('errors.404.title'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", (string) $missing->headers->get('Content-Security-Policy'), 'Unmatched routes get the CSP too (global middleware).');
        $this->actingAs(User::factory()->sales()->create())->get(route('users.index'))->assertForbidden()->assertSee(__('errors.403.title'));
    }

    public function test_every_locale_has_every_error_text(): void
    {
        foreach (['uz', 'ru', 'en'] as $locale) {
            App::setLocale($locale);

            foreach (array_keys(self::codes()) as $code) {
                foreach (['title', 'message'] as $part) {
                    $this->assertTrue(trans()->hasForLocale('errors.'.$code.'.'.$part, $locale), "errors.$code.$part missing in $locale");
                }
            }

            $html = view('errors.404')->render();
            $this->assertStringContainsString(__('errors.404.title', [], $locale), $html);
        }
    }
}
