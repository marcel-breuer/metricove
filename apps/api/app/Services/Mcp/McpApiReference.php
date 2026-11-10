<?php

namespace App\Services\Mcp;

use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Mcp\Schema\Content\TextResourceContents;

class McpApiReference
{
    public const URI = 'metricove://api/http/openapi.json';

    public function __construct(private readonly CacheableGenerator $generator) {}

    public function read(): TextResourceContents
    {
        $config = Scramble::getGeneratorConfig('default');
        $specification = $this->generator->generate($config)->spec($config->openApiVersion());

        return new TextResourceContents(
            uri: self::URI,
            mimeType: 'application/json',
            text: json_encode(
                $specification,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
