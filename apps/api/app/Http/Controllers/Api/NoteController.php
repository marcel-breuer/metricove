<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteFolderRequest;
use App\Http\Requests\StorePersonalNoteRequest;
use App\Http\Requests\UpdateNoteFolderRequest;
use App\Http\Requests\UpdatePersonalNoteRequest;
use App\Models\User;
use App\Services\Notes\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NoteController extends Controller
{
    public function preview(Request $request, NoteService $notes): JsonResponse
    {
        $values = Validator::make($request->all(), [
            'body' => ['present', 'string', 'max:100000'],
        ])->validate();

        return response()->json(['markdown_html' => $notes->preview($this->actor($request), $values['body'])]);
    }

    public function index(Request $request, NoteService $notes): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'search' => ['sometimes', 'string', 'max:160'],
            'folder_id' => ['sometimes', 'integer', 'min:1'],
            'tag' => ['sometimes', 'string', 'max:48'],
            'status' => ['sometimes', 'in:active,archived,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return response()->json($notes->index($this->actor($request), $filters));
    }

    public function show(Request $request, int $note, NoteService $notes): JsonResponse
    {
        return response()->json(['note' => $notes->show($this->actor($request), $note)]);
    }

    public function store(StorePersonalNoteRequest $request, NoteService $notes): JsonResponse
    {
        return response()->json([
            'message' => 'Note created.',
            'note' => $notes->create($this->actor($request), $request->validated()),
        ], 201);
    }

    public function update(UpdatePersonalNoteRequest $request, int $note, NoteService $notes): JsonResponse
    {
        return response()->json([
            'message' => 'Note updated.',
            'note' => $notes->update($this->actor($request), $note, $request->validated()),
        ]);
    }

    public function archive(Request $request, int $note, NoteService $notes): JsonResponse
    {
        $values = Validator::make($request->all(), [
            'archived' => ['required', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        return response()->json([
            'message' => $values['archived'] ? 'Note archived.' : 'Note restored.',
            'note' => $notes->archive($this->actor($request), $note, (bool) $values['archived'], (int) $values['expected_version']),
        ]);
    }

    public function destroy(Request $request, int $note, NoteService $notes): JsonResponse
    {
        $notes->delete($this->actor($request), $note);

        return response()->json(['message' => 'Note deleted.']);
    }

    public function folders(Request $request, NoteService $notes): JsonResponse
    {
        return response()->json(['folders' => $notes->folders($this->actor($request))]);
    }

    public function storeFolder(StoreNoteFolderRequest $request, NoteService $notes): JsonResponse
    {
        return response()->json([
            'message' => 'Folder created.',
            'folder' => $notes->createFolder($this->actor($request), $request->validated('name')),
        ], 201);
    }

    public function updateFolder(UpdateNoteFolderRequest $request, int $folder, NoteService $notes): JsonResponse
    {
        return response()->json([
            'message' => 'Folder updated.',
            'folder' => $notes->updateFolder(
                $this->actor($request), $folder, $request->validated('name'), (int) $request->validated('expected_version'),
            ),
        ]);
    }

    public function destroyFolder(Request $request, int $folder, NoteService $notes): JsonResponse
    {
        $notes->deleteFolder($this->actor($request), $folder);

        return response()->json(['message' => 'Folder deleted. Notes were kept.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
