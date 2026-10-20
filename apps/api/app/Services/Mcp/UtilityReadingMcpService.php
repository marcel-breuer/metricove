<?php

namespace App\Services\Mcp;

use App\Models\HouseholdAuditEvent;
use App\Models\HouseholdSettings;
use App\Models\McpAccessToken;
use App\Models\McpActionPreview;
use App\Models\User;
use App\Models\UtilityReading;
use App\Services\Utilities\DecimalValue;
use App\Services\Utilities\UtilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UtilityReadingMcpService
{
    public function __construct(
        private readonly McpAccessService $access,
        private readonly UtilityService $utilities,
    ) {}

    /** @param array<string, mixed> $arguments
     * @return array{preview_id: string, state: string, meter: string, reading_date: string, value: string, unit: string, expires_at: string}
     */
    public function preview(McpAccessToken $token, array $arguments): array
    {
        if (array_diff(array_keys($arguments), ['meter_id', 'reading_date', 'value', 'note']) !== []
            || ! isset($arguments['meter_id'], $arguments['reading_date'], $arguments['value'])
            || ! is_int($arguments['meter_id'])
            || ! is_string($arguments['reading_date'])
            || ! is_string($arguments['value'])
            || (isset($arguments['note']) && ! is_string($arguments['note']))) {
            throw ValidationException::withMessages(['arguments' => 'The utility reading preview arguments are invalid.']);
        }

        $date = $arguments['reading_date'];
        $value = DecimalValue::normalize($arguments['value']);
        $note = trim($arguments['note'] ?? '');

        if (! $this->validDate($date)) {
            throw ValidationException::withMessages(['reading_date' => 'Enter a valid date in YYYY-MM-DD format.']);
        }
        if ($value === null) {
            throw ValidationException::withMessages(['value' => 'Enter a valid non-negative decimal reading.']);
        }
        if (mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'The note may not exceed 2000 characters.']);
        }

        return DB::transaction(function () use ($token, $arguments, $date, $value, $note): array {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $freshToken = McpAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
            $actor = $this->access->assertTokenAllows($freshToken, 'utilities:create');
            $context = $this->utilities->importContext($actor, $arguments['meter_id'], true);
            $this->assertChronology($context['readings'], $date, $value);

            $preview = McpActionPreview::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $actor->id,
                'mcp_access_token_id' => $freshToken->id,
                'action' => 'utility_reading.create',
                'payload' => [
                    'meter_id' => $context['meter']->id,
                    'meter_name' => $context['meter']->name,
                    'unit' => $context['meter']->unit,
                    'reading_date' => $date,
                    'value' => $value,
                    'note' => $note === '' ? null : $note,
                ],
                'state' => 'pending',
                'expires_at' => now()->addMinutes(10),
            ]);

            $this->audit($actor, 'mcp.action.previewed', [
                'preview_id' => $preview->id,
                'token_id' => $freshToken->id,
                'action' => $preview->action,
            ]);

            return [
                'preview_id' => $preview->id,
                'state' => 'pending_human_approval',
                'meter' => $context['meter']->name,
                'reading_date' => $date,
                'value' => $value,
                'unit' => $context['meter']->unit,
                'expires_at' => $preview->expires_at->toISOString(),
            ];
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        return McpActionPreview::query()
            ->where('user_id', $user->id)
            ->whereIn('state', ['pending', 'approved'])
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get()
            ->map(fn (McpActionPreview $preview): array => $this->presentPreview($preview))
            ->all();
    }

    public function approve(User $user, string $previewId): void
    {
        $unavailable = false;
        DB::transaction(function () use ($user, $previewId, &$unavailable): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $preview = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->lockForUpdate()->firstOrFail();

            if ($preview->action !== 'utility_reading.create') {
                $unavailable = true;

                return;
            }
            if (! in_array($preview->state, ['pending', 'approved'], true)) {
                $unavailable = true;

                return;
            }

            $token = McpAccessToken::query()->whereKey($preview->mcp_access_token_id)->lockForUpdate()->firstOrFail();
            $actor = $this->access->assertTokenAllows($token, 'utilities:create');
            $context = $this->utilities->importContext($actor, (int) $preview->payload['meter_id'], true);
            $this->assertChronology($context['readings'], $preview->payload['reading_date'], $preview->payload['value']);

            if ($preview->state === 'approved') {
                return;
            }
            if ($preview->expires_at->isPast()) {
                $preview->forceFill(['state' => 'expired'])->save();
                $unavailable = true;

                return;
            }

            $preview->forceFill(['state' => 'approved', 'approved_at' => now()])->save();
            $this->audit($actor, 'mcp.action.approved', [
                'preview_id' => $preview->id,
                'token_id' => $token->id,
                'action' => $preview->action,
            ]);
        }, 3);

        if ($unavailable) {
            throw new AuthorizationException('This action is no longer awaiting approval.');
        }
    }

    public function reject(User $user, string $previewId): void
    {
        $unavailable = false;
        DB::transaction(function () use ($user, $previewId, &$unavailable): void {
            HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $preview = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->lockForUpdate()->firstOrFail();

            if ($preview->state === 'rejected') {
                return;
            }
            if (! in_array($preview->state, ['pending', 'approved'], true)) {
                $unavailable = true;

                return;
            }
            if ($preview->expires_at->isPast()) {
                $preview->forceFill(['state' => 'expired'])->save();
                $unavailable = true;

                return;
            }

            $preview->forceFill(['state' => 'rejected'])->save();
            $this->audit($user, 'mcp.action.rejected', [
                'preview_id' => $preview->id,
                'token_id' => $preview->mcp_access_token_id,
                'action' => $preview->action,
            ]);
        }, 3);

        if ($unavailable) {
            throw new AuthorizationException('This action is no longer awaiting a decision.');
        }
    }

    /** @return array{status: string, reading_id: int, reading_date: string} */
    public function complete(McpAccessToken $token, string $previewId, string $idempotencyKey): array
    {
        if (! Str::isUuid($previewId) || ! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages(['arguments' => 'The approved action identifier is invalid.']);
        }

        try {
            $result = DB::transaction(function () use ($token, $previewId, $idempotencyKey): ?array {
                HouseholdSettings::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                $freshToken = McpAccessToken::query()->whereKey($token->id)->lockForUpdate()->firstOrFail();
                $actor = $this->access->assertTokenAllows($freshToken, 'utilities:create');
                $preview = McpActionPreview::query()
                    ->whereKey($previewId)
                    ->where('mcp_access_token_id', $freshToken->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($preview->state === 'completed') {
                    if ($preview->user_id !== $actor->id
                        || $preview->action !== 'utility_reading.create'
                        || $preview->idempotency_key !== $idempotencyKey
                        || ! is_array($preview->result)) {
                        throw new AuthorizationException('The MCP action is unavailable.');
                    }

                    $this->utilities->importContext($actor, (int) $preview->payload['meter_id'], true);

                    return $preview->result;
                }

                if ($preview->user_id !== $actor->id) {
                    throw new AuthorizationException('The MCP action is unavailable.');
                }
                if ($preview->action !== 'utility_reading.create') {
                    throw new AuthorizationException('The MCP action is unavailable.');
                }
                if ($preview->state !== 'approved') {
                    throw new AuthorizationException('This action has not been approved in Metricove.');
                }
                if ($preview->expires_at->isPast()) {
                    $preview->forceFill(['state' => 'expired'])->save();

                    return null;
                }

                $meterId = (int) $preview->payload['meter_id'];
                $this->utilities->importContext($actor, $meterId, true);
                $reading = $this->utilities->createReading($actor, $meterId, [
                    'reading_date' => $preview->payload['reading_date'],
                    'value' => $preview->payload['value'],
                    'note' => $preview->payload['note'],
                ]);

                $result = [
                    'status' => 'completed',
                    'reading_id' => (int) $reading['id'],
                    'reading_date' => $reading['reading_date'],
                ];

                $preview->forceFill([
                    'state' => 'completed',
                    'idempotency_key' => $idempotencyKey,
                    'result' => $result,
                    'completed_at' => now(),
                ])->save();
                $this->audit($actor, 'mcp.action.executed', [
                    'preview_id' => $preview->id,
                    'token_id' => $freshToken->id,
                    'action' => $preview->action,
                    'reading_id' => $result['reading_id'],
                ]);

                return $result;
            }, 3);

            if (! is_array($result)) {
                throw new AuthorizationException('The approved action has expired.');
            }

            return $result;
        } catch (UniqueConstraintViolationException $exception) {
            throw new AuthorizationException('The MCP action idempotency key has already been used.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function presentPreview(McpActionPreview $preview): array
    {
        $payload = $preview->payload;

        return [
            'id' => $preview->id,
            'action' => $preview->action,
            'state' => $preview->state,
            'meter' => $payload['meter_name'],
            'reading_date' => $payload['reading_date'],
            'value' => $payload['value'],
            'unit' => $payload['unit'],
            'note' => $payload['note'],
            'token_name' => $preview->token()->value('name') ?? 'Revoked token',
            'expires_at' => $preview->expires_at->toISOString(),
        ];
    }

    /** @param list<UtilityReading> $readings */
    private function assertChronology(array $readings, string $date, string $value): void
    {
        $previous = null;
        $next = null;
        foreach ($readings as $reading) {
            $readingDate = $reading->reading_date->format('Y-m-d');
            if ($readingDate === $date) {
                throw ValidationException::withMessages(['reading_date' => 'This meter already has a reading for that date.']);
            }
            if ($readingDate < $date) {
                $previous = $reading;
            } elseif ($readingDate > $date) {
                $next = $reading;
                break;
            }
        }

        if ($previous !== null && DecimalValue::compare((string) $previous->value, $value) > 0) {
            throw ValidationException::withMessages(['value' => 'A reading cannot be lower than the previous chronological reading.']);
        }
        if ($next !== null && DecimalValue::compare($value, (string) $next->value) > 0) {
            throw ValidationException::withMessages(['value' => 'A reading cannot be higher than the next chronological reading.']);
        }
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    /** @param array<string, mixed> $details */
    private function audit(User $actor, string $action, array $details): void
    {
        HouseholdAuditEvent::query()->create([
            'actor_user_id' => $actor->id,
            'subject_user_id' => $actor->id,
            'action' => $action,
            'details' => $details,
        ]);
    }
}
