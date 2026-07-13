import { useState, useEffect, useRef } from "react";

// ─── Theme ────────────────────────────────────────────────────────────────
const T = {
  ink: "#0E0E13",
  panel: "#16161D",
  panelUp: "#1D1D26",
  line: "#2A2A35",
  amber: "#E8A33D",
  amberDim: "rgba(232,163,61,0.14)",
  red: "#C4453A",
  green: "#5FA97A",
  text: "#F0EBE1",
  mute: "#8B8A96",
  faint: "#5B5A66",
};

const FONTS = `
@import url('https://fonts.googleapis.com/css2?family=Unbounded:wght@500;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap');
.disp { font-family: 'Unbounded', sans-serif; }
.body { font-family: 'IBM Plex Sans', sans-serif; }
.mono { font-family: 'IBM Plex Mono', monospace; }
@keyframes scanPulse { 0%,100% { opacity: 1 } 50% { opacity: 0.35 } }
.scanning { animation: scanPulse 1.1s ease-in-out infinite; }
@media (prefers-reduced-motion: reduce) { .scanning { animation: none; } }
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-thumb { background: #2A2A35; border-radius: 4px; }
::-webkit-scrollbar-track { background: transparent; }
`;

const DEFAULT_CLUBS = [
  "Клуб Игоря Бутмана (Москва)",
  "Джаз-клуб Эссе",
  "Louis Jazz Club (Патриаршие)",
  "Академ Джаз Клуб",
  "Джаз-клуб Союз Композиторов",
  "Джем Клуб (Сретенка)",
  "Ритм-Блюз Кафе",
  "Gamma More (Сухаревская)",
];

const PERIODS = [
  { id: 7, label: "Неделя" },
  { id: 14, label: "2 недели" },
  { id: 30, label: "Месяц" },
];

const SECTIONS = [
  { key: "artists", label: "Артисты и программы", icon: "♪" },
  { key: "imports", label: "Привозы и гастролёры", icon: "✈" },
  { key: "news", label: "Инфоповоды", icon: "◉" },
  { key: "promos", label: "Промо и PR", icon: "%" },
  { key: "festivals", label: "Фестивали", icon: "★" },
];

// ─── API helpers ──────────────────────────────────────────────────────────
async function callClaude(messages, useSearch) {
  const body = {
    model: "claude-sonnet-4-6",
    max_tokens: 1000,
    messages,
  };
  if (useSearch) body.tools = [{ type: "web_search_20250305", name: "web_search" }];
  const resp = await fetch("https://api.anthropic.com/v1/messages", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!resp.ok) throw new Error(`API ${resp.status}`);
  const data = await resp.json();
  return (data.content || [])
    .filter((b) => b.type === "text")
    .map((b) => b.text)
    .join("\n");
}

function extractJSON(text) {
  const clean = text.replace(/```json|```/g, "").trim();
  const start = clean.indexOf("{");
  const end = clean.lastIndexOf("}");
  if (start === -1 || end === -1) throw new Error("JSON не найден");
  return JSON.parse(clean.slice(start, end + 1));
}

function clubPrompt(club, days) {
  const today = new Date().toLocaleDateString("ru-RU", {
    day: "numeric", month: "long", year: "numeric",
  });
  return `Ты — конкурентный аналитик московского джаз-клуба. Сегодня ${today}.
Через веб-поиск собери свежую информацию о площадке «${club}» за последние ${days} дней и на ближайшие ${days} дней вперёд: афиша, заметные артисты, иностранные привозы, инфоповоды, пиар-акции, скидки, фестивали, новые форматы, открытия/новости.

Ответь ТОЛЬКО валидным JSON без markdown и без пояснений, строго по схеме:
{"artists":[{"t":"имя/программа","d":"дата если есть","n":"почему заметно, до 12 слов"}],"imports":[{"t":"","d":"","n":""}],"news":[{"t":"","n":""}],"promos":[{"t":"","n":""}],"festivals":[{"t":"","n":""}],"insight":"один вывод для конкурента, до 25 слов"}

Максимум 4 элемента в каждом массиве. Пустые массивы допустимы. Не выдумывай: если данных нет — оставь массив пустым.`;
}

function synthesisPrompt(results, days) {
  const digest = results
    .filter((r) => r.data)
    .map((r) => `${r.club}: ${r.data.insight || ""} | новости: ${(r.data.news || []).map((n) => n.t).join("; ")}`)
    .join("\n");
  return `Ты — стратег Клуба Алексея Козлова (4 сцены, ~175 концертов/мес). Ниже сводка по конкурентам за ${days} дней:
${digest}

Ответь ТОЛЬКО валидным JSON: {"takeaways":["...","...","..."]} — 3–5 конкретных выводов и действий для Клуба Козлова, каждый до 25 слов.`;
}

// ─── Storage ──────────────────────────────────────────────────────────────
async function loadKey(key, fallback) {
  try {
    const r = await window.storage.get(key);
    return r ? JSON.parse(r.value) : fallback;
  } catch { return fallback; }
}
async function saveKey(key, value) {
  try { await window.storage.set(key, JSON.stringify(value)); } catch (e) { console.error(e); }
}

// ─── Small components ────────────────────────────────────────────────────
function Tag({ children, color }) {
  return (
    <span className="mono" style={{
      fontSize: 10, letterSpacing: "0.08em", textTransform: "uppercase",
      color: color || T.mute, border: `1px solid ${T.line}`,
      padding: "2px 7px", borderRadius: 3,
    }}>{children}</span>
  );
}

function ChannelStrip({ name, status, onRetry }) {
  const colors = { idle: T.faint, scanning: T.amber, done: T.green, error: T.red };
  const labels = { idle: "ожидает", scanning: "сканирую…", done: "готово", error: "ошибка" };
  return (
    <div className="flex items-center gap-3 py-2 px-3" style={{ borderBottom: `1px solid ${T.line}` }}>
      <div className={status === "scanning" ? "scanning" : ""} style={{
        width: 8, height: 8, borderRadius: "50%", background: colors[status],
        boxShadow: status !== "idle" ? `0 0 8px ${colors[status]}` : "none", flexShrink: 0,
      }} />
      <div className="body flex-1 truncate" style={{ fontSize: 13, color: status === "idle" ? T.mute : T.text }}>{name}</div>
      <div className="mono" style={{ fontSize: 10, color: colors[status], letterSpacing: "0.06em" }}>{labels[status]}</div>
      {status === "error" && onRetry && (
        <button onClick={onRetry} className="mono" style={{
          fontSize: 10, color: T.amber, border: `1px solid ${T.amber}`,
          padding: "1px 8px", borderRadius: 3, background: "transparent", cursor: "pointer",
        }}>повторить</button>
      )}
    </div>
  );
}

function SectionList({ label, icon, items, showDates }) {
  if (!items || items.length === 0) return null;
  return (
    <div className="mb-3">
      <div className="mono mb-1.5" style={{ fontSize: 10, color: T.amber, letterSpacing: "0.12em", textTransform: "uppercase" }}>
        {icon} {label}
      </div>
      {items.map((it, i) => (
        <div key={i} className="body mb-1.5" style={{ fontSize: 13, lineHeight: 1.45 }}>
          <span style={{ color: T.text, fontWeight: 500 }}>{it.t}</span>
          {showDates && it.d ? <span className="mono" style={{ color: T.faint, fontSize: 11 }}> · {it.d}</span> : null}
          {it.n ? <span style={{ color: T.mute }}> — {it.n}</span> : null}
        </div>
      ))}
    </div>
  );
}

function ClubCard({ result }) {
  const { club, data, error } = result;
  const empty = data && SECTIONS.every((s) => !(data[s.key] || []).length);
  return (
    <div style={{ background: T.panel, border: `1px solid ${T.line}`, borderRadius: 8, padding: 18 }}>
      <div className="flex items-start justify-between gap-3 mb-3">
        <div className="disp" style={{ fontSize: 14, fontWeight: 700, color: T.text, lineHeight: 1.3 }}>{club}</div>
        {error ? <Tag color={T.red}>сбой</Tag> : empty ? <Tag>тихо</Tag> : <Tag color={T.green}>данные</Tag>}
      </div>
      {error && <div className="body" style={{ fontSize: 12, color: T.red }}>Не удалось получить данные: {error}. Нажмите «повторить» в списке каналов.</div>}
      {empty && !error && <div className="body" style={{ fontSize: 12, color: T.faint }}>За период заметных событий не найдено.</div>}
      {data && SECTIONS.map((s) => (
        <SectionList key={s.key} label={s.label} icon={s.icon} items={data[s.key]} showDates={s.key === "artists" || s.key === "imports"} />
      ))}
      {data?.insight && (
        <div className="body mt-3 pt-3" style={{ borderTop: `1px dashed ${T.line}`, fontSize: 12.5, color: T.amber, fontStyle: "italic" }}>
          {data.insight}
        </div>
      )}
    </div>
  );
}

// ─── Report → markdown export ────────────────────────────────────────────
function reportToMarkdown(report) {
  let md = `# Обзор конкурентов · ${report.dateLabel} · ${report.periodLabel}\n\n`;
  for (const r of report.results) {
    md += `## ${r.club}\n`;
    if (r.error) { md += `_Ошибка получения данных_\n\n`; continue; }
    for (const s of SECTIONS) {
      const items = r.data?.[s.key] || [];
      if (!items.length) continue;
      md += `**${s.label}:**\n`;
      for (const it of items) md += `- ${it.t}${it.d ? ` (${it.d})` : ""}${it.n ? ` — ${it.n}` : ""}\n`;
    }
    if (r.data?.insight) md += `\n_${r.data.insight}_\n`;
    md += `\n`;
  }
  if (report.takeaways?.length) {
    md += `## Выводы для Клуба Козлова\n`;
    for (const t of report.takeaways) md += `- ${t}\n`;
  }
  return md;
}

// ─── Main app ────────────────────────────────────────────────────────────
export default function CompetitorRadar() {
  const [clubs, setClubs] = useState(DEFAULT_CLUBS);
  const [period, setPeriod] = useState(7);
  const [statuses, setStatuses] = useState({});
  const [results, setResults] = useState([]);
  const [takeaways, setTakeaways] = useState([]);
  const [running, setRunning] = useState(false);
  const [phase, setPhase] = useState("");
  const [reports, setReports] = useState([]);
  const [view, setView] = useState("live"); // live | archive
  const [openReport, setOpenReport] = useState(null);
  const [newClub, setNewClub] = useState("");
  const [editMode, setEditMode] = useState(false);
  const [copied, setCopied] = useState(false);
  const cancelRef = useRef(false);

  useEffect(() => {
    (async () => {
      setClubs(await loadKey("radar-clubs", DEFAULT_CLUBS));
      setReports(await loadKey("radar-reports", []));
    })();
  }, []);

  const setStatus = (club, s) => setStatuses((p) => ({ ...p, [club]: s }));

  async function scanClub(club) {
    setStatus(club, "scanning");
    try {
      const text = await callClaude([{ role: "user", content: clubPrompt(club, period) }], true);
      const data = extractJSON(text);
      setStatus(club, "done");
      return { club, data, error: null };
    } catch (e) {
      setStatus(club, "error");
      return { club, data: null, error: e.message };
    }
  }

  async function runScan() {
    cancelRef.current = false;
    setRunning(true);
    setView("live");
    setOpenReport(null);
    setResults([]);
    setTakeaways([]);
    setStatuses(Object.fromEntries(clubs.map((c) => [c, "idle"])));
    const collected = [];
    for (const club of clubs) {
      if (cancelRef.current) break;
      const r = await scanClub(club);
      collected.push(r);
      setResults([...collected]);
    }
    let tks = [];
    if (!cancelRef.current && collected.some((r) => r.data)) {
      setPhase("Формирую выводы для Козлова…");
      try {
        const text = await callClaude([{ role: "user", content: synthesisPrompt(collected, period) }], false);
        tks = extractJSON(text).takeaways || [];
        setTakeaways(tks);
      } catch (e) { console.error(e); }
    }
    setPhase("");
    setRunning(false);
    if (collected.some((r) => r.data)) {
      const report = {
        id: Date.now(),
        dateLabel: new Date().toLocaleDateString("ru-RU", { day: "numeric", month: "long", year: "numeric" }),
        periodLabel: PERIODS.find((p) => p.id === period)?.label || `${period} дн.`,
        results: collected,
        takeaways: tks,
      };
      const next = [report, ...reports].slice(0, 8);
      setReports(next);
      saveKey("radar-reports", next);
    }
  }

  function retryClub(club) {
    scanClub(club).then((r) => {
      setResults((prev) => {
        const next = prev.map((x) => (x.club === club ? r : x));
        return next;
      });
    });
  }

  function addClub() {
    const name = newClub.trim();
    if (!name || clubs.includes(name)) return;
    const next = [...clubs, name];
    setClubs(next);
    saveKey("radar-clubs", next);
    setNewClub("");
  }

  function removeClub(name) {
    const next = clubs.filter((c) => c !== name);
    setClubs(next);
    saveKey("radar-clubs", next);
  }

  function deleteReport(id) {
    const next = reports.filter((r) => r.id !== id);
    setReports(next);
    saveKey("radar-reports", next);
    if (openReport?.id === id) setOpenReport(null);
  }

  async function copyMarkdown(report) {
    try {
      await navigator.clipboard.writeText(reportToMarkdown(report));
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch (e) { console.error(e); }
  }

  const liveReport = { dateLabel: "сейчас", periodLabel: PERIODS.find((p) => p.id === period)?.label, results, takeaways };
  const shown = view === "archive" && openReport ? openReport : liveReport;
  const doneCount = Object.values(statuses).filter((s) => s === "done" || s === "error").length;

  return (
    <div className="body min-h-screen" style={{ background: T.ink, color: T.text }}>
      <style>{FONTS}</style>

      {/* Header */}
      <div style={{ borderBottom: `1px solid ${T.line}`, background: T.panel }}>
        <div className="max-w-5xl mx-auto px-5 py-5 flex flex-wrap items-end justify-between gap-4">
          <div>
            <div className="mono" style={{ fontSize: 10, color: T.amber, letterSpacing: "0.2em", textTransform: "uppercase", marginBottom: 6 }}>
              Клуб Алексея Козлова · разведка сцены
            </div>
            <h1 className="disp" style={{ fontSize: 24, fontWeight: 700, lineHeight: 1.1 }}>
              РАДАР<span style={{ color: T.amber }}>·</span>КОНКУРЕНТОВ
            </h1>
          </div>
          <div className="flex items-center gap-2">
            <button onClick={() => { setView("live"); setOpenReport(null); }} className="mono" style={{
              fontSize: 11, padding: "6px 12px", borderRadius: 4, cursor: "pointer",
              background: view === "live" ? T.amberDim : "transparent",
              color: view === "live" ? T.amber : T.mute, border: `1px solid ${view === "live" ? T.amber : T.line}`,
            }}>Живой обзор</button>
            <button onClick={() => setView("archive")} className="mono" style={{
              fontSize: 11, padding: "6px 12px", borderRadius: 4, cursor: "pointer",
              background: view === "archive" ? T.amberDim : "transparent",
              color: view === "archive" ? T.amber : T.mute, border: `1px solid ${view === "archive" ? T.amber : T.line}`,
            }}>Архив ({reports.length})</button>
          </div>
        </div>
      </div>

      <div className="max-w-5xl mx-auto px-5 py-6">
        {view === "live" && (
          <>
            {/* Controls */}
            <div className="flex flex-wrap items-center gap-3 mb-5">
              <div className="flex gap-1" style={{ background: T.panel, border: `1px solid ${T.line}`, borderRadius: 6, padding: 3 }}>
                {PERIODS.map((p) => (
                  <button key={p.id} onClick={() => setPeriod(p.id)} disabled={running} className="mono" style={{
                    fontSize: 11, padding: "6px 12px", borderRadius: 4, cursor: running ? "default" : "pointer",
                    background: period === p.id ? T.amber : "transparent",
                    color: period === p.id ? T.ink : T.mute, border: "none", fontWeight: 500,
                  }}>{p.label}</button>
                ))}
              </div>
              <button onClick={running ? () => { cancelRef.current = true; } : runScan} className="disp" style={{
                fontSize: 13, fontWeight: 700, padding: "10px 22px", borderRadius: 6, cursor: "pointer",
                background: running ? "transparent" : T.amber, color: running ? T.red : T.ink,
                border: running ? `1px solid ${T.red}` : "none", letterSpacing: "0.02em",
              }}>
                {running ? "Остановить" : "Собрать обзор"}
              </button>
              <button onClick={() => setEditMode(!editMode)} disabled={running} className="mono" style={{
                fontSize: 11, padding: "8px 12px", borderRadius: 4, cursor: "pointer",
                background: "transparent", color: editMode ? T.amber : T.mute, border: `1px solid ${T.line}`,
              }}>{editMode ? "Готово" : "Клубы ✎"}</button>
              {running && (
                <span className="mono" style={{ fontSize: 11, color: T.mute }}>
                  {phase || `${doneCount} / ${clubs.length}`}
                </span>
              )}
            </div>

            {/* Club editor */}
            {editMode && (
              <div className="mb-5 p-4" style={{ background: T.panel, border: `1px solid ${T.line}`, borderRadius: 8 }}>
                <div className="flex flex-wrap gap-2 mb-3">
                  {clubs.map((c) => (
                    <span key={c} className="body flex items-center gap-2" style={{
                      fontSize: 12, background: T.panelUp, border: `1px solid ${T.line}`,
                      padding: "5px 10px", borderRadius: 4,
                    }}>
                      {c}
                      <button onClick={() => removeClub(c)} style={{ color: T.red, background: "none", border: "none", cursor: "pointer", fontSize: 13, lineHeight: 1 }}>×</button>
                    </span>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input value={newClub} onChange={(e) => setNewClub(e.target.value)}
                    onKeyDown={(e) => e.key === "Enter" && addClub()}
                    placeholder="Добавить площадку (название + район для точности)"
                    className="body flex-1" style={{
                      fontSize: 13, background: T.ink, border: `1px solid ${T.line}`, borderRadius: 4,
                      padding: "8px 12px", color: T.text, outline: "none",
                    }} />
                  <button onClick={addClub} className="mono" style={{
                    fontSize: 11, padding: "8px 14px", borderRadius: 4, cursor: "pointer",
                    background: T.amberDim, color: T.amber, border: `1px solid ${T.amber}`,
                  }}>Добавить</button>
                </div>
              </div>
            )}

            {/* Channel strips */}
            {(running || results.length > 0) && (
              <div className="mb-6" style={{ background: T.panel, border: `1px solid ${T.line}`, borderRadius: 8, overflow: "hidden" }}>
                <div className="mono px-3 py-2" style={{ fontSize: 10, color: T.faint, letterSpacing: "0.15em", textTransform: "uppercase", borderBottom: `1px solid ${T.line}` }}>
                  Каналы сканирования
                </div>
                {clubs.map((c) => (
                  <ChannelStrip key={c} name={c} status={statuses[c] || "idle"} onRetry={() => retryClub(c)} />
                ))}
              </div>
            )}

            {/* Empty state */}
            {!running && results.length === 0 && (
              <div className="text-center py-16">
                <div className="disp" style={{ fontSize: 40, color: T.line, marginBottom: 12 }}>♪</div>
                <div className="body" style={{ fontSize: 14, color: T.mute, maxWidth: 420, margin: "0 auto", lineHeight: 1.6 }}>
                  Выберите период и нажмите «Собрать обзор» — радар пройдёт по всем площадкам через веб-поиск и соберёт артистов, привозы, инфоповоды и промо-активности.
                </div>
              </div>
            )}
          </>
        )}

        {/* Archive list */}
        {view === "archive" && !openReport && (
          <div>
            {reports.length === 0 && (
              <div className="body text-center py-16" style={{ fontSize: 14, color: T.mute }}>
                Архив пуст. Соберите первый обзор — он сохранится здесь автоматически.
              </div>
            )}
            {reports.map((r) => (
              <div key={r.id} className="flex items-center justify-between gap-3 p-4 mb-2"
                style={{ background: T.panel, border: `1px solid ${T.line}`, borderRadius: 8 }}>
                <button onClick={() => setOpenReport(r)} className="text-left flex-1" style={{ background: "none", border: "none", cursor: "pointer" }}>
                  <div className="body" style={{ fontSize: 14, color: T.text, fontWeight: 500 }}>{r.dateLabel}</div>
                  <div className="mono" style={{ fontSize: 11, color: T.mute }}>{r.periodLabel} · {r.results.filter((x) => x.data).length} площадок</div>
                </button>
                <button onClick={() => deleteReport(r.id)} className="mono" style={{
                  fontSize: 10, color: T.red, background: "none", border: `1px solid ${T.line}`,
                  padding: "4px 10px", borderRadius: 4, cursor: "pointer",
                }}>удалить</button>
              </div>
            ))}
          </div>
        )}

        {/* Report body (live results or opened archive) */}
        {((view === "live" && results.length > 0) || (view === "archive" && openReport)) && (
          <>
            {view === "archive" && openReport && (
              <div className="flex items-center gap-3 mb-4">
                <button onClick={() => setOpenReport(null)} className="mono" style={{
                  fontSize: 11, color: T.mute, background: "none", border: `1px solid ${T.line}`,
                  padding: "5px 12px", borderRadius: 4, cursor: "pointer",
                }}>← к архиву</button>
                <div className="body" style={{ fontSize: 14, fontWeight: 500 }}>{openReport.dateLabel} · {openReport.periodLabel}</div>
              </div>
            )}

            {shown.takeaways?.length > 0 && (
              <div className="mb-6 p-5" style={{
                background: `linear-gradient(135deg, ${T.amberDim}, transparent 60%), ${T.panel}`,
                border: `1px solid ${T.amber}`, borderRadius: 8,
              }}>
                <div className="disp mb-3" style={{ fontSize: 13, fontWeight: 700, color: T.amber }}>ВЫВОДЫ ДЛЯ КОЗЛОВА</div>
                {shown.takeaways.map((t, i) => (
                  <div key={i} className="body flex gap-3 mb-2" style={{ fontSize: 13.5, lineHeight: 1.5 }}>
                    <span className="mono" style={{ color: T.amber, fontSize: 12 }}>{String(i + 1).padStart(2, "0")}</span>
                    <span>{t}</span>
                  </div>
                ))}
              </div>
            )}

            <div className="grid gap-4" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(300px, 1fr))" }}>
              {shown.results.map((r) => <ClubCard key={r.club} result={r} />)}
            </div>

            {!running && shown.results.some((r) => r.data) && (
              <div className="mt-6 flex gap-3">
                <button onClick={() => copyMarkdown(shown)} className="mono" style={{
                  fontSize: 11, padding: "8px 16px", borderRadius: 4, cursor: "pointer",
                  background: copied ? T.green : "transparent", color: copied ? T.ink : T.mute,
                  border: `1px solid ${copied ? T.green : T.line}`,
                }}>{copied ? "Скопировано ✓" : "Копировать как Markdown"}</button>
              </div>
            )}
          </>
        )}
      </div>

      <div className="mono text-center py-6" style={{ fontSize: 10, color: T.faint, letterSpacing: "0.1em" }}>
        данные собираются веб-поиском в момент запуска · отчёты хранятся локально в артефакте
      </div>
    </div>
  );
}
