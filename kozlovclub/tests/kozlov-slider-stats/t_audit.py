"""Scenarios from the outside audit of 1.0.0 (live page, script injected locally, nothing sent to Metrika)."""
from harness import *
import re as _re

CFG = {"v": "1.0.1", "counter": int(DUMMY), "home": True, "next": True, "silent": False}
DESK = {"viewport": {"width": 1440, "height": 900}, "locale": "ru-RU"}

def no_metrika(body):
    # the theme's Metrika snippet (it defines ym() in <head>) is cut out: ym() appears only when the test adds it
    return _re.sub(r"<!-- Yandex\.Metrika counter -->.*?<!-- /Yandex\.Metrika counter -->", "", body, count=1, flags=_re.S)

LATE_YM = """() => { window.ym = function(){ (window.ym.a = window.ym.a || []).push(arguments); }; }"""

async def page(b, cfg=CFG, transform=None, dev=None, url="https://kozlovclub.ru/", p=None):
    ctx = await b.new_context(**(dev or DESK))
    await ctx.route("**/*", make_router(cfg, transform))
    pg = await ctx.new_page()
    errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    await pg.goto(url, wait_until="load", timeout=60000)
    return ctx, pg, errs

async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])

        # 2.1 Metrika code late: events wait in the queue
        ctx, pg, errs = await page(b, transform=no_metrika)
        await pg.wait_for_timeout(3000)
        before = await pg.evaluate("typeof window.ym")
        await pg.evaluate(LATE_YM)
        await pg.wait_for_timeout(1200)
        got = await pg.evaluate(CALLS_JS)
        print("2.1a ym() appears 3 s late: before =", before, "| delivered:", [c["goal"] for c in got], "| errors:", errs)
        await ctx.close()
        ctx, pg, errs = await page(b, transform=no_metrika)
        await pg.wait_for_timeout(23000)
        await pg.evaluate(LATE_YM)
        await pg.wait_for_timeout(1000)
        got = await pg.evaluate(CALLS_JS)
        print("2.1b ym() never during 23 s: queue dropped, delivered later:", [c["goal"] for c in got], "| errors:", errs)
        await ctx.close()

        # 2.2 slider click, then a buy button in the afisha of the SAME home page: nothing
        ctx, pg, errs = await page(b)
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(1500)
        await pg.hover(".slider .slides")
        await pg.click(".slider .owl-item.active .slide .title a")
        await pg.click(".events_wrap .event [tc-event]", force=True)
        await pg.wait_for_timeout(300)
        got = await pg.evaluate(CALLS_JS)
        print("2.2a same page afisha buy after slider click: kzsl_buy_next sent =", any(c["goal"] == "kzsl_buy_next" for c in got))
        # same address, different Ticketscloud id (another date of the same concert page): «другой концерт»
        state = {"cfg": {**CFG, "home": False}}
        await ctx.route("**/*", make_router(state["cfg"]))
        await pg.evaluate("""() => { const r = JSON.parse(sessionStorage.getItem('kzsl_click')); r.p = '/event/nani-eva-skazochnyj-novyj-god'; r.e = 'ffffffffffffffffffffffff'; r.pv = 'old'; sessionStorage.setItem('kzsl_click', JSON.stringify(r)); }""")
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/", wait_until="load", timeout=60000)
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        show(await pg.evaluate(CALLS_JS))
        print("     ^ 2.2b same address, other Ticketscloud id")
        await ctx.close()

        # 2.3 stable id: the same concert gets the same id on desktop and on the phone
        ids = {}
        for name, dev in (("desktop", DESK), ("iphone", {**p.devices["iPhone 13"], "locale": "ru-RU"})):
            ctx, pg, errs = await page(b, dev=dev)
            await pg.wait_for_timeout(14000 if name == "desktop" else 6000)
            if name == "iphone":
                for _ in range(3):
                    await pg.evaluate("(() => { const t = document.querySelector('#kzf-root .kzf-track'); t.scrollBy({left: t.clientWidth}); })()")
                    await pg.wait_for_timeout(1600)
            ids[name] = sorted(set(list(c["p"]["Слайдер на главной"].values())[0]["Показ слайда"].__iter__().__next__() for c in await pg.evaluate(CALLS_JS) if c["goal"] == "kzsl_slide_view"))
            await ctx.close()
        print("2.3 slide ids desktop:", ids["desktop"]); print("    slide ids iphone: ", ids["iphone"])
        print("    common:", sorted(set(ids["desktop"]) & set(ids["iphone"])))

        # 2.5 debug: own memory, nothing real; persists to the next page; ?kzsl=stop ends it
        ctx, pg, errs = await page(b, url="https://kozlovclub.ru/?kzsl=debug")
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(1500)
        await pg.hover(".slider .slides")
        await pg.click(".slider .owl-item.active .slide .title a")
        mem = await pg.evaluate("[sessionStorage.getItem('kzsl_click'), !!sessionStorage.getItem('kzsl_click_debug')]")
        print("2.5a debug click: real memory =", mem[0], "| debug memory =", mem[1], "| ym calls =", len(await pg.evaluate(CALLS_JS)))
        await ctx.route("**/*", make_router({**CFG, "home": False}))
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/", wait_until="load", timeout=60000)
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        print("2.5b next page still in debug: panel =", await pg.evaluate("!!document.querySelector('.kzsl-debug')"), "| ym calls =", len(await pg.evaluate(CALLS_JS)),
              "| panel shows:", (await pg.evaluate("(document.querySelector('.kzsl-debug')||{}).innerText||''")).split("\n")[1:2])
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/?kzsl=stop", wait_until="load", timeout=60000)
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        print("2.5c after ?kzsl=stop: panel =", await pg.evaluate("!!document.querySelector('.kzsl-debug')"), "| ym calls =", len(await pg.evaluate(CALLS_JS)), "(no real slider click in this tab)")
        await ctx.close()
        # editor: no memory written
        ctx, pg, errs = await page(b, cfg={**CFG, "silent": True})
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(1500)
        await pg.hover(".slider .slides")
        await pg.click(".slider .owl-item.active .slide .title a")
        print("2.5d editor click: memory =", await pg.evaluate("sessionStorage.getItem('kzsl_click')"), "| ym calls =", len(await pg.evaluate(CALLS_JS)))
        await ctx.close()

        # isTrusted: a click made by a script is ignored
        ctx, pg, errs = await page(b)
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(1500)
        n0 = len(await pg.evaluate(CALLS_JS))
        await pg.evaluate("document.querySelector('.slider .owl-item.active .slide .title a').click()")
        await pg.wait_for_timeout(200)
        print("isTrusted: script click adds calls:", len(await pg.evaluate(CALLS_JS)) - n0)
        await ctx.close()

        # other page without a slider click in this tab: the script stops at once (no listeners)
        ctx, pg, errs = await page(b, cfg={**CFG, "home": False}, url="https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/")
        await pg.evaluate("""() => sessionStorage.setItem('kzsl_click', JSON.stringify({t: Date.now(), pv: 'x', v: 'Десктоп', id: 'test', p: '/x', e: ''}))""")
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        print("other page, memory added after load: calls =", len(await pg.evaluate(CALLS_JS)), "(listeners were never attached)")
        await ctx.close()

        # IntersectionObserver: slider scrolled away, autoplay goes on, no slide views; back on screen, they resume
        ctx, pg, errs = await page(b)
        await pg.wait_for_timeout(2500)
        n1 = sum(1 for c in await pg.evaluate(CALLS_JS) if c["goal"] == "kzsl_slide_view")
        await pg.evaluate("window.scrollTo(0, 3000)")
        await pg.wait_for_timeout(11000)
        n2 = sum(1 for c in await pg.evaluate(CALLS_JS) if c["goal"] == "kzsl_slide_view")
        await pg.evaluate("window.scrollTo(0, 0)")
        await pg.wait_for_timeout(2000)
        n3 = sum(1 for c in await pg.evaluate(CALLS_JS) if c["goal"] == "kzsl_slide_view")
        print("IO: slide views on screen =", n1, "| after 11 s scrolled away =", n2, "| back on screen =", n3)
        print("page errors:", errs)
        await ctx.close()
        await b.close()
if __name__ == "__main__":
    asyncio.run(main())
