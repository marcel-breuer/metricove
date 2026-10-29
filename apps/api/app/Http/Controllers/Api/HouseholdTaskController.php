<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteHouseholdTaskRequest;
use App\Http\Requests\ReorderTaskChecklistItemsRequest;
use App\Http\Requests\StoreHouseholdTaskRequest;
use App\Http\Requests\StoreTaskChecklistItemRequest;
use App\Http\Requests\UpdateHouseholdTaskRequest;
use App\Http\Requests\UpdateTaskChecklistCompletionRequest;
use App\Http\Requests\UpdateTaskChecklistItemRequest;
use App\Models\User;
use App\Services\Tasks\HouseholdTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HouseholdTaskController extends Controller
{
    public function index(Request $request, HouseholdTaskService $tasks): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'status' => ['sometimes', 'in:open,completed,all'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'in:low,medium,high'],
            'due' => ['sometimes', 'in:overdue,upcoming,none'],
            'sort' => ['sometimes', 'in:due_at,priority,assignee,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ])->validate();

        return response()->json(['tasks' => $tasks->index($this->actor($request), $filters)]);
    }

    public function assignees(Request $request, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json(['assignees' => $tasks->assignees($this->actor($request))]);
    }

    public function store(StoreHouseholdTaskRequest $request, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Task created.',
            'task' => $tasks->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdateHouseholdTaskRequest $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Task updated.',
            'task' => $tasks->update($this->actor($request), $task, $request->validated()),
        ]);
    }

    public function complete(CompleteHouseholdTaskRequest $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Task occurrence completed.',
            'task' => $tasks->complete($this->actor($request), $task, $request->validated()),
        ]);
    }

    public function history(Request $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json(['completions' => $tasks->history($this->actor($request), $task)]);
    }

    public function addChecklistItem(StoreTaskChecklistItemRequest $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Checklist item created.',
            'task' => $tasks->addChecklistItem($this->actor($request), $task, $request->validated()),
        ], 201);
    }

    public function updateChecklistItem(UpdateTaskChecklistItemRequest $request, int $task, int $item, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Checklist item updated.',
            'task' => $tasks->updateChecklistItem($this->actor($request), $task, $item, $request->validated()),
        ]);
    }

    public function completeChecklistItem(UpdateTaskChecklistCompletionRequest $request, int $task, int $item, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Checklist item updated.',
            'task' => $tasks->completeChecklistItem($this->actor($request), $task, $item, $request->validated()),
        ]);
    }

    public function reorderChecklistItems(ReorderTaskChecklistItemsRequest $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        return response()->json([
            'message' => 'Checklist order updated.',
            'task' => $tasks->reorderChecklistItems($this->actor($request), $task, $request->validated()),
        ]);
    }

    public function deleteChecklistItem(Request $request, int $task, int $item, HouseholdTaskService $tasks): JsonResponse
    {
        $values = Validator::make($request->all(), ['expected_version' => ['required', 'integer', 'min:1']])->validate();
        $tasks->deleteChecklistItem($this->actor($request), $task, $item, (int) $values['expected_version']);

        return response()->json(['message' => 'Checklist item deleted.']);
    }

    public function destroy(Request $request, int $task, HouseholdTaskService $tasks): JsonResponse
    {
        $tasks->delete($this->actor($request), $task);

        return response()->json(['message' => 'Task deleted.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
