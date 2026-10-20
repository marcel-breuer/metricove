<?php

use App\Models\AiContextEntry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('metricove:purge-ai-context', function (): void {
    $deleted = AiContextEntry::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
    $this->info("Removed {$deleted} expired AI context entries.");
})->purpose('Delete expired personal AI context entries');

Schedule::command('metricove:purge-ai-context')->hourly();
