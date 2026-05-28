<?php

declare(strict_types=1);

ob_start();

require __DIR__ . '/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        ob_end_clean();
        json_response(['ok' => false, 'message' => 'Methode nicht erlaubt.']);
    }

    $rawBody = (string) file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        ob_end_clean();
        json_response(['ok' => false, 'message' => 'Ungültige Anfrage.']);
    }

    $client = [
        'id' => client_clean_text($payload['clientId'] ?? '', 80),
        'viewId' => client_clean_text($payload['viewId'] ?? '', 80),
        'viewName' => client_clean_text($payload['viewName'] ?? '', 120),
        'scope' => client_clean_text($payload['scope'] ?? 'all', 160),
        'path' => client_clean_text($payload['path'] ?? '', 180),
        'lastSeen' => time(),
    ];

    if ($client['id'] === '') {
        $client['id'] = bin2hex(random_bytes(16));
    }

    client_store_heartbeat($client);

    ob_end_clean();
    json_response(['ok' => true]);
} catch (Throwable $exception) {
    ob_end_clean();
    json_response(normalize_error($exception), 500);
}

function client_clean_text(mixed $value, int $maxLength): string
{
    $text = trim((string) $value);
    $text = preg_replace('/[^\P{C}\t]+/u', '', $text) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function client_store_heartbeat(array $client): void
{
    $config = app_config();
    if (!ensure_cache_dir()) {
        return;
    }

    $file = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'clients.json';
    $lockFile = $file . '.lock';
    $now = time();
    $clients = [];

    $lock = @fopen($lockFile, 'c');
    if ($lock !== false) {
        @flock($lock, LOCK_EX);
    }

    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $clients = $decoded;
        }
    }

    $clients = array_filter($clients, static function (array $item) use ($now): bool {
        return (int) ($item['lastSeen'] ?? 0) >= $now - 300;
    });

    $clients[$client['id']] = $client;
    @file_put_contents($file, json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if ($lock !== false) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}
