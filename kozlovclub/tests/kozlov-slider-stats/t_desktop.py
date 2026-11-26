from harness import *
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
        ctx = await b.new_context(viewport={"width": 1440, "height": 900}, locale="ru-RU")
        await ctx.route("**/*", make_router({"v": "1.0.1", "counter": int(DUMMY), "home": True, "next": True, "silent": False}))
        pg = await ctx.new_page()
        errs = []
        pg.on("pageerror", lambda e: errs.append(str(e)))
        await pg.goto("https://kozlovclub.ru/", wait_until="load", timeout=60000)
        await pg.evaluate(NO_NAV_JS)
        await pg.wait_for_timeout(7500)
        print("1) after 7.5 s on screen (autoplay):"); show(await pg.evaluate(CALLS_JS))
        await pg.hover(".slider .slides")  # autoplay pauses on hover
        await pg.click(".slider .owl-nav .owl-next")
        await pg.wait_for_timeout(1600)
        print("2) arrow click:"); show((await pg.evaluate(CALLS_JS))[-2:])
        # click the title of the active slide
        await pg.click(".slider .owl-item.active .slide .title a")
        print("3) title click:"); show((await pg.evaluate(CALLS_JS))[-1:])
        await pg.wait_for_timeout(1600)
        btn = await pg.query_selector(".slider .owl-item.active .slide .btn-widget button[tc-event]")
        if btn:
            await btn.click(force=True)
            await pg.wait_for_timeout(300)
            print("4) buy button click:"); show((await pg.evaluate(CALLS_JS))[-2:])
        else:
            print("4) active slide has no buy button")
        print("sessionStorage:", await pg.evaluate("sessionStorage.getItem('kzsl_click')"))
        # tab click (titles nav) should not send a second swipe
        await pg.click(".slider .titles .nav[data-pos='0']")
        await pg.wait_for_timeout(300)
        allc = await pg.evaluate(CALLS_JS)
        print("5) swipe goals total:", sum(1 for c in allc if c["goal"] == "kzsl_swipe"), "| view goals:", sum(1 for c in allc if c["goal"] == "kzsl_view"),
              "| methods:", [flat(c["p"]) for c in allc if "Листание" in flat(c["p"])])
        print("page errors:", errs[:5])
        await b.close()
asyncio.run(main())
