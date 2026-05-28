const state = {
  scope: "all",
  layouts: [],
  widgets: [],
  currentLayoutId: "",
  refreshSeconds: 60,
  refreshTimer: null,
  countdownTimer: null,
  nextRefreshAt: null,
  latestData: null,
  clientId: getClientId(),
  clientHeartbeatTimer: null
};

const apiBase = new URL("./api/", window.location.href);

const els = {
  viewTitle: document.querySelector("#view-title"),
  layoutSelect: document.querySelector("#layout-select"),
  departmentSelect: document.querySelector("#department-select"),
  refreshSelect: document.querySelector("#refresh-select"),
  sourceLabel: document.querySelector("#source-label"),
  lastUpdated: document.querySelector("#last-updated"),
  nextRefresh: document.querySelector("#next-refresh"),
  errorPanel: document.querySelector("#error-panel"),
  kpis: {
    overdue: document.querySelector("#kpi-overdue"),
    dueToday: document.querySelector("#kpi-due-today"),
    open: document.querySelector("#kpi-open"),
    newToday: document.querySelector("#kpi-new-today"),
    closedToday: document.querySelector("#kpi-closed-today"),
    age: document.querySelector("#kpi-age")
  },
  criticalCount: document.querySelector("#critical-count"),
  criticalTable: document.querySelector("#critical-table"),
  recentTable: document.querySelector("#recent-table"),
  bars: {
    departments: document.querySelector("#department-bars"),
    priorities: document.querySelector("#priority-bars"),
    statuses: document.querySelector("#status-bars"),
    sites: document.querySelector("#site-bars"),
    equipment: document.querySelector("#equipment-bars"),
    costCenters: document.querySelector("#cost-center-bars"),
    skills: document.querySelector("#skill-bars"),
    workOrderTypes: document.querySelector("#work-order-type-bars"),
    failTypes: document.querySelector("#fail-type-bars"),
    employees: document.querySelector("#employee-bars"),
    vendors: document.querySelector("#vendor-bars")
  },
  widgets: document.querySelectorAll("[data-widget]")
};

boot();

async function boot() {
  bindEvents();
  const config = await fetchJson(new URL("config.php", apiBase));
  const layoutConfig = await fetchJson(new URL("layouts.php", apiBase));
  state.layouts = layoutConfig.layouts || [];
  state.widgets = layoutConfig.widgets || [];
  state.refreshSeconds = config.refreshSeconds || state.refreshSeconds;
  els.sourceLabel.textContent = config.ultimoBaseUrl || "Ultimo API";

  const requestedView = new URLSearchParams(window.location.search).get("view");
  const firstLayout = requestedView
    ? state.layouts.find((layout) => layout.id === requestedView)
    : state.layouts[0];
  if (firstLayout) {
    activateLayout(firstLayout.id, false);
  } else {
    els.refreshSelect.value = String(state.refreshSeconds);
  }

  if (!config.hasApiKey) {
    populateLayouts();
    applyWidgetLayout();
    showError("ULTIMO_API_KEY fehlt. Bitte lege im Projekt eine .env-Datei an und lade die Seite neu.");
    return;
  }

  startClientHeartbeat();
  await refresh();
  scheduleRefresh();
}

function bindEvents() {
  els.layoutSelect.addEventListener("change", () => {
    activateLayout(els.layoutSelect.value, true);
    sendClientHeartbeat();
    refresh();
  });

  els.departmentSelect.addEventListener("change", () => {
    state.currentLayoutId = "";
    state.scope = els.departmentSelect.value || "all";
    render(state.latestData);
    sendClientHeartbeat();
    refresh();
  });

  els.refreshSelect.addEventListener("change", () => {
    state.refreshSeconds = Number(els.refreshSelect.value);
    scheduleRefresh();
    sendClientHeartbeat();
  });
}

async function refresh() {
  try {
    hideError();
    const params = new URLSearchParams();
    if (state.scope && state.scope !== "all") {
      params.set("scope", state.scope);
    }
    const query = params.toString();
    const url = new URL("dashboard.php", apiBase);
    if (query) {
      url.search = query;
    }
    const data = await fetchJson(url);
    state.latestData = data;
    render(data);
    scheduleRefresh();
  } catch (error) {
    showError(error.message);
    scheduleRefresh();
  }
}

function scheduleRefresh() {
  clearTimeout(state.refreshTimer);
  clearInterval(state.countdownTimer);
  state.nextRefreshAt = Date.now() + state.refreshSeconds * 1000;
  state.refreshTimer = setTimeout(refresh, state.refreshSeconds * 1000);
  updateCountdown();
  state.countdownTimer = setInterval(updateCountdown, 1000);
}

function updateCountdown() {
  if (!state.nextRefreshAt) return;
  const seconds = Math.max(0, Math.ceil((state.nextRefreshAt - Date.now()) / 1000));
  els.nextRefresh.textContent = `${seconds}s`;
}

function render(data) {
  els.viewTitle.textContent = currentTitle(data);

  if (!data) return;

  populateLayouts();
  populateScopes(data.departmentGroups || [], data.lookups.Department || [], data.lookups.SkillCategory || []);
  applyWidgetLayout();
  els.lastUpdated.textContent = formatDateTime(data.generatedAt);
  els.sourceLabel.textContent = data.source || "Ultimo API";

  const kpis = data.summary.kpis;
  els.kpis.overdue.textContent = formatNumber(kpis.overdue);
  els.kpis.dueToday.textContent = formatNumber(kpis.dueToday);
  els.kpis.open.textContent = formatNumber(kpis.open);
  els.kpis.newToday.textContent = formatNumber(kpis.newToday);
  els.kpis.closedToday.textContent = formatNumber(kpis.closedToday);
  els.kpis.age.textContent = kpis.averageOpenAgeHours === null ? "-" : `${Math.round(kpis.averageOpenAgeHours)}h`;

  els.criticalCount.textContent = data.summary.criticalJobs.length;
  renderTable(els.criticalTable, data.summary.criticalJobs, criticalColumns());
  renderTable(els.recentTable, data.summary.recentJobs, recentColumns());
  renderBars(els.bars.departments, data.summary.byDepartment);
  renderBars(els.bars.priorities, data.summary.byPriority);
  renderBars(els.bars.statuses, data.summary.byProgressStatus);
  renderBars(els.bars.sites, data.summary.bySite);
  renderBars(els.bars.equipment, data.summary.byEquipment);
  renderBars(els.bars.costCenters, data.summary.byCostCenter);
  renderBars(els.bars.skills, data.summary.bySkillCategory);
  renderBars(els.bars.workOrderTypes, data.summary.byWorkOrderType);
  renderBars(els.bars.failTypes, data.summary.byFailType);
  renderBars(els.bars.employees, data.summary.byEmployee);
  renderBars(els.bars.vendors, data.summary.byVendor);
}

function activateLayout(layoutId, updateUrl) {
  const layout = state.layouts.find((item) => item.id === layoutId);
  if (!layout) return;

  state.currentLayoutId = layout.id;
  state.scope = layout.scope || "all";
  state.refreshSeconds = Number(layout.refreshSeconds || state.refreshSeconds);
  els.refreshSelect.value = String(state.refreshSeconds);

  if (updateUrl) {
    const url = new URL(window.location.href);
    url.searchParams.set("view", layout.id);
    window.history.replaceState({}, "", url);
  }
}

function populateLayouts() {
  const current = state.currentLayoutId;
  const options = state.layouts
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name, "de"))
    .map((layout) => option(layout.id, layout.name));

  if (!current) {
    options.unshift(option("", "Manuelle Auswahl"));
  }

  els.layoutSelect.replaceChildren(...options);
  els.layoutSelect.value = current;
}

function populateScopes(groups, departments, skillCategories) {
  const current = state.scope || "all";
  const options = [option("all", "Gesamtübersicht")];

  const activeGroups = groups
    .filter((group) => Array.isArray(group.departmentIds) && group.departmentIds.length > 0)
    .sort((a, b) => a.name.localeCompare(b.name, "de"))
    .map((group) => option(`group:${group.id}`, `${group.name} gesamt`));

  if (activeGroups.length) {
    options.push(option("", "──────────"));
    options.at(-1).disabled = true;
    options.push(...activeGroups);
  }

  options.push(option("", "──────────"));
  options.at(-1).disabled = true;
  options.push(
    ...departments
      .slice()
      .sort((a, b) => labelOf(a).localeCompare(labelOf(b), "de"))
      .map((department) => option(`department:${department.Id}`, labelOf(department)))
  );

  const skillOptions = skillCategories
    .slice()
    .sort((a, b) => labelOf(a).localeCompare(labelOf(b), "de"))
    .map((skill) => option(`skill:${skill.Id}`, `Fähigkeit: ${labelOf(skill)}`));

  if (skillOptions.length) {
    options.push(option("", "──────────"));
    options.at(-1).disabled = true;
    options.push(...skillOptions);
  }

  els.departmentSelect.replaceChildren(...options);
  els.departmentSelect.value = current;
}

function applyWidgetLayout() {
  const layout = state.layouts.find((item) => item.id === state.currentLayoutId);
  const visibleWidgets = layout?.widgets?.length
    ? layout.widgets
    : Array.from(els.widgets).map((widget) => widget.dataset.widget);
  const settings = layout?.widgetSettings || {};

  for (const widget of els.widgets) {
    const id = widget.dataset.widget;
    const position = visibleWidgets.indexOf(id);
    const width = settings[id]?.width || widgetDefinition(id)?.defaultWidth || "normal";
    widget.classList.toggle("hidden", position === -1);
    widget.classList.remove("widget-normal", "widget-wide", "widget-full");
    widget.classList.add(`widget-${width}`);
    widget.style.order = position === -1 ? "" : String(position + 1);
  }
}

function widgetDefinition(id) {
  return state.widgets.find((widget) => widget.id === id);
}

function criticalColumns() {
  return [
    (job) => job.Id,
    (job) => mainText(job),
    (job) => job.labels.Department || "-",
    (job) => job.labels.Priority || "-",
    (job) => statusBadge(job),
    (job) => formatDate(job.ServiceContractTargetFinishedDate || job.TargetDate)
  ];
}

function recentColumns() {
  return [
    (job) => job.Id,
    (job) => mainText(job),
    (job) => job.labels.Department || "-",
    (job) => job.labels.Location || "-",
    (job) => job.labels.ProgressStatus || job.Status || "-",
    (job) => formatDateTime(job.StatusCreatedReportDate)
  ];
}

function renderTable(tbody, rows, columns) {
  tbody.replaceChildren();
  if (!rows.length) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = columns.length;
    cell.className = "empty";
    cell.textContent = "Keine passenden Meldungen";
    row.append(cell);
    tbody.append(row);
    return;
  }

  for (const item of rows) {
    const tr = document.createElement("tr");
    for (const column of columns) {
      const td = document.createElement("td");
      const value = column(item);
      if (value instanceof Node) {
        td.append(value);
      } else {
        td.textContent = value || "-";
      }
      tr.append(td);
    }
    tbody.append(tr);
  }
}

function renderBars(container, rows) {
  container.replaceChildren();
  if (!rows.length) {
    const empty = document.createElement("p");
    empty.className = "empty";
    empty.textContent = "Keine offenen Meldungen";
    container.append(empty);
    return;
  }

  const max = Math.max(...rows.map((row) => row.total), 1);
  for (const row of rows.slice(0, 12)) {
    const wrap = document.createElement("div");
    wrap.className = "bar-row";

    const label = document.createElement("span");
    label.className = "bar-label";
    label.title = row.key;
    label.textContent = row.key;

    const value = document.createElement("span");
    value.className = "bar-value";
    value.textContent = row.total;

    const track = document.createElement("div");
    track.className = "bar-track";
    const fill = document.createElement("div");
    fill.className = "bar-fill";
    fill.style.width = `${Math.max(5, (row.total / max) * 100)}%`;
    track.append(fill);

    wrap.append(label, value, track);
    container.append(wrap);
  }
}

async function fetchJson(url) {
  const response = await fetch(url, { headers: { "Accept": "application/json" } });
  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.message || `HTTP ${response.status}`);
  }
  return data;
}

function showError(message) {
  els.errorPanel.textContent = message;
  els.errorPanel.classList.remove("hidden");
}

function hideError() {
  els.errorPanel.classList.add("hidden");
  els.errorPanel.textContent = "";
}

function statusBadge(job) {
  const span = document.createElement("span");
  span.className = "badge";
  if (job.metrics.isOverdue || job.metrics.responseOverdue) {
    span.classList.add("danger");
    span.textContent = "Überfällig";
  } else if (job.metrics.dueWithin24h) {
    span.classList.add("warning");
    span.textContent = "Fällig bald";
  } else {
    span.textContent = job.labels.ProgressStatus || job.Status || "Offen";
  }
  return span;
}

function mainText(job) {
  return job.Description || job.ReportText || job.Text || job.FeedbackText || "-";
}

function scopeName(data, value) {
  if (!value || value === "all") {
    return "Gesamtübersicht";
  }

  if (value.startsWith("group:")) {
    const groupId = value.slice("group:".length);
    const group = data?.departmentGroups?.find((item) => item.id === groupId);
    return group ? `${group.name} gesamt` : groupId;
  }

  if (value.startsWith("skill:")) {
    const skillId = value.slice("skill:".length);
    const skill = data?.lookups?.SkillCategory?.find((item) => item.Id === skillId);
    return skill ? labelOf(skill) : skillId;
  }

  const departmentId = value.slice("department:".length);
  const department = data?.lookups?.Department?.find((item) => item.Id === departmentId);
  return department ? labelOf(department) : departmentId;
}

function currentTitle(data) {
  const layout = state.layouts.find((item) => item.id === state.currentLayoutId);
  if (layout) {
    return layout.name;
  }

  return scopeName(data, state.scope);
}

function startClientHeartbeat() {
  clearInterval(state.clientHeartbeatTimer);
  sendClientHeartbeat();
  state.clientHeartbeatTimer = setInterval(sendClientHeartbeat, 30000);
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      sendClientHeartbeat();
    }
  });
}

async function sendClientHeartbeat() {
  try {
    await fetch(new URL("clients.php", apiBase), {
      method: "POST",
      headers: { "Content-Type": "application/json", "Accept": "application/json" },
      body: JSON.stringify({
        clientId: state.clientId,
        viewId: state.currentLayoutId,
        viewName: currentTitle(state.latestData),
        scope: state.scope || "all",
        path: `${window.location.pathname}${window.location.search}`
      })
    });
  } catch {
    // Der Heartbeat ist nur lokale Admin-Metadaten; das Dashboard laeuft ohne ihn weiter.
  }
}

function getClientId() {
  const key = "ultimo-dashboard-client-id";
  try {
    const existing = window.localStorage.getItem(key);
    if (existing) {
      return existing;
    }
    const id = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    window.localStorage.setItem(key, id);
    return id;
  } catch {
    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  }
}

function labelOf(item) {
  return item.Description || item.Id;
}

function option(value, label) {
  const item = document.createElement("option");
  item.value = value;
  item.textContent = label;
  return item;
}

function formatNumber(value) {
  return new Intl.NumberFormat("de-DE").format(value || 0);
}

function formatDate(value) {
  if (!value) return "-";
  return new Intl.DateTimeFormat("de-DE", { day: "2-digit", month: "2-digit" }).format(new Date(value));
}

function formatDateTime(value) {
  if (!value) return "-";
  return new Intl.DateTimeFormat("de-DE", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit"
  }).format(new Date(value));
}
