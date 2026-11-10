<?php

namespace App\Services\Mcp;

use Illuminate\Support\Facades\Storage;
use Mcp\Schema\Content\TextResourceContents;
use Symfony\Component\Yaml\Yaml;

class McpApiReference
{
    public const URI = 'metricove://api/http/openapi.json';

    public function read(): TextResourceContents
    {
        $specification = $this->document();

        return new TextResourceContents(
            uri: self::URI,
            mimeType: 'application/json',
            text: json_encode(
                $specification,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function document(): array
    {
        $path = Storage::disk('local')->path('scribe/openapi.yaml');

        abort_unless(is_file($path), 503, 'The HTTP API reference is not available yet.');

        $document = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);

        abort_unless(is_array($document), 503, 'The HTTP API reference could not be read.');

        return $document;
    }
}
