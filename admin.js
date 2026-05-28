const apiBase = new URL("./api/", window.location.href);

const state = {
  layouts: [],
  widgets: [],
  scopeOptions: [],
  activeId: ""
};

const widthOptions = [
  { value: "normal", label: "Normal" },
  { value: "wide", label: "Breit" },
  { value: "full", label: "Ganze Breite" }
];

const els = {
  error: document.querySelector("#admin-error"),
  ok: document.querySelector("#admin-ok"),
  list: document.querySelector("#layout-list"),
  widgetList: document.querySelector("#widget-list"),
  form: document.querySelector("#layout-form"),
  newLayout: document.querySelector("#new-layout"),
  id: document.querySelector("#layout-id"),
  name: document.querySelector("#layout-name"),
  scope: document.querySelector("#layout-scope"),
  refresh: document.querySelector("#layout-refresh"),
  preview: document.querySelector("#preview-layout"),
  copyLink: document.querySelector("#copy-layout-link"),
  deleteLayout: document.querySelector("#delete-layout")
};

boot();

async function boot() {
  bindEvents();
  const data = await fetchJson(new URL("layouts.php", apiBase));
  state.layouts = data.layouts || [];
  state.widgets = data.widgets || [];
  state.scopeOptions = data.scopeOptions || [];
  state.activeId = state.layouts[0]?.id || "";
  render();
  bootAdminTools();
}

function bindEvents() {
  els.newLayout.addEventListener("click", () => {
    const id = nextLayoutId();
    state.layouts.push({
      id,
      name: "Neue Ansicht",
      scope: "all",
      refreshSeconds: 60,
      widgets: state.widgets.map((widget) => widget.id),
      widgetSettings: defaultWidgetSettings()
    });
    state.activeId = id;
    render();
  });

  els.form.addEventListener("submit", async (event) => {
    event.preventDefault();
    await saveActiveLayout();
  });

  els.copyLink.addEventListener("click", copyDashboardLink);
  els.deleteLayout.addEventListener("click", deleteActiveLayout);

  for (const input of [els.name, els.scope, els.refresh]) {
    input.addEventListener("input", () => {
      syncFormToLayout();
      renderLayoutList();
      renderPreviewLink();
    });
    input.addEventListener("change", () => {
      syncFormToLayout();
      renderLayoutList();
      renderPreviewLink();
    });
  }

  els.id.addEventListener("input", renderPreviewLink);
}

function render() {
  renderLayoutList();
  renderScopeOptions();
  renderForm();
}

function renderLayoutList() {
  els.list.replaceChildren();
  for (const layout of state.layouts) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "layout-button";
    button.classList.toggle("active", layout.id === state.activeId);
    button.innerHTML = `<strong></strong><span></span>`;
    button.querySelector("strong").textContent = layout.name;
    button.querySelector("span").textContent = layout.scope || "all";
    button.addEventListener("click", () => {
      state.activeId = layout.id;
      render();
    });
    els.list.append(button);
  }
}

function renderScopeOptions() {
  els.scope.replaceChildren(
    ...state.scopeOptions.map((scope) => option(scope.value, scope.label))
  );
}

function renderForm() {
  const layout = activeLayout();
  if (!layout) return;

  els.id.value = layout.id;
  els.name.value = layout.name;
  els.scope.value = layout.scope || "all";
  els.refresh.value = String(layout.refreshSeconds || 60);
  els.deleteLayout.disabled = state.layouts.length <= 1;
  renderPreviewLink();
  renderWidgets(layout);
}

function renderWidgets(layout) {
  els.widgetList.replaceChildren();
  const order = layout.widgets?.length ? layout.widgets : state.widgets.map((widget) => widget.id);
  const settings = { ...defaultWidgetSettings(), ...(layout.widgetSettings || {}) };
  const widgets = [
    ...order.map((id) => state.widgets.find((widget) => widget.id === id)).filter(Boolean),
    ...state.widgets.filter((widget) => !order.includes(widget.id))
  ];

  for (const widget of widgets) {
    const row = document.createElement("div");
    row.className = "widget-row";
    row.classList.toggle("is-hidden", !order.includes(widget.id));
    row.draggable = true;
    row.dataset.widget = widget.id;

    const handle = document.createElement("span");
    handle.className = "drag-handle";
    handle.textContent = "::";
    handle.title = "Ziehen zum Sortieren";

    const label = document.createElement("label");
    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.checked = order.includes(widget.id);
    checkbox.addEventListener("change", () => {
      row.classList.toggle("is-hidden", !checkbox.checked);
      syncWidgetsFromDom();
    });

    const text = document.createElement("span");
    const title = document.createElement("strong");
    title.textContent = widget.name;
    const hint = document.createElement("small");
    hint.textContent = checkbox.checked ? "sichtbar" : "ausgeblendet";
    checkbox.addEventListener("change", () => {
      hint.textContent = checkbox.checked ? "sichtbar" : "ausgeblendet";
    });
    text.append(title, hint);
    label.append(checkbox, text);

    const size = document.createElement("label");
    size.className = "size-field";
    const sizeLabel = document.createElement("span");
    sizeLabel.textContent = "Größe";
    const sizeSelect = document.createElement("select");
    sizeSelect.dataset.size = "true";
    sizeSelect.replaceChildren(...widthOptions.map((item) => option(item.value, item.label)));
    sizeSelect.value = settings[widget.id]?.width || widget.defaultWidth || "normal";
    sizeSelect.addEventListener("change", syncWidgetsFromDom);
    size.append(sizeLabel, sizeSelect);

    row.append(handle, label, size);
    addDragEvents(row);
    els.widgetList.append(row);
  }
}

function addDragEvents(row) {
  row.addEventListener("dragstart", () => {
    row.classList.add("dragging");
  });
  row.addEventListener("dragend", () => {
    row.classList.remove("dragging");
    syncWidgetsFromDom();
  });
  row.addEventListener("dragover", (event) => {
    event.preventDefault();
    const dragging = els.widgetList.querySelector(".dragging");
    if (!dragging || dragging === row) {
      return;
    }
    const after = row.getBoundingClientRect().top + row.offsetHeight / 2;
    if (event.clientY > after) {
      row.after(dragging);
    } else {
      row.before(dragging);
    }
  });
}

function syncWidgetsFromDom() {
  const layout = activeLayout();
  if (!layout) return;

  const rows = Array.from(els.widgetList.querySelectorAll(".widget-row"));
  layout.widgets = rows
    .filter((row) => row.querySelector("input[type='checkbox']")?.checked)
    .map((row) => row.dataset.widget);

  layout.widgetSettings = {};
  for (const row of rows) {
    const widget = widgetDefinition(row.dataset.widget);
    layout.widgetSettings[row.dataset.widget] = {
      width: row.querySelector("[data-size]")?.value || widget?.defaultWidth || "normal"
    };
  }
}

async function saveActiveLayout() {
  const layout = activeLayout();
  if (!layout) return;

  syncWidgetsFromDom();
  syncFormToLayout({ includeId: true });

  if (!layout.id || !layout.name) {
    showError("Bitte Name und ID ausfüllen.");
    return;
  }

  const data = await fetchJson(new URL("layouts.php", apiBase), {
    method: "POST",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ layout })
  });

  state.layouts = data.layouts || state.layouts;
  state.activeId = data.layout?.id || layout.id;
  showOk("Layout gespeichert.");
  render();
}

async function deleteActiveLayout() {
  const layout = activeLayout();
  if (!layout) return;

  const confirmed = window.confirm(`Ansicht "${layout.name}" wirklich löschen?`);
  if (!confirmed) {
    return;
  }

  const existingIds = state.layouts.map((item) => item.id);
  const data = await fetchJson(new URL("layouts.php", apiBase), {
    method: "DELETE",
    headers: {
      "Content-Type": "application/json"
    },
    body: JSON.stringify({ id: layout.id })
  });

  state.layouts = data.layouts || state.layouts.filter((item) => item.id !== layout.id);
  if (!state.layouts.length && existingIds.includes(layout.id)) {
    state.layouts = [];
  }
  state.activeId = state.layouts[0]?.id || "";
  showOk("Layout gelöscht.");
  render();
}

function syncFormToLayout({ includeId = false } = {}) {
  const layout = activeLayout();
  if (!layout) return;

  if (includeId) {
    layout.id = normalizeId(els.id.value);
    state.activeId = layout.id;
  }
  layout.name = els.name.value.trim();
  layout.scope = els.scope.value || "all";
  layout.refreshSeconds = Number(els.refresh.value || 60);
}

function activeLayout() {
  return state.layouts.find((layout) => layout.id === state.activeId);
}

function widgetDefinition(id) {
  return state.widgets.find((widget) => widget.id === id);
}

function defaultWidgetSettings() {
  const settings = {};
  for (const widget of state.widgets) {
    settings[widget.id] = {
      width: widget.defaultWidth || "normal"
    };
  }
  return settings;
}

function renderPreviewLink() {
  const id = normalizeId(els.id.value) || activeLayout()?.id || "";
  const url = new URL("index.php", window.location.href);
  if (id) {
    url.searchParams.set("view", id);
  }
  els.preview.href = url.toString();
}

async function copyDashboardLink() {
  renderPreviewLink();
  const url = els.preview.href;
  if (navigator.clipboard) {
    try {
      await navigator.clipboard.writeText(url);
      showOk("Dashboard-Link kopiert.");
      return;
    } catch (error) {
      showOk(url);
      return;
    }
  }

  showOk(url);
}

function nextLayoutId() {
  let number = state.layouts.length + 1;
  while (state.layouts.some((layout) => layout.id === `ansicht-${number}`)) {
    number++;
  }
  return `ansicht-${number}`;
}

async function fetchJson(url, options = {}) {
  hideMessages();
  const response = await fetch(url, { headers: { "Accept": "application/json", ...(options.headers || {}) }, ...options });
  const data = await response.json();
  if (!response.ok) {
    showError(data.message || `HTTP ${response.status}`);
    throw new Error(data.message || `HTTP ${response.status}`);
  }
  return data;
}

function showError(message) {
  els.error.textContent = message;
  els.error.classList.remove("hidden");
}

function showOk(message) {
  els.ok.textContent = message;
  els.ok.classList.remove("hidden");
}

function hideMessages() {
  els.error.classList.add("hidden");
  els.ok.classList.add("hidden");
}

function normalizeId(value) {
  return value.trim().toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
}

function option(value, label) {
  const item = document.createElement("option");
  item.value = value;
  item.textContent = label;
  return item;
}

// ═══════════════════════════════════════════════════════════════════════════
// Admin-Tools
// ═══════════════════════════════════════════════════════════════════════════

const SESSION_KEY = "ultimo_admin_pw";

const toolsEls = {
  wrap: document.querySelector("#admin-tools-wrap"),
  toggle: document.querySelector("#tools-toggle"),
  body: document.querySelector("#tools-body"),
  gate: document.querySelector("#tools-gate"),
  passwordForm: document.querySelector("#tools-password-form"),
  passwordInput: document.querySelector("#tools-password"),
  gateError: document.querySelector("#tools-gate-error"),
  dashboard: document.querySelector("#tools-dashboard"),
  toolsError: document.querySelector("#tools-error"),
  toolsOk: document.querySelector("#tools-ok"),
  statusBody: document.querySelector("#tools-status-body"),
  clientsBody: document.querySelector("#tools-clients-body"),
  btnStatus: document.querySelector("#btn-status"),
  btnFlushLookups: document.querySelector("#btn-flush-lookups"),
  btnForceFetch: document.querySelector("#btn-force-fetch"),
  btnFlushDashboard: document.querySelector("#btn-flush-dashboard"),
  btnFlushAll: document.querySelector("#btn-flush-all"),
  btnLogout: document.querySelector("#btn-logout")
};

async function bootAdminTools() {
  // Sicherheitsnetz: Falls ein Element fehlt (z.B. altes admin.php), still abbrechen
  const requiredKeys = ["wrap", "toggle", "body", "gate", "gateError", "passwordForm", "dashboard"];
  for (const key of requiredKeys) {
    if (!toolsEls[key]) {
      console.warn("Admin-Tools: #" + key + " nicht gefunden – Abschnitt übersprungen.");
      return;
    }
  }

  // Dashboard immer versteckt starten, Gate immer sichtbar
  toolsEls.dashboard.classList.add("hidden");
  toolsEls.gate.classList.remove("hidden");

  // Prüfen ob ADMIN_PASSWORD in .env gesetzt ist
  try {
    const info = await fetch(new URL("admin-actions.php", apiBase), {
      headers: { "Accept": "application/json" }
    });
    const data = await info.json();
    if (!data.hasAdminPassword) {
      // Passwort nicht konfiguriert → Meldung im Gate zeigen, Formular ausblenden
      toolsEls.passwordForm.classList.add("hidden");
      toolsEls.gateError.textContent = "ADMIN_PASSWORD ist nicht in .env gesetzt. Bitte dort eintragen und Apache neu laden.";
      toolsEls.gateError.classList.remove("hidden");
    }
  } catch {
    // Netzwerkfehler: Formular normal anzeigen, Nutzer merkt es beim Login-Versuch
  }

  // Toggle-Button: Sektion ein-/ausklappen
  toolsEls.toggle.addEventListener("click", () => {
    const expanded = toolsEls.toggle.getAttribute("aria-expanded") === "true";
    toolsEls.toggle.setAttribute("aria-expanded", String(!expanded));
    toolsEls.toggle.querySelector(".tools-toggle-chevron").textContent = expanded ? "▸" : "▾";
    toolsEls.body.classList.toggle("hidden", expanded);

    if (!expanded) {
      // Beim Öffnen: gespeichertes Passwort aus Session prüfen
      const saved = sessionStorage.getItem(SESSION_KEY);
      if (saved && toolsEls.dashboard.classList.contains("hidden")) {
        unlockTools(saved);
      }
    }
  });

  // Passwort-Formular
  toolsEls.passwordForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const pw = toolsEls.passwordInput.value;
    if (!pw) return;
    await unlockTools(pw, true);
  });

  // Buttons verdrahten
  toolsEls.btnStatus.addEventListener("click", () => toolsAction("cache-status"));
  toolsEls.btnFlushLookups.addEventListener("click", () => toolsAction("flush-lookups"));
  toolsEls.btnForceFetch.addEventListener("click", () => toolsAction("force-fetch-lookups"));
  toolsEls.btnFlushDashboard.addEventListener("click", () => toolsAction("flush-dashboard"));
  toolsEls.btnFlushAll.addEventListener("click", async () => {
    if (!confirm("Wirklich den gesamten Cache leeren? Stammdaten und alle Dashboard-Snapshots werden gelöscht.")) return;
    await toolsAction("flush-all");
  });
  toolsEls.btnLogout.addEventListener("click", () => {
    sessionStorage.removeItem(SESSION_KEY);
    toolsEls.dashboard.classList.add("hidden");
    toolsEls.gate.classList.remove("hidden");
    toolsEls.passwordInput.value = "";
    toolsHideMessages();
  });
}

async function unlockTools(password, saveToSession = false) {
  toolsHideMessages();
  toolsEls.gateError.classList.add("hidden");

  // Passwort mit Cache-Status-Abruf validieren
  const result = await toolsRequest("cache-status", password);
  if (!result.ok) {
    toolsEls.gateError.textContent = result.message || "Falsches Passwort.";
    toolsEls.gateError.classList.remove("hidden");
    return;
  }

  if (saveToSession) {
    sessionStorage.setItem(SESSION_KEY, password);
  }

  toolsEls.gate.classList.add("hidden");
  toolsEls.dashboard.classList.remove("hidden");
  renderCacheStatus(result.status);
}

async function toolsAction(action) {
  const password = sessionStorage.getItem(SESSION_KEY) || toolsEls.passwordInput.value;
  if (!password) return;

  toolsHideMessages();
  setToolsLoading(true);

  const result = await toolsRequest(action, password);
  setToolsLoading(false);

  if (!result.ok) {
    toolsShowError(result.message || "Fehler bei der Aktion.");
    return;
  }

  if (result.message) {
    toolsShowOk(result.message);
  }

  // Ergebnisse anzeigen
  if (result.counts) {
    // force-fetch-lookups liefert Zähler zurück
    const lines = Object.entries(result.counts)
      .map(([k, v]) => `${k}: ${v} Einträge`)
      .join(" · ");
    toolsShowOk((result.message ? result.message + "\n" : "") + lines);
  }

  if (result.status) {
    renderCacheStatus(result.status);
  }
}

async function toolsRequest(action, password) {
  try {
    const response = await fetch(new URL("admin-actions.php", apiBase), {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({ action, password })
    });
    const data = await response.json();
    return data;
  } catch (error) {
    return { ok: false, message: error.message || "Netzwerkfehler." };
  }
}

function renderCacheStatus(status) {
  const body = toolsEls.statusBody;
  body.replaceChildren();

  if (!status) {
    body.innerHTML = `<p class="tools-placeholder">Keine Daten.</p>`;
    return;
  }

  const clients = status.clients || {};

  // Stammdaten-Block
  const lookups = status.lookups || {};
  const lookupsCard = document.createElement("div");
  lookupsCard.className = "status-block";

  const lookupsTitle = document.createElement("strong");
  lookupsTitle.textContent = "Stammdaten-Cache";
  lookupsCard.append(lookupsTitle);

  if (!lookups.exists) {
    lookupsCard.append(statusPill("Kein Cache", "danger"));
  } else {
    lookupsCard.append(
      statusPill(lookups.expired ? "Abgelaufen" : "Aktuell", lookups.expired ? "warning" : "ok"),
      statusRow("Erstellt", formatRelative(lookups.ageSeconds)),
      statusRow("Läuft ab", formatDate(lookups.expiresAt)),
      statusRow("Größe", `${lookups.sizeKb} KB`)
    );

    if (lookups.counts && Object.keys(lookups.counts).length) {
      const countsWrap = document.createElement("div");
      countsWrap.className = "status-counts";
      for (const [resource, count] of Object.entries(lookups.counts)) {
        if (count > 0) {
          const chip = document.createElement("span");
          chip.className = "status-chip";
          chip.textContent = `${resource}: ${count}`;
          countsWrap.append(chip);
        }
      }
      lookupsCard.append(countsWrap);
    }
  }

  // Dashboard-Cache-Block
  const dash = status.dashboard || {};
  const dashCard = document.createElement("div");
  dashCard.className = "status-block";

  const dashTitle = document.createElement("strong");
  dashTitle.textContent = `Dashboard-Cache (${dash.fileCount || 0} Ansicht${dash.fileCount !== 1 ? "en" : ""})`;
  dashCard.append(dashTitle);

  if (!dash.fileCount) {
    dashCard.append(statusPill("Leer", "muted"));
  } else {
    dashCard.append(
      statusRow("TTL", `${dash.ttlSeconds}s`)
    );
    for (const file of (dash.files || [])) {
      const fileWrap = document.createElement("div");
      fileWrap.className = "status-file";

      const title = document.createElement("strong");
      title.className = "status-file-title";
      title.textContent = file.label || file.fileName || "Unbekannte Ansicht";

      const meta = document.createElement("span");
      meta.className = "status-file-meta";
      meta.textContent = file.scope ? `Scope: ${file.scope}` : file.fileName || "";

      fileWrap.append(
        title,
        meta,
        statusPill(file.expired ? "Abgelaufen" : "Aktuell", file.expired ? "warning" : "ok"),
        statusPill(file.writable === false ? "Nicht beschreibbar" : "Beschreibbar", file.writable === false ? "danger" : "ok"),
        statusRow("Clients", String(clientCountForCache(file, clients.groups || []))),
        statusRow("Erstellt", formatRelative(file.ageSeconds)),
        statusRow("Größe", `${file.sizeKb} KB`)
      );
      dashCard.append(fileWrap);
    }
  }

  // TTL-Einstellungen
  const ttl = status.cacheTtl || {};
  const ttlCard = document.createElement("div");
  ttlCard.className = "status-block";
  const ttlTitle = document.createElement("strong");
  ttlTitle.textContent = "Konfigurierte TTL";
  ttlCard.append(
    ttlTitle,
    statusRow("Stammdaten", formatSeconds(ttl.lookupSeconds)),
    statusRow("Dashboard", formatSeconds(ttl.dashboardSeconds))
  );

  body.append(lookupsCard, dashCard, ttlCard);
  renderClientStatus(status.clients || {});
}

function renderClientStatus(clients) {
  const body = toolsEls.clientsBody;
  if (!body) return;

  body.replaceChildren();

  const summary = document.createElement("div");
  summary.className = "client-summary";
  summary.append(
    statusPill(`${clients.total || 0} aktiv`, clients.total ? "ok" : "muted"),
    statusRow("Zeitfenster", `${clients.activeSeconds || 90}s`)
  );
  body.append(summary);

  if (!clients.total) {
    const empty = document.createElement("p");
    empty.className = "tools-placeholder";
    empty.textContent = "Keine aktiven Dashboard-Clients.";
    body.append(empty);
    return;
  }

  for (const group of (clients.groups || [])) {
    const item = document.createElement("div");
    item.className = "client-row";

    const text = document.createElement("div");
    const name = document.createElement("strong");
    name.textContent = group.viewName || group.scope || "Unbekannte Ansicht";
    const meta = document.createElement("span");
    meta.textContent = `${group.scope || "all"} · zuletzt ${formatRelative(group.lastSeenAgeSeconds)}`;
    text.append(name, meta);

    const count = document.createElement("span");
    count.className = "client-count";
    count.textContent = String(group.count || 0);

    item.append(text, count);
    body.append(item);
  }
}

function clientCountForCache(file, groups) {
  const viewIds = (file.views || []).map((view) => view.id).filter(Boolean);
  const hasScope = Boolean(file.scope);
  const scope = file.scope || "all";
  return groups.reduce((total, group) => {
    const sameView = viewIds.includes(group.viewId);
    const sameScope = hasScope && (group.scope || "all") === scope;
    return sameView || sameScope ? total + Number(group.count || 0) : total;
  }, 0);
}

function statusPill(text, type = "ok") {
  const span = document.createElement("span");
  span.className = `status-pill status-pill-${type}`;
  span.textContent = text;
  return span;
}

function statusRow(label, value) {
  const row = document.createElement("div");
  row.className = "status-row";
  row.innerHTML = `<span class="status-row-label"></span><span class="status-row-value"></span>`;
  row.querySelector(".status-row-label").textContent = label;
  row.querySelector(".status-row-value").textContent = value;
  return row;
}

function formatRelative(seconds) {
  if (seconds == null) return "–";
  if (seconds < 60) return `vor ${seconds}s`;
  if (seconds < 3600) return `vor ${Math.round(seconds / 60)}min`;
  return `vor ${Math.round(seconds / 3600)}h`;
}

function formatDate(iso) {
  if (!iso) return "–";
  return new Intl.DateTimeFormat("de-DE", {
    day: "2-digit", month: "2-digit",
    hour: "2-digit", minute: "2-digit"
  }).format(new Date(iso));
}

function formatSeconds(s) {
  if (s == null) return "–";
  if (s < 60) return `${s}s`;
  if (s < 3600) return `${Math.round(s / 60)}min`;
  return `${Math.round(s / 3600)}h`;
}

function toolsShowError(message) {
  toolsEls.toolsError.textContent = message;
  toolsEls.toolsError.classList.remove("hidden");
}

function toolsShowOk(message) {
  toolsEls.toolsOk.textContent = message;
  toolsEls.toolsOk.classList.remove("hidden");
}

function toolsHideMessages() {
  toolsEls.toolsError.classList.add("hidden");
  toolsEls.toolsOk.classList.add("hidden");
}

function setToolsLoading(loading) {
  const buttons = [
    toolsEls.btnStatus,
    toolsEls.btnFlushLookups,
    toolsEls.btnForceFetch,
    toolsEls.btnFlushDashboard,
    toolsEls.btnFlushAll
  ];
  for (const btn of buttons) {
    btn.disabled = loading;
  }
  if (loading) {
    toolsEls.statusBody.innerHTML = `<p class="tools-placeholder">Lade…</p>`;
  }
}
