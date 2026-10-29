<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmReceiptRequest;
use App\Http\Requests\PreviewReceiptRequest;
use App\Http\Requests\ReceiptCreateTargetRequest;
use App\Http\Requests\ReceiptIndexRequest;
use App\Http\Requests\ReceiptLinkRequest;
use App\Http\Requests\ReceiptSharingRequest;
use App\Http\Requests\ReceiptTargetOptionsRequest;
use App\Models\User;
use App\Services\Receipts\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReceiptController extends Controller
{
    public function index(ReceiptIndexRequest $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json($receipts->index($this->actor($request), $request->validated()));
    }

    public function options(Request $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json($receipts->options($this->actor($request)));
    }

    public function targetOptions(ReceiptTargetOptionsRequest $request, ReceiptService $receipts): JsonResponse
    {
        $data = $request->validated();

        return response()->json($receipts->targetOptions($this->actor($request), $data['target_type'], $data['search'] ?? ''));
    }

    public function preview(PreviewReceiptRequest $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json($receipts->preview($this->actor($request), $request->file('file')));
    }

    public function confirm(ConfirmReceiptRequest $request, ReceiptService $receipts): JsonResponse
    {
        $result = $receipts->confirm($this->actor($request), $request->file('file'), $request->validated());

        return response()->json([
            'message' => $result['created']
                ? ($result['receipt']['target_type'] === null ? 'Receipt saved to archive.' : 'Receipt saved.')
                : 'This receipt was already saved.',
            'receipt' => $result['receipt'],
            'target' => $result['target'],
        ], $result['created'] ? 201 : 200);
    }

    public function file(Request $request, int $receipt, ReceiptService $receipts): BinaryFileResponse
    {
        $file = $receipts->file($this->actor($request), $receipt);
        $response = response()->download($file['path'], 'receipt-'.$receipt.'.'.$file['extension'], [
            'Content-Type' => $file['mime_type'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();

        return $response;
    }

    public function previewFile(Request $request, int $receipt, ReceiptService $receipts): BinaryFileResponse
    {
        $file = $receipts->file($this->actor($request), $receipt);
        $response = response()->file($file['path'], [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="receipt-'.$receipt.'.'.$file['extension'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();

        return $response;
    }

    public function updateSharing(ReceiptSharingRequest $request, int $receipt, ReceiptService $receipts): JsonResponse
    {
        $document = $receipts->updateSharing(
            $this->actor($request),
            $receipt,
            $request->boolean('shared_with_household'),
        );

        return response()->json([
            'message' => $document['shared_with_household'] ? 'Receipt shared with the household.' : 'Receipt is private again.',
            'receipt' => $document,
        ]);
    }

    public function linkTarget(ReceiptLinkRequest $request, int $receipt, ReceiptService $receipts): JsonResponse
    {
        $data = $request->validated();
        $document = $receipts->linkTarget($this->actor($request), $receipt, $data['target_type'], (int) $data['target_record_id']);

        return response()->json(['message' => 'Receipt linked to the selected entry.', 'receipt' => $document]);
    }

    public function createTarget(ReceiptCreateTargetRequest $request, int $receipt, ReceiptService $receipts): JsonResponse
    {
        $result = $receipts->createTarget($this->actor($request), $receipt, $request->validated());

        return response()->json([
            'message' => $result['created'] ? 'Entry created and linked to the receipt.' : 'This receipt is already linked to an entry.',
            'receipt' => $result['receipt'],
            'target' => $result['target'],
        ], $result['created'] ? 201 : 200);
    }

    public function destroy(Request $request, int $receipt, ReceiptService $receipts): JsonResponse
    {
        $receipts->delete($this->actor($request), $receipt);

        return response()->json(['message' => 'Receipt deleted.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
