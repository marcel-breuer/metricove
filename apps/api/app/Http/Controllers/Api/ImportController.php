<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmFinanceTransactionsImportRequest;
use App\Http\Requests\ConfirmUtilityReadingsImportRequest;
use App\Http\Requests\PreviewFinanceTransactionsImportRequest;
use App\Http\Requests\PreviewUtilityReadingsImportRequest;
use App\Http\Requests\SuggestUtilityReadingRequest;
use App\Models\User;
use App\Services\Import\FinanceTransactionImportService;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function previewFinanceTransactions(
        PreviewFinanceTransactionsImportRequest $request,
        FinanceTransactionImportService $imports,
    ): JsonResponse {
        $selectedRows = $request->validated('selected_rows');
        if ($request->boolean('selection_submitted')) {
            $selectedRows ??= [];
        }

        return response()->json($imports->preview(
            $this->actor($request),
            $request->file('file'),
            $request->validated('mapping'),
            $request->validated('category_defaults'),
            $selectedRows,
        ));
    }

    public function confirmFinanceTransactions(
        ConfirmFinanceTransactionsImportRequest $request,
        FinanceTransactionImportService $imports,
    ): JsonResponse {
        $selectedRows = $request->validated('selected_rows');
        if ($request->boolean('selection_submitted')) {
            $selectedRows ??= [];
        }

        return response()->json($imports->confirm(
            $this->actor($request),
            $request->file('file'),
            $request->validated('preview_token'),
            $request->validated('idempotency_key'),
            $request->validated('mapping'),
            $request->validated('category_defaults'),
            $selectedRows,
        ));
    }

    public function preview(
        PreviewUtilityReadingsImportRequest $request,
        int $meter,
        ImportService $imports,
    ): JsonResponse {
        return response()->json($imports->previewUtilityReadings(
            $this->actor($request),
            $meter,
            $request->file('file'),
            $request->validated('mapping'),
        ));
    }

    public function confirm(
        ConfirmUtilityReadingsImportRequest $request,
        int $meter,
        ImportService $imports,
    ): JsonResponse {
        return response()->json($imports->confirmUtilityReadings(
            $this->actor($request),
            $meter,
            $request->file('file'),
            $request->validated('preview_token'),
            $request->validated('idempotency_key'),
            $request->validated('mapping'),
        ));
    }

    public function export(Request $request, int $meter, ImportService $imports): StreamedResponse
    {
        $actor = $this->actor($request);
        $readings = $imports->exportUtilityReadings($actor, $meter);

        $response = response()->streamDownload(function () use ($readings): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['reading_date', 'value', 'note'], ',', '"', '');

            foreach ($readings as $reading) {
                fputcsv($output, [
                    $reading->reading_date->format('Y-m-d'),
                    (string) $reading->value,
                    $this->spreadsheetSafeNote($reading->note),
                ], ',', '"', '');
            }

            fclose($output);
        }, "utility-meter-{$meter}-readings.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();

        return $response;
    }

    public function suggest(
        SuggestUtilityReadingRequest $request,
        int $meter,
        ImportService $imports,
    ): JsonResponse {
        return response()->json($imports->suggestUtilityReading(
            $this->actor($request),
            $meter,
            $request->file('photo'),
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function spreadsheetSafeNote(?string $note): string
    {
        $note = (string) $note;

        return preg_match('/^\s*[=+@-]/u', $note) === 1 ? "'".$note : $note;
    }
}
