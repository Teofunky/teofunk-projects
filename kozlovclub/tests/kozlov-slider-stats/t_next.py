from harness import *
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
        # A. slider buy on desktop, then the concert page, then its buy button
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        cfg = {"v": "1.0.1", "counter": int(DUMMY), "home": True, "next": True, "silent": False}
        state = {"cfg": cfg}
        async def route(r):
            return await make_router(state["cfg"])(r)
        await ctx.route("**/*", route)
        pg = await ctx.new_page()
        await pg.goto("https://kozlovclub.ru/", wait_until="load", timeout=60000)
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(1500)
        await pg.hover(".slider .slides")
        await pg.click(".slider .titles .nav[data-pos='1']")
        await pg.wait_for_timeout(1200)
        await pg.click(".slider .owl-item.active .slide .btn-widget button[tc-event]", force=True)
        await pg.wait_for_timeout(300)
        print("A1) desktop buy in slider:"); show((await pg.evaluate(CALLS_JS))[-2:])
        rec = json.loads(await pg.evaluate("sessionStorage.getItem('kzsl_click')"))
        print("    stored:", rec)
        state["cfg"] = {**cfg, "home": False}
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/", wait_until="load", timeout=60000)
        await pg.wait_for_timeout(800)
        n = await pg.evaluate("document.querySelectorAll('[tc-event]').length")
        print("    buttons with tc-event on concert page:", n)
        await pg.click("[tc-event]", force=True)
        await pg.wait_for_timeout(300)
        print("A2) buy on concert page:"); show(await pg.evaluate(CALLS_JS))
        # another concert page: 'другой концерт'
        await pg.goto("https://kozlovclub.ru/event/algorithm-kamerun-rossiya-9/", wait_until="load", timeout=60000)
        await pg.wait_for_timeout(800)
        await pg.click("[tc-event]", force=True)
        await pg.wait_for_timeout(300)
        print("A3) buy on another concert page:"); show(await pg.evaluate(CALLS_JS))
        await ctx.close()

        # B. no slider click in this tab: a buy on a concert page sends nothing
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        await ctx.route("**/*", make_router({**cfg, "home": False}))
        pg = await ctx.new_page()
        await pg.goto("https://kozlovclub.ru/event/nani-eva-skazochnyj-novyj-god/", wait_until="load", timeout=60000)
        await pg.click("[tc-event]", force=True); await pg.wait_for_timeout(300)
        print("B) concert page without a slider click, calls:", len(await pg.evaluate(CALLS_JS)))
        await ctx.close()

        # C. debug mode: panel shown, nothing to ym
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        await ctx.route("**/*", make_router(cfg))
        pg = await ctx.new_page()
        await pg.goto("https://kozlovclub.ru/?kzsl=debug", wait_until="load", timeout=60000)
        await pg.wait_for_timeout(3500)
        print("C) debug: ym calls =", len(await pg.evaluate(CALLS_JS)), "| panel:", await pg.evaluate("(document.querySelector('.kzsl-debug')||{}).innerText"))
        await pg.screenshot(path="debug_desktop.png")
        await ctx.close()

        # D. silent (logged-in editor): nothing to ym
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        await ctx.route("**/*", make_router({**cfg, "silent": True}))
        pg = await ctx.new_page()
        await pg.goto("https://kozlovclub.ru/", wait_until="load", timeout=60000)
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(2500)
        await pg.click(".slider .owl-item.active .slide .title a")
        print("D) silent: ym calls =", len(await pg.evaluate(CALLS_JS)))
        await ctx.close()

        # E. filtered home (slider not printed): no errors, nothing sent
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        await ctx.route("**/*", make_router(cfg))
        pg = await ctx.new_page()
        errs = []; pg.on("pageerror", lambda e: errs.append(str(e)))
        await pg.goto("https://kozlovclub.ru/?genre=jazz", wait_until="load", timeout=60000)
        await pg.wait_for_timeout(2500)
        print("E) filtered home: slider present =", await pg.evaluate("!!document.querySelector('.content_home .slider-wrapper .slider')"), "| calls =", len(await pg.evaluate(CALLS_JS)), "| errors:", errs[:3])
        await b.close()
if __name__ == "__main__":
    asyncio.run(main())
