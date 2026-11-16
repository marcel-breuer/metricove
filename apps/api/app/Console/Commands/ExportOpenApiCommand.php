<?php

namespace App\Console\Commands;

use App\Docs\OpenApi\OpenApiContract;
use Illuminate\Console\Command;

class ExportOpenApiCommand extends Command
{
    protected $signature = 'api:openapi';

    protected $description = 'Regenerate the checked-in OpenAPI contract (docs/api/openapi.yaml) from the current routes';

    public function handle(OpenApiContract $contract): int
    {
        $this->call('scribe:generate');
        $contract->write();
        $this->info('Wrote '.realpath(OpenApiContract::path()));

        return self::SUCCESS;
    }
}
