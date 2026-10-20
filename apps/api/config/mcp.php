<?php

return [
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_ALLOWED_ORIGINS', '')),
    ))),
];
