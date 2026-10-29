<?php

namespace App\Services\Receipts;

use App\Exceptions\LocalReceiptOcrUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class LocalReceiptOcr
{
    public function extract(UploadedFile $file): string
    {
        return $this->extractDetailed($file)['text'];
    }

    /** @return array{text: string, confidence: ?float} */
    public function extractDetailed(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path)) {
            throw new LocalReceiptOcrUnavailable;
        }

        $mimeType = $file->getMimeType();
        if ($mimeType !== 'application/pdf') {
            return $this->recognizeDetailed($path);
        }

        $directory = sys_get_temp_dir().'/metricove-receipts-'.bin2hex(random_bytes(12));
        File::ensureDirectoryExists($directory, 0700, true);

        try {
            $pages = $this->pdfPageCount($path);
            $maximumPages = max(1, (int) config('receipts.ocr.max_pdf_pages', 5));
            if ($pages > $maximumPages) {
                throw ValidationException::withMessages([
                    'file' => 'Keep invoice PDFs to '.$maximumPages.' pages or fewer.',
                ]);
            }

            $text = $this->extractPdfText($path);
            if (preg_match('/[\pL\pN]/u', $text) === 1) {
                return ['text' => mb_substr($text, 0, 100_000), 'confidence' => null];
            }

            $prefix = $directory.'/page';
            $render = new Process([
                (string) config('receipts.ocr.pdftoppm_binary', '/usr/bin/pdftoppm'),
                '-f', '1',
                '-l', (string) $pages,
                '-r', '180',
                '-png',
                $path,
                $prefix,
            ]);
            $render->setTimeout(45);
            $render->setEnv(['LC_ALL' => 'C']);
            $render->run();
            if (! $render->isSuccessful()) {
                throw new LocalReceiptOcrUnavailable;
            }

            $images = glob($prefix.'-*.png') ?: [];
            sort($images, SORT_NATURAL);
            if ($images === []) {
                throw new LocalReceiptOcrUnavailable;
            }

            $recognized = [];
            $confidences = [];
            foreach ($images as $image) {
                $page = $this->recognizeDetailed($image);
                $recognized[] = $page['text'];
                if ($page['confidence'] !== null) {
                    $confidences[] = $page['confidence'];
                }
            }

            return [
                'text' => mb_substr(implode("\n", $recognized), 0, 100_000),
                'confidence' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new LocalReceiptOcrUnavailable;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function pdfPageCount(string $path): int
    {
        $process = new Process([
            (string) config('receipts.ocr.pdfinfo_binary', '/usr/bin/pdfinfo'),
            $path,
        ]);
        $process->setTimeout(10);
        $process->setEnv(['LC_ALL' => 'C']);
        $process->run();

        if (! $process->isSuccessful()
            || preg_match('/^Pages:\s+(\d+)\s*$/m', $process->getOutput(), $matches) !== 1) {
            throw new LocalReceiptOcrUnavailable;
        }

        return max(1, (int) $matches[1]);
    }

    private function extractPdfText(string $path): string
    {
        $process = new Process([
            (string) config('receipts.ocr.pdftotext_binary', '/usr/bin/pdftotext'),
            '-layout',
            '-enc',
            'UTF-8',
            $path,
            '-',
        ]);
        $process->setTimeout(15);
        $process->setEnv(['LC_ALL' => 'C']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LocalReceiptOcrUnavailable;
        }

        return $process->getOutput();
    }

    /** @return array{text: string, confidence: ?float} */
    private function recognizeDetailed(string $path): array
    {
        $process = new Process([
            (string) config('receipts.ocr.tesseract_binary', '/usr/bin/tesseract'),
            $path,
            'stdout',
            '-l',
            (string) config('receipts.ocr.languages', 'deu+eng'),
            '--psm',
            '6',
            'tsv',
        ]);
        $process->setTimeout((float) config('receipts.ocr.timeout_seconds', 10));
        $process->setEnv(['LC_ALL' => 'C']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LocalReceiptOcrUnavailable;
        }

        $lines = [];
        $confidenceValues = [];
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) ?: [] as $row) {
            $columns = explode("\t", $row);
            if (count($columns) < 12 || ! is_numeric($columns[10])) {
                continue;
            }

            $word = trim($columns[11]);
            $confidence = (float) $columns[10];
            if ($word === '' || $confidence < 0) {
                continue;
            }

            $lineKey = implode(':', array_slice($columns, 1, 4));
            $lines[$lineKey][] = $word;
            $confidenceValues[] = $confidence;
        }

        return [
            'text' => implode("\n", array_map(static fn (array $words): string => implode(' ', $words), array_values($lines))),
            'confidence' => $confidenceValues === [] ? null : array_sum($confidenceValues) / count($confidenceValues),
        ];
    }
}
