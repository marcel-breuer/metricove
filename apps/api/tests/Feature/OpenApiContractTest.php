<?php

namespace Tests\Feature;

use App\Docs\OpenApi\OpenApiContract;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Fails when the checked-in contract (docs/api/openapi.yaml) and the real routes drift apart. */
class OpenApiContractTest extends TestCase
{
    private function contract(): OpenApiContract
    {
        return new OpenApiContract;
    }

    /** @return list<string> */
    private function routeOperations(): array
    {
        $operations = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = '/'.trim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/') || $uri === '/api/mcp') {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operations[] = $method.' '.OpenApiContract::canonicalPath($uri);
            }
        }
        sort($operations);

        return $operations;
    }

    public function test_committed_spec_lists_exactly_the_routed_api_operations(): void
    {
        $committed = $this->contract()->committed();

        $this->assertSame('3.1.0', $committed['openapi']);
        $this->assertSame($this->routeOperations(), $this->contract()->operations($committed),
            'docs/api/openapi.yaml is out of date. Run `php artisan api:openapi` in apps/api and commit the result.');
    }

    public function test_committed_spec_matches_freshly_generated_contract(): void
    {
        // Scribe renders views with output buffering, so regenerate only when no output exists yet.
        if (! Storage::disk('local')->exists('scribe/openapi.yaml')) {
            $this->artisan('scribe:generate')->assertExitCode(0);
        }

        $this->assertEquals(
            $this->contract()->generated(),
            $this->contract()->committed(),
            'docs/api/openapi.yaml differs from the generated contract. Run `php artisan api:openapi` in apps/api and commit the result.',
        );
    }

    public function test_every_operation_declares_security_tags_responses_and_the_error_envelope(): void
    {
        $spec = $this->contract()->committed();
        $this->assertArrayHasKey('Error', $spec['components']['schemas']);
        $this->assertArrayHasKey('ValidationError', $spec['components']['schemas']);

        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['operationId'])) {
                    continue;
                }
                $label = strtoupper($method).' '.$path;
                $this->assertArrayHasKey('security', $operation, $label);
                $this->assertNotEmpty($operation['tags'] ?? [], $label);
                $this->assertNotEmpty($operation['summary'] ?? '', $label);
                $this->assertArrayHasKey('200', $operation['responses'], $label);
                if ($operation['security'] !== []) {
                    $this->assertArrayHasKey('401', $operation['responses'], $label);
                }
                if (in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
                    $this->assertArrayHasKey('422', $operation['responses'], $label);
                }
            }
        }
    }

    public function test_module_gated_routes_declare_their_module_in_the_spec(): void
    {
        $spec = $this->contract()->committed();

        $this->assertSame(['key' => 'finance', 'capability' => 'view'], $spec['paths']['/api/finance/transactions']['get']['x-metricove-module']);
        $this->assertTrue($spec['paths']['/api/admin/members']['get']['x-metricove-admin-only']);
        $this->assertArrayNotHasKey('/api/mcp', $spec['paths']);
    }
}
