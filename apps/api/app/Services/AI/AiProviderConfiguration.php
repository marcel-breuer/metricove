<?php

namespace App\Services\AI;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AiProviderConfiguration
{
    /** @return array{provider: string, base_url: ?string, model: ?string, api_key: ?string, external_provider: bool} */
    public function current(): array
    {
        $settings = HouseholdSettings::query()->find(1);

        return [
            'provider' => $settings?->ai_provider ?? (string) config('ai.provider', 'disabled'),
            'base_url' => $settings?->ai_base_url ?? config('ai.base_url'),
            'model' => $settings?->ai_model ?? config('ai.model'),
            'api_key' => $settings?->ai_api_key ?? config('ai.api_key'),
            'external_provider' => $settings?->ai_external_provider ?? (bool) config('ai.external_provider', false),
        ];
    }

    /** @return array{provider: string, base_url: ?string, model: ?string, api_key_configured: bool, external_provider: bool} */
    public function forAdmin(): array
    {
        $configuration = $this->current();

        return [
            'provider' => $configuration['provider'],
            'base_url' => $configuration['base_url'],
            'model' => $configuration['model'],
            'api_key_configured' => is_string($configuration['api_key']) && $configuration['api_key'] !== '',
            'external_provider' => $configuration['external_provider'],
        ];
    }

    /** @param array{provider: string, base_url: ?string, model: ?string, external_provider: bool, api_key?: ?string, clear_api_key?: bool} $data
     * @return array{provider: string, base_url: ?string, model: ?string, api_key_configured: bool, external_provider: bool}
     */
    public function update(User $actor, array $data): array
    {
        DB::transaction(function () use ($actor, $data): void {
            $settings = HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $currentActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($currentActor instanceof User && $currentActor->is_active && $currentActor->is_admin, 403);

            $settings->ai_provider = $data['provider'];
            $settings->ai_base_url = $data['base_url'];
            $settings->ai_model = $data['model'];
            $settings->ai_external_provider = $data['external_provider'];

            if (($data['clear_api_key'] ?? false) === true) {
                $settings->ai_api_key = '';
            } elseif (is_string($data['api_key'] ?? null) && trim((string) $data['api_key']) !== '') {
                $settings->ai_api_key = trim((string) $data['api_key']);
            }

            $settings->save();

            HouseholdAuditEvent::query()->create([
                'actor_user_id' => $currentActor->id,
                'subject_user_id' => null,
                'action' => 'household.ai_provider_settings_changed',
                'details' => [
                    'provider' => $data['provider'],
                    'external_provider' => $data['external_provider'],
                ],
                'created_at' => now(),
            ]);
        }, 3);

        return $this->forAdmin();
    }
}
