<?php

declare(strict_types=1);

// Jegliche versehentliche PHP-Ausgabe (Notices, Warnings) abfangen,
// damit immer sauberes JSON zurückkommt – nie HTML.
ob_start();

require __DIR__ . '/bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Statusabfrage ohne Passwort: Gibt nur zurück, ob ADMIN_PASSWORD gesetzt ist
        $adminPassword = env_value('ADMIN_PASSWORD', '');
        ob_end_clean();
        json_response([
            'hasAdminPassword' => $adminPassword !== '',
        ]);
    }

    if ($method !== 'POST') {
        ob_end_clean();
        // HTTP 200 statt 405: Apache fängt sonst 4xx-Statuscodes ab
        // und liefert seine eigene HTML-Fehlerseite zurück.
        json_response(['ok' => false, 'message' => 'Methode nicht erlaubt.']);
    }

    $rawBody = (string) file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        ob_end_clean();
        json_response(['ok' => false, 'message' => 'Ungültige Anfrage.']);
    }

    // Passwortprüfung – immer HTTP 200 zurückgeben, Fehler im JSON-Body
    $password     = (string) ($payload['password'] ?? '');
    $adminPassword = env_value('ADMIN_PASSWORD', '');

    if ($adminPassword === '') {
        ob_end_clean();
        json_response(['ok' => false, 'message' => 'ADMIN_PASSWORD ist nicht in .env gesetzt.']);
    }

    if (!hash_equals($adminPassword, $password)) {
        // Kurze Pause gegen Brute-Force
        usleep(500_000);
        ob_end_clean();
        json_response(['ok' => false, 'message' => 'Falsches Passwort.']);
    }

    $action = (string) ($payload['action'] ?? '');
    $config  = app_config();

    switch ($action) {

        case 'cache-status':
            ob_end_clean();
            json_response([
                'ok'     => true,
                'status' => admin_cache_status($config),
            ]);

        case 'flush-lookups':
            $file    = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'lookups-v2.json';
            $deleted = is_file($file) && @unlink($file);
            ob_end_clean();
            json_response([
                'ok'      => true,
                'message' => $deleted
                    ? 'Stammdaten-Cache gelöscht. Beim nächsten Dashboard-Aufruf werden die Daten neu von Ultimo geladen.'
                    : 'Kein Stammdaten-Cache vorhanden.',
                'status'  => admin_cache_status($config),
            ]);

        case 'flush-dashboard':
            $count  = admin_delete_pattern($config['cacheDir'], 'dashboard-*.json');
            $count += admin_delete_pattern($config['cacheDir'], 'dashboard-*.json.lock');
            ob_end_clean();
            json_response([
                'ok'      => true,
                'message' => "Dashboard-Cache gelöscht ({$count} Datei(en)). Nächster Abruf holt frische Daten.",
                'status'  => admin_cache_status($config),
            ]);

        case 'flush-all':
            $count  = admin_delete_pattern($config['cacheDir'], '*.json');
            $count += admin_delete_pattern($config['cacheDir'], '*.json.lock');
            ob_end_clean();
            json_response([
                'ok'      => true,
                'message' => "Gesamter Cache gelöscht ({$count} Datei(en)).",
                'status'  => admin_cache_status($config),
            ]);

        case 'force-fetch-lookups':
            $file = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'lookups-v2.json';
            @unlink($file);

            if (env_value('ULTIMO_API_KEY', '') === '') {
                ob_end_clean();
                json_response(['ok' => false, 'message' => 'ULTIMO_API_KEY fehlt – Stammdaten können nicht geladen werden.']);
            }

            $lookups = fetch_lookups();
            $counts  = [];
            foreach ($lookups as $resource => $items) {
                $counts[$resource] = count($items);
            }

            ob_end_clean();
            json_response([
                'ok'      => true,
                'message' => 'Stammdaten erfolgreich neu geladen.',
                'counts'  => $counts,
                'status'  => admin_cache_status($config),
            ]);

        default:
            ob_end_clean();
            json_response(['ok' => false, 'message' => "Unbekannte Aktion: {$action}"]);
    }
} catch (Throwable $exception) {
    ob_end_clean();
    json_response(normalize_error($exception), 500);
}

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

function admin_cache_status(array $config): array
{
    $cacheDir = $config['cacheDir'];

    // Stammdaten-Cache
    $lookupsFile = $cacheDir . DIRECTORY_SEPARATOR . 'lookups-v2.json';
    $lookupInfo  = admin_file_info($lookupsFile, $config['lookupCacheSeconds']);

    // Stammdaten-Einträge zählen
    $lookupCounts = [];
    if ($lookupInfo['exists']) {
        $raw = @file_get_contents($lookupsFile);
        if ($raw !== false) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                foreach ($parsed as $resource => $items) {
                    $lookupCounts[$resource] = is_array($items) ? count($items) : 0;
                }
            }
        }
    }

    // Dashboard-Cache-Dateien
    $dashFiles = glob($cacheDir . DIRECTORY_SEPARATOR . 'dashboard-*.json') ?: [];
    $dashLabels = admin_dashboard_cache_labels();
    $dashInfos = [];
    foreach ($dashFiles as $f) {
        $info = admin_file_info($f, $config['dashboardCacheSeconds']);
        $name = basename($f);
        $label = $dashLabels[$name] ?? admin_dashboard_file_label($f);
        $dashInfos[] = array_merge($info, [
            'fileName' => $name,
            'views' => $label['views'] ?? [],
            'scope' => $label['scope'] ?? null,
            'label' => $label['label'] ?? 'Unbekannte Ansicht',
        ]);
    }

    return [
        'lookups' => array_merge($lookupInfo, ['counts' => $lookupCounts]),
        'dashboard' => [
            'fileCount'  => count($dashFiles),
            'ttlSeconds' => $config['dashboardCacheSeconds'],
            'files'      => $dashInfos,
        ],
        'clients' => admin_client_status(),
        'cacheTtl' => [
            'lookupSeconds'    => $config['lookupCacheSeconds'],
            'dashboardSeconds' => $config['dashboardCacheSeconds'],
        ],
    ];
}

function admin_file_info(string $file, int $ttl): array
{
    if (!is_file($file)) {
        return ['exists' => false, 'expired' => true];
    }

    $mtime = (int) filemtime($file);
    $size  = (int) filesize($file);
    $age   = time() - $mtime;

    return [
        'exists'     => true,
        'cachedAt'   => date(DATE_ATOM, $mtime),
        'ageSeconds' => $age,
        'expired'    => $age >= $ttl,
        'expiresAt'  => date(DATE_ATOM, $mtime + $ttl),
        'sizeKb'     => round($size / 1024, 1),
        'writable'    => is_writable($file),
    ];
}

function admin_delete_pattern(string $dir, string $pattern): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [];
    $count = 0;
    foreach ($files as $file) {
        if (is_file($file) && @unlink($file)) {
            $count++;
        }
    }

    return $count;
}

function admin_dashboard_cache_labels(): array
{
    $labels = [];
    foreach (load_dashboard_layouts() as $layout) {
        $scope = normalize_scope((string) ($layout['scope'] ?? 'all'));
        $cacheScope = $scope === 'all' ? null : $scope;
        $fileName = basename(dashboard_cache_file([
            'scope' => $cacheScope,
            'includeFinishedDays' => 14,
        ]));

        if (!isset($labels[$fileName])) {
            $labels[$fileName] = [
                'scope' => $scope,
                'label' => '',
                'views' => [],
            ];
        }

        $view = [
            'id' => (string) ($layout['id'] ?? ''),
            'name' => (string) ($layout['name'] ?? ''),
        ];
        $labels[$fileName]['views'][] = $view;
        $labels[$fileName]['label'] = implode(', ', array_map(
            static fn (array $item): string => $item['name'] !== '' ? $item['name'] : $item['id'],
            $labels[$fileName]['views']
        ));
    }

    return $labels;
}

function admin_dashboard_file_label(string $file): array
{
    $decoded = json_decode((string) @file_get_contents($file), true);
    $scope = null;
    if (is_array($decoded)) {
        $rawScope = $decoded['filter']['scope'] ?? null;
        $scope = $rawScope === null || $rawScope === '' ? 'all' : normalize_scope((string) $rawScope);
    }

    if ($scope === null) {
        return [
            'scope' => null,
            'label' => 'Unbekannte Ansicht',
            'views' => [],
        ];
    }

    $views = [];
    foreach (load_dashboard_layouts() as $layout) {
        if (($layout['scope'] ?? 'all') === $scope) {
            $views[] = [
                'id' => (string) ($layout['id'] ?? ''),
                'name' => (string) ($layout['name'] ?? ''),
            ];
        }
    }

    $label = $views === []
        ? admin_scope_label($scope)
        : implode(', ', array_map(
            static fn (array $view): string => $view['name'] !== '' ? $view['name'] : $view['id'],
            $views
        ));

    return [
        'scope' => $scope,
        'label' => $label,
        'views' => $views,
    ];
}

function admin_client_status(): array
{
    $config = app_config();
    $file = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'clients.json';
    $now = time();
    $activeSeconds = 90;
    $clients = [];

    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            foreach ($decoded as $client) {
                if (!is_array($client)) {
                    continue;
                }

                $lastSeen = (int) ($client['lastSeen'] ?? 0);
                if ($lastSeen < $now - $activeSeconds) {
                    continue;
                }

                $clients[] = [
                    'id' => (string) ($client['id'] ?? ''),
                    'viewId' => (string) ($client['viewId'] ?? ''),
                    'viewName' => (string) ($client['viewName'] ?? ''),
                    'scope' => (string) ($client['scope'] ?? 'all'),
                    'path' => (string) ($client['path'] ?? ''),
                    'lastSeen' => date(DATE_ATOM, $lastSeen),
                    'ageSeconds' => max(0, $now - $lastSeen),
                ];
            }
        }
    }

    $groups = [];
    foreach ($clients as $client) {
        $viewId = $client['viewId'] !== '' ? $client['viewId'] : 'manual';
        $scope = $client['scope'] !== '' ? $client['scope'] : 'all';
        $key = $viewId . '|' . $scope;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'viewId' => $viewId,
                'viewName' => $client['viewName'] !== '' ? $client['viewName'] : admin_scope_label($scope),
                'scope' => $scope,
                'count' => 0,
                'lastSeenAgeSeconds' => null,
            ];
        }

        $groups[$key]['count']++;
        $age = (int) $client['ageSeconds'];
        if ($groups[$key]['lastSeenAgeSeconds'] === null || $age < $groups[$key]['lastSeenAgeSeconds']) {
            $groups[$key]['lastSeenAgeSeconds'] = $age;
        }
    }

    usort($groups, static function (array $left, array $right): int {
        if ($left['count'] === $right['count']) {
            return strcmp($left['viewName'], $right['viewName']);
        }

        return $right['count'] <=> $left['count'];
    });

    return [
        'activeSeconds' => $activeSeconds,
        'total' => count($clients),
        'groups' => array_values($groups),
    ];
}

function admin_scope_label(string $scope): string
{
    if ($scope === '' || $scope === 'all') {
        return 'Gesamtübersicht';
    }

    foreach (load_dashboard_layouts() as $layout) {
        if (($layout['scope'] ?? 'all') === $scope) {
            return (string) ($layout['name'] ?? $scope);
        }
    }

    return $scope;
}
