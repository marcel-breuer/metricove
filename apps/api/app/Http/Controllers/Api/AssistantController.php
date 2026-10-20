<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssistantProviderException;
use App\Exceptions\AssistantUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Requests\AskAssistantRequest;
use App\Models\User;
use App\Services\AI\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function status(Request $request, AssistantService $assistant): JsonResponse
    {
        return response()->json($assistant->status($this->actor($request)));
    }

    public function ask(AskAssistantRequest $request, AssistantService $assistant): JsonResponse
    {
        try {
            return response()->json($assistant->ask(
                $this->actor($request),
                trim($request->validated('message')),
                (bool) $request->validated('allow_external_data_sharing', false),
                $request->validated('project_key'),
            ));
        } catch (AssistantUnavailable) {
            return response()->json(['message' => 'The assistant is not configured for this installation.'], 503);
        } catch (AssistantProviderException) {
            return response()->json(['message' => 'The assistant provider is currently unavailable.'], 502);
        }
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
