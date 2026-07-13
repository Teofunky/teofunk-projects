const state = { report: null, stats: null };

const $ = (id) => document.getElementById(id);
const esc = (value = "") => String(value)
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;")
  .replaceAll("'", "&#039;");

function selectedCompetitors() {
  return [...document.querySelectorAll('input[name="competitor"]:checked')].map(x => x.value);
}

function currentParams() {
  return {
    date_from: $("dateFrom").value,
    date_to: $("dateTo").value,
    competitors: selectedCompetitors(),
    include_news: $("includeNews").checked,
  };
}

function setStatus(text, kind = "") {
  const node = $("status");
  node.textContent = text;
  node.className = `status ${kind}`;
}

function money(value) {
  return value ? new Intl.NumberFormat("ru-RU").format(value) + " ₽" : "—";
}

function dateText(value) {
  if (!value) return "—";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value.slice(0, 16);
  return new Intl.DateTimeFormat("ru-RU", { day: "2-digit", month: "short", hour: value.includes("T") ? "2-digit" : undefined, minute: value.includes("T") ? "2-digit" : undefined }).format(d);
}

function categoriesHtml(categories = []) {
  return categories.slice(0, 4).map(c => `<span class="pill">${esc(c)}</span>`).join("");
}

function sourceLink(item) {
  const url = item.item_url || item.source_url || "#";
  return `<a href="${esc(url)}" target="_blank" rel="noopener">${esc(item.title)}</a>`;
}

function renderMetrics(report) {
  const cards = [
    ["События конкурентов", report.totals.events],
    ["Новости и PR", report.totals.news],
    ["Источники", report.totals.sources],
    ["События Клуба Козлова", report.totals.benchmark_events],
  ];
  $("metricRow").innerHTML = cards.map(([label, value]) => `
    <div class="metric-card"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>
  `).join("");
}

function renderBars(counts = {}) {
  const rows = Object.entries(counts).slice(0, 6);
  if (!rows.length) return '<div class="compact-list"><span>Нет данных</span></div>';
  const max = Math.max(...rows.map(([, v]) => v), 1);
  return rows.map(([name, value]) => `
    <div class="bar-row">
      <span>${esc(name)}</span>
      <div class="bar"><i style="width:${Math.max(5, Math.round(value / max * 100))}%"></i></div>
      <strong>${value}</strong>
    </div>
  `).join("");
}

function renderSummary(report) {
  const signals = report.signals.length
    ? `<div class="signal-box"><h3>Главные сигналы периода</h3><ul>${report.signals.map(x => `<li>${esc(x)}</li>`).join("")}</ul></div>`
    : `<div class="signal-box"><h3>Главные сигналы периода</h3><p>Пока недостаточно данных для выводов.</p></div>`;

  const cards = report.competitors.map(comp => {
    const repeated = comp.repeated_programs.length
      ? `<div class="mini-title">Повторяющиеся программы</div><ul class="compact-list">${comp.repeated_programs.slice(0, 5).map(x => `<li>${esc(x.title)} — <strong>${x.count}</strong></li>`).join("")}</ul>`
      : "";
    const overlap = comp.overlap_with_kozlov.length
      ? `<div class="mini-title">Пересечения с Клубом Козлова</div><ul class="compact-list">${comp.overlap_with_kozlov.slice(0, 5).map(x => `<li>${esc(x.competitor_title)} <span class="source-tag">${x.score}%</span></li>`).join("")}</ul>`
      : "";
    const notable = comp.notable.length
      ? `<div class="mini-title">Заметные активности</div><ul class="compact-list">${comp.notable.slice(0, 5).map(item => `<li>${sourceLink(item)}<br><span class="source-tag">${esc((item.starts_at || item.published_at || "").slice(0, 10))}</span> ${categoriesHtml(item.categories)}</li>`).join("")}</ul>`
      : "";
    return `
      <article class="competitor-card">
        <h3>${esc(comp.name)}</h3>
        <div class="statline">
          <span class="pill hot">${comp.event_count} событий</span>
          <span class="pill">${comp.news_count} PR</span>
          <span class="pill">${comp.imports} привозов</span>
          <span class="pill">${comp.festivals} фестивалей</span>
          <span class="pill">${comp.special_formats} спецформатов</span>
        </div>
        <div class="mini-title">Цены</div>
        <div><strong>${money(comp.price_min)}</strong>${comp.price_average ? ` · средняя минимальная ${money(comp.price_average)}` : ""}</div>
        <div class="mini-title">Жанровая активность</div>
        ${renderBars(comp.genre_counts)}
        ${repeated}${overlap}${notable}
      </article>
    `;
  }).join("");
  $("tab-summary").innerHTML = signals + `<div class="competitor-list">${cards}</div>`;
}

function renderEvents(report) {
  const rows = report.events.map(item => `
    <tr>
      <td>${esc(dateText(item.starts_at))}</td>
      <td><strong>${esc(item.competitor)}</strong></td>
      <td>${sourceLink(item)}<div class="statline">${categoriesHtml(item.categories)}</div></td>
      <td>${money(item.price_min)}</td>
      <td>${item.is_import ? '<span class="pill hot">привоз</span>' : "—"}</td>
      <td>${(item.sources || []).map(s => `<span class="source-tag">${esc(s.type)}</span>`).join(" ")}</td>
    </tr>
  `).join("");
  $("tab-events").innerHTML = `
    <table class="data-table">
      <thead><tr><th>Дата</th><th>Площадка</th><th>Артист / программа</th><th>Цена от</th><th>Привоз</th><th>Проверка</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="6">Событий за выбранный период нет.</td></tr>'}</tbody>
    </table>`;
}

function renderNews(report) {
  const rows = report.news.map(item => `
    <tr>
      <td>${esc(dateText(item.published_at))}</td>
      <td><strong>${esc(item.competitor)}</strong></td>
      <td>${sourceLink(item)}<div class="statline">${categoriesHtml(item.categories)}</div></td>
      <td>${esc(item.source_name)}</td>
    </tr>
  `).join("");
  $("tab-news").innerHTML = `
    <table class="data-table">
      <thead><tr><th>Дата</th><th>Площадка</th><th>Инфоповод</th><th>Источник</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="4">Новостных упоминаний за выбранный период нет.</td></tr>'}</tbody>
    </table>`;
}

function renderSources(stats) {
  const sourceRows = (stats?.sources || []).map(item => `
    <tr>
      <td>${esc(item.source_name)}</td>
      <td class="${item.status === "ok" ? "status-ok" : "status-error"}">${item.status === "ok" ? "ОК" : "Ошибка"}</td>
      <td>${item.count}</td>
      <td>${item.duration_seconds} сек.</td>
      <td>${esc(item.error || "")}</td>
    </tr>
  `).join("");
  $("tab-sources").innerHTML = stats
    ? `<p>Получено записей: <strong>${stats.collected}</strong>. Новых или обновлённых: <strong>${stats.inserted_or_updated}</strong>.</p>
       <table class="data-table"><thead><tr><th>Источник</th><th>Статус</th><th>Записей</th><th>Время</th><th>Комментарий</th></tr></thead><tbody>${sourceRows}</tbody></table>`
    : `<p>Диагностика источников появится после нового запуска.</p>`;
}

function renderReport(report, stats = null) {
  state.report = report;
  state.stats = stats;
  $("emptyState").classList.add("hidden");
  $("dashboard").classList.remove("hidden");
  renderMetrics(report);
  renderSummary(report);
  renderEvents(report);
  renderNews(report);
  renderSources(stats);
}

async function runCollection() {
  const params = currentParams();
  if (!params.date_from || !params.date_to) return setStatus("Укажите обе даты.", "error");
  if (!params.competitors.length) return setStatus("Выберите хотя бы одного конкурента.", "error");
  const button = $("runButton");
  button.disabled = true;
  button.textContent = "Собираю…";
  setStatus("Проверяю официальные сайты, агрегаторы и новости. Некоторые источники могут отвечать медленно.");
  try {
    const response = await fetch("/api/run", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(params),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.detail || "Ошибка сбора");
    renderReport(data.report, data.stats);
    const errors = data.stats.sources.filter(x => x.status !== "ok").length;
    setStatus(`Обзор готов. Получено ${data.stats.collected} записей${errors ? `; источников с ошибками: ${errors}` : ""}.`, errors ? "" : "success");
  } catch (error) {
    setStatus(error.message, "error");
  } finally {
    button.disabled = false;
    button.textContent = "Собрать обзор";
  }
}

async function showStored() {
  const params = currentParams();
  const q = new URLSearchParams({
    date_from: params.date_from,
    date_to: params.date_to,
    competitors: params.competitors.join("|"),
  });
  setStatus("Открываю ранее собранные данные…");
  try {
    const response = await fetch(`/api/report?${q}`);
    const data = await response.json();
    if (!response.ok) throw new Error(data.detail || "Ошибка отчёта");
    renderReport(data, null);
    setStatus("Показаны данные, уже сохранённые в базе.", "success");
  } catch (error) {
    setStatus(error.message, "error");
  }
}

function exportFile(kind) {
  const params = currentParams();
  const q = new URLSearchParams({
    date_from: params.date_from,
    date_to: params.date_to,
    competitors: params.competitors.join("|"),
  });
  window.location.href = `/api/export.${kind}?${q}`;
}

$("runButton").addEventListener("click", runCollection);
$("showStored").addEventListener("click", showStored);
$("exportMd").addEventListener("click", () => exportFile("md"));
$("exportCsv").addEventListener("click", () => exportFile("csv"));

$("toggleAll").addEventListener("click", () => {
  const boxes = [...document.querySelectorAll('input[name="competitor"]')];
  const allChecked = boxes.every(x => x.checked);
  boxes.forEach(x => { x.checked = !allChecked; });
  $("toggleAll").textContent = allChecked ? "Выбрать всех" : "Снять выделение";
});

document.querySelectorAll(".tab").forEach(button => {
  button.addEventListener("click", () => {
    document.querySelectorAll(".tab").forEach(x => x.classList.remove("active"));
    document.querySelectorAll(".tab-panel").forEach(x => x.classList.add("hidden"));
    button.classList.add("active");
    $(`tab-${button.dataset.tab}`).classList.remove("hidden");
  });
});
