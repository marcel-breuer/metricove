<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\LastActiveAdministrator;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use App\Services\Auth\MemberManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMemberController extends Controller
{
    public function index(Request $request, MemberManagementService $members): JsonResponse
    {
        return response()->json([
            'members' => $members->members($request->user('web'))->map(fn (User $member): array => $this->resource($member)),
        ]);
    }

    public function update(
        UpdateMemberRequest $request,
        int $member,
        MemberManagementService $members,
    ): JsonResponse {
        try {
            $updated = $members->update($request->user('web'), $member, $request->validated());
        } catch (LastActiveAdministrator) {
            return $this->lastAdministratorResponse();
        }

        return response()->json([
            'message' => 'Member access updated.',
            'member' => $this->resource($updated),
        ]);
    }

    public function destroy(Request $request, int $member, MemberManagementService $members): JsonResponse
    {
        $actor = $request->user('web');

        try {
            $members->remove($actor, $member);
        } catch (LastActiveAdministrator) {
            return $this->lastAdministratorResponse();
        }

        if ($actor->id === $member) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Member removed.']);
    }

    /**
     * @return array{id: int, name: string, email: string, is_admin: bool, is_active: bool, created_at: string}
     */
    private function resource(User $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'is_admin' => $member->is_admin,
            'is_active' => $member->is_active,
            'created_at' => $member->created_at->toIso8601String(),
        ];
    }

    private function lastAdministratorResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The last active administrator cannot be demoted, suspended, or removed.',
        ], 409);
    }
}
