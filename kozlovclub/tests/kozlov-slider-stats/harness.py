"""Live-page test for kzsl.js: the real kozlovclub.ru page is loaded, the plugin's config + script are injected
into the HTML on the fly (nothing is installed on the site). The Metrika counter number in the page is replaced by
a dummy one and every request to mc.yandex.ru, mail.ru, vk.com and the Ticketscloud iframe is blocked, so no
statistics are sent anywhere. Calls to ym() stay in the theme's ym.a queue and are read from there."""
import asyncio, json, re, sys, pathlib
from playwright.async_api import async_playwright

JS = pathlib.Path("/home/claude/teofunk-projects/kozlovclub/plugins/kozlov-slider-stats/assets/kzsl.js").read_text()
DUMMY = "11111111"

def is_blocked(url):
    if "tcwidget.js" in url: return False
    return any(h in url for h in ("mc.yandex", "mail.ru", "vk.com", "ticketscloud", "googletag", "google-analytics", "an.yandex"))

def make_router(cfg, transform=None):
    async def route(r):
        url = r.request.url
        if is_blocked(url):
            return await r.abort()
        if r.request.resource_type == "document" and "kozlovclub.ru" in url:
            resp = await r.fetch()
            body = await resp.text()
            body = body.replace("44419291", DUMMY)
            # the plugin may already be installed on the site: its own config and script are removed, the test copy is injected
            body = re.sub(r'<script type="application/json" id="kzsl-config">.*?</script>\s*', '', body, flags=re.S)
            body = re.sub(r'<script[^>]*kozlov-slider-stats/assets/kzsl\.js[^>]*></script>\s*', '', body)
            inj = '<script type="application/json" id="kzsl-config">' + json.dumps(cfg) + '</script>\n<script>' + JS + '</script>\n'
            body = body.replace("</body>", inj + "</body>", 1)
            if transform: body = transform(body)
            return await r.fulfill(response=resp, body=body, headers={**resp.headers, "content-type": "text/html; charset=UTF-8"})
        await r.continue_()
    return route

CALLS_JS = """() => (window.ym && window.ym.a ? window.ym.a : []).filter(a => a && String(a[1]) !== 'init' && (String(a[2]||'').indexOf('kzsl') === 0 || a[1] === 'params')).map(a => ({m: a[1], goal: a[1]==='reachGoal'? a[2] : null, p: a[1]==='reachGoal' ? a[3] : a[2], id: a[0]}))"""
NO_NAV_JS = """() => { window.addEventListener('click', e => { const a = e.target.closest && e.target.closest('a[href]'); if (a) e.preventDefault(); }, false); }"""

def flat(p):
    out = []
    def walk(x):
        if isinstance(x, dict):
            for k, v in x.items(): out.append(k); walk(v)
        else: out.append(str(x))
    walk(p); return " › ".join(out[1:])

def show(calls):
    for c in calls: print("   ", c["goal"] or "params", "|", flat(c["p"]))
