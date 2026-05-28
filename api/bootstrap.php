<?php

declare(strict_types=1);

load_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

date_default_timezone_set(env_value('APP_TIMEZONE', 'Europe/Berlin'));

const JOB_SELECT = [
    'Id',
    'Description',
    'Status',
    'StatusActiveDate',
    'StatusCreatedReportDate',
    'StatusFinishedDate',
    'ScheduledStartDate',
    'ServiceContractTargetFinishedDate',
    'ServiceContractTargetResponseDate',
    'TargetDate',
    'ReportText',
    'Text',
    'FeedbackText',
    'Department',
    'CostCenter',
    'Equipment',
    'EquipmentType',
    'Priority',
    'ProgressStatus',
    'ProcessFunction',
    'Site',
    'Space',
    'Employee',
    'Vendor',
    'WorkOrderType',
    'FailType',
    'Component',
    'ComponentProblem',
    'Remedy',
    'SkillCategory',
];

const LOOKUP_RESOURCES = [
    'Department',
    'Priority',
    'ProgressStatus',
    'Equipment',
    'Site',
    'Space',
    'Employee',
    'CostCenter',
    'ProcessFunction',
    'WorkOrderType',
    'FailType',
    'Component',
    'ComponentProblem',
    'Remedy',
    'SkillCategory',
    'Vendor',
];

const CLOSED_PROGRESS_STATUS_IDS = ['068'];

const ACTIVE_JOB_STATUS_VALUES = [1, 2, 4, 16];

function app_config(): array
{
    $root = dirname(__DIR__);

    return [
        'baseUrl' => rtrim(env_value('ULTIMO_BASE_URL', 'https://neemann.ultimo.net/api/v1'), '/'),
        'apiKey' => env_value('ULTIMO_API_KEY', ''),
        'refreshSeconds' => clamp_number(env_value('REFRESH_SECONDS', '60'), 30, 300, 60),
        'dashboardCacheSeconds' => clamp_number(env_value('DASHBOARD_CACHE_SECONDS', '150'), 30, 1800, 300),
        'lookupCacheSeconds' => clamp_number(env_value('LOOKUP_CACHE_SECONDS', '86400'), 300, 604800, 86400),
        'cacheDir' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache',
        'layoutDir' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'layouts',
        'departmentGroupsPath' => $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'department-groups.php',
    ];
}

function env_value(string $key, string $fallback = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $fallback;
    }

    return (string) $value;
}

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if ($name === '' || array_key_exists($name, $_ENV) || getenv($name) !== false) {
            continue;
        }

        $value = preg_replace('/^([\'"])(.*)\1$/', '$2', $value) ?? $value;
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cached_dashboard_response(array $cacheParts, callable $producer): never
{
    $config = app_config();
    $ttl = (int) $config['dashboardCacheSeconds'];

    if ($ttl <= 0 || !ensure_cache_dir()) {
        json_response($producer());
    }

    $cacheFile = dashboard_cache_file($cacheParts);
    $lockFile = $cacheFile . '.lock';

    $cached = read_fresh_json_cache($cacheFile, $ttl);
    if ($cached !== null) {
        header('X-Dashboard-Cache: HIT');
        json_response(with_dashboard_cache_meta($cached, true, $ttl, cache_file_time($cacheFile)));
    }

    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && @flock($lock, LOCK_EX)) {
        $cached = read_fresh_json_cache($cacheFile, $ttl);
        if ($cached !== null) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            header('X-Dashboard-Cache: HIT');
            json_response(with_dashboard_cache_meta($cached, true, $ttl, cache_file_time($cacheFile)));
        }

        $payload = $producer();
        write_json_cache($cacheFile, $payload);
        @flock($lock, LOCK_UN);
        @fclose($lock);

        header('X-Dashboard-Cache: MISS');
        json_response(with_dashboard_cache_meta($payload, false, $ttl, cache_file_time($cacheFile)));
    }

    $payload = $producer();
    write_json_cache($cacheFile, $payload);

    header('X-Dashboard-Cache: MISS');
    json_response(with_dashboard_cache_meta($payload, false, $ttl, cache_file_time($cacheFile)));
}

function dashboard_cache_file(array $cacheParts): string
{
    $config = app_config();
    $cacheKey = hash('sha256', json_encode($cacheParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $config['cacheDir'] . DIRECTORY_SEPARATOR . 'dashboard-' . $cacheKey . '.json';
}

function ensure_cache_dir(): bool
{
    $cacheDir = app_config()['cacheDir'];
    if (is_dir($cacheDir)) {
        return true;
    }

    return @mkdir($cacheDir, 0775, true) || is_dir($cacheDir);
}

function read_fresh_json_cache(string $cacheFile, int $ttl): ?array
{
    $mtime = cache_file_time($cacheFile);
    if ($mtime === null || $mtime <= time() - $ttl) {
        return null;
    }

    $cached = json_decode((string) @file_get_contents($cacheFile), true);
    return is_array($cached) ? $cached : null;
}

function write_json_cache(string $cacheFile, array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }

    @file_put_contents($cacheFile, $encoded, LOCK_EX);
}

function cache_file_time(string $cacheFile): ?int
{
    if (!is_file($cacheFile)) {
        return null;
    }

    $mtime = filemtime($cacheFile);
    return $mtime === false ? null : $mtime;
}

function with_dashboard_cache_meta(array $payload, bool $hit, int $ttl, ?int $cachedAt): array
{
    $payload['cache'] = [
        'hit' => $hit,
        'ttlSeconds' => $ttl,
        'cachedAt' => $cachedAt !== null ? date(DATE_ATOM, $cachedAt) : null,
        'expiresAt' => $cachedAt !== null ? date(DATE_ATOM, $cachedAt + $ttl) : null,
    ];

    return $payload;
}

function require_api_key(): void
{
    $config = app_config();
    if ($config['apiKey'] !== '') {
        return;
    }

    throw new RuntimeException('ULTIMO_API_KEY fehlt. Bitte in .env setzen.');
}

function ultimo_request(string $apiPath, array $params = []): array
{
    require_api_key();
    $config = app_config();

    $url = $config['baseUrl'] . $apiPath;
    if ($params !== []) {
        $url .= '?' . http_build_query(array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
    }

    $headers = [
        'Accept: application/json',
        'ApiKey: ' . $config['apiKey'],
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawBody === false) {
            throw new RuntimeException($error !== '' ? $error : 'Ultimo request failed.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);
        $rawBody = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }
        if ($rawBody === false) {
            throw new RuntimeException('Ultimo request failed.');
        }
    }

    $decoded = json_decode($rawBody, true);
    $body = is_array($decoded) ? $decoded : ['raw' => $rawBody];

    if ($status < 200 || $status >= 300) {
        $message = $body['message'] ?? $body['detailMessage'] ?? ('Ultimo returned ' . $status);
        throw new RuntimeException($message);
    }

    return $body;
}

function fetch_collection(string $resource, array $params = []): array
{
    $data = ultimo_request('/object/' . $resource, $params);

    return [
        'count' => isset($data['count']) && is_numeric($data['count']) ? (int) $data['count'] : null,
        'nextPageLink' => $data['nextPageLink'] ?? null,
        'items' => isset($data['items']) && is_array($data['items']) ? $data['items'] : [],
    ];
}

function fetch_lookups(): array
{
    $config = app_config();
    $cacheFile = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'lookups-v2.json';

    if (is_file($cacheFile) && filemtime($cacheFile) !== false && filemtime($cacheFile) > time() - (int) $config['lookupCacheSeconds']) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && lookup_cache_has_values($cached)) {
            return $cached;
        }
    }

    $lookups = [];
    foreach (LOOKUP_RESOURCES as $resource) {
        try {
            $result = fetch_collection($resource, lookup_query_params($resource));

            $lookups[$resource] = [];
            foreach ($result['items'] as $item) {
                $id = (string) ($item['Id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $lookups[$resource][$id] = lookup_description($resource, $item);
            }
        } catch (Throwable $exception) {
            $lookups[$resource] = [];
        }
    }

    if (!is_dir($config['cacheDir'])) {
        @mkdir($config['cacheDir'], 0777, true);
    }
    if (is_dir($config['cacheDir']) && lookup_cache_has_values($lookups)) {
        @file_put_contents($cacheFile, json_encode($lookups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    return $lookups;
}

function lookup_cache_has_values(array $lookups): bool
{
    foreach ($lookups as $items) {
        if (is_array($items) && $items !== []) {
            return true;
        }
    }

    return false;
}

function lookup_query_params(string $resource): array
{
    $params = [
        'top' => 1000,
        'count' => 'true',
        'orderby' => 'Description',
        'select' => 'Id,Description',
    ];

    if ($resource === 'Space') {
        $params['select'] = 'Id,Description,RoomNumber';
    }

    return $params;
}

function lookup_description(string $resource, array $item): string
{
    $id = (string) ($item['Id'] ?? '');
    $description = trim((string) ($item['Description'] ?? ''));

    if ($resource !== 'Space') {
        return $description !== '' ? $description : $id;
    }

    $roomNumber = trim((string) ($item['RoomNumber'] ?? ''));
    if ($description === '') {
        return $roomNumber !== '' ? 'Raum ' . $roomNumber : $id;
    }

    if ($roomNumber === '' || str_contains($description, $roomNumber)) {
        return $description;
    }

    return $description . ' - Raum ' . $roomNumber;
}

function load_department_groups(): array
{
    $path = app_config()['departmentGroupsPath'];
    if (!is_file($path)) {
        return [];
    }

    $groups = require $path;
    if (!is_array($groups)) {
        return [];
    }

    $normalized = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $id = trim((string) ($group['id'] ?? ''));
        $name = trim((string) ($group['name'] ?? ''));
        $departments = $group['departments'] ?? [];

        if ($id === '' || $name === '' || !is_array($departments)) {
            continue;
        }

        $normalized[] = [
            'id' => $id,
            'name' => $name,
            'departments' => array_values(array_filter(
                array_map(static fn (mixed $department): string => trim((string) $department), $departments),
                static fn (string $department): bool => $department !== ''
            )),
        ];
    }

    return $normalized;
}

function resolve_department_group_ids(string $groupId, array $lookups): array
{
    $groups = load_department_groups();
    $group = null;
    foreach ($groups as $candidate) {
        if ($candidate['id'] === $groupId) {
            $group = $candidate;
            break;
        }
    }

    if ($group === null) {
        return [];
    }

    $departments = $lookups['Department'] ?? [];
    $wanted = array_map('normalize_department_name', $group['departments']);
    $ids = [];

    foreach ($departments as $id => $description) {
        $descriptionKey = normalize_department_name((string) $description);
        $idKey = normalize_department_name((string) $id);
        if (in_array($descriptionKey, $wanted, true) || in_array($idKey, $wanted, true)) {
            $ids[] = (string) $id;
        }
    }

    return array_values(array_unique($ids));
}

function serialize_department_groups(array $lookups): array
{
    $groups = load_department_groups();

    return array_map(static function (array $group) use ($lookups): array {
        return [
            'id' => $group['id'],
            'name' => $group['name'],
            'departmentIds' => resolve_department_group_ids($group['id'], $lookups),
            'departments' => $group['departments'],
        ];
    }, $groups);
}

function department_filter(array $departmentIds): ?string
{
    $departmentIds = array_values(array_filter(array_unique($departmentIds), static fn (string $id): bool => $id !== ''));
    if ($departmentIds === []) {
        return null;
    }

    $conditions = array_map(
        static fn (string $id): string => "Department eq '" . str_replace("'", "''", $id) . "'",
        $departmentIds
    );

    return count($conditions) === 1 ? $conditions[0] : '(' . implode(' or ', $conditions) . ')';
}

function field_filter(string $field, array $values): ?string
{
    $values = array_values(array_filter(array_unique($values), static fn (string $value): bool => trim($value) !== ''));
    if ($values === []) {
        return null;
    }

    $conditions = array_map(
        static fn (string $value): string => $field . " eq '" . str_replace("'", "''", trim($value)) . "'",
        $values
    );

    return count($conditions) === 1 ? $conditions[0] : '(' . implode(' or ', $conditions) . ')';
}

function number_field_filter(string $field, array $values): ?string
{
    $values = array_values(array_filter(array_unique($values), static fn (mixed $value): bool => is_numeric($value)));
    if ($values === []) {
        return null;
    }

    $conditions = array_map(
        static fn (mixed $value): string => $field . ' eq ' . (int) $value,
        $values
    );

    return count($conditions) === 1 ? $conditions[0] : '(' . implode(' or ', $conditions) . ')';
}

function combine_filters(array $filters): string
{
    $filters = array_values(array_filter(
        $filters,
        static fn (mixed $filter): bool => is_string($filter) && trim($filter) !== ''
    ));

    return implode(' and ', $filters);
}

function active_job_filter(): string
{
    return number_field_filter('Status', ACTIVE_JOB_STATUS_VALUES) ?? 'Status eq -1';
}

function closed_job_filter(): string
{
    return field_filter('ProgressStatus', CLOSED_PROGRESS_STATUS_IDS) ?? "ProgressStatus eq '__NO_MATCH__'";
}

function merge_jobs_by_id(array ...$collections): array
{
    $merged = [];
    foreach ($collections as $jobs) {
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $id = trim((string) ($job['Id'] ?? ''));
            $key = $id !== '' ? $id : 'job-' . count($merged);
            $merged[$key] = $job;
        }
    }

    return array_values($merged);
}

function scope_filter(?string $scope, array $lookups): ?string
{
    if ($scope === null || $scope === '' || $scope === 'all') {
        return null;
    }

    if (str_starts_with($scope, 'department:')) {
        return department_filter([substr($scope, strlen('department:'))]);
    }

    if (str_starts_with($scope, 'group:')) {
        $ids = resolve_department_group_ids(substr($scope, strlen('group:')), $lookups);
        return department_filter($ids) ?? "Department eq '__NO_MATCH__'";
    }

    if (str_starts_with($scope, 'skill:')) {
        return field_filter('SkillCategory', [substr($scope, strlen('skill:'))]);
    }

    return null;
}

function dashboard_widget_definitions(): array
{
    return [
        ['id' => 'kpis', 'name' => 'Kennzahlen', 'defaultWidth' => 'full'],
        ['id' => 'critical', 'name' => 'Kritische Meldungen', 'defaultWidth' => 'wide'],
        ['id' => 'departments', 'name' => 'Nach Abteilung', 'defaultWidth' => 'normal'],
        ['id' => 'priorities', 'name' => 'Prioritäten', 'defaultWidth' => 'normal'],
        ['id' => 'statuses', 'name' => 'Bearbeitungsstatus', 'defaultWidth' => 'normal'],
        ['id' => 'sites', 'name' => 'Standorte', 'defaultWidth' => 'normal'],
        ['id' => 'equipment', 'name' => 'Anlagen / Maschinen', 'defaultWidth' => 'normal'],
        ['id' => 'costCenters', 'name' => 'Kostenstellen', 'defaultWidth' => 'normal'],
        ['id' => 'skills', 'name' => 'Fähigkeiten', 'defaultWidth' => 'normal'],
        ['id' => 'workOrderTypes', 'name' => 'Auftragstypen', 'defaultWidth' => 'normal'],
        ['id' => 'failTypes', 'name' => 'Fehlerarten', 'defaultWidth' => 'normal'],
        ['id' => 'employees', 'name' => 'Mitarbeiter', 'defaultWidth' => 'normal'],
        ['id' => 'vendors', 'name' => 'Dienstleister', 'defaultWidth' => 'normal'],
        ['id' => 'recent', 'name' => 'Letzte Meldungen', 'defaultWidth' => 'wide'],
    ];
}

function default_widget_settings(): array
{
    $settings = [];
    foreach (dashboard_widget_definitions() as $widget) {
        $settings[$widget['id']] = [
            'width' => $widget['defaultWidth'] ?? 'normal',
        ];
    }

    return $settings;
}

function default_dashboard_layouts(): array
{
    return [
        [
            'id' => 'management',
            'name' => 'Management',
            'scope' => 'all',
            'refreshSeconds' => app_config()['refreshSeconds'],
            'widgets' => ['kpis', 'critical', 'departments', 'priorities', 'statuses', 'sites', 'equipment', 'costCenters', 'skills', 'recent'],
            'widgetSettings' => default_widget_settings(),
        ],
        [
            'id' => 'druck',
            'name' => 'Druck',
            'scope' => 'group:druck',
            'refreshSeconds' => app_config()['refreshSeconds'],
            'widgets' => ['kpis', 'critical', 'priorities', 'statuses', 'sites', 'equipment', 'failTypes', 'recent'],
            'widgetSettings' => default_widget_settings(),
        ],
        [
            'id' => 'konfektion',
            'name' => 'Konfektion',
            'scope' => 'group:konfektion',
            'refreshSeconds' => app_config()['refreshSeconds'],
            'widgets' => ['kpis', 'critical', 'priorities', 'statuses', 'sites', 'equipment', 'failTypes', 'recent'],
            'widgetSettings' => default_widget_settings(),
        ],
        [
            'id' => 'it',
            'name' => 'IT',
            'scope' => 'skill:IT',
            'refreshSeconds' => app_config()['refreshSeconds'],
            'widgets' => ['kpis', 'critical', 'statuses', 'employees', 'vendors', 'recent'],
            'widgetSettings' => default_widget_settings(),
        ],
    ];
}

function layout_file_path(): string
{
    return app_config()['layoutDir'] . DIRECTORY_SEPARATOR . 'dashboard-layouts.json';
}

function load_dashboard_layouts(): array
{
    $file = layout_file_path();
    if (!is_file($file)) {
        return default_dashboard_layouts();
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return default_dashboard_layouts();
    }

    $layouts = [];
    foreach ($decoded as $layout) {
        if (!is_array($layout)) {
            continue;
        }

        $clean = sanitize_dashboard_layout($layout);
        if ($clean !== null) {
            $layouts[] = $clean;
        }
    }

    return $layouts !== [] ? $layouts : default_dashboard_layouts();
}

function save_dashboard_layouts(array $layouts): void
{
    $config = app_config();
    if (!is_dir($config['layoutDir']) && !@mkdir($config['layoutDir'], 0775, true) && !is_dir($config['layoutDir'])) {
        throw new RuntimeException('Layout-Verzeichnis konnte nicht erstellt werden.');
    }

    $payload = json_encode(array_values($layouts), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false || @file_put_contents(layout_file_path(), $payload . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Layouts konnten nicht gespeichert werden.');
    }
}

function sanitize_dashboard_layout(array $layout): ?array
{
    $id = normalize_layout_id((string) ($layout['id'] ?? ''));
    $name = trim((string) ($layout['name'] ?? ''));
    if ($id === '' || $name === '') {
        return null;
    }

    $knownWidgets = array_column(dashboard_widget_definitions(), 'id');
    $widgets = $layout['widgets'] ?? [];
    if (!is_array($widgets)) {
        $widgets = [];
    }

    $cleanWidgets = [];
    foreach ($widgets as $widget) {
        $widget = (string) $widget;
        if (in_array($widget, $knownWidgets, true) && !in_array($widget, $cleanWidgets, true)) {
            $cleanWidgets[] = $widget;
        }
    }

    if ($cleanWidgets === []) {
        $cleanWidgets = $knownWidgets;
    }

    $widgetSettings = default_widget_settings();
    $incomingSettings = $layout['widgetSettings'] ?? [];
    if (is_array($incomingSettings)) {
        foreach ($incomingSettings as $widgetId => $settings) {
            $widgetId = (string) $widgetId;
            if (!in_array($widgetId, $knownWidgets, true) || !is_array($settings)) {
                continue;
            }

            $width = normalize_widget_width((string) ($settings['width'] ?? ''));
            if ($width !== null) {
                $widgetSettings[$widgetId]['width'] = $width;
            }
        }
    }

    return [
        'id' => $id,
        'name' => function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80),
        'scope' => normalize_scope((string) ($layout['scope'] ?? 'all')),
        'refreshSeconds' => clamp_number($layout['refreshSeconds'] ?? app_config()['refreshSeconds'], 30, 300, app_config()['refreshSeconds']),
        'widgets' => $cleanWidgets,
        'widgetSettings' => $widgetSettings,
        'updatedAt' => date(DATE_ATOM),
    ];
}

function normalize_widget_width(string $width): ?string
{
    $width = trim($width);
    if (in_array($width, ['normal', 'wide', 'full'], true)) {
        return $width;
    }

    return null;
}

function normalize_layout_id(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? '';
    return trim(strtolower($value), '-_');
}

function normalize_scope(string $scope): string
{
    $scope = trim($scope);
    if ($scope === '' || $scope === 'all') {
        return 'all';
    }

    foreach (['department:', 'group:', 'skill:'] as $prefix) {
        if (str_starts_with($scope, $prefix) && trim(substr($scope, strlen($prefix))) !== '') {
            return $scope;
        }
    }

    return 'all';
}

function layout_scope_options(array $lookups): array
{
    $options = [
        ['value' => 'all', 'label' => 'Gesamtübersicht'],
    ];

    foreach (serialize_department_groups($lookups) as $group) {
        if (($group['departmentIds'] ?? []) !== []) {
            $options[] = ['value' => 'group:' . $group['id'], 'label' => $group['name'] . ' gesamt'];
        }
    }

    foreach ($lookups['Department'] ?? [] as $id => $label) {
        $options[] = ['value' => 'department:' . $id, 'label' => (string) $label];
    }

    foreach ($lookups['SkillCategory'] ?? [] as $id => $label) {
        $options[] = ['value' => 'skill:' . $id, 'label' => 'Fähigkeit: ' . $label];
    }

    return $options;
}

function normalize_department_name(string $value): string
{
    $trimmed = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
}

function enrich_job(array $job, array $lookups): array
{
    $now = time();
    $finishedAt = parse_timestamp($job['StatusFinishedDate'] ?? null);
    $targetAt = parse_timestamp($job['ServiceContractTargetFinishedDate'] ?? ($job['TargetDate'] ?? null));
    $responseTargetAt = parse_timestamp($job['ServiceContractTargetResponseDate'] ?? null);
    $createdAt = parse_timestamp($job['StatusCreatedReportDate'] ?? null);
    $activeAt = parse_timestamp($job['StatusActiveDate'] ?? ($job['StatusCreatedReportDate'] ?? null));
    $ageHours = $activeAt === null ? null : max(0, ($now - $activeAt) / 3600);

    $job['labels'] = [
        'Department' => lookup_label($lookups['Department'] ?? [], $job['Department'] ?? null),
        'Priority' => lookup_label($lookups['Priority'] ?? [], $job['Priority'] ?? null),
        'ProgressStatus' => lookup_label($lookups['ProgressStatus'] ?? [], $job['ProgressStatus'] ?? null),
        'Equipment' => lookup_label($lookups['Equipment'] ?? [], $job['Equipment'] ?? null),
        'Site' => lookup_label($lookups['Site'] ?? [], $job['Site'] ?? null),
        'Space' => lookup_label($lookups['Space'] ?? [], $job['Space'] ?? null),
        'Employee' => lookup_label($lookups['Employee'] ?? [], $job['Employee'] ?? null),
        'CostCenter' => lookup_label($lookups['CostCenter'] ?? [], $job['CostCenter'] ?? null),
        'ProcessFunction' => lookup_label($lookups['ProcessFunction'] ?? [], $job['ProcessFunction'] ?? null),
        'WorkOrderType' => lookup_label($lookups['WorkOrderType'] ?? [], $job['WorkOrderType'] ?? null),
        'FailType' => lookup_label($lookups['FailType'] ?? [], $job['FailType'] ?? null),
        'Component' => lookup_label($lookups['Component'] ?? [], $job['Component'] ?? null),
        'ComponentProblem' => lookup_label($lookups['ComponentProblem'] ?? [], $job['ComponentProblem'] ?? null),
        'Remedy' => lookup_label($lookups['Remedy'] ?? [], $job['Remedy'] ?? null),
        'SkillCategory' => lookup_label($lookups['SkillCategory'] ?? [], $job['SkillCategory'] ?? null),
        'Vendor' => lookup_label($lookups['Vendor'] ?? [], $job['Vendor'] ?? null),
    ];
    $job['labels']['Location'] = job_location_label($job);
    $isClosed = is_closed_job($job);
    $isOpen = !$isClosed;

    $job['metrics'] = [
        'isClosed' => $isClosed,
        'isOpen' => $isOpen,
        'ageHours' => $ageHours,
        'isOverdue' => $isOpen && $targetAt !== null && $targetAt < $now,
        'responseOverdue' => $isOpen && $responseTargetAt !== null && $responseTargetAt < $now,
        'createdToday' => is_today($createdAt),
        'finishedToday' => is_today($finishedAt),
        'dueToday' => $isOpen && $targetAt !== null && is_today($targetAt),
        'dueWithin24h' => $isOpen && $targetAt !== null && $targetAt >= $now && $targetAt <= $now + 86400,
    ];

    return $job;
}

function build_summary(array $jobs): array
{
    $openJobs = array_values(array_filter($jobs, static fn (array $job): bool => (bool) ($job['metrics']['isOpen'] ?? false)));
    $finishedJobs = array_values(array_filter($jobs, static fn (array $job): bool => (bool) ($job['metrics']['isClosed'] ?? false)));

    $criticalJobs = array_values(array_filter($openJobs, static function (array $job): bool {
        return (bool) ($job['metrics']['isOverdue'] ?? false)
            || (bool) ($job['metrics']['responseOverdue'] ?? false)
            || (bool) ($job['metrics']['dueWithin24h'] ?? false);
    }));

    usort($criticalJobs, 'compare_criticality');

    $recentJobs = $openJobs;
    usort($recentJobs, static function (array $left, array $right): int {
        return date_value($right['StatusCreatedReportDate'] ?? null) <=> date_value($left['StatusCreatedReportDate'] ?? null);
    });

    return [
        'kpis' => [
            'total' => count($jobs),
            'open' => count($openJobs),
            'finishedRecently' => count($finishedJobs),
            'overdue' => count(array_filter($openJobs, static fn (array $job): bool => (bool) ($job['metrics']['isOverdue'] ?? false))),
            'responseOverdue' => count(array_filter($openJobs, static fn (array $job): bool => (bool) ($job['metrics']['responseOverdue'] ?? false))),
            'dueToday' => count(array_filter($openJobs, static fn (array $job): bool => (bool) ($job['metrics']['dueToday'] ?? false))),
            'dueWithin24h' => count(array_filter($openJobs, static fn (array $job): bool => (bool) ($job['metrics']['dueWithin24h'] ?? false))),
            'newToday' => count(array_filter($jobs, static fn (array $job): bool => (bool) ($job['metrics']['createdToday'] ?? false))),
            'closedToday' => count(array_filter($finishedJobs, static fn (array $job): bool => (bool) ($job['metrics']['finishedToday'] ?? false))),
            'averageOpenAgeHours' => average(array_map(static fn (array $job) => $job['metrics']['ageHours'] ?? null, $openJobs)),
        ],
        'byDepartment' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'Department', 'Ohne Abteilung')),
        'byPriority' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'Priority', 'Ohne Priorität')),
        'byProgressStatus' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'ProgressStatus', 'Ohne Status')),
        'bySite' => group_jobs($openJobs, static fn (array $job): string => trim((string) ($job['labels']['Location'] ?? '')) ?: 'Ohne Standort'),
        'byEquipment' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'Equipment', 'Ohne Anlage')),
        'byCostCenter' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'CostCenter', 'Ohne Kostenstelle')),
        'bySkillCategory' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'SkillCategory', 'Ohne Fähigkeit')),
        'byWorkOrderType' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'WorkOrderType', 'Ohne Auftragstyp')),
        'byFailType' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'FailType', 'Ohne Fehlerart')),
        'byEmployee' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'Employee', 'Ohne Mitarbeiter')),
        'byVendor' => group_jobs($openJobs, static fn (array $job): string => group_key($job, 'Vendor', 'Ohne Dienstleister')),
        'criticalJobs' => array_slice($criticalJobs, 0, 20),
        'recentJobs' => array_slice($recentJobs, 0, 20),
    ];
}

function group_key(array $job, string $labelKey, string $fallback): string
{
    $label = trim((string) ($job['labels'][$labelKey] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $raw = trim((string) ($job[$labelKey] ?? ''));
    return $raw !== '' ? $raw : $fallback;
}

function job_location_label(array $job): string
{
    $space = trim((string) ($job['labels']['Space'] ?? ''));
    if ($space !== '') {
        return $space;
    }

    $processFunction = trim((string) ($job['labels']['ProcessFunction'] ?? ''));
    if ($processFunction !== '') {
        return $processFunction;
    }

    return trim((string) ($job['labels']['Site'] ?? ''));
}

function is_closed_job(array $job): bool
{
    $status = $job['Status'] ?? null;
    if (is_numeric($status)) {
        return !in_array((int) $status, ACTIVE_JOB_STATUS_VALUES, true);
    }

    $progressStatus = trim((string) ($job['ProgressStatus'] ?? ''));
    if (in_array($progressStatus, CLOSED_PROGRESS_STATUS_IDS, true)) {
        return true;
    }

    $progressStatusLabel = normalize_status_text((string) ($job['labels']['ProgressStatus'] ?? ''));
    if ($progressStatusLabel === 'geschlossen') {
        return true;
    }

    if ($progressStatus !== '' || $progressStatusLabel !== '') {
        return false;
    }

    return normalize_status_text((string) ($job['Status'] ?? '')) === 'geschlossen';
}

function normalize_status_text(string $value): string
{
    $value = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function group_jobs(array $jobs, callable $keyResolver): array
{
    $groups = [];
    foreach ($jobs as $job) {
        $key = $keyResolver($job);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'total' => 0,
                'overdue' => 0,
                'dueToday' => 0,
                'responseOverdue' => 0,
            ];
        }

        $groups[$key]['total']++;
        $groups[$key]['overdue'] += !empty($job['metrics']['isOverdue']) ? 1 : 0;
        $groups[$key]['dueToday'] += !empty($job['metrics']['dueToday']) ? 1 : 0;
        $groups[$key]['responseOverdue'] += !empty($job['metrics']['responseOverdue']) ? 1 : 0;
    }

    $groupValues = array_values($groups);
    usort($groupValues, static function (array $left, array $right): int {
        if ($left['total'] === $right['total']) {
            return strcmp($left['key'], $right['key']);
        }
        return $right['total'] <=> $left['total'];
    });

    return $groupValues;
}

function serialize_lookups(array $lookups): array
{
    $result = [];
    foreach ($lookups as $resource => $items) {
        $result[$resource] = [];
        foreach ($items as $id => $description) {
            $result[$resource][] = [
                'Id' => $id,
                'Description' => $description,
            ];
        }
    }
    return $result;
}

function lookup_label(array $lookup, mixed $id): string
{
    if ($id === null || $id === '') {
        return '';
    }

    $key = (string) $id;
    return isset($lookup[$key]) ? (string) $lookup[$key] : $key;
}

function parse_timestamp(?string $value): ?int
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

function date_value(?string $value): int
{
    return parse_timestamp($value) ?? 0;
}

function is_today(?int $timestamp): bool
{
    if ($timestamp === null) {
        return false;
    }

    $date = new DateTimeImmutable('@' . $timestamp);
    $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $today = new DateTimeImmutable('now');
    return $date->format('Y-m-d') === $today->format('Y-m-d');
}

function average(array $values): ?float
{
    $clean = array_values(array_filter($values, static fn (mixed $value): bool => is_numeric($value)));
    if ($clean === []) {
        return null;
    }

    return array_sum($clean) / count($clean);
}

function compare_criticality(array $left, array $right): int
{
    $score = static function (array $job): float {
        return (!empty($job['metrics']['isOverdue']) ? 100 : 0)
            + (!empty($job['metrics']['responseOverdue']) ? 80 : 0)
            + (!empty($job['metrics']['dueToday']) ? 50 : 0)
            + (!empty($job['metrics']['dueWithin24h']) ? 20 : 0)
            - ((float) ($job['metrics']['ageHours'] ?? 0) / 1000);
    };

    return $score($right) <=> $score($left);
}

function normalize_error(Throwable $exception): array
{
    return [
        'ok' => false,
        'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Unexpected error',
    ];
}

function clean_optional(?string $value): ?string
{
    $normalized = trim((string) $value);
    return $normalized === '' ? null : $normalized;
}

function clamp_number(mixed $value, int $min, int $max, int $fallback): int
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    $number = (int) round((float) $value);
    return max($min, min($max, $number));
}

/**
 * Holt ALLE Jobs einmalig von Ultimo und cached sie global.
 *
 * Egal wie viele Ansichten oder TVs aktiv sind – Ultimo wird nur
 * einmal pro DASHBOARD_CACHE_SECONDS-Intervall kontaktiert.
 * Scope-spezifische Filterung erfolgt danach in PHP (filter_jobs_by_scope).
 */
function fetch_all_jobs_cached(int $maxJobs = 500): array
{
    $config = app_config();
    $ttl = (int) $config['dashboardCacheSeconds'];
    $cacheFile = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'all-jobs-global.json';
    $lockFile  = $cacheFile . '.lock';

    // Cache frisch genug → direkt zurückgeben
    $cached = read_fresh_json_cache($cacheFile, $ttl);
    if ($cached !== null && isset($cached['openItems'])) {
        return $cached;
    }

    // Lock holen damit parallele Requests nicht alle gleichzeitig fetchen
    ensure_cache_dir();
    $lock = @fopen($lockFile, 'c');
    if ($lock !== false && @flock($lock, LOCK_EX)) {
        // Nach dem Lock nochmals prüfen (anderer Prozess war schneller)
        $cached = read_fresh_json_cache($cacheFile, $ttl);
        if ($cached !== null && isset($cached['openItems'])) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            return $cached;
        }

        $data = do_fetch_all_jobs($maxJobs);
        write_json_cache($cacheFile, $data);
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $data;
    }

    // Lock fehlgeschlagen → trotzdem fetchen (kein Cache lieber als Absturz)
    $data = do_fetch_all_jobs($maxJobs);
    write_json_cache($cacheFile, $data);
    return $data;
}

/**
 * Führt die eigentlichen zwei Ultimo-Requests durch (offen + geschlossen).
 * Wird nur von fetch_all_jobs_cached aufgerufen.
 */
function do_fetch_all_jobs(int $maxJobs): array
{
    $openJobs = fetch_collection('Job', [
        'top'     => $maxJobs,
        'count'   => 'true',
        'orderby' => 'StatusCreatedReportDate desc',
        'select'  => implode(',', JOB_SELECT),
        'filter'  => active_job_filter(),
    ]);

    $closedJobs = fetch_collection('Job', [
        'top'     => min($maxJobs, 300),
        'count'   => 'true',
        'orderby' => 'StatusFinishedDate desc',
        'select'  => implode(',', JOB_SELECT),
        'filter'  => closed_job_filter(),
    ]);

    return [
        'fetchedAt'   => date(DATE_ATOM),
        'openItems'   => $openJobs['items'],
        'closedItems' => $closedJobs['items'],
        'openCount'   => $openJobs['count'],
        'closedCount' => $closedJobs['count'],
    ];
}

/**
 * Filtert einen bereits in PHP vorliegenden Job-Array nach dem gewünschten Scope.
 * Ersetzt den bisherigen Ultimo-seitigen OData-Filter.
 */
function filter_jobs_by_scope(array $jobs, ?string $scope, array $lookups): array
{
    if ($scope === null || $scope === '' || $scope === 'all') {
        return $jobs;
    }

    if (str_starts_with($scope, 'department:')) {
        $deptId = trim(substr($scope, strlen('department:')));
        return array_values(array_filter(
            $jobs,
            static fn (array $job): bool => (string) ($job['Department'] ?? '') === $deptId
        ));
    }

    if (str_starts_with($scope, 'group:')) {
        $groupId = trim(substr($scope, strlen('group:')));
        $deptIds = resolve_department_group_ids($groupId, $lookups);
        if ($deptIds === []) {
            return [];
        }
        return array_values(array_filter(
            $jobs,
            static fn (array $job): bool => in_array((string) ($job['Department'] ?? ''), $deptIds, true)
        ));
    }

    if (str_starts_with($scope, 'skill:')) {
        $skillId = trim(substr($scope, strlen('skill:')));
        return array_values(array_filter(
            $jobs,
            static fn (array $job): bool => (string) ($job['SkillCategory'] ?? '') === $skillId
        ));
    }

    // Unbekannter Scope → alle zurückgeben
    return $jobs;
}

/**
 * Löscht den globalen Job-Cache (z.B. nach manuellem Refresh nützlich).
 */
function invalidate_global_jobs_cache(): void
{
    $config = app_config();
    $cacheFile = $config['cacheDir'] . DIRECTORY_SEPARATOR . 'all-jobs-global.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}
