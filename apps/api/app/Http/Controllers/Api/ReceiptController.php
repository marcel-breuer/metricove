<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmReceiptRequest;
use App\Http\Requests\PreviewReceiptRequest;
use App\Models\User;
use App\Services\Receipts\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReceiptController extends Controller
{
    public function index(Request $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json(['receipts' => $receipts->index($this->actor($request))]);
    }

    public function options(Request $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json($receipts->options($this->actor($request)));
    }

    public function preview(PreviewReceiptRequest $request, ReceiptService $receipts): JsonResponse
    {
        return response()->json($receipts->preview($this->actor($request), $request->file('file')));
    }

    public function confirm(ConfirmReceiptRequest $request, ReceiptService $receipts): JsonResponse
    {
        $result = $receipts->confirm($this->actor($request), $request->file('file'), $request->validated());

        return response()->json([
            'message' => $result['created'] ? 'Receipt saved.' : 'This receipt was already saved.',
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
