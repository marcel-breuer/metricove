<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Http\Controllers\Controller;
use App\Models\AiContextEntry;
use App\Models\User;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiContextController extends Controller
{
    public function __construct(private readonly ModuleAccessService $access) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $projectKey = $request->query('project_key');
        validator(['project_key' => $projectKey], ['project_key' => ['nullable', 'string', 'max:120']])->validate();

        return response()->json([
            'items' => AiContextEntry::query()->where('owner_user_id', $actor->id)
                ->when($projectKey, fn ($query) => $query->where(fn ($scope) => $scope
                    ->where('scope', 'user')->orWhere(fn ($project) => $project
                    ->where('scope', 'project')->where('project_key', $projectKey))))
                ->orderByDesc('updated_at')->limit(200)->get(),
            'enabled' => $actor->ai_context_enabled,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $this->validated($request);
        $data['expires_at'] ??= now()->addDays((int) config('ai.context_retention_days', 365));
        $entry = DB::transaction(function () use ($actor, $data): AiContextEntry {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            AiContextEntry::query()->where('owner_user_id', $actor->id)
                ->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();
            if (AiContextEntry::query()->where('owner_user_id', $actor->id)->count() >= 200) {
                throw ValidationException::withMessages(['content' => 'You can save up to 200 context entries. Delete an entry before adding another.']);
            }

            return AiContextEntry::create([...$data, 'owner_user_id' => $actor->id, 'version' => 1]);
        });

        return response()->json(['item' => $entry], 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $item = $this->owned($actor, $entry);
        $expectedVersion = $request->validate(['expected_version' => ['required', 'integer', 'min:1']])['expected_version'];
        $data = $this->validated($request);
        if (array_key_exists('expires_at', $data) && $data['expires_at'] === null) {
            $data['expires_at'] = now()->addDays((int) config('ai.context_retention_days', 365));
        }
        $updated = AiContextEntry::query()->whereKey($item->id)
            ->where('owner_user_id', $actor->id)
            ->where('version', $expectedVersion)
            ->update([...$data, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw ValidationException::withMessages(['expected_version' => 'This context entry changed. Reload before editing it.']);
        }

        return response()->json(['item' => $item->refresh()]);
    }

    public function destroy(Request $request, int $entry): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $this->owned($actor, $entry)->delete();

        return response()->json(['message' => 'Context entry deleted.']);
    }

    public function preferences(Request $request): JsonResponse
    {
        $actor = $this->authorizeActor($request);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $actor->forceFill(['ai_context_enabled' => $data['enabled']])->save();

        return response()->json(['enabled' => $actor->ai_context_enabled]);
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);
        try {
            $this->access->authorize($actor, 'ai', 'view');
        } catch (ModuleDisabled|ModuleNotFound) {
            abort(404, 'This module is unavailable.');
        } catch (AuthorizationException) {
            abort(403, 'This module operation is not permitted.');
        }

        return $actor->refresh();
    }

    private function owned(User $actor, int $id): AiContextEntry
    {
        return AiContextEntry::query()->where('owner_user_id', $actor->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['user', 'project'])],
            'project_key' => ['nullable', 'string', 'max:120', 'required_if:scope,project'],
            'kind' => ['required', 'string', 'max:40'],
            'content' => ['required', 'string', 'max:4000'],
            'source' => ['nullable', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ]);
        unset($data['expected_version']);
        if ($data['scope'] === 'user') {
            $data['project_key'] = null;
        }

        return $data;
    }
}
