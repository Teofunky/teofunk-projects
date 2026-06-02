import React, { useState } from "react";

// ─────────────────────────────────────────────────────────────
//  MOUSEWORK CALIBRATOR · v0.3 «НАБЛЮДАТЕЛЬ ДОСТАТОЧНОГО РАЗМЕРА»
//  Хаос пользователя  ✕  живой интернет  →  карта скрытого порядка.
//  Ядро РЕАЛЬНО ищет в сети (web_search) контекст по сущностям и
//  паттернам, накладывает локальную возню на глобальную картину.
//  Оптика (lens) подстраивает движок под любого: бизнес, учёный,
//  врач, следователь, частный человек, урбанист, аналитик рынка.
// ─────────────────────────────────────────────────────────────

const LENSES = [
  { id: "business", icon: "▤", name: "Бизнес", hint: "трение, отток, риски, отложенные последствия" },
  { id: "science", icon: "✦", name: "Учёный", hint: "связи между дисциплинами, слепые зоны литературы" },
  { id: "medicine", icon: "✛", name: "Врач", hint: "структурирует наблюдения; НЕ ставит диагноз" },
  { id: "investigator", icon: "⊠", name: "Следователь", hint: "временные линии, несостыковки, повторы имён" },
  { id: "personal", icon: "◍", name: "Частный человек", hint: "жизненные паттерны, выгорание, что влияет на результат" },
  { id: "urban", icon: "⬡", name: "Урбанист", hint: "жалобы как предвестники инфраструктурных проблем" },
  { id: "market", icon: "➹", name: "Рынок", hint: "слабые сигналы, тональность, цепочки по компаниям" },
];

const LENS_PROMPTS = {
  business:
    "Оптика управленца. Ищи невидимое трение, ранние признаки оттока клиентов и выгорания команды, малые сбои перед крупными, отложенные последствия решений. Не BI-дашборд, а диспетчер скрытых закономерностей.",
  science:
    "Оптика исследователя. Ищи связи между разными дисциплинами, где об одном говорят разными языками; слепые зоны в литературе; сходящиеся независимые сигналы; гипотезы на стыке областей.",
  medicine:
    "Оптика медицинская. КРИТИЧНО: ты НЕ ставишь диагнозов и не назначаешь лечение. Ты только структурируешь наблюдения, выделяешь паттерны и аномалии и готовишь материал для обсуждения с врачом. Любой причинный вывод по здоровью требует клинической проверки.",
  investigator:
    "Оптика расследователя/журналиста. Строй временные линии, находи повторяющиеся имена/компании/адреса/даты, несостыковки, где отсутствует доказательство, отличай подтверждённый факт от предположения.",
  personal:
    "Оптика самонаблюдения. Ищи повторяющиеся жизненные паттерны, влияющие на результаты, но незаметные изнутри: что предшествует упадку энергии, какие обязательства дают хронический стресс, какие решения потом оказывались удачными.",
  urban:
    "Оптика городской системы. Рассматривай мелкие жалобы, сбои, погоду, потоки как ранние индикаторы будущих инфраструктурных проблем; ищи, где разрозненные обращения указывают на общий источник.",
  market:
    "Оптика рыночного аналитика. КРИТИЧНО: не обещай точных предсказаний рынка и не давай инвестиционных советов. Выявляй слабые сигналы, сдвиги тональности, цепочки событий по компаниям, скрытые связи регуляторики-логистики-спроса как ГИПОТЕЗЫ и сценарии.",
};

const BADGE = {
  факт: { c: "#4ec9b0", t: "ФАКТ" },
  связь: { c: "#61afef", t: "СВЯЗЬ" },
  паттерн: { c: "#dcb67a", t: "ПАТТЕРН" },
  гипотеза: { c: "#9d7cd8", t: "ГИПОТЕЗА" },
  причина: { c: "#e06c75", t: "ПРИЧИНА" },
  спекуляция: { c: "#7f8c9a", t: "СПЕКУЛЯЦИЯ" },
};

const SEEDS = {
  business: `участились жалобы поддержки со словом «непонятно»
два ключевых клиента перенесли встречи без причины
счёт за облако вырос на 23% при прежнем трафике
тихо уволился ведущий инженер
конкурент запустил лендинг с похожей формулировкой
CEO дважды сказал «оптимизация» на созвоне
рейтинг в сторе упал с 4.8 до 4.6`,
  science: `рост цитирований статьи 2009 года по твёрдотельным электролитам
три независимые лаборатории сообщили о схожей аномалии проводимости
скачок препринтов на стыке микробиологии и материаловедения
патент на новый метод синтеза подан малоизвестной группой
конференция по теме внезапно перенесена и расширена`,
  personal: `плохо сплю после вечерних созвонов
по понедельникам почти всегда раздражение к обеду
когда пропускаю прогулку — вечером тянет на сладкое
лучшие идеи приходили в поездках, не за столом
после общения с одним знакомым стабильно упадок сил`,
  urban: `участились жалобы на запах в трёх кварталах у реки
мелкие перебои с давлением воды по утрам
рост вызовов аварийки на один и тот же перекрёсток
жители пишут про вибрацию по ночам
подтопление подвала после несильного дождя`,
};

export default function App() {
  const [lens, setLens] = useState("business");
  const [raw, setRaw] = useState(SEEDS.business);
  const [useWeb, setUseWeb] = useState(true);
  const [status, setStatus] = useState("idle");
  const [phase, setPhase] = useState("");
  const [res, setRes] = useState(null);
  const [err, setErr] = useState("");
  const [tab, setTab] = useState("verdict");

  function pickLens(id) {
    setLens(id);
    if (SEEDS[id]) setRaw(SEEDS[id]);
  }

  async function calibrate() {
    if (raw.trim().length < 15) {
      setErr("Дай движку поток событий — хотя бы несколько строк.");
      setStatus("error");
      return;
    }
    setStatus("running");
    setErr("");
    setRes(null);

    const phases = useWeb
      ? [
          "Извлекаю сущности из твоего хаоса…",
          "Ищу в интернете контекст по теме…",
          "Сверяю локальные сигналы с мировыми…",
          "Отделяю общий тренд от твоей аномалии…",
          "Строю гипотезы и проставляю доказанность…",
        ]
      : [
          "Извлекаю сущности и события…",
          "Ищу повторы, кластеры, аномалии…",
          "Формулирую причинные гипотезы…",
          "Проставляю уровни доказанности…",
        ];
    let pi = 0;
    setPhase(phases[0]);
    const ticker = setInterval(() => {
      pi = (pi + 1) % phases.length;
      setPhase(phases[pi]);
    }, 1500);

    const lensText = LENS_PROMPTS[lens];
    const system =
      "Ты — ядро Pattern Intelligence платформы «Калибратор мышиной возни». " +
      "Твоя миссия из метафоры: стать «наблюдателем достаточного размера». " +
      "Человек видит только свою узкую щель событий — мышиную возню. Ты накладываешь её на " +
      "общую картину мира и показываешь скрытый порядок.\n\n" +
      (useWeb
        ? "У ТЕБЯ ЕСТЬ ВЕБ-ПОИСК. Используй его в ДВА слоя.\n" +
          "СЛОЙ 1 — МИР: определи ключевые сущности/темы из потока пользователя и найди актуальный контекст " +
          "(новости, исследования, отраслевые тренды, статистику) — что уже произошло.\n" +
          "СЛОЙ 2 — КОЛЛЕКТИВНОЕ ВНИМАНИЕ (поисковый интерес): оцени, что человечество ИЩЕТ по этим темам прямо сейчас. " +
          "Поисковый запрос опережает событие: люди гуглят тревогу до того, как она станет новостью. Через поиск свежих " +
          "материалов и обсуждений определи: есть ли заметный ВСПЛЕСК интереса к теме сейчас; какие смежные/восходящие " +
          "запросы поднимаются (rising queries); ускоряется ли внимание или гаснет. Если точных цифр нет — честно оцени " +
          "направление (рост/спад/ровно) и помечай бейджем 'связь' или 'гипотеза', не 'факт'.\n" +
          "ГЛАВНОЕ: поставь рядом ТРИ потока — (а) локальная возня пользователя, (б) мир/что уже случилось, " +
          "(в) поисковый интерес/что назревает — и определи для каждого сигнала: это уже-случившееся или ещё-только-назревающее. " +
          "Совпадение всплеска поиска с локальной аномалией — самый сильный ранний сигнал.\n\n"
        : "Работай только по данным пользователя, без внешних источников.\n\n") +
      "ОПТИКА ПОЛЬЗОВАТЕЛЯ: " + lensText + "\n\n" +
      "ЖЕЛЕЗНЫЕ ПРИНЦИПЫ:\n" +
      "1) Не предсказывать, а ОБНАРУЖИВАТЬ повторяющиеся структуры настоящего.\n" +
      "2) Корреляция ≠ причина. Бейдж 'причина' — только при отдельном обосновании, иначе 'гипотеза'/'связь'.\n" +
      "3) Каждый вывод честно маркируй уровнем доказанности.\n" +
      "4) Всегда давай 'что проверить'.\n" +
      "5) Отделяй ШУМ от СИГНАЛА под выбранную оптику.\n\n" +
      "После исследования верни СТРОГО валидный JSON (без markdown, без ```), последним сообщением, объект:\n" +
      "{\n" +
      '  "verdict": "2-4 предложения: что здесь на самом деле происходит, с учётом мирового контекста и оптики",\n' +
      '  "local_vs_global": [{"signal":"сигнал пользователя","world":"что говорит мир/интернет по этому поводу","reading":"совпадает с трендом | локальная аномалия | опережает тренд | отстаёт","badge":"факт|связь|гипотеза"}],\n' +
      '  "search_pulse": [{"query":"что люди ищут по теме","direction":"всплеск | растёт | ровно | гаснет","window":"за какой срок, если оценимо","meaning":"что этот поисковый интерес говорит — что назревает","relates_to":"к какому сигналу пользователя это относится","badge":"связь|гипотеза|спекуляция"}],\n' +
      '  "patterns": [{"title":"...","observation":"что повторяется","badge":"паттерн","confidence":0.0-1.0}],\n' +
      '  "anomalies": [{"text":"что выбивается и почему","badge":"факт|связь"}],\n' +
      '  "hypotheses": [{"claim":"...","evidence":"что подтверждает","against":"что ослабляет","badge":"гипотеза|связь|причина","confidence":0.0-1.0,"check":"что проверить"}],\n' +
      '  "blindspots": [{"text":"что человек склонен не замечать","why":"почему незаметно"}],\n' +
      '  "actions": ["что сделать или проверить дальше, под оптику"],\n' +
      '  "sources": ["краткое название источника из веба, если использовал"]\n' +
      "}\n" +
      "Отвечай на русском, ёмко, без воды.";

    const body = {
      model: "claude-sonnet-4-20250514",
      max_tokens: 4000,
      system,
      messages: [{ role: "user", content: "ПОТОК ХАОСА:\n" + raw }],
    };
    if (useWeb) {
      body.tools = [{ type: "web_search_20250305", name: "web_search" }];
    }

    try {
      const resp = await fetch("https://api.anthropic.com/v1/messages", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const data = await resp.json();
      // Collect every text block; the JSON verdict is the final text block.
      const texts = (data.content || [])
        .filter((b) => b.type === "text")
        .map((b) => b.text);
      const joined = texts.join("\n");
      // Extract the last {...} JSON object from the combined text.
      const start = joined.lastIndexOf("{");
      const end = joined.lastIndexOf("}");
      let parsed = null;
      if (start !== -1 && end !== -1 && end > start) {
        const cand = joined.slice(start, end + 1);
        parsed = JSON.parse(cand);
      } else if (joined.trim()) {
        parsed = JSON.parse(joined.replace(/```json|```/g, "").trim());
      } else {
        throw new Error("ядро вернуло пустой ответ");
      }
      clearInterval(ticker);
      setRes(parsed);
      setStatus("done");
      setTab("verdict");
    } catch (e) {
      clearInterval(ticker);
      setErr("Калибровка сорвалась: " + (e?.message || "ядро вернуло шум."));
      setStatus("error");
    }
  }

  const curLens = LENSES.find((l) => l.id === lens);

  return (
    <div style={S.root}>
      <div style={S.scan} />
      <div style={S.grain} />
      <div style={S.wrap}>
        <Header />

        {/* LENS SELECTOR */}
        <div style={S.lensBar}>
          {LENSES.map((l) => (
            <button
              key={l.id}
              onClick={() => pickLens(l.id)}
              style={{ ...S.lensBtn, ...(lens === l.id ? S.lensActive : {}) }}
              title={l.hint}
            >
              <span style={S.lensIcon}>{l.icon}</span>
              {l.name}
            </button>
          ))}
        </div>
        <div style={S.lensHint}>оптика: {curLens.hint}</div>

        {/* INPUT */}
        <div style={S.deck}>
          <div style={S.deckLeft}>
            <div style={S.fieldLabel}>ПОТОК ХАОСА · по строке на событие</div>
            <textarea
              value={raw}
              onChange={(e) => setRaw(e.target.value)}
              style={S.textarea}
              spellCheck={false}
            />
          </div>
          <div style={S.deckRight}>
            <label style={S.webToggle}>
              <input
                type="checkbox"
                checked={useWeb}
                onChange={(e) => setUseWeb(e.target.checked)}
                style={{ accentColor: "#4ec9b0" }}
              />
              <span>
                <b>Связать с миром</b>
                <br />
                <span style={S.webSub}>
                  движок ищет в интернете контекст и сверяет твою возню с
                  глобальной картиной
                </span>
              </span>
            </label>

            <button
              onClick={calibrate}
              disabled={status === "running"}
              style={{
                ...S.mainBtn,
                ...(status === "running" ? { opacity: 0.6, cursor: "wait" } : {}),
              }}
            >
              {status === "running" ? "▣ КАЛИБРУЮ…" : "▶ ОТКАЛИБРОВАТЬ ВОЗНЮ"}
            </button>
            {status === "running" && <div style={S.phase}>◖ {phase}</div>}
            {status === "error" && <div style={S.errBox}>{err}</div>}
            <BadgeLegend />
          </div>
        </div>

        {/* RESULTS */}
        {res && (
          <div style={S.results}>
            {res.verdict && (
              <div style={S.verdict}>
                <span style={S.vMark}>⟁</span> {res.verdict}
              </div>
            )}

            <div style={S.tabs}>
              {[
                ["verdict", "ЛОКАЛЬНОЕ ✕ МИРОВОЕ"],
                ["pulse", "ПУЛЬС ВНИМАНИЯ"],
                ["patterns", "ПАТТЕРНЫ И ГИПОТЕЗЫ"],
                ["blind", "ЧТО Я НЕ ВИЖУ"],
                ["actions", "ЧТО ДЕЛАТЬ"],
              ].map(([id, label]) => (
                <button
                  key={id}
                  onClick={() => setTab(id)}
                  style={{ ...S.tab, ...(tab === id ? S.tabActive : {}) }}
                >
                  {label}
                </button>
              ))}
            </div>

            {tab === "verdict" && <LocalGlobal res={res} />}
            {tab === "pulse" && <SearchPulse res={res} />}
            {tab === "patterns" && <Patterns res={res} />}
            {tab === "blind" && <Blind res={res} />}
            {tab === "actions" && <Actions res={res} />}

            {(res.sources || []).length > 0 && (
              <div style={S.sources}>
                <span style={S.srcLabel}>источники из сети:</span>{" "}
                {res.sources.join(" · ")}
              </div>
            )}
          </div>
        )}

        <Footer />
      </div>
    </div>
  );
}

function LocalGlobal({ res }) {
  const rows = res.local_vs_global || [];
  if (!rows.length) return <Empty />;
  const tone = (r) =>
    /аномал/i.test(r)
      ? "#e06c75"
      : /опереж/i.test(r)
      ? "#dcb67a"
      : /отста/i.test(r)
      ? "#9d7cd8"
      : "#4ec9b0";
  return (
    <div>
      <SubLabel t="ТВОЙ СИГНАЛ ПРОТИВ МИРОВОГО КОНТЕКСТА" />
      {rows.map((r, i) => (
        <div key={i} style={S.lgRow}>
          <div style={S.lgSignal}>
            <span style={S.lgArrowYou}>ты ▸</span> {r.signal}
          </div>
          <div style={S.lgWorld}>
            <span style={S.lgArrowWorld}>мир ▸</span> {r.world}
          </div>
          <div style={S.lgFoot}>
            <span style={{ ...S.reading, color: tone(r.reading), borderColor: tone(r.reading) }}>
              {r.reading}
            </span>
            <Badge b={r.badge} small />
          </div>
        </div>
      ))}
    </div>
  );
}

function SearchPulse({ res }) {
  const rows = res.search_pulse || [];
  if (!rows.length)
    return (
      <div style={S.empty}>
        Поисковый пульс не считан. Включи «Связать с миром» и откалибруй заново —
        движок оценит, что человечество ищет по твоей теме прямо сейчас.
      </div>
    );
  const dir = (d) => {
    const s = (d || "").toLowerCase();
    if (/всплеск/.test(s)) return { c: "#e06c75", icon: "▲▲", label: "ВСПЛЕСК" };
    if (/раст/.test(s)) return { c: "#dcb67a", icon: "▲", label: "РАСТЁТ" };
    if (/гасн|спад|пад/.test(s)) return { c: "#7f8c9a", icon: "▼", label: "ГАСНЕТ" };
    return { c: "#4ec9b0", icon: "▬", label: "РОВНО" };
  };
  return (
    <div>
      <div style={S.pulseIntro}>
        Поисковый запрос опережает событие — люди ищут тревогу до того, как она
        станет новостью. Это третий поток: что{" "}
        <span style={{ color: "#e06c75" }}>назревает</span> в коллективном
        внимании, поверх того, что уже случилось.
      </div>
      <SubLabel t="ПУЛЬС КОЛЛЕКТИВНОГО ВНИМАНИЯ" />
      {rows.map((r, i) => {
        const D = dir(r.direction);
        return (
          <div key={i} style={S.pulseRow}>
            <div style={S.pulseTop}>
              <span style={{ ...S.pulseDir, color: D.c, borderColor: D.c }}>
                {D.icon} {D.label}
              </span>
              <span style={S.pulseQuery}>«{r.query}»</span>
              <Badge b={r.badge} small />
            </div>
            <div style={S.pulseMeaning}>{r.meaning}</div>
            <div style={S.pulseFoot}>
              {r.window && <span style={S.pulseWindow}>окно: {r.window}</span>}
              {r.relates_to && (
                <span style={S.pulseRel}>↔ твой сигнал: {r.relates_to}</span>
              )}
            </div>
          </div>
        );
      })}
      <div style={S.apiNote}>
        ⌬ слой совместим с Google Trends API (interest-over-time · rising
        queries). Сейчас пульс оценивается через веб-поиск; при подключении ключа
        Trends реальные ряды встанут на это место.
      </div>
    </div>
  );
}

function Patterns({ res }) {
  return (
    <div>
      {(res.patterns || []).length > 0 && (
        <>
          <SubLabel t="ПОВТОРЯЮЩИЕСЯ ПАТТЕРНЫ" />
          {res.patterns.map((p, i) => (
            <div key={i} style={S.patCard}>
              <div style={S.row}>
                <span style={S.patTitle}>{p.title}</span>
                <Badge b={p.badge} />
                <Conf c={p.confidence} />
              </div>
              <div style={S.obs}>{p.observation}</div>
            </div>
          ))}
        </>
      )}
      {(res.anomalies || []).length > 0 && (
        <>
          <SubLabel t="АНОМАЛИИ" />
          {res.anomalies.map((a, i) => (
            <div key={i} style={S.anomaly}>
              <span style={{ marginRight: 8 }}>⚠</span>
              <span style={{ flex: 1 }}>{a.text}</span>
              <Badge b={a.badge} small />
            </div>
          ))}
        </>
      )}
      {(res.hypotheses || []).length > 0 && (
        <>
          <SubLabel t="КОМНАТА ГИПОТЕЗ" />
          {res.hypotheses.map((h, i) => (
            <div key={i} style={S.hypCard}>
              <div style={{ ...S.row, marginBottom: 10 }}>
                <span style={S.hypClaim}>{h.claim}</span>
                <Badge b={h.badge} />
                <Conf c={h.confidence} />
              </div>
              <div style={S.hypGrid}>
                <div style={S.hypCol}>
                  <div style={S.hypColLabel}>✓ подтверждает</div>
                  <div style={S.hypColText}>{h.evidence}</div>
                </div>
                <div style={S.hypCol}>
                  <div style={{ ...S.hypColLabel, color: "#e06c75" }}>✗ ослабляет</div>
                  <div style={S.hypColText}>{h.against}</div>
                </div>
              </div>
              {h.check && (
                <div style={S.check}>
                  <span style={S.checkMark}>⊕ проверить:</span> {h.check}
                </div>
              )}
            </div>
          ))}
        </>
      )}
    </div>
  );
}

function Blind({ res }) {
  const b = res.blindspots || [];
  if (!b.length) return <Empty />;
  return (
    <div style={S.blindPanel}>
      <div style={S.blindTitle}>👁 ЧТО ОБЫЧНО ОСТАЁТСЯ НЕВИДИМЫМ</div>
      {b.map((x, i) => (
        <div key={i} style={S.blindRow}>
          <div style={S.blindText}>{x.text}</div>
          <div style={S.blindWhy}>почему незаметно: {x.why}</div>
        </div>
      ))}
    </div>
  );
}

function Actions({ res }) {
  const a = res.actions || [];
  if (!a.length) return <Empty />;
  return (
    <div>
      <SubLabel t="ЧТО ПРОВЕРИТЬ ИЛИ СДЕЛАТЬ ДАЛЬШЕ" />
      {a.map((x, i) => (
        <div key={i} style={S.actionRow}>
          <span style={S.actionNum}>{String(i + 1).padStart(2, "0")}</span>
          <span>{x}</span>
        </div>
      ))}
    </div>
  );
}

function Badge({ b, small }) {
  const info = BADGE[b] || BADGE["спекуляция"];
  return (
    <span
      style={{
        ...S.badge,
        color: info.c,
        borderColor: info.c,
        ...(small ? { fontSize: 9, padding: "1px 5px" } : {}),
      }}
    >
      {info.t}
    </span>
  );
}
function BadgeLegend() {
  return (
    <div style={S.legend}>
      <div style={S.legendTitle}>УРОВНИ ДОКАЗАННОСТИ</div>
      <div style={S.legendWrap}>
        {Object.values(BADGE).map((b) => (
          <span key={b.t} style={{ ...S.legendBadge, color: b.c, borderColor: b.c }}>
            {b.t}
          </span>
        ))}
      </div>
    </div>
  );
}
function Conf({ c }) {
  const pct = Math.round((c || 0) * 100);
  return (
    <span style={S.confWrap} title={`уверенность ${pct}%`}>
      <span style={S.confTrack}>
        <span
          style={{
            ...S.confFill,
            width: pct + "%",
            background: pct > 66 ? "#4ec9b0" : pct > 33 ? "#dcb67a" : "#e06c75",
          }}
        />
      </span>
      <span style={S.confPct}>{pct}%</span>
    </span>
  );
}
function SubLabel({ t }) {
  return <div style={S.subLabel}>— {t}</div>;
}
function Empty() {
  return <div style={S.empty}>Под эту вкладку движок ничего не выделил.</div>;
}
function Header() {
  return (
    <div style={S.header}>
      <div style={S.logo}>⟁</div>
      <div>
        <div style={S.title}>КАЛИБРАТОР МЫШИНОЙ ВОЗНИ</div>
        <div style={S.subtitle}>
          твой хаос ✕ весь интернет · наблюдатель достаточного размера · не
          предсказывает — обнаруживает
        </div>
      </div>
    </div>
  );
}
function Footer() {
  return (
    <div style={S.footer}>
      «связи существуют всегда — нет лишь наблюдателя достаточного размера»
    </div>
  );
}

const mono = "'Courier New', ui-monospace, monospace";
const S = {
  root: {
    minHeight: "100vh",
    background:
      "radial-gradient(120% 80% at 50% 0%, #10211c 0%, #0a0e0d 55%, #050706 100%)",
    color: "#cdd6d3",
    fontFamily: mono,
    position: "relative",
    overflow: "hidden",
  },
  grain: {
    position: "fixed",
    inset: 0,
    pointerEvents: "none",
    opacity: 0.05,
    zIndex: 5,
    backgroundImage:
      "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E\")",
  },
  scan: {
    position: "fixed",
    inset: 0,
    pointerEvents: "none",
    zIndex: 4,
    background:
      "repeating-linear-gradient(0deg, rgba(78,201,176,0.03) 0px, rgba(78,201,176,0.03) 1px, transparent 2px, transparent 4px)",
  },
  wrap: {
    position: "relative",
    zIndex: 10,
    maxWidth: 1200,
    margin: "0 auto",
    padding: "30px 26px 50px",
  },
  header: {
    display: "flex",
    alignItems: "center",
    gap: 16,
    borderBottom: "1px solid #1c2e29",
    paddingBottom: 16,
    marginBottom: 20,
  },
  logo: { fontSize: 38, color: "#4ec9b0", textShadow: "0 0 18px rgba(78,201,176,0.6)" },
  title: { fontSize: 21, letterSpacing: 4, fontWeight: 700, color: "#e8f1ee" },
  subtitle: { fontSize: 10.5, letterSpacing: 0.8, color: "#6f8079", marginTop: 4 },
  lensBar: { display: "flex", flexWrap: "wrap", gap: 7, marginBottom: 8 },
  lensBtn: {
    display: "flex",
    alignItems: "center",
    gap: 6,
    background: "rgba(10,20,17,0.6)",
    border: "1px solid #243a34",
    color: "#8a9a94",
    borderRadius: 3,
    padding: "7px 11px",
    fontSize: 12,
    fontFamily: mono,
    cursor: "pointer",
  },
  lensActive: { background: "#16302a", borderColor: "#4ec9b0", color: "#4ec9b0" },
  lensIcon: { fontSize: 13 },
  lensHint: { fontSize: 11, color: "#5f7269", marginBottom: 18, fontStyle: "italic" },
  deck: { display: "flex", gap: 22, alignItems: "stretch", marginBottom: 24 },
  deckLeft: { flex: 1, display: "flex", flexDirection: "column" },
  deckRight: { flex: "0 0 310px", display: "flex", flexDirection: "column" },
  fieldLabel: { fontSize: 10.5, letterSpacing: 1.5, color: "#6f8079", marginBottom: 8 },
  textarea: {
    flex: 1,
    minHeight: 230,
    background: "rgba(10,20,17,0.6)",
    border: "1px solid #243a34",
    borderRadius: 4,
    padding: 12,
    color: "#dde6e3",
    fontFamily: mono,
    fontSize: 12.5,
    lineHeight: 1.6,
    outline: "none",
    resize: "vertical",
  },
  webToggle: {
    display: "flex",
    gap: 10,
    alignItems: "flex-start",
    border: "1px solid #2a4640",
    borderRadius: 4,
    padding: 12,
    marginBottom: 14,
    fontSize: 12.5,
    color: "#cdd6d3",
    cursor: "pointer",
    background: "rgba(22,48,42,0.3)",
  },
  webSub: { fontSize: 10.5, color: "#6f8079", lineHeight: 1.4 },
  mainBtn: {
    width: "100%",
    background: "linear-gradient(180deg,#1c4d43,#123830)",
    border: "1px solid #4ec9b0",
    color: "#d4f5ed",
    padding: 13,
    borderRadius: 4,
    fontFamily: mono,
    fontSize: 13.5,
    letterSpacing: 2,
    fontWeight: 700,
    cursor: "pointer",
    boxShadow: "0 0 22px rgba(78,201,176,0.16)",
  },
  phase: { marginTop: 10, fontSize: 11.5, color: "#7fa399" },
  errBox: {
    marginTop: 10,
    padding: 10,
    border: "1px solid #5a2a2a",
    background: "rgba(40,12,12,0.5)",
    color: "#e0a0a0",
    fontSize: 11.5,
    borderRadius: 4,
  },
  legend: {
    marginTop: 16,
    border: "1px solid #1c2e29",
    borderRadius: 4,
    padding: 12,
    background: "rgba(10,18,16,0.4)",
  },
  legendTitle: { fontSize: 9.5, letterSpacing: 1.5, color: "#5f7269", marginBottom: 9 },
  legendWrap: { display: "flex", flexWrap: "wrap", gap: 5 },
  legendBadge: { fontSize: 9, border: "1px solid", borderRadius: 2, padding: "2px 6px" },
  results: { marginTop: 6 },
  verdict: {
    background: "rgba(78,201,176,0.07)",
    border: "1px solid #2a4640",
    borderLeft: "3px solid #4ec9b0",
    padding: "15px 17px",
    borderRadius: 4,
    fontSize: 14.5,
    lineHeight: 1.6,
    color: "#e8f1ee",
    marginBottom: 18,
  },
  vMark: { color: "#4ec9b0", marginRight: 6 },
  tabs: { display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 18 },
  tab: {
    background: "rgba(10,20,17,0.6)",
    border: "1px solid #243a34",
    color: "#8a9a94",
    borderRadius: 3,
    padding: "8px 13px",
    fontSize: 11,
    letterSpacing: 1,
    fontFamily: mono,
    cursor: "pointer",
  },
  tabActive: { background: "#16302a", borderColor: "#4ec9b0", color: "#4ec9b0" },
  subLabel: { fontSize: 10.5, letterSpacing: 2, color: "#5f7269", margin: "16px 0 10px" },
  pulseIntro: {
    fontSize: 12.5,
    color: "#aebab5",
    lineHeight: 1.6,
    background: "rgba(224,108,117,0.06)",
    border: "1px solid #4a2d30",
    borderRadius: 4,
    padding: "12px 14px",
    marginBottom: 6,
  },
  pulseRow: {
    border: "1px solid #1c2e29",
    borderRadius: 4,
    padding: "12px 14px",
    marginBottom: 9,
    background: "rgba(12,22,19,0.4)",
  },
  pulseTop: { display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" },
  pulseDir: {
    fontSize: 10.5,
    border: "1px solid",
    borderRadius: 2,
    padding: "2px 8px",
    letterSpacing: 1,
    fontWeight: 700,
    flex: "0 0 auto",
  },
  pulseQuery: { fontSize: 13, color: "#e8f1ee", flex: 1 },
  pulseMeaning: { fontSize: 12.5, color: "#aebab5", marginTop: 8, lineHeight: 1.5 },
  pulseFoot: { display: "flex", gap: 14, marginTop: 8, flexWrap: "wrap" },
  pulseWindow: { fontSize: 11, color: "#6f8079" },
  pulseRel: { fontSize: 11, color: "#61afef" },
  apiNote: {
    marginTop: 14,
    fontSize: 10.5,
    color: "#5f7269",
    lineHeight: 1.5,
    border: "1px dashed #1c2e29",
    borderRadius: 4,
    padding: "10px 12px",
    fontStyle: "italic",
  },
  lgRow: {
    border: "1px solid #1c2e29",
    borderRadius: 4,
    padding: "12px 14px",
    marginBottom: 9,
    background: "rgba(12,22,19,0.4)",
  },
  lgSignal: { fontSize: 12.5, color: "#dcb67a", lineHeight: 1.5 },
  lgArrowYou: { color: "#dcb67a", fontWeight: 700, marginRight: 4 },
  lgWorld: { fontSize: 12.5, color: "#9fb4cf", lineHeight: 1.5, marginTop: 6 },
  lgArrowWorld: { color: "#61afef", fontWeight: 700, marginRight: 4 },
  lgFoot: { display: "flex", alignItems: "center", gap: 8, marginTop: 9 },
  reading: {
    fontSize: 10.5,
    border: "1px solid",
    borderRadius: 2,
    padding: "2px 8px",
    letterSpacing: 0.5,
  },
  row: { display: "flex", alignItems: "center", gap: 9, flexWrap: "wrap" },
  patCard: {
    border: "1px solid #1c2e29",
    borderRadius: 4,
    padding: "11px 13px",
    marginBottom: 8,
    background: "rgba(12,22,19,0.4)",
  },
  patTitle: { fontSize: 13, color: "#dcb67a", fontWeight: 700, flex: 1 },
  obs: { fontSize: 12.5, color: "#aebab5", marginTop: 6, lineHeight: 1.5 },
  anomaly: {
    display: "flex",
    alignItems: "center",
    border: "1px solid #5a4520",
    background: "rgba(40,30,8,0.4)",
    borderRadius: 4,
    padding: "9px 12px",
    marginBottom: 6,
    fontSize: 12.5,
    color: "#e5c07b",
    lineHeight: 1.5,
  },
  hypCard: {
    border: "1px solid #243a34",
    borderRadius: 4,
    padding: "13px 15px",
    marginBottom: 10,
    background: "rgba(14,26,23,0.45)",
  },
  hypClaim: { fontSize: 13.5, color: "#cdc0e6", fontWeight: 700, flex: 1 },
  hypGrid: { display: "flex", gap: 12 },
  hypCol: {
    flex: 1,
    border: "1px solid #1c2e29",
    borderRadius: 3,
    padding: 9,
    background: "rgba(8,14,12,0.4)",
  },
  hypColLabel: { fontSize: 10, color: "#4ec9b0", marginBottom: 5, letterSpacing: 1 },
  hypColText: { fontSize: 11.5, color: "#aebab5", lineHeight: 1.45 },
  check: {
    marginTop: 10,
    fontSize: 12,
    color: "#d8e0dd",
    lineHeight: 1.5,
    background: "rgba(78,201,176,0.06)",
    border: "1px solid #1c2e29",
    borderRadius: 3,
    padding: "8px 10px",
  },
  checkMark: { color: "#4ec9b0", fontWeight: 700 },
  blindPanel: {
    border: "1px solid #3a2f52",
    borderLeft: "3px solid #9d7cd8",
    borderRadius: 4,
    padding: 16,
    background: "rgba(20,14,34,0.4)",
  },
  blindTitle: {
    fontSize: 12,
    letterSpacing: 2,
    color: "#c8b8e6",
    marginBottom: 14,
    fontWeight: 700,
  },
  blindRow: { padding: "9px 0", borderBottom: "1px dashed #2a2340" },
  blindText: { fontSize: 12.5, color: "#d8d0e8", lineHeight: 1.5 },
  blindWhy: { fontSize: 11, color: "#8678a8", marginTop: 3 },
  actionRow: {
    display: "flex",
    gap: 12,
    alignItems: "flex-start",
    border: "1px solid #1c2e29",
    borderRadius: 4,
    padding: "10px 13px",
    marginBottom: 7,
    fontSize: 12.5,
    color: "#c4d0cc",
    lineHeight: 1.5,
  },
  actionNum: { color: "#4ec9b0", flex: "0 0 auto" },
  badge: { fontSize: 9.5, border: "1px solid", borderRadius: 2, padding: "2px 6px", flex: "0 0 auto" },
  confWrap: { marginLeft: "auto", display: "flex", alignItems: "center", gap: 6, flex: "0 0 auto" },
  confTrack: { width: 50, height: 5, background: "#16221f", borderRadius: 3, overflow: "hidden" },
  confFill: { display: "block", height: "100%" },
  confPct: { fontSize: 10, color: "#7f8c88" },
  empty: { color: "#5a6b65", fontSize: 13, padding: 20, textAlign: "center" },
  sources: {
    marginTop: 18,
    paddingTop: 12,
    borderTop: "1px dashed #1c2e29",
    fontSize: 11,
    color: "#7f8c88",
    lineHeight: 1.5,
  },
  srcLabel: { color: "#4ec9b0" },
  footer: {
    marginTop: 34,
    paddingTop: 16,
    borderTop: "1px solid #1c2e29",
    fontSize: 11,
    color: "#566661",
    fontStyle: "italic",
    textAlign: "center",
  },
};
