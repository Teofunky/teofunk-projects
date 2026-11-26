"""The plugin as installed on kozlovclub.ru: nothing is injected. Requests to Metrika, mail.ru, vk.com and the
Ticketscloud iframe are blocked, so tag.js never loads and ym() calls stay in the theme's ym.a queue, read from there."""
from harness import *
from t_mobile import swipe

async def router(r):
    if is_blocked(r.request.url): return await r.abort()
    await r.continue_()

async def open_page(b, dev, url, hero_n=None):
    ctx = await b.new_context(**dev)
    await ctx.route("**/*", router)
    if hero_n is not None:
        await ctx.add_init_script(f"try{{localStorage.setItem('kzf_hero_n','{hero_n}')}}catch(e){{}}")
    pg = await ctx.new_page()
    errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    await pg.goto(url, wait_until="load", timeout=60000)
    return ctx, pg, errs

async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
        DESK = {"viewport": {"width": 1440, "height": 900}, "locale": "ru-RU"}
        PHONE = {**p.devices["iPhone 13"], "locale": "ru-RU"}

        # desktop: view, slide views, arrow, buy in slider, then the concert page
        ctx, pg, errs = await open_page(b, DESK, "https://kozlovclub.ru/")
        await pg.evaluate(NO_NAV_JS)
        print("config:", await pg.evaluate("document.getElementById('kzsl-config').textContent"))
        await pg.wait_for_timeout(7000)
        await pg.hover(".slider .slides")
        await pg.click(".slider .owl-nav .owl-next")
        await pg.wait_for_timeout(1500)
        await pg.click(".slider .titles .nav[data-pos='1']")
        await pg.wait_for_timeout(1500)
        btn = await pg.query_selector(".slider .owl-item.active .slide .btn-widget button[tc-event]")
        if btn: await btn.click(force=True)
        await pg.wait_for_timeout(300)
        print("== desktop home"); show(await pg.evaluate(CALLS_JS))
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/", wait_until="load", timeout=60000)
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        print("== desktop concert page"); show(await pg.evaluate(CALLS_JS))
        print("errors:", errs); await ctx.close()

        for hero, name in (("0", "facade"), ("1", "shadows")):
            ctx, pg, errs = await open_page(b, PHONE, "https://kozlovclub.ru/", hero)
            await pg.evaluate(NO_NAV_JS)
            await pg.wait_for_timeout(6000)
            await swipe(pg, "#kzf-root .kzf-track", -220)
            await pg.wait_for_timeout(1600)
            i = await pg.evaluate("(() => { const t = document.querySelector('#kzf-root .kzf-track'); return Math.round(t.scrollLeft / t.clientWidth); })()")
            sel = f"#kzf-root .kzf-slide:nth-child({i+1}) .kzf-buy[tc-event]"
            if await pg.query_selector(sel): await pg.tap(sel)
            await pg.wait_for_timeout(300)
            print(f"== iPhone {name}"); show(await pg.evaluate(CALLS_JS))
            print("errors:", errs); await ctx.close()

        # check mode on the installed site: panel, no ym() calls
        ctx, pg, errs = await open_page(b, PHONE, "https://kozlovclub.ru/?kzsl=debug", "0")
        await pg.wait_for_timeout(6000)
        print("== iPhone ?kzsl=debug: ym calls =", len(await pg.evaluate(CALLS_JS)))
        print(await pg.evaluate("(document.querySelector('.kzsl-debug')||{}).innerText"))
        await pg.screenshot(path="/home/claude/work/debug_iphone.png")
        print("errors:", errs); await ctx.close()
        await b.close()
if __name__ == "__main__":
    asyncio.run(main())
