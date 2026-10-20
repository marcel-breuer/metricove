<?php

namespace App\Services\Import;

use App\Contracts\MeterPhotoOcr;
use App\Exceptions\LocalOcrUnavailable;
use App\Services\Utilities\DecimalValue;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Throwable;

class TesseractMeterPhotoOcr implements MeterPhotoOcr
{
    public function suggest(UploadedFile $photo): ?string
    {
        $binary = (string) config('import.ocr.binary', '/usr/bin/tesseract');
        try {
            $path = $photo->getRealPath();
            if (! is_string($path)) {
                throw new LocalOcrUnavailable;
            }

            $process = new Process([
                $binary,
                $path,
                'stdout',
                '--psm',
                '7',
                '-l',
                'eng',
                '-c',
                'tessedit_char_whitelist=0123456789.,',
            ]);
            $process->setTimeout((float) config('import.ocr.timeout_seconds', 8));
            $process->setEnv(['LC_ALL' => 'C']);
            $process->run();
        } catch (Throwable $exception) {
            throw new LocalOcrUnavailable;
        }

        if (! $process->isSuccessful()) {
            throw new LocalOcrUnavailable;
        }

        return self::singleCandidate($process->getOutput());
    }

    public static function singleCandidate(string $text): ?string
    {
        preg_match_all('/(?<![0-9.,])[0-9]{1,14}(?:[.,][0-9]{1,6})?(?![0-9.,])/', $text, $matches);
        $candidates = [];

        foreach ($matches[0] as $match) {
            $parts = preg_split('/[.,]/', $match, 2);
            $whole = ltrim($parts[0], '0');
            $whole = $whole === '' ? '0' : $whole;
            $candidate = $whole.(isset($parts[1]) ? '.'.$parts[1] : '');
            $normalized = DecimalValue::normalize($candidate);

            if ($normalized !== null) {
                $candidates[$normalized] = true;
            }
        }

        return count($candidates) === 1 ? array_key_first($candidates) : null;
    }
}
