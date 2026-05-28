<!doctype html>
<html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ultimo Dashboard</title>
    <link rel="stylesheet" href="styles.css">
  </head>
  <body>
    <main class="app-shell">
      <header class="topbar">
        <div>
          <p class="eyebrow">ULTIMO LIVE-DASHBOARD</p>
          <h1 id="view-title">Gesamtübersicht</h1>
        </div>
        <section class="controls" aria-label="Dashboard Einstellungen">
          <label class="select-label">
            <span>Ansicht</span>
            <select id="layout-select"></select>
          </label>
          <label class="select-label">
            <span>Abteilung</span>
            <select id="department-select"></select>
          </label>
          <label class="select-label">
            <span>Aktualisierung</span>
            <select id="refresh-select">
              <option value="30">30s</option>
              <option value="60" selected>60s</option>
              <option value="120">2min</option>
              <option value="300">5min</option>
            </select>
          </label>
          <a class="admin-link" href="admin.php">Layout</a>
        </section>
      </header>

      <section class="status-strip">
        <div>
          <span class="muted">Quelle</span>
          <strong id="source-label">Ultimo API</strong>
        </div>
        <div>
          <span class="muted">Letztes Update</span>
          <strong id="last-updated">-</strong>
        </div>
        <div>
          <span class="muted">Nächstes Update</span>
          <strong id="next-refresh">-</strong>
        </div>
      </section>

      <section id="error-panel" class="error-panel hidden" role="alert"></section>

      <section id="dashboard-canvas" class="dashboard-grid" aria-label="Dashboard">
        <section class="kpi-grid" data-widget="kpis" aria-label="Kennzahlen">
          <article class="kpi danger">
            <span>Überfällig</span>
            <strong id="kpi-overdue">-</strong>
          </article>
          <article class="kpi warning">
            <span>Fällig heute</span>
            <strong id="kpi-due-today">-</strong>
          </article>
          <article class="kpi">
            <span>Offen</span>
            <strong id="kpi-open">-</strong>
          </article>
          <article class="kpi">
            <span>Neu heute</span>
            <strong id="kpi-new-today">-</strong>
          </article>
          <article class="kpi">
            <span>Geschlossen heute</span>
            <strong id="kpi-closed-today">-</strong>
          </article>
          <article class="kpi">
            <span>Ø Alter offen</span>
            <strong id="kpi-age">-</strong>
          </article>
        </section>

        <article class="panel" data-widget="critical">
          <div class="panel-header">
            <h2>Kritische Meldungen</h2>
            <span id="critical-count" class="pill">0</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Beschreibung</th>
                  <th>Abteilung</th>
                  <th>Priorität</th>
                  <th>Status</th>
                  <th>Ziel</th>
                </tr>
              </thead>
              <tbody id="critical-table"></tbody>
            </table>
          </div>
        </article>

        <article class="panel" data-widget="departments">
          <div class="panel-header">
            <h2>Nach Abteilung</h2>
          </div>
          <div id="department-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="priorities">
          <div class="panel-header">
            <h2>Prioritäten</h2>
          </div>
          <div id="priority-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="statuses">
          <div class="panel-header">
            <h2>Bearbeitungsstatus</h2>
          </div>
          <div id="status-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="sites">
          <div class="panel-header">
            <h2>Standorte</h2>
          </div>
          <div id="site-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="equipment">
          <div class="panel-header">
            <h2>Anlagen / Maschinen</h2>
          </div>
          <div id="equipment-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="costCenters">
          <div class="panel-header">
            <h2>Kostenstellen</h2>
          </div>
          <div id="cost-center-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="skills">
          <div class="panel-header">
            <h2>Fähigkeiten</h2>
          </div>
          <div id="skill-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="workOrderTypes">
          <div class="panel-header">
            <h2>Auftragstypen</h2>
          </div>
          <div id="work-order-type-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="failTypes">
          <div class="panel-header">
            <h2>Fehlerarten</h2>
          </div>
          <div id="fail-type-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="employees">
          <div class="panel-header">
            <h2>Mitarbeiter</h2>
          </div>
          <div id="employee-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="vendors">
          <div class="panel-header">
            <h2>Dienstleister</h2>
          </div>
          <div id="vendor-bars" class="bars"></div>
        </article>

        <article class="panel" data-widget="recent">
          <div class="panel-header">
            <h2>Letzte Meldungen</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Beschreibung</th>
                  <th>Abteilung</th>
                  <th>Standort</th>
                  <th>Status</th>
                  <th>Erstellt</th>
                </tr>
              </thead>
              <tbody id="recent-table"></tbody>
            </table>
          </div>
        </article>
      </section>
    </main>

    <script src="app.js" type="module"></script>
  </body>
</html>
