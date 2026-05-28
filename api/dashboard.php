<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    require_api_key();

    $scope              = clean_optional($_GET['scope']            ?? null);
    $department         = clean_optional($_GET['department']       ?? null);
    $departmentGroup    = clean_optional($_GET['departmentGroup']  ?? null);
    $maxJobs            = clamp_number($_GET['maxJobs']            ?? 500, 50, 1000, 500);
    $includeFinishedDays = clamp_number($_GET['includeFinishedDays'] ?? 14, 1, 60, 14);

    // Scope aus Legacy-Parametern ableiten (Abwärtskompatibilität)
    if ($scope === null && $departmentGroup !== null) {
        $scope = 'group:' . $departmentGroup;
    } elseif ($scope === null && $department !== null) {
        $scope = 'department:' . $department;
    }

    // Cache-Key: nur der Scope bestimmt den Schlüssel – die rohen Jobs
    // kommen immer aus dem globalen Cache und werden in PHP gefiltert.
    cached_dashboard_response([
        'scope'              => $scope,
        'includeFinishedDays' => $includeFinishedDays,
    ], static function () use ($scope, $department, $departmentGroup, $maxJobs, $includeFinishedDays): array {

        // ── Stammdaten ────────────────────────────────────────────────
        $lookups = fetch_lookups();

        // ── Globaler Job-Cache (ein einziger Ultimo-Abruf für alle Scopes) ──
        // Statt pro Scope 2× bei Ultimo anzufragen, holen wir ALLE Jobs
        // einmalig und filtern in PHP. Dadurch sinken die Ultimo-Requests
        // auf maximal 2 pro DASHBOARD_CACHE_SECONDS-Intervall – unabhängig
        // davon, wie viele Ansichten oder TVs aktiv sind.
        $allJobs = fetch_all_jobs_cached($maxJobs);

        $rawOpen   = $allJobs['openItems']   ?? [];
        $rawClosed = $allJobs['closedItems'] ?? [];

        // ── PHP-seitige Scope-Filterung ───────────────────────────────
        $filteredOpen   = filter_jobs_by_scope($rawOpen,   $scope, $lookups);
        $filteredClosed = filter_jobs_by_scope($rawClosed, $scope, $lookups);

        $rawJobs = merge_jobs_by_id($filteredOpen, $filteredClosed);

        $enrichedJobs = array_map(
            static fn (array $job): array => enrich_job($job, $lookups),
            $rawJobs
        );

        return [
            'generatedAt' => date(DATE_ATOM),
            'source'      => app_config()['baseUrl'] . '/object/Job',
            'filter'      => [
                'scope'              => $scope,
                'department'         => $department,
                'departmentGroup'    => $departmentGroup,
                'maxJobs'            => $maxJobs,
                'includeFinishedDays' => $includeFinishedDays,
            ],
            'counts' => [
                'returnedJobs'       => count($rawJobs),
                'returnedOpenJobs'   => count($filteredOpen),
                'returnedClosedJobs' => count($filteredClosed),
                // Globale Zahlen aus dem Cache (Ultimo-Antwort)
                'ultimoCount'        => (int) ($allJobs['openCount'] ?? 0) + (int) ($allJobs['closedCount'] ?? 0),
                'ultimoOpenCount'    => $allJobs['openCount']   ?? null,
                'ultimoClosedCount'  => $allJobs['closedCount'] ?? null,
            ],
            'lookups'          => serialize_lookups($lookups),
            'departmentGroups' => serialize_department_groups($lookups),
            'summary'          => build_summary($enrichedJobs),
            'jobs'             => $enrichedJobs,
        ];
    });
} catch (Throwable $exception) {
    json_response(normalize_error($exception), 500);
}
