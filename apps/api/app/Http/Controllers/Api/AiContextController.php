<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiContextEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiContextController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['items' => AiContextEntry::where('owner_user_id', $request->user('web')->id)
            ->orderByDesc('updated_at')->limit(200)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $entry = AiContextEntry::create([...$data, 'owner_user_id' => $request->user('web')->id]);

        return response()->json(['item' => $entry], 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $item = $this->owned($request, $entry);
        $item->update($this->validated($request));

        return response()->json(['item' => $item->refresh()]);
    }

    public function destroy(Request $request, int $entry): JsonResponse
    {
        $this->owned($request, $entry)->delete();

        return response()->json(['message' => 'Context entry deleted.']);
    }

    private function owned(Request $request, int $id): AiContextEntry
    {
        return AiContextEntry::where('owner_user_id', $request->user('web')->id)->findOrFail($id);
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
        ]);
        if ($data['scope'] === 'user') {
            $data['project_key'] = null;
        }

        return $data;
    }
}
