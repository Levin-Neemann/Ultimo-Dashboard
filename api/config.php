<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $config = app_config();
    json_response([
        'ultimoBaseUrl' => $config['baseUrl'],
        'hasApiKey' => $config['apiKey'] !== '',
        'refreshSeconds' => $config['refreshSeconds'],
    ]);
} catch (Throwable $exception) {
    json_response(normalize_error($exception), 500);
}
