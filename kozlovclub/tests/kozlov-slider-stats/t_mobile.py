from harness import *
async def swipe(pg, sel, dx):
    box = await pg.eval_on_selector(sel, "e => { const r = e.getBoundingClientRect(); return [r.x + r.width*0.7, r.y + r.height/2]; }")
    cdp = await pg.context.new_cdp_session(pg)
    x, y = box
    await cdp.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": [{"x": x, "y": y}]})
    for i in range(1, 11):
        await cdp.send("Input.dispatchTouchEvent", {"type": "touchMove", "touchPoints": [{"x": x + dx*i/10, "y": y + 2}]})
        await pg.wait_for_timeout(16)
    await cdp.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})

async def run(p, url, hero_n=None, label=""):
    b = await p.chromium.launch(executable_path="/usr/bin/google-chrome", args=["--no-sandbox"])
    ctx = await b.new_context(**p.devices["iPhone 13"], locale="ru-RU")
    await ctx.route("**/*", make_router({"v": "1.0.0", "counter": int(DUMMY), "home": True, "next": True, "silent": False}))
    if hero_n is not None:
        await ctx.add_init_script(f"try{{localStorage.setItem('kzf_hero_n','{hero_n}')}}catch(e){{}}")
    pg = await ctx.new_page()
    errs = []
    pg.on("pageerror", lambda e: errs.append(str(e)))
    await pg.goto(url, wait_until="load", timeout=60000)
    await pg.evaluate(NO_NAV_JS)
    await pg.wait_for_timeout(6000)
    print(f"== {label}: html class =", await pg.evaluate("document.documentElement.className"))
    print("1) after 6 s:"); show(await pg.evaluate(CALLS_JS))
    kzf = await pg.evaluate("document.documentElement.classList.contains('kzf-on')")
    if kzf:
        await swipe(pg, "#kzf-root .kzf-track", -220)
        await pg.wait_for_timeout(1800)
        print("2) swipe on cards:"); show((await pg.evaluate(CALLS_JS))[-2:])
        i = await pg.evaluate("(() => { const t = document.querySelector('#kzf-root .kzf-track'); return Math.round(t.scrollLeft / t.clientWidth); })()")
        btn = f"#kzf-root .kzf-slide:nth-child({i+1}) .kzf-buy[tc-event]"
        if await pg.query_selector(btn):
            await pg.tap(btn)
            await pg.wait_for_timeout(400)
            print("3) tap buy:"); show((await pg.evaluate(CALLS_JS))[-2:])
        else:
            print("3) current card has no TC button, index", i)
        await pg.wait_for_timeout(1600)
        await pg.tap(f"#kzf-root .kzf-slide:nth-child({i+1}) .kzf-title a")
        await pg.wait_for_timeout(300)
        print("4) tap title link:"); show((await pg.evaluate(CALLS_JS))[-1:])
    else:
        await swipe(pg, ".slider .slides", -200)
        await pg.wait_for_timeout(1800)
        print("2) swipe owl:"); show((await pg.evaluate(CALLS_JS))[-2:])
        await pg.tap(".slider .owl-item.active .slide .thumbnail a")
        await pg.wait_for_timeout(300)
        print("3) tap picture:"); show((await pg.evaluate(CALLS_JS))[-1:])
    allc = await pg.evaluate(CALLS_JS)
    from collections import Counter
    print("goal counts:", dict(Counter(c["goal"] for c in allc if c["goal"])))
    print("page errors:", errs[:5])
    await b.close()

async def main():
    async with async_playwright() as p:
        await run(p, "https://kozlovclub.ru/", "0", "facade")
        await run(p, "https://kozlovclub.ru/", "1", "shadows")
        await run(p, "https://kozlovclub.ru/?kzf=off", None, "phone, facade off")
asyncio.run(main())
