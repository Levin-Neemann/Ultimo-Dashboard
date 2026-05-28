<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Layouts</title>
    <link rel="stylesheet" href="styles.css?v=20260528-clients">
    <link rel="stylesheet" href="admin.css?v=20260528-clients">
  </head>
  <body>
    <main class="admin-shell">
      <header class="admin-topbar">
        <div>
          <p class="eyebrow">ULTIMO DASHBOARD</p>
          <h1>Layouts</h1>
        </div>
        <a class="admin-link" href="index.php">Dashboard</a>
      </header>

      <section id="admin-error" class="error-panel hidden" role="alert"></section>
      <section id="admin-ok" class="success-panel hidden" role="status"></section>

      <section class="admin-grid">
        <aside class="admin-panel">
          <div class="panel-header">
            <h2>Ansichten</h2>
            <button id="new-layout" type="button">Neu</button>
          </div>
          <div id="layout-list" class="layout-list"></div>
        </aside>

        <form id="layout-form" class="admin-panel layout-form">
          <div class="form-grid">
            <label>
              <span>Name</span>
              <input id="layout-name" name="name" required maxlength="80">
            </label>
            <label>
              <span>ID</span>
              <input id="layout-id" name="id" required pattern="[a-zA-Z0-9_-]+">
            </label>
            <label>
              <span>Filter</span>
              <select id="layout-scope" name="scope"></select>
            </label>
            <label>
              <span>Aktualisierung</span>
              <select id="layout-refresh" name="refreshSeconds">
                <option value="30">30s</option>
                <option value="60">60s</option>
                <option value="120">2min</option>
                <option value="300">5min</option>
              </select>
            </label>
          </div>

          <div class="builder-head">
            <div>
              <p class="eyebrow">Dashboard-Baukasten</p>
              <h2>Widgets, Reihenfolge und Größe</h2>
            </div>
            <div class="builder-actions">
              <a id="preview-layout" class="admin-link" href="index.php" target="_blank" rel="noreferrer">Vorschau</a>
              <button id="copy-layout-link" type="button">Link kopieren</button>
              <button id="delete-layout" class="danger-button" type="button">Löschen</button>
              <button id="save-layout" type="submit">Speichern</button>
            </div>
          </div>

          <div id="widget-list" class="widget-list"></div>
        </form>
      </section>

      <!-- ═══════════════════════════════════════════════════════
           Admin-Tools (passwortgeschützt)
           ═══════════════════════════════════════════════════════ -->
      <section class="admin-tools-wrap" id="admin-tools-wrap">

        <button class="tools-toggle" id="tools-toggle" type="button" aria-expanded="false">
          <span class="tools-toggle-icon">⚙</span>
          <span>Admin-Tools</span>
          <span class="tools-toggle-chevron">▸</span>
        </button>

        <div class="tools-body hidden" id="tools-body">

          <!-- Passwort-Gate -->
          <div class="tools-gate" id="tools-gate">
            <p class="tools-gate-hint">
              Diese Funktionen erfordern das in <code>.env</code> gesetzte <code>ADMIN_PASSWORD</code>.
            </p>
            <form class="tools-gate-form" id="tools-password-form">
              <input
                id="tools-password"
                type="password"
                placeholder="Admin-Passwort"
                autocomplete="current-password"
                class="tools-password-input"
              >
              <button type="submit" class="tools-unlock-btn">Entsperren</button>
            </form>
            <p id="tools-gate-error" class="tools-gate-error hidden"></p>
          </div>

          <!-- Tools (nach Login sichtbar) -->
          <div class="tools-dashboard hidden" id="tools-dashboard">

            <div class="tools-error hidden" id="tools-error" role="alert"></div>
            <div class="tools-ok hidden" id="tools-ok" role="status"></div>

            <!-- Cache-Status -->
            <div class="tools-card" id="tools-status-card">
              <div class="tools-card-head">
                <div>
                  <strong>Cache-Status</strong>
                  <p class="tools-card-sub">Übersicht über gecachte Daten</p>
                </div>
                <button class="tools-btn" id="btn-status" type="button">Aktualisieren</button>
              </div>
              <div id="tools-status-body" class="tools-status-grid">
                <p class="tools-placeholder">Noch nicht geladen.</p>
              </div>
            </div>

            <!-- Aktive Clients -->
            <div class="tools-card" id="tools-clients-card">
              <div class="tools-card-head">
                <div>
                  <strong>Aktive Clients</strong>
                  <p class="tools-card-sub">Offene Dashboard-Browser nach Ansicht</p>
                </div>
              </div>
              <div id="tools-clients-body" class="tools-client-list">
                <p class="tools-placeholder">Noch nicht geladen.</p>
              </div>
            </div>

            <!-- Stammdaten -->
            <div class="tools-card">
              <div class="tools-card-head">
                <div>
                  <strong>Stammdaten (Lookups)</strong>
                  <p class="tools-card-sub">Abteilungen, Anlagen, Mitarbeiter, Prioritäten u.&thinsp;a.</p>
                </div>
                <div class="tools-card-actions">
                  <button class="tools-btn" id="btn-flush-lookups" type="button">Cache leeren</button>
                  <button class="tools-btn tools-btn-primary" id="btn-force-fetch" type="button">Jetzt neu laden ↺</button>
                </div>
              </div>
              <p class="tools-hint">
                <em>Cache leeren</em> entfernt nur die gespeicherte Datei – die Daten werden beim nächsten
                regulären Dashboard-Aufruf neu geholt.<br>
                <em>Jetzt neu laden</em> ruft Ultimo sofort ab und speichert das Ergebnis.
              </p>
            </div>

            <!-- Dashboard-Cache -->
            <div class="tools-card">
              <div class="tools-card-head">
                <div>
                  <strong>Dashboard-Cache</strong>
                  <p class="tools-card-sub">Zwischengespeicherte Auswertungen pro Ansicht</p>
                </div>
                <div class="tools-card-actions">
                  <button class="tools-btn" id="btn-flush-dashboard" type="button">Dashboard-Cache leeren</button>
                  <button class="tools-btn danger-button" id="btn-flush-all" type="button">Gesamten Cache leeren</button>
                </div>
              </div>
              <p class="tools-hint">
                Leert alle zwischengespeicherten Dashboard-Antworten. Der nächste Seitenaufruf
                erzwingt einen frischen Ultimo-API-Request pro Ansicht.
              </p>
            </div>

            <!-- Session -->
            <div class="tools-card tools-card-slim">
              <div class="tools-card-head">
                <div>
                  <strong>Sitzung</strong>
                  <p class="tools-card-sub">Passwort aus dem Browser-Speicher entfernen</p>
                </div>
                <button class="tools-btn danger-button" id="btn-logout" type="button">Abmelden</button>
              </div>
            </div>

          </div><!-- /tools-dashboard -->
        </div><!-- /tools-body -->
      </section>

    </main>

    <script src="admin.js?v=20260528-clients" type="module"></script>
  </body>
</html>
