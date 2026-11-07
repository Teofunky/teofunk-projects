---
name: "kozlovclub-site"
description: "Teofunk's hands-on work on the current live WordPress site kozlovclub.ru (cleanup, fixes, Kozlov Smart Search plugin and his requirements for it, the Jazz Navigator widget); read when he returns to this site, its search or the navigator"
---

- Works on the live WordPress site kozlovclub.ru himself; makes replacements, deletions and installs on the site with his own hands
- Wants a new on-site search built as a plugin without breaking the site or its layout
- Decided the search covers upcoming events only, no archive of past events at all
- Plan for the search: working prototype first, he checks it on mobile and desktop, then a full plugin is made and deployed
- Now wants event schema (Event JSON-LD) markup done as a separate isolated plugin that must not break the site (Oct 2026; earlier he had deprioritized it)
- Installed the Kozlov Event Schema plugin (concert-page event markup) on kozlovclub.ru himself (Oct 2026), after having its versions reviewed by an outside reviewer
- Calls the search plugin Kozlov Smart Search; requires it fully isolated from the existing theme and WordPress functionality: no theme file changes, no changes to existing tables, data or the afisha system, first version read-only
- Plugin requirements: CSS classes prefixed `kss-`, no global CSS selectors, Shadow DOM to be considered, JS without globals or jQuery, REST only under `/kozlov-search/v1/`, non-blocking assets, cached index, no SQL per keystroke
- Plugin requirements: any plugin error must leave the site and afisha working, global Enable/Disable in admin, no WordPress/theme/third-party plugin updates as a condition of install
- Dropped his earlier "test on staging first" requirement: decided to go straight to the full plugin without staging
- Wants the search to also look in the announcement texts when a word (e.g. «фанк») is not a genre on the site
- After an outside technical review of the plugin, decided: four fixes (rebuild locking, Ticketscloud purchase integration, password-protected events, real header check), then launch
- Decided the English version of the site is not needed for now; disabled English in WPGlobus himself (Oct 2026)
- The programmer who built the Jazz Navigator (Джаз-навигатор) widget on kozlovclub.ru quit and left; he has no idea where the widget's source project is (Oct 2026)
- Decided the Jazz Navigator on kozlovclub.ru should work without AI (rule-based concert picking); does not want margin-based priority in it; would like its mood-to-genre matching taken from Kozlov Smart Search (Oct 2026)
- Wants a public MCP server for the club so AI agents (ChatGPT, Claude) can recommend Kozlov Club concerts to users, aiming to raise ticket conversion via AI (Oct 2026)
- Planning SEO-optimized artist pages on kozlovclub.ru for a list of 20 artists (list still being chosen), with upcoming events added automatically (ideally via Kozlov Smart Search) and artist subscription, possibly via Telegram (Oct 2026)
- Says three plugins for kozlovclub.ru have been built together with Claude, and three bots are currently on pause (Oct 2026)
- For new kozlovclub.ru pages wants the site's own aesthetic kept (not a black/dark theme, not a literal copy of existing page markup) with everything else modern and stylish; sees no need for a slider on artist pages (Oct 2026)
- Dislikes oversized or "колхозно" UI details and wasted space on kozlovclub.ru; decided the home page SEO H1 («Джаз-клуб Алексея Козлова: афиша концертов в Москве») should not be visible to users, only kept in the code for search engines (Oct 2026)
- Artist pages: first a full pilot page for Большой джазовый оркестр Петра Востокова (БДО), he will send photos, videos and texts for the other artists later; wants maximum automation so the editor's work is minimal, and expects a strong mobile UI/UX (Oct 2026)

FINAL WRITE
- Wants a TodayTix-style filter panel (Сегодня/Завтра/Выходные, calendar, price, genre, stage) added to kozlovclub.ru on top of Kozlov Smart Search, convenient on mobile; went ahead with a prototype first (Oct 2026)
- In the club's afisha, midnight concerts are entered with time 23:59, because 00:00 would put them on the next day
- Wants the filter panel placed on the home page directly under the slider (his own mockups: one row on desktop, 4+3 button grid on mobile), compact and clearly showing which buttons open dropdowns; rules: show the selected value on the button plus a visible «Сбросить», and the «Концерты в октябре» heading changes with the selected dates (Oct 2026)
- Decided artist pages on kozlovclub.ru are built as a separate new plugin "Kozlov Artists" (Oct 2026); the "you may also like" block on them should be picked automatically by artist tags
- Wants concert/event pages on kozlovclub.ru reworked for mobile to raise ticket conversion and convenience, more modern but keeping the site's overall aesthetic; started with a mobile prototype of one event page (Oct 2026); wants it built as a plugin that uses the existing announcement media without changing or adding photos
- For the new event-page plugin decided: the new version shows a photo the editor uploads manually while the old page keeps showing the old banner; the plugin gets a separate optional "program name" field; desktop version gets redesigned too; no purchase-states screen needed; for deposit evenings, for now just a «Забронировать стол» button plus the phone number (Oct 2026)
- Event-page plugin rollout: no A/B test (he considers the new page clearly better); announcements without a newly uploaded hero photo stay in the old layout, uploading the hero photo switches that event to the new layout at once, plus a global switch while editors upload photos; wants a photo-size hint and a convenient admin (Oct 2026)
- In the afisha calendar (All-in-One Event Calendar), editors must pick the empty line under «UTC» as an event's timezone for its time to show correctly on the site; wants that, and the Ticketscloud ID field, filled automatically for editors (Oct 2026)
