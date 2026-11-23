<?php

namespace Tests\Feature;

use App\Services\Mcp\McpApiReference;
use App\Services\Mcp\McpDomainReadWorkflowRegistry;
use App\Services\Mcp\McpWriteWorkflowRegistry;
use Tests\TestCase;

/** Keeps docs/mcp and the documentation links in step with the registered MCP surface. */
class McpDocumentationTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = base_path('../../'.$relative);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_every_registered_mcp_tool_is_documented_in_the_catalogue(): void
    {
        $catalogue = $this->read('docs/mcp/tools.md');
        $names = [
            ...array_column(app(McpDomainReadWorkflowRegistry::class)->all(), 'name'),
            ...array_column(app(McpWriteWorkflowRegistry::class)->all(), 'name'),
            'summarize_visible_tracker_entries',
            'summarize_visible_utility_readings', 'summarize_visible_vehicle_odometer_readings', 'list_visible_recipes',
            'show_visible_meal_plans', 'list_visible_pantry_items', 'list_visible_shopping_lists', 'show_visible_analytics',
            'list_visible_tasks', 'preview_utility_reading',
            'complete_approved_utility_reading', 'complete_approved_mcp_change',
        ];

        foreach ($names as $name) {
            $this->assertStringContainsString('`'.$name.'`', $catalogue, "Tool {$name} is missing from docs/mcp/tools.md");
        }
        $this->assertStringContainsString(McpApiReference::URI, $catalogue);
        $this->assertStringContainsString('metricove://mcp/approval-workflow', $catalogue);
    }

    public function test_documentation_is_linked_from_readme_and_docs_index(): void
    {
        $readme = $this->read('README.md');
        $index = $this->read('docs/README.md');

        foreach (['docs/api/README.md', 'docs/mcp/README.md', 'docs/mcp/tools.md', 'docs/api/openapi.yaml', 'docs/README.md'] as $link) {
            $this->assertStringContainsString($link, $readme, "README.md must link {$link}");
        }
        foreach (['api/README.md', 'mcp/README.md', 'mcp/tools.md', 'api/openapi.yaml'] as $link) {
            $this->assertStringContainsString($link, $index, "docs/README.md must link {$link}");
        }
    }
}
