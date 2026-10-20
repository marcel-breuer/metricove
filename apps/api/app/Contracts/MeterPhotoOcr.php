<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface MeterPhotoOcr
{
    public function suggest(UploadedFile $photo): ?string;
}
