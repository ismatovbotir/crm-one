<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\OpenApi\OneCIntegrationSpec;
use App\Support\OpenApi\YamlEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The OpenAPI description of the 1C integration API matches the code: every integration route is
 * documented and every documented operation exists; the committed copy is current; the docs page
 * is self-hosted, CSP-safe, read-only and restricted outside local.
 */
class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string> "METHOD /path" relative to the server path
     */
    private function routeOperations(): array
    {
        $prefix = ltrim(OneCIntegrationSpec::SERVER_PATH, '/');
        $operations = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix.'/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operations[] = $method.' '.substr($route->uri(), strlen($prefix));
            }
        }

        sort($operations);

        return $operations;
    }

    public function test_every_integration_route_is_documented_and_nothing_else(): void
    {
        $documented = [];

        foreach ((new OneCIntegrationSpec)->build()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.$path;
            }
        }

        sort($documented);
        $this->assertNotEmpty($documented);
        $this->assertSame($this->routeOperations(), $documented, 'Update App\Support\OpenApi\OneCIntegrationSpec (and run php artisan crm:openapi --write).');
    }

    public function test_spec_is_openapi_31_with_responses_and_examples_only(): void
    {
        $spec = (new OneCIntegrationSpec)->build();
        $this->assertSame('3.1.0', $spec['openapi']);

        foreach ($spec['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $codes = array_map('strval', array_keys($operation['responses']));
                $this->assertNotEmpty(array_filter($codes, fn (string $code): bool => str_starts_with($code, '2')), "{$method} {$path} needs a 2xx response");
                $this->assertContains('401', $codes);
            }
        }

        $json = (string) json_encode($spec);
        $this->assertDoesNotMatchRegularExpression('/1c_[A-Za-z0-9]{48}/', $json, 'Never embed a real token.');

        foreach (['accountant_code', 'class_code', 'package_code', 'is_serialized', 'purchase_price', 'sale_currency', 'cost_currency', 'serials', 'goods_receipt.posted', 'delivery.posted', 'OrganizationRoles', 'prepared-not-active'] as $needle) {
            $this->assertStringContainsString($needle, $json);
        }
    }

    public function test_committed_copies_are_current_and_yaml_matches_json(): void
    {
        $spec = (new OneCIntegrationSpec)->build();

        $this->assertSame(json_decode((string) file_get_contents(base_path('docs/openapi/1c-integration.json')), true), json_decode((string) json_encode($spec), true), 'Run php artisan crm:openapi --write.');
        $this->assertEquals(json_decode((string) json_encode($spec), true), Yaml::parse(YamlEmitter::dump($spec)));
    }

    public function test_docs_page_is_restricted_self_hosted_and_read_only(): void
    {
        $this->get(route('api-docs'))->assertRedirect(route('login'));
        $this->get(route('api-docs.json'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->sales()->create())->get(route('api-docs'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $page = $this->actingAs($admin)->get(route('api-docs'))->assertOk()
            ->assertSee('vendor/swagger-ui/swagger-ui-bundle.js', false)
            ->assertSee('vendor/swagger-ui/crm-api-docs.js', false)
            ->assertHeader('Content-Security-Policy');
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $page->getContent(), 'No inline scripts.');
        $this->assertStringNotContainsString('unpkg.com', $page->getContent());
        $this->assertStringNotContainsString('cdn', mb_strtolower($page->getContent()));

        $this->actingAs($admin)->get(route('api-docs.json'))->assertOk()->assertJsonPath('openapi', '3.1.0');
        $this->actingAs($admin)->get(route('api-docs.yaml'))->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');

        $this->assertFileExists(public_path('vendor/swagger-ui/swagger-ui-bundle.js'));
        $this->assertStringContainsString('supportedSubmitMethods: []', (string) file_get_contents(public_path('vendor/swagger-ui/crm-api-docs.js')));
        $this->assertFileExists(public_path('vendor/swagger-ui/LICENSE'));

        $this->actingAs($admin)->get(route('settings.integrations'))->assertOk()->assertSee(__('integration.docs.link'))->assertSee(route('api-docs'), false);
    }

    public function test_docs_are_never_public_even_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('api-docs.json'))->assertRedirect(route('login'));
        $this->get(route('api-docs'))->assertRedirect(route('login'));
    }
}
