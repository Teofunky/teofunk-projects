"""Драйвер ATELIER Studio: открывает автономный ATELIER-Studio.html в headless Chrome
и рендерит проект .atelier2 его собственным движком (функции paint и browserMovie).

Пиксели считает сама программа, поэтому результат совпадает с тем, что увидит Teofunk
в студии на Mac.
"""
import asyncio
import base64
import json
import os
import time
import zipfile
from pathlib import Path

from playwright.async_api import async_playwright

HTML = os.environ.get("ATELIER_HTML", "/home/claude/atl/ATELIER-Studio.html")
CHROME = os.environ.get("ATELIER_CHROME", "/usr/bin/google-chrome")
ARGS = ["--no-sandbox", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist"]
CHUNK = 4_000_000


def project_name(path):
    with zipfile.ZipFile(path) as z:
        return json.loads(z.read("manifest.json"))["project"]["name"]


class Studio:
    def __init__(self, html=HTML):
        self.html = Path(html).resolve()
        self.errors = []

    async def __aenter__(self):
        if not self.html.exists():
            raise FileNotFoundError(f"Нет {self.html}: скопируй ATELIER-Studio.html с Mac или задай ATELIER_HTML")
        self._pw = await async_playwright().start()
        self.browser = await self._pw.chromium.launch(executable_path=CHROME, args=ARGS)
        self.page = await self.browser.new_page(viewport={"width": 1470, "height": 900})
        self.page.on("pageerror", lambda e: self.errors.append(str(e)))
        self.page.on("console", lambda m: self.errors.append("console: " + m.text) if m.type == "error" else None)
        await self.page.goto(self.html.as_uri())
        await self.page.evaluate("atelierReady")
        return self

    async def __aexit__(self, *exc):
        await self.browser.close()
        await self._pw.stop()

    async def load(self, project, timeout=60):
        """Открывает .atelier2 через штатное поле «Открыть файл проекта»."""
        name = project_name(project)
        await self.page.set_input_files("#projectFile", str(project))
        t = time.time()
        while time.time() - t < timeout:
            st = await self.page.evaluate("[typeof film!=='undefined'&&film?film.name:'', document.getElementById('msg')?.textContent||'']")
            if st[0] == name:
                await asyncio.sleep(0.3)
                return await self.info()
            if "error" in (await self.page.evaluate("document.getElementById('msg')?.className||''")):
                raise RuntimeError("ATELIER не открыл проект: " + st[1])
            await asyncio.sleep(0.2)
        raise TimeoutError("Проект не открылся за %d с. Сообщение студии: %s" % (timeout, st[1]))

    async def info(self):
        return await self.page.evaluate(
            "({name:film.name, ratio:film.ratio, frames:total(film), audio:film.audio,"
            " scenes:film.scenes.map(s=>({id:s.id,name:s.name,frames:s.frames,exposure:s.exposure||1,"
            " parts:currentTake(s).layers.length, tiers:s.stand?.layers.length||0}))})")

    async def size(self, height=1080):
        return await self.page.evaluate(f"dimensions(film.ratio,{int(height)})")

    async def frame_png(self, frame, height=1080, what="mix"):
        """Один кадр (глобальный номер) как PNG без потерь."""
        b64 = await self.page.evaluate(
            """async ([f,h,what])=>{const [w,hh]=dimensions(film.ratio,h);
            if(!window.__cv||window.__cv.width!==w||window.__cv.height!==hh)window.__cv=newCanvas(w,hh);
            await paint(film,f,window.__cv,what);
            const c=document.createElement('canvas');c.width=w;c.height=hh;c.getContext('2d').drawImage(window.__cv,0,0);
            return c.toDataURL('image/png').split(',')[1]}""", [int(frame), int(height), what])
        return base64.b64decode(b64)

    async def frames(self, frames, out_dir, height=1080, what="mix", prefix="f"):
        out = Path(out_dir)
        out.mkdir(parents=True, exist_ok=True)
        paths = []
        for f in frames:
            p = out / f"{prefix}{int(f):05d}.png"
            p.write_bytes(await self.frame_png(f, height, what))
            paths.append(p)
        return paths

    async def patch(self, js):
        """Меняет открытый фильм (например, для проверочной копии). js получает film."""
        await self.page.evaluate(f"(()=>{{const film_=film; ({js})(film_); clearArtCaches?.()}})()")

    async def movie(self, out, height=1080, first=0, count=None, log=print):
        """Весь фильм или отрезок в MP4 штатным браузерным экспортом ATELIER (H.264 + AAC)."""
        n = count or (await self.info())["frames"] - first
        await self.page.evaluate(
            """([h,first,n])=>{window.__prog=0;window.__out=null;window.__err=null;(async()=>{try{
            const [w,hh]=dimensions(film.ratio,h);const c=newCanvas(w,hh);
            const blob=await browserMovie(copy(film),c,first,n,(i,v,a)=>{window.__prog=i;window.__codec=[v,a||'']});
            const buf=new Uint8Array(await blob.arrayBuffer());let s='';
            for(let i=0;i<buf.length;i+=32768)s+=String.fromCharCode.apply(null,buf.subarray(i,i+32768));
            window.__out=btoa(s)}catch(e){window.__err=String(e)}})()}""", [int(height), int(first), int(n)])
        t = time.time()
        while True:
            await asyncio.sleep(5)
            prog, done, err, codec = await self.page.evaluate("[window.__prog,!!window.__out,window.__err||'',window.__codec||[]]")
            log(f"  кадр {prog}/{n} {codec} {time.time() - t:.0f} с")
            if err:
                raise RuntimeError("Экспорт ATELIER: " + err)
            if done:
                break
        size = await self.page.evaluate("window.__out.length")
        parts = [await self.page.evaluate(f"window.__out.slice({i},{i + CHUNK})") for i in range(0, size, CHUNK)]
        Path(out).write_bytes(base64.b64decode("".join(parts)))
        await self.page.evaluate("window.__out=null")
        return Path(out)
