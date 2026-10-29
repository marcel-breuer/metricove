<?php

return [
    'ocr' => [
        'tesseract_binary' => env('METRICOVE_RECEIPT_OCR_BINARY', '/usr/bin/tesseract'),
        'pdftotext_binary' => env('METRICOVE_PDFTOTEXT_BINARY', '/usr/bin/pdftotext'),
        'pdfinfo_binary' => env('METRICOVE_PDFINFO_BINARY', '/usr/bin/pdfinfo'),
        'pdftoppm_binary' => env('METRICOVE_PDFTOPPM_BINARY', '/usr/bin/pdftoppm'),
        'languages' => env('METRICOVE_RECEIPT_OCR_LANGUAGES', 'deu+eng'),
        'timeout_seconds' => 10,
        'max_pdf_pages' => 5,
    ],
];
