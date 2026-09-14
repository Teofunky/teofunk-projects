from __future__ import annotations

import json, os, re, sqlite3, subprocess, threading, time, uuid, shutil, math
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

BASE = Path(__file__).resolve().parent
DATA = BASE / 'data'
VIDEOS, THUMBS, TABS = DATA/'videos', DATA/'thumbs', DATA/'tabs'
DB = DATA/'reel_practice.sqlite3'
SETTINGS_FILE = DATA/'settings.json'
STATIC = BASE/'static'
for p in (DATA, VIDEOS, THUMBS, TABS): p.mkdir(parents=True, exist_ok=True)

app = FastAPI(title='Reel Practice', version='2.0.0')

# ---------- DB ----------
def con():
    c=sqlite3.connect(DB, check_same_thread=False)
    c.row_factory=sqlite3.Row
    return c

def init_db():
    c=con()
    c.executescript('''
    CREATE TABLE IF NOT EXISTS lessons(
      id TEXT PRIMARY KEY,
      title TEXT NOT NULL,
      author TEXT,
      source_url TEXT,
      source_type TEXT NOT NULL DEFAULT 'upload',
      status TEXT NOT NULL DEFAULT 'ready',
      video_file TEXT,
      thumb_file TEXT,
      duration REAL,
      width INTEGER,
      height INTEGER,
      tags TEXT NOT NULL DEFAULT '[]',
      note TEXT,
      error TEXT,
      deleted INTEGER NOT NULL DEFAULT 0,
      analysis_status TEXT NOT NULL DEFAULT 'idle',
      analysis_progress REAL NOT NULL DEFAULT 0,
      has_tab INTEGER NOT NULL DEFAULT 0,
      created_at REAL NOT NULL,
      updated_at REAL NOT NULL
    );
    CREATE TABLE IF NOT EXISTS tab_captures(
      id TEXT PRIMARY KEY,
      lesson_id TEXT NOT NULL,
      image_file TEXT NOT NULL,
      timestamp REAL NOT NULL,
      label TEXT,
      confidence REAL,
      auto_detected INTEGER NOT NULL DEFAULT 0,
      created_at REAL NOT NULL,
      FOREIGN KEY(lesson_id) REFERENCES lessons(id)
    );
    ''')
    # migrations from v1 DB if user drops it in
    cols={r['name'] for r in c.execute('PRAGMA table_info(lessons)').fetchall()}
    for name,sql in {
      'author':'ALTER TABLE lessons ADD COLUMN author TEXT',
      'analysis_status':"ALTER TABLE lessons ADD COLUMN analysis_status TEXT NOT NULL DEFAULT 'idle'",
      'analysis_progress':"ALTER TABLE lessons ADD COLUMN analysis_progress REAL NOT NULL DEFAULT 0",
      'has_tab':"ALTER TABLE lessons ADD COLUMN has_tab INTEGER NOT NULL DEFAULT 0",
    }.items():
        if name not in cols: c.execute(sql)
    tcols={r['name'] for r in c.execute('PRAGMA table_info(tab_captures)').fetchall()}
    for name,sql in {
      'confidence':'ALTER TABLE tab_captures ADD COLUMN confidence REAL',
      'auto_detected':'ALTER TABLE tab_captures ADD COLUMN auto_detected INTEGER NOT NULL DEFAULT 0',
    }.items():
        if name not in tcols: c.execute(sql)
    c.commit(); c.close()
init_db()

def load_settings():
    default={'cookie_browser':'chrome','auto_analyze':True}
    if SETTINGS_FILE.exists():
        try: default.update(json.loads(SETTINGS_FILE.read_text()))
        except Exception: pass
    return default

def save_settings(s): SETTINGS_FILE.write_text(json.dumps(s,indent=2))

# ---------- helpers ----------
def ffprobe(path:Path):
    try:
      raw=subprocess.check_output(['ffprobe','-v','quiet','-print_format','json','-show_format','-show_streams',str(path)],timeout=20)
      d=json.loads(raw); v=next((x for x in d.get('streams',[]) if x.get('codec_type')=='video'),{})
      dur=d.get('format',{}).get('duration') or v.get('duration')
      return {'duration':float(dur) if dur else None,'width':int(v['width']) if v.get('width') else None,'height':int(v['height']) if v.get('height') else None}
    except Exception:return {}

def make_thumb(video:Path,lid:str,at=None):
    m=ffprobe(video); dur=m.get('duration') or 1
    if at is None: at=min(max(dur*.20,.25),max(dur-.25,.25))
    out=THUMBS/f'{lid}.jpg'
    try:
      subprocess.run(['ffmpeg','-loglevel','error','-y','-ss',f'{at:.3f}','-i',str(video),'-frames:v','1','-vf',"scale='min(1000,iw)':-2",'-q:v','2',str(out)],check=True,timeout=30)
      return out.name if out.exists() else None
    except Exception:return None

def row_to_dict(r,c):
    x=dict(r); x['tags']=json.loads(x.get('tags') or '[]')
    x['video_url']=f"/media/videos/{x['video_file']}" if x.get('video_file') else None
    x['thumb_url']=f"/media/thumbs/{x['thumb_file']}" if x.get('thumb_file') else None
    tabs=c.execute('SELECT * FROM tab_captures WHERE lesson_id=? ORDER BY timestamp',(x['id'],)).fetchall()
    x['tabs']=[{**dict(t),'image_url':f"/media/tabs/{t['image_file']}"} for t in tabs]
    return x

def update_lesson(lid,**fields):
    if not fields:return
    fields['updated_at']=time.time(); keys=list(fields)
    c=con(); c.execute(f"UPDATE lessons SET {', '.join(k+'=?' for k in keys)} WHERE id=?",(*[fields[k] for k in keys],lid)); c.commit(); c.close()

def create_lesson(title, source_url=None, source_type='upload', status='ready', **kw):
    lid=str(uuid.uuid4()); now=time.time(); c=con()
    c.execute('''INSERT INTO lessons(id,title,author,source_url,source_type,status,video_file,thumb_file,duration,width,height,error,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)''',(lid,title,kw.get('author'),source_url,source_type,status,kw.get('video_file'),kw.get('thumb_file'),kw.get('duration'),kw.get('width'),kw.get('height'),kw.get('error'),now,now))
    c.commit(); c.close(); return lid

def capture_frame(video:Path,out:Path,t:float):
    subprocess.run(['ffmpeg','-loglevel','error','-y','-ss',f'{max(0,t):.3f}','-i',str(video),'-frames:v','1','-vf',"scale='min(1800,iw)':-2",'-q:v','2',str(out)],check=True,timeout=30)

# ---------- automatic source TAB detector ----------
def tab_score_image(path:Path):
    """Heuristic: find 5-7 long, near-horizontal, regularly spaced lines.
    It only flags source frames; it never invents notes.
    """
    try:
      import cv2, numpy as np
      im=cv2.imread(str(path))
      if im is None:return 0.0
      h,w=im.shape[:2]
      gray=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY)
      gray=cv2.GaussianBlur(gray,(3,3),0)
      edges=cv2.Canny(gray,60,170)
      lines=cv2.HoughLinesP(edges,1,np.pi/180,threshold=max(26,w//12),minLineLength=int(w*.24),maxLineGap=int(w*.06))
      if lines is None:return 0.0
      segs=[]
      for x1,y1,x2,y2 in lines[:,0]:
        dx=abs(int(x2)-int(x1)); dy=abs(int(y2)-int(y1))
        if dx>=w*.24 and dy<=max(3,dx*.028): segs.append(((y1+y2)/2,dx/w))
      if len(segs)<5:return 0.0
      ys=sorted(y for y,_ in segs); clusters=[]
      for y in ys:
        if not clusters or y-clusters[-1][-1]>4: clusters.append([y])
        else: clusters[-1].append(y)
      centers=[sum(g)/len(g) for g in clusters]
      best=0.0
      for n in (6,7,5):
        for i in range(max(0,len(centers)-n+1)):
          seq=centers[i:i+n]
          if len(seq)<5:continue
          ds=np.diff(seq); med=float(np.median(ds))
          if med<5 or med>h*.08:continue
          cv=float(np.std(ds)/(med+1e-6))
          if cv>.36:continue
          cov=[]
          for y in seq:
            lens=[ln for yy,ln in segs if abs(yy-y)<5]; cov.append(max(lens or [0]))
          score=(n/6)*max(0,1-cv)*float(np.mean(cov))
          best=max(best,score)
      return float(best)
    except Exception:return 0.0

def analyze_tabs(lid:str, force=False):
    c=con(); row=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone(); c.close()
    if not row or not row['video_file']:return
    video=VIDEOS/row['video_file']; meta=ffprobe(video); dur=meta.get('duration') or 0
    if dur<=0:return
    update_lesson(lid,analysis_status='running',analysis_progress=0,error=None)
    work=DATA/'_scan'/lid; shutil.rmtree(work,ignore_errors=True); work.mkdir(parents=True,exist_ok=True)
    try:
      if force:
        c=con(); old=c.execute('SELECT * FROM tab_captures WHERE lesson_id=? AND auto_detected=1',(lid,)).fetchall()
        for r in old:(TABS/r['image_file']).unlink(missing_ok=True)
        c.execute('DELETE FROM tab_captures WHERE lesson_id=? AND auto_detected=1',(lid,)); c.commit(); c.close()
      interval=max(1.0,min(2.0,dur/60))
      times=[min(dur-.05,i*interval+.25) for i in range(max(1,int(math.ceil(dur/interval))))]
      candidates=[]
      for idx,t in enumerate(times):
        f=work/f'{idx:04d}.jpg'
        try:capture_frame(video,f,t)
        except Exception:continue
        s=tab_score_image(f)
        if s>=0.48:candidates.append((s,t,f))
        if idx%3==0:update_lesson(lid,analysis_progress=min(.92,(idx+1)/max(1,len(times))))
      # deduplicate by timestamp proximity and image perceptual hash
      try:
        import cv2, numpy as np
        kept=[]; hashes=[]
        for s,t,f in sorted(candidates,key=lambda z:z[1]):
          im=cv2.imread(str(f),cv2.IMREAD_GRAYSCALE)
          small=cv2.resize(im,(16,16)); hsh=(small>small.mean()).flatten()
          duplicate=False
          for (ps,pt,pf),ph in zip(kept,hashes):
            sim=(hsh==ph).mean()
            if abs(t-pt)<3.0 and sim>.82: duplicate=True; break
          if not duplicate:
            kept.append((s,t,f)); hashes.append(hsh)
        # cap to 12 strongest while preserving chronology
        if len(kept)>12: kept=sorted(sorted(kept,key=lambda z:z[0],reverse=True)[:12],key=lambda z:z[1])
      except Exception: kept=candidates[:12]
      c=con(); now=time.time()
      for s,t,f in kept:
        tid=str(uuid.uuid4()); out=TABS/f'{tid}.jpg'; shutil.copy2(f,out)
        c.execute('INSERT INTO tab_captures(id,lesson_id,image_file,timestamp,label,confidence,auto_detected,created_at) VALUES(?,?,?,?,?,?,?,?)',(tid,lid,out.name,t,'Source TAB',round(s,3),1,now))
      c.commit(); c.close()
      update_lesson(lid,analysis_status='done',analysis_progress=1,has_tab=1 if kept else 0)
    except Exception as e:
      update_lesson(lid,analysis_status='error',analysis_progress=0,error=f'Analysis: {e}')
    finally: shutil.rmtree(work,ignore_errors=True)

def maybe_analyze(lid):
    if load_settings().get('auto_analyze',True):
      threading.Thread(target=analyze_tabs,args=(lid,),daemon=True).start()

# ---------- import URL ----------
def try_download_url(lid,url):
    update_lesson(lid,status='importing',error=None)
    template=str(VIDEOS/f'{lid}.%(ext)s')
    settings=load_settings(); browser=settings.get('cookie_browser','').strip()
    cmd=['yt-dlp','--no-playlist','--merge-output-format','mp4','--restrict-filenames','--no-warnings','--write-info-json','-o',template,url]
    if browser and browser!='none': cmd[1:1]=['--cookies-from-browser',browser]
    try:
      p=subprocess.run(cmd,capture_output=True,text=True,timeout=240)
      if p.returncode!=0:
        err=(p.stderr or p.stdout or 'Source did not provide the video').strip()[-1500:]
        update_lesson(lid,status='waiting',error=err);return
      matches=[x for x in VIDEOS.glob(f'{lid}.*') if x.suffix.lower() not in ('.json','.part','.ytdl')]
      path=sorted(matches,key=lambda x:x.stat().st_mtime,reverse=True)[0] if matches else None
      if not path or not path.exists(): update_lesson(lid,status='waiting',error='Download finished without a playable file'); return
      meta=ffprobe(path)
      if not meta.get('width'): update_lesson(lid,status='waiting',error='Downloaded media is not a playable video');return
      infofile=next(iter(VIDEOS.glob(f'{lid}*.info.json')),None)
      title='Imported Reel'; author=None
      if infofile:
        try:
          inf=json.loads(infofile.read_text()); title=inf.get('title') or title; author=inf.get('uploader') or inf.get('channel')
        except Exception: pass
        infofile.unlink(missing_ok=True)
      thumb=make_thumb(path,lid)
      update_lesson(lid,status='ready',video_file=path.name,thumb_file=thumb,duration=meta.get('duration'),width=meta.get('width'),height=meta.get('height'),title=title[:180],author=(author or '')[:120] or None,error=None)
      maybe_analyze(lid)
    except FileNotFoundError:update_lesson(lid,status='waiting',error='yt-dlp is missing. Run install.command.')
    except subprocess.TimeoutExpired:update_lesson(lid,status='waiting',error='Import timed out. You can attach the video file manually.')
    except Exception as e:update_lesson(lid,status='waiting',error=str(e))

# ---------- models ----------
class LinkIn(BaseModel): url:str
class UpdateIn(BaseModel): title:Optional[str]=None; note:Optional[str]=None; tags:Optional[list[str]]=None
class CaptureIn(BaseModel): timestamp:float; label:Optional[str]=None
class SettingsIn(BaseModel): cookie_browser:str='chrome'; auto_analyze:bool=True

# ---------- API ----------
@app.get('/api/health')
def health(): return {'ok':True,'version':'2.0.0','ffmpeg':bool(shutil.which('ffmpeg')),'ytdlp':bool(shutil.which('yt-dlp'))}

@app.get('/api/settings')
def get_settings(): return load_settings()
@app.put('/api/settings')
def put_settings(s:SettingsIn): save_settings(s.model_dump()); return {'ok':True}

@app.get('/api/lessons')
def list_lessons(deleted:bool=False):
    c=con(); rows=c.execute('SELECT * FROM lessons WHERE deleted=? ORDER BY updated_at DESC',(1 if deleted else 0,)).fetchall(); out=[row_to_dict(r,c) for r in rows]; c.close(); return out
@app.get('/api/lessons/{lid}')
def get_lesson(lid:str):
    c=con(); r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone()
    if not r:c.close();raise HTTPException(404)
    x=row_to_dict(r,c);c.close();return x

@app.post('/api/lessons/link')
def import_link(p:LinkIn):
    url=p.url.strip()
    if not re.match(r'^https?://',url):raise HTTPException(400,'Enter a full http(s) URL')
    lid=create_lesson('Instagram Reel' if 'instagram.com' in url else 'Imported link',source_url=url,source_type='link',status='queued')
    threading.Thread(target=try_download_url,args=(lid,url),daemon=True).start();return {'id':lid,'status':'queued'}

async def save_upload(file:UploadFile,target:Path):
    with target.open('wb') as f:
      while True:
        chunk=await file.read(1024*1024)
        if not chunk:break
        f.write(chunk)

@app.post('/api/lessons/upload')
async def upload_video(file:UploadFile=File(...),source_url:Optional[str]=Form(default=None)):
    lid=str(uuid.uuid4()); suffix=Path(file.filename or 'video.mp4').suffix.lower() or '.mp4'; target=VIDEOS/f'{lid}{suffix}'
    await save_upload(file,target); meta=ffprobe(target)
    if not meta.get('width'): target.unlink(missing_ok=True); raise HTTPException(400,'This is not a playable video')
    thumb=make_thumb(target,lid); now=time.time(); c=con(); title=Path(file.filename or 'Video lesson').stem
    c.execute('''INSERT INTO lessons(id,title,source_url,source_type,status,video_file,thumb_file,duration,width,height,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)''',(lid,title,source_url,'upload','ready',target.name,thumb,meta.get('duration'),meta.get('width'),meta.get('height'),now,now));c.commit();c.close();maybe_analyze(lid);return {'id':lid,'status':'ready'}

@app.post('/api/lessons/{lid}/attach')
async def attach_video(lid:str,file:UploadFile=File(...)):
    c=con();r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r:raise HTTPException(404)
    suffix=Path(file.filename or 'video.mp4').suffix.lower() or '.mp4';target=VIDEOS/f'{lid}{suffix}';await save_upload(file,target);meta=ffprobe(target)
    if not meta.get('width'):target.unlink(missing_ok=True);raise HTTPException(400,'This is not a playable video')
    thumb=make_thumb(target,lid);update_lesson(lid,status='ready',video_file=target.name,thumb_file=thumb,duration=meta.get('duration'),width=meta.get('width'),height=meta.get('height'),error=None);maybe_analyze(lid);return {'ok':True}

@app.patch('/api/lessons/{lid}')
def patch_lesson(lid:str,p:UpdateIn):
    f={}
    if p.title is not None:f['title']=p.title.strip() or 'Untitled lesson'
    if p.note is not None:f['note']=p.note
    if p.tags is not None:f['tags']=json.dumps(p.tags)
    update_lesson(lid,**f);return {'ok':True}

@app.post('/api/lessons/{lid}/analyze')
def analyze(lid:str):
    c=con();r=c.execute('SELECT video_file FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r or not r['video_file']:raise HTTPException(400,'Attach a video first')
    threading.Thread(target=analyze_tabs,args=(lid,True),daemon=True).start();return {'ok':True}

@app.post('/api/lessons/{lid}/capture-tab')
def manual_capture(lid:str,p:CaptureIn):
    c=con();r=c.execute('SELECT video_file FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r or not r['video_file']:raise HTTPException(404,'No local video')
    tid=str(uuid.uuid4());out=TABS/f'{tid}.jpg';capture_frame(VIDEOS/r['video_file'],out,p.timestamp);c=con();now=time.time();c.execute('INSERT INTO tab_captures(id,lesson_id,image_file,timestamp,label,confidence,auto_detected,created_at) VALUES(?,?,?,?,?,?,?,?)',(tid,lid,out.name,p.timestamp,p.label or 'Source TAB',1.0,0,now));c.commit();c.close();update_lesson(lid,has_tab=1);return {'ok':True}

@app.delete('/api/tabs/{tid}')
def delete_tab(tid:str):
    c=con();r=c.execute('SELECT * FROM tab_captures WHERE id=?',(tid,)).fetchone()
    if r:(TABS/r['image_file']).unlink(missing_ok=True);c.execute('DELETE FROM tab_captures WHERE id=?',(tid,));c.commit();cnt=c.execute('SELECT COUNT(*) n FROM tab_captures WHERE lesson_id=?',(r['lesson_id'],)).fetchone()['n'];update_lesson(r['lesson_id'],has_tab=1 if cnt else 0)
    c.close();return {'ok':True}

@app.delete('/api/lessons/{lid}')
def delete_lesson(lid:str,hard:bool=False):
    c=con();r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone()
    if not r:c.close();raise HTTPException(404)
    if not hard:c.execute('UPDATE lessons SET deleted=1,updated_at=? WHERE id=?',(time.time(),lid));c.commit();c.close();return {'ok':True}
    tabs=c.execute('SELECT * FROM tab_captures WHERE lesson_id=?',(lid,)).fetchall()
    for t in tabs:(TABS/t['image_file']).unlink(missing_ok=True)
    if r['video_file']:(VIDEOS/r['video_file']).unlink(missing_ok=True)
    if r['thumb_file']:(THUMBS/r['thumb_file']).unlink(missing_ok=True)
    c.execute('DELETE FROM tab_captures WHERE lesson_id=?',(lid,));c.execute('DELETE FROM lessons WHERE id=?',(lid,));c.commit();c.close();return {'ok':True}
@app.post('/api/lessons/{lid}/restore')
def restore(lid:str):update_lesson(lid,deleted=0);return {'ok':True}

@app.get('/media/{kind}/{filename}')
def media(kind:str,filename:str):
    root={'videos':VIDEOS,'thumbs':THUMBS,'tabs':TABS}.get(kind)
    if not root:raise HTTPException(404)
    p=(root/filename).resolve()
    if root.resolve() not in p.parents or not p.exists():raise HTTPException(404)
    return FileResponse(p)

app.mount('/static',StaticFiles(directory=STATIC),name='static')
@app.get('/')
def index():return FileResponse(STATIC/'index.html')
@app.get('/{path:path}')
def spa(path:str):return FileResponse(STATIC/'index.html')
