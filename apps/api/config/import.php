<?php

return [
    'ocr' => [
        'binary' => env('METRICOVE_OCR_BINARY', '/usr/bin/tesseract'),
        'timeout_seconds' => 8,
    ],
];
