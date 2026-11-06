<?php

namespace App\Services\Mcp;

use App\Models\McpActionPreview;
use App\Models\User;

class McpHumanApprovalService
{
    public function __construct(
        private readonly UtilityReadingMcpService $utilityActions,
        private readonly McpWriteWorkflowService $workflows,
    ) {}

    /** @return list<array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        $utility = $this->utilityActions->pendingFor($user);

        return [...$utility, ...$this->workflows->pendingFor($user)];
    }

    public function approve(User $user, string $previewId): void
    {
        $action = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->value('action');
        if (is_string($action) && str_starts_with($action, 'workflow:')) {
            $this->workflows->approve($user, $previewId);

            return;
        }

        $this->utilityActions->approve($user, $previewId);
    }

    public function reject(User $user, string $previewId): void
    {
        $action = McpActionPreview::query()->where('user_id', $user->id)->whereKey($previewId)->value('action');
        if (is_string($action) && str_starts_with($action, 'workflow:')) {
            $this->workflows->reject($user, $previewId);

            return;
        }

        $this->utilityActions->reject($user, $previewId);
    }
}
