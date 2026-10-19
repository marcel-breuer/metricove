<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HouseholdAuditEvent;
use App\Services\Auth\MemberManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMemberAuditController extends Controller
{
    public function __invoke(Request $request, MemberManagementService $members): JsonResponse
    {
        return response()->json([
            'events' => $members->auditEvents($request->user('web'))->map(fn (HouseholdAuditEvent $event): array => [
                'id' => $event->id,
                'actor_user_id' => $event->actor_user_id,
                'subject_user_id' => $event->subject_user_id,
                'action' => $event->action,
                'details' => $event->details,
                'created_at' => $event->created_at->toIso8601String(),
            ]),
        ]);
    }
}
