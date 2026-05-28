<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        try {
            $lookups = app_config()['apiKey'] !== '' ? fetch_lookups() : [];
        } catch (Throwable $exception) {
            $lookups = [];
        }
        json_response([
            'layouts' => load_dashboard_layouts(),
            'widgets' => dashboard_widget_definitions(),
            'scopeOptions' => layout_scope_options($lookups),
        ]);
    }

    if ($method === 'POST') {
        $rawBody = (string) file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Ungültige Layout-Daten.');
        }

        $layout = $payload['layout'] ?? null;
        if (!is_array($layout)) {
            throw new RuntimeException('Layout fehlt.');
        }

        $cleanLayout = sanitize_dashboard_layout($layout);
        if ($cleanLayout === null) {
            throw new RuntimeException('Layout braucht ID und Namen.');
        }

        $layouts = load_dashboard_layouts();
        $saved = false;
        foreach ($layouts as $index => $existing) {
            if (($existing['id'] ?? '') === $cleanLayout['id']) {
                $layouts[$index] = $cleanLayout;
                $saved = true;
                break;
            }
        }

        if (!$saved) {
            $layouts[] = $cleanLayout;
        }

        save_dashboard_layouts($layouts);

        json_response([
            'ok' => true,
            'layout' => $cleanLayout,
            'layouts' => $layouts,
        ]);
    }

    if ($method === 'DELETE') {
        $rawBody = (string) file_get_contents('php://input');
        $payload = $rawBody !== '' ? json_decode($rawBody, true) : [];
        $id = normalize_layout_id((string) (($payload['id'] ?? null) ?: ($_GET['id'] ?? '')));
        if ($id === '') {
            throw new RuntimeException('Layout-ID fehlt.');
        }

        $layouts = load_dashboard_layouts();
        if (count($layouts) <= 1) {
            throw new RuntimeException('Mindestens eine Ansicht muss erhalten bleiben.');
        }

        $filtered = array_values(array_filter(
            $layouts,
            static fn (array $layout): bool => ($layout['id'] ?? '') !== $id
        ));

        if (count($filtered) === count($layouts)) {
            throw new RuntimeException('Layout wurde nicht gefunden.');
        }

        save_dashboard_layouts($filtered);

        json_response([
            'ok' => true,
            'deletedId' => $id,
            'layouts' => $filtered,
        ]);
    }

    json_response(['ok' => false, 'message' => 'Methode nicht erlaubt.'], 405);
} catch (Throwable $exception) {
    json_response(normalize_error($exception), 500);
}
