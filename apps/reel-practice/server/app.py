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
VIDEOS, THUMBS, TABS, SHEETS = DATA/'videos', DATA/'thumbs', DATA/'tabs', DATA/'sheets'
DB = DATA/'reel_practice.sqlite3'
SETTINGS_FILE = DATA/'settings.json'
STATIC = BASE/'static'
for p in (DATA, VIDEOS, THUMBS, TABS, SHEETS): p.mkdir(parents=True, exist_ok=True)

app = FastAPI(title='Reel Practice', version='2.7.0')

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
    CREATE TABLE IF NOT EXISTS practice_sections(
      id TEXT PRIMARY KEY,
      lesson_id TEXT NOT NULL,
      title TEXT NOT NULL,
      start_time REAL NOT NULL,
      end_time REAL NOT NULL,
      created_at REAL NOT NULL,
      FOREIGN KEY(lesson_id) REFERENCES lessons(id)
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
    CREATE TABLE IF NOT EXISTS collections(
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL UNIQUE COLLATE NOCASE,
      created_at REAL NOT NULL
    );
    CREATE TABLE IF NOT EXISTS lesson_collections(
      lesson_id TEXT NOT NULL,
      collection_id TEXT NOT NULL,
      PRIMARY KEY(lesson_id, collection_id),
      FOREIGN KEY(lesson_id) REFERENCES lessons(id),
      FOREIGN KEY(collection_id) REFERENCES collections(id)
    );
    ''')
    # migrations from v1 DB if user drops it in
    cols={r['name'] for r in c.execute('PRAGMA table_info(lessons)').fetchall()}
    for name,sql in {
      'author':'ALTER TABLE lessons ADD COLUMN author TEXT',
      'analysis_status':"ALTER TABLE lessons ADD COLUMN analysis_status TEXT NOT NULL DEFAULT 'idle'",
      'analysis_progress':"ALTER TABLE lessons ADD COLUMN analysis_progress REAL NOT NULL DEFAULT 0",
      'has_tab':"ALTER TABLE lessons ADD COLUMN has_tab INTEGER NOT NULL DEFAULT 0",
      'favorite':"ALTER TABLE lessons ADD COLUMN favorite INTEGER NOT NULL DEFAULT 0",
      'last_practiced':"ALTER TABLE lessons ADD COLUMN last_practiced REAL",
      'auto_tags':"ALTER TABLE lessons ADD COLUMN auto_tags TEXT NOT NULL DEFAULT '[]'",
      'source_description':"ALTER TABLE lessons ADD COLUMN source_description TEXT",
      'content_text':"ALTER TABLE lessons ADD COLUMN content_text TEXT",
      'content_analysis_status':"ALTER TABLE lessons ADD COLUMN content_analysis_status TEXT NOT NULL DEFAULT 'idle'",
      'notation_type':"ALTER TABLE lessons ADD COLUMN notation_type TEXT NOT NULL DEFAULT 'video'",
      'notation_confidence':"ALTER TABLE lessons ADD COLUMN notation_confidence REAL NOT NULL DEFAULT 0",
      'notation_sheet_file':"ALTER TABLE lessons ADD COLUMN notation_sheet_file TEXT",
      'notation_version':"ALTER TABLE lessons ADD COLUMN notation_version INTEGER NOT NULL DEFAULT 0",
    }.items():
        if name not in cols: c.execute(sql)
    tcols={r['name'] for r in c.execute('PRAGMA table_info(tab_captures)').fetchall()}
    for name,sql in {
      'confidence':'ALTER TABLE tab_captures ADD COLUMN confidence REAL',
      'auto_detected':'ALTER TABLE tab_captures ADD COLUMN auto_detected INTEGER NOT NULL DEFAULT 0',
      'kind':"ALTER TABLE tab_captures ADD COLUMN kind TEXT NOT NULL DEFAULT 'tab'",
      'crop_file':"ALTER TABLE tab_captures ADD COLUMN crop_file TEXT",
    }.items():
        if name not in tcols: c.execute(sql)
    c.commit(); c.close()
init_db()

def cleanup_pending_duplicates():
    c=con()
    rows=c.execute("SELECT source_url, GROUP_CONCAT(id) ids, COUNT(*) n FROM lessons WHERE deleted=0 AND source_url IS NOT NULL AND status!='ready' GROUP BY source_url HAVING COUNT(*)>1").fetchall()
    for r in rows:
        ids=r['ids'].split(',')
        keep=c.execute("SELECT id FROM lessons WHERE id IN (%s) ORDER BY updated_at DESC LIMIT 1" % ','.join('?'*len(ids)),ids).fetchone()['id']
        for lid in ids:
            if lid!=keep: c.execute('DELETE FROM lessons WHERE id=?',(lid,))
    c.commit(); c.close()
cleanup_pending_duplicates()

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
    x=dict(r); x['tags']=json.loads(x.get('tags') or '[]'); x['auto_tags']=json.loads(x.get('auto_tags') or '[]')
    x['video_url']=f"/media/videos/{x['video_file']}" if x.get('video_file') else None
    x['thumb_url']=f"/media/thumbs/{x['thumb_file']}" if x.get('thumb_file') else None
    x['notation_sheet_url']=f"/media/sheets/{x['notation_sheet_file']}" if x.get('notation_sheet_file') else None
    x['friendly_error']=friendly_import_error(x.get('error') or '') if x.get('status')=='waiting' and x.get('error') else None
    tabs=c.execute('SELECT * FROM tab_captures WHERE lesson_id=? ORDER BY timestamp',(x['id'],)).fetchall()
    x['tabs']=[{**dict(t),'image_url':f"/media/tabs/{t['image_file']}",'crop_url':(f"/media/tabs/{t['crop_file']}" if t['crop_file'] else None)} for t in tabs]
    secs=c.execute('SELECT * FROM practice_sections WHERE lesson_id=? ORDER BY start_time',(x['id'],)).fetchall()
    x['practice_sections']=[dict(z) for z in secs]
    cols=c.execute('''SELECT c.id,c.name FROM collections c JOIN lesson_collections lc ON lc.collection_id=c.id WHERE lc.lesson_id=? ORDER BY c.name COLLATE NOCASE''',(x['id'],)).fetchall()
    x['collections']=[dict(z) for z in cols]
    x['favorite']=bool(x.get('favorite'))
    return x


def derive_source_tags(title:str='', description:str='', author:str=''):
    """Conservative source-derived tags from the Reel's own title/caption/hashtags.
    No note, key or technique is invented when the source text does not support it.
    """
    text=' '.join([title or '', description or ''])
    low=text.lower()
    out=[]
    def add(x):
        x=x.strip().lstrip('#')
        if x and x.lower() not in {z.lower() for z in out}: out.append(x)
    # Explicit hashtags are the strongest signal because they come from the source caption.
    for h in re.findall(r'#([\w-]{2,40})', text, flags=re.UNICODE): add(h)
    rules=[
      (r'\bminor pentatonic|minor\s+pentatonic', 'minor pentatonic'),
      (r'\bmajor pentatonic|major\s+pentatonic', 'major pentatonic'),
      (r'\bpentatonic', 'pentatonic'), (r'\btriads?\b', 'triads'),
      (r'\barpeggios?\b', 'arpeggio'), (r'\bchord melody\b', 'chord melody'),
      (r'\bdouble[- ]?stops?\b', 'double stops'), (r'\bbends?|bending\b', 'bends'),
      (r'\bvibrato\b', 'vibrato'), (r'\bphrasing\b', 'phrasing'),
      (r'\bfingerstyle\b|finger style', 'fingerstyle'), (r'\bhybrid picking\b', 'hybrid picking'),
      (r'\balternate picking\b', 'alternate picking'), (r'\bsweep picking\b', 'sweep picking'),
      (r'\bblues\b', 'blues'), (r'\bfunk\b', 'funk'), (r'\bjazz\b', 'jazz'),
      (r'\bcountry\b', 'country'), (r'\blicks?\b', 'lick'), (r'\bsolo\b', 'solo'),
      (r'\bchords?\b', 'chords'), (r'\bvoicings?\b', 'voicings'), (r'\binversions?\b', 'inversions'),
      (r'\brhythm\b', 'rhythm'), (r'\btechnique\b', 'technique'),
      (r'\b(?:srv|stevie ray vaughan)\b', 'SRV style'), (r'\bhendrix\b', 'Hendrix'),
    ]
    for pat,tag in rules:
        if re.search(pat,low): add(tag)
    # Tonality only when the source explicitly states a key/major/minor context.
    # Chord symbols such as Abmaj7 or C7 are deliberately NOT converted into key tags.
    key_patterns=[
      r'\bkey\s+of\s+([A-G](?:#|b)?)(?:\s+|-)?(major|minor|maj|min)?\b',
      r'\bin\s+([A-G](?:#|b)?)(?:\s+|-)?(major|minor|maj|min)?\b',
      r'\b([A-G](?:#|b)?)\s+(major|minor)\s+(?:pentatonic|scale|key|lick|blues)\b',
    ]
    for pat in key_patterns:
        for m in re.finditer(pat,text,flags=re.I):
            root=m.group(1); qual=(m.group(2) or '').lower() if len(m.groups())>1 else ''
            if qual in ('maj','major'): qual='major'
            elif qual in ('min','minor'): qual='minor'
            add(f'{root} {qual}'.strip())
    return out[:16]

def backfill_source_tags():
    c=con(); rows=c.execute("SELECT id,title,source_description,author,auto_tags FROM lessons WHERE deleted=0").fetchall()
    for r in rows:
        current=json.loads(r['auto_tags'] or '[]') if r['auto_tags'] is not None else []
        if not current:
            tags=derive_source_tags(r['title'] or '',r['source_description'] or '',r['author'] or '')
            if tags: c.execute('UPDATE lessons SET auto_tags=? WHERE id=?',(json.dumps(tags,ensure_ascii=False),r['id']))
    c.commit(); c.close()
backfill_source_tags()

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

# ---------- content analysis: visible text -> title + auto tags ----------
def _clean_ocr_text(text:str):
    lines=[]
    for raw in (text or '').splitlines():
        x=re.sub(r'\s+',' ',raw).strip(' |_-')
        if len(x)<3: continue
        if re.fullmatch(r'[\W_\d]+',x): continue
        lines.append(x)
    return lines

def ocr_frame(video:Path, t:float, tmp:Path):
    """Extract one frame and read only text visibly present in the source video."""
    frame=tmp/f'ocr_{int(t*1000):08d}.jpg'
    try:
        capture_frame(video,frame,t)
        if not shutil.which('tesseract'): return ''
        cp=subprocess.run(['tesseract',str(frame),'stdout','-l','eng','--psm','11'],capture_output=True,text=True,timeout=25)
        return cp.stdout or ''
    except Exception:
        return ''
    finally:
        try: frame.unlink(missing_ok=True)
        except Exception: pass

def unique_preserve(items):
    out=[]; seen=set()
    for x in items:
        k=x.lower().strip()
        if k and k not in seen: seen.add(k); out.append(x)
    return out

def choose_source_title(existing:str, lines:list[str]):
    generic=not existing or bool(re.match(r'^(Video by|Imported Reel|Uploaded video|Instagram Reel)',existing,re.I))
    if not generic: return existing
    bad=('follow','subscribe','instagram','patreon','link in bio','send this to','someone who','likes','comments')
    scored=[]
    for line in lines:
        low=line.lower()
        if any(b in low for b in bad) or '@' in line or '.club' in low: continue
        if not re.search(r'[A-Za-z]{3}',line): continue
        score=0
        if 5<=len(line)<=55: score+=3
        if re.search(r'\b(lick|pentatonic|triad|chord|arpeggio|blues|jazz|country|srv|hendrix|scale|phrasing|vibrato|bend|picking|lesson|style)\b',low): score+=6
        if re.search(r'\b[A-G](?:#|b)?(?:7|m|maj|min|sus|dim|aug|add)?\b',line): score+=2
        score-=max(0,(len(line)-65)/15)
        scored.append((score,line))
    if not scored: return existing
    # Common lesson layout: song/title on one line, topic on another.
    clean=[line for _,line in sorted(scored,key=lambda z:z[0],reverse=True)]
    topic=next((x for x in clean if re.search(r'\b(chord melody|lick|triads?|pentatonic|arpeggio|phrasing|picking|blues|scale)\b',x,re.I)),None)
    subject=next((x for x in unique_preserve(lines) if 3<=len(x)<=35 and x!=topic and '@' not in x and len(re.findall(r'[A-Za-z]',x))>=max(3,int(len(x)*0.55)) and not re.search(r'\b(chord|lick|pentatonic|triad|arpeggio|follow|subscribe)\b',x,re.I)),None)
    if subject and topic and len(subject+' · '+topic)<=80:
        return (subject+' · '+topic)[:90]
    best=max(scored,key=lambda z:z[0])
    return best[1][:90] if best[0]>=4 else existing

def analyze_content(lid:str, force=False):
    c=con(); r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone(); c.close()
    if not r or not r['video_file']: return
    if not force and r['content_analysis_status']=='done' and (r['auto_tags'] or '[]')!='[]': return
    video=VIDEOS/r['video_file']
    if not video.exists(): return
    update_lesson(lid,content_analysis_status='running')
    try:
        meta=ffprobe(video); dur=float(meta.get('duration') or r['duration'] or 1)
        # Dense enough for short Reels, bounded for longer lessons.
        n=max(8,min(24,int(math.ceil(dur/2.5))))
        times=sorted(set([0.35]+[min(max(.2,dur*i/(n-1)),max(.2,dur-.2)) for i in range(n)]))
        tmp=DATA/'ocr_tmp'; tmp.mkdir(exist_ok=True)
        all_lines=[]
        for t in times:
            all_lines.extend(_clean_ocr_text(ocr_frame(video,t,tmp)))
        # de-duplicate repeated overlay text while preserving first appearance
        unique=[]; seen=set()
        for line in all_lines:
            key=re.sub(r'[^a-z0-9]+','',line.lower())
            if len(key)<3 or key in seen: continue
            seen.add(key); unique.append(line)
        visible='\n'.join(unique[:120])
        combined='\n'.join([r['title'] or '',r['source_description'] or '',visible])
        tags=derive_source_tags(r['title'] or '',combined,r['author'] or '')
        title=choose_source_title(r['title'] or '',unique)
        update_lesson(lid,title=title,auto_tags=json.dumps(tags,ensure_ascii=False),content_text=visible[:12000] or None,content_analysis_status='done')
        print(f"[CONTENT {lid[:8]}] title={title!r} tags={tags}",flush=True)
    except Exception as e:
        update_lesson(lid,content_analysis_status='error')
        print(f"[CONTENT {lid[:8]}] FAILED {e}",flush=True)

def maybe_analyze_content(lid, force=False):
    threading.Thread(target=analyze_content,args=(lid,force),daemon=True).start()

def backfill_content_library():
    if not shutil.which('tesseract'):
        print('[CONTENT] Tesseract not installed; content backfill skipped.',flush=True); return
    c=con(); rows=c.execute("SELECT id,title,auto_tags,content_analysis_status FROM lessons WHERE deleted=0 AND status='ready' AND video_file IS NOT NULL ORDER BY created_at").fetchall(); c.close()
    for r in rows:
        generic=not r['title'] or bool(re.match(r'^(Video by|Imported Reel|Uploaded video|Instagram Reel)',r['title'],re.I))
        tags=json.loads(r['auto_tags'] or '[]')
        if generic or not tags:
            analyze_content(r['id'],False)

# ---------- source notation analysis (TAB / notation / diagram) ----------
def _cluster_axis(values, tol=5):
    groups=[]
    for item in sorted(values, key=lambda z:z[0]):
        if not groups or item[0]-groups[-1][-1][0] > tol: groups.append([item])
        else: groups[-1].append(item)
    out=[]
    for g in groups:
        pos=sum(z[0] for z in g)/len(g)
        lo=min(z[1] for z in g); hi=max(z[2] for z in g)
        out.append((pos,lo,hi))
    return out

def _line_group_score(im, n):
    """Find a source staff-like group. n=6 is guitar TAB, n=5 is standard notation.
    Returns (score, bbox). It is deliberately strict to avoid treating chord grids as TAB.
    """
    try:
        import cv2, numpy as np
        h,w=im.shape[:2]
        gray=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY)
        gray=cv2.GaussianBlur(gray,(3,3),0)
        edges=cv2.Canny(gray,55,165)
        lines=cv2.HoughLinesP(edges,1,np.pi/180,threshold=max(22,w//15),minLineLength=max(70,int(w*.20)),maxLineGap=max(10,int(w*.045)))
        if lines is None:return (0.0,None)
        hs=[]
        for x1,y1,x2,y2 in lines[:,0]:
            dx=abs(int(x2)-int(x1)); dy=abs(int(y2)-int(y1))
            if dx>=w*.20 and dy<=max(3,dx*.022):
                hs.append(((y1+y2)/2,min(x1,x2),max(x1,x2)))
        rows=_cluster_axis(hs,4)
        best=(0.0,None)
        if len(rows)<n:return best
        # Scan exact-size windows. Extra nearby rows are allowed, which handles notation+TAB layouts.
        for i in range(len(rows)-n+1):
            seq=rows[i:i+n]
            ys=[z[0] for z in seq]; ds=np.diff(ys); med=float(np.median(ds))
            if med<4 or med>h*.065:continue
            cv=float(np.std(ds)/(med+1e-6))
            if cv>.24:continue
            x0=max(z[1] for z in seq); x1=min(z[2] for z in seq)
            common=max(0,x1-x0); common_ratio=common/max(1,w)
            union0=min(z[1] for z in seq); union1=max(z[2] for z in seq)
            if common_ratio<.16 or union1-union0 < w*.25:continue
            # Character/mark evidence after removing horizontal rules. Six clean empty strings alone are not enough.
            py0=max(0,int(ys[0]-med*1.25)); py1=min(h,int(ys[-1]+med*1.25))
            px0=max(0,int(union0-med)); px1=min(w,int(union1+med))
            crop=gray[py0:py1,px0:px1]
            if crop.size==0:continue
            bw=cv2.adaptiveThreshold(crop,255,cv2.ADAPTIVE_THRESH_GAUSSIAN_C,cv2.THRESH_BINARY_INV,31,11)
            hk=cv2.getStructuringElement(cv2.MORPH_RECT,(max(18,crop.shape[1]//8),1))
            rules=cv2.morphologyEx(bw,cv2.MORPH_OPEN,hk)
            marks=cv2.subtract(bw,rules)
            num,lab,stats,cent=cv2.connectedComponentsWithStats(marks,8)
            components=0
            area=crop.shape[0]*crop.shape[1]
            for st in stats[1:]:
                x,y,cw,ch,a=map(int,st)
                if 2<=cw<=max(5,int(crop.shape[1]*.09)) and 3<=ch<=max(7,int(crop.shape[0]*.45)) and 5<=a<=max(20,int(area*.02)):
                    components+=1
            mark_score=min(1.0,components/8.0)
            score=max(0,1-cv)*min(1.0,(union1-union0)/(w*.55))*0.55 + common_ratio*0.18 + mark_score*0.27
            # TAB needs stronger mark evidence than standard notation, preventing grids from becoming TAB.
            if n==6 and components<2: score*=.45
            bbox=(px0,py0,px1,py1)
            if score>best[0]:best=(float(score),bbox)
        return best
    except Exception:
        return (0.0,None)

def _projection_line_group_score(im, n):
    """Second detector for faint/short source staves (e.g. a small TAB strip at the bottom of a Reel)."""
    try:
        import cv2, numpy as np
        h,w=im.shape[:2]; gray=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY)
        bw=cv2.adaptiveThreshold(gray,255,cv2.ADAPTIVE_THRESH_GAUSSIAN_C,cv2.THRESH_BINARY_INV,31,11)
        hk=cv2.getStructuringElement(cv2.MORPH_RECT,(max(14,w//18),1))
        hor=cv2.morphologyEx(bw,cv2.MORPH_OPEN,hk)
        cov=(hor>0).mean(axis=1); ys=np.where(cov>.15)[0]
        runs=[]
        if len(ys):
            st=prev=int(ys[0])
            for yy in ys[1:]:
                yy=int(yy)
                if yy>prev+2:runs.append((st,prev));st=yy
                prev=yy
            runs.append((st,prev))
        rows=[]
        for a,b in runs:
            pos=(a+b)/2; mask=np.any(hor[a:b+1]>0,axis=0); xs=np.where(mask)[0]
            if len(xs):rows.append((pos,int(xs[0]),int(xs[-1]),float(cov[a:b+1].max())))
        best=(0.0,None)
        for i in range(len(rows)-n+1):
            seq=rows[i:i+n]; yy=[z[0] for z in seq]; ds=np.diff(yy); med=float(np.median(ds))
            if med<4 or med>h*.035:continue
            cv=float(np.std(ds)/(med+1e-6))
            if cv>.29:continue
            u0=min(z[1] for z in seq);u1=max(z[2] for z in seq)
            common=max(0,min(z[2] for z in seq)-max(z[1] for z in seq))/max(1,w)
            width_ratio=(u1-u0)/max(1,w)
            if width_ratio<.18 or common<.42:continue
            avg_cov=sum(z[3] for z in seq)/n
            score=max(0,1-cv)*.50+min(1,width_ratio/.55)*.25+min(1,avg_cov/.50)*.25
            pad=max(10,int(med*1.5)); bbox=(max(0,u0-pad),max(0,int(yy[0]-med*1.5)),min(w,u1+pad),min(h,int(yy[-1]+med*1.5)))
            if score>best[0]:best=(float(score),bbox)
        return best
    except Exception:return (0.0,None)

def _bbox_overlap(a,b):
    if not a or not b:return 0.0
    ax0,ay0,ax1,ay1=a;bx0,by0,bx1,by1=b
    iw=max(0,min(ax1,bx1)-max(ax0,bx0));ih=max(0,min(ay1,by1)-max(ay0,by0));inter=iw*ih
    aa=max(1,(ax1-ax0)*(ay1-ay0));bb=max(1,(bx1-bx0)*(by1-by0))
    return inter/min(aa,bb)

def _diagram_score_axis(im):
    """Detect a localized fretboard/chord grid. Physical guitar necks may also look grid-like;
    overlap logic in analyze_notation_frame prevents them from stealing a separate TAB overlay.
    """
    try:
        import cv2, numpy as np
        h,w=im.shape[:2];gray=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY)
        bw=cv2.adaptiveThreshold(gray,255,cv2.ADAPTIVE_THRESH_GAUSSIAN_C,cv2.THRESH_BINARY_INV,31,11)
        hk=cv2.getStructuringElement(cv2.MORPH_RECT,(max(12,w//24),1));vk=cv2.getStructuringElement(cv2.MORPH_RECT,(1,max(12,h//30)))
        hor=cv2.morphologyEx(bw,cv2.MORPH_OPEN,hk);ver=cv2.morphologyEx(bw,cv2.MORPH_OPEN,vk)
        def axis_items(mask,axis):
            proj=(mask>0).mean(axis=axis);idx=np.where(proj>.075)[0];runs=[]
            if len(idx):
                st=prev=int(idx[0])
                for zz in idx[1:]:
                    zz=int(zz)
                    if zz>prev+2:runs.append((st,prev));st=zz
                    prev=zz
                runs.append((st,prev))
            out=[]
            for a,b in runs:
                pos=(a+b)/2
                ext=np.where(np.any(mask[a:b+1]>0,axis=0))[0] if axis==1 else np.where(np.any(mask[:,a:b+1]>0,axis=1))[0]
                if len(ext):out.append((pos,int(ext[0]),int(ext[-1]),float(proj[a:b+1].max())))
            return out
        hs=axis_items(hor,1);vs=axis_items(ver,0);best=(0.0,None)
        for a in range(len(hs)):
            for b in range(a+3,min(len(hs),a+11)):
                seq=hs[a:b+1];ys=[z[0] for z in seq];span=ys[-1]-ys[0]
                if span<18 or span>h*.55:continue
                ux0=min(z[1] for z in seq);ux1=max(z[2] for z in seq)
                if ux1-ux0<w*.12:continue
                common=max(0,min(z[2] for z in seq)-max(z[1] for z in seq))
                vsel=[]
                for v in vs:
                    x,y0,y1,c=v
                    if ux0-12<=x<=ux1+12 and min(y1,ys[-1])-max(y0,ys[0])>=span*.53:vsel.append(v)
                if len(vsel)<3:continue
                xs=[z[0] for z in vsel];xspan=max(xs)-min(xs)
                if xspan<(ux1-ux0)*.32:continue
                score=min(1.0,(len(seq)/6)*.34+(len(vsel)/6)*.38+min(.18,common/max(1,w))+.10)
                pad=max(10,int(min(max(1,xspan),max(1,span))*.14));bbox=(max(0,int(min(xs)-pad)),max(0,int(ys[0]-pad)),min(w,int(max(xs)+pad)),min(h,int(ys[-1]+pad)))
                if score>best[0]:best=(float(score),bbox)
        return best
    except Exception:return (0.0,None)

def _diagram_score_morph(im):
    """Grid detector tuned for boxed chord/fretboard diagrams, on either dark or light backgrounds."""
    try:
        import cv2, numpy as np
        h,w=im.shape[:2];gray=cv2.cvtColor(im,cv2.COLOR_BGR2GRAY);best=(0.0,None)
        for typ,cval in ((cv2.THRESH_BINARY_INV,9),(cv2.THRESH_BINARY,-5),(cv2.THRESH_BINARY,9)):
            bw=cv2.adaptiveThreshold(gray,255,cv2.ADAPTIVE_THRESH_GAUSSIAN_C,typ,31,cval)
            hk=cv2.getStructuringElement(cv2.MORPH_RECT,(max(18,w//16),1));vk=cv2.getStructuringElement(cv2.MORPH_RECT,(1,max(18,h//18)))
            hor=cv2.morphologyEx(bw,cv2.MORPH_OPEN,hk);ver=cv2.morphologyEx(bw,cv2.MORPH_OPEN,vk)
            grid=cv2.bitwise_or(hor,ver);grid=cv2.dilate(grid,np.ones((3,3),np.uint8),iterations=1)
            contours,_=cv2.findContours(grid,cv2.RETR_EXTERNAL,cv2.CHAIN_APPROX_SIMPLE)
            for cnt in contours:
                x,y,cw,ch=cv2.boundingRect(cnt)
                if cw<w*.10 or ch<h*.07 or cw>w*.82 or ch>h*.78:continue
                roi_h=hor[y:y+ch,x:x+cw];roi_v=ver[y:y+ch,x:x+cw]
                hp=(roi_h>0).mean(axis=1);vp=(roi_v>0).mean(axis=0)
                def runs(a,thr):
                    n=0;on=False
                    for v in a:
                        if v>thr and not on:n+=1;on=True
                        elif v<=thr:on=False
                    return n
                hr=runs(hp,.30);vr=runs(vp,.30)
                if hr<4 or vr<3:continue
                aspect=cw/max(1,ch);density=float((grid[y:y+ch,x:x+cw]>0).mean())
                score=min(1.0,(hr/6)*.36+(vr/5)*.34+min(.22,density*2.2)+(.08 if .35<aspect<3.5 else 0))
                pad=max(8,int(min(cw,ch)*.12));bbox=(max(0,x-pad),max(0,y-pad),min(w,x+cw+pad),min(h,y+ch+pad))
                if score>best[0]:best=(score,bbox)
        return best
    except Exception:return (0.0,None)

def _grid_regularity(im,bbox):
    """Verify that a diagram candidate contains a genuinely regular orthogonal grid.
    This rejects a photographed guitar fretboard, whose fret spacing/perspective is not a regular screen grid.
    """
    try:
        import cv2, numpy as np
        if not bbox:return 0.0
        x0,y0,x1,y1=[int(v) for v in bbox];roi=im[y0:y1,x0:x1]
        if roi.size==0:return 0.0
        h,w=roi.shape[:2];g=cv2.cvtColor(roi,cv2.COLOR_BGR2GRAY);edges=cv2.Canny(g,50,150)
        lines=cv2.HoughLinesP(edges,1,np.pi/180,threshold=max(18,min(w,h)//5),minLineLength=max(20,int(min(w,h)*.24)),maxLineGap=max(8,int(min(w,h)*.08)))
        if lines is None:return 0.0
        hs=[];vs=[]
        for X1,Y1,X2,Y2 in lines[:,0]:
            dx=abs(int(X2)-int(X1));dy=abs(int(Y2)-int(Y1))
            if dx>max(20,w*.20) and dy<max(4,dx*.06):hs.append(((Y1+Y2)/2,min(X1,X2),max(X1,X2)))
            if dy>max(20,h*.20) and dx<max(4,dy*.06):vs.append(((X1+X2)/2,min(Y1,Y2),max(Y1,Y2)))
        def centers(vals,tol=5):
            vals=sorted(vals,key=lambda z:z[0]);groups=[]
            for z in vals:
                if not groups or z[0]-groups[-1][-1][0]>tol:groups.append([z])
                else:groups[-1].append(z)
            return [sum(v[0] for v in g)/len(g) for g in groups]
        H=centers(hs);V=centers(vs)
        def best_cv(arr):
            if len(arr)<4:return None
            best=99.0
            for a in range(len(arr)):
                for b in range(a+3,len(arr)):
                    d=np.diff(arr[a:b+1]);m=float(np.mean(d))
                    if m<3:continue
                    cv=float(np.std(d)/(m+1e-6));best=min(best,cv)
            return best if best<99 else None
        ch=best_cv(H);cv=best_cv(V)
        if ch is None or cv is None or ch>.22 or cv>.22:return 0.0
        return float(max(0,min(1,1-(ch+cv)/.44)))
    except Exception:return 0.0

def _diagram_score(im):
    candidates=[_diagram_score_morph(im),_diagram_score_axis(im)]
    best=(0.0,None)
    for raw,bbox in candidates:
        if raw<.50 or not bbox:continue
        reg=_grid_regularity(im,bbox)
        if reg<=0:continue
        score=raw*(.72+.28*reg)
        if score>best[0]:best=(float(score),bbox)
    return best

def analyze_notation_frame(path:Path):
    try:
        import cv2
        im=cv2.imread(str(path))
        if im is None:return {'kind':'video','score':0.0,'bbox':None,'evidence':'none'}
        tab_h,tab_hb=_line_group_score(im,6); tab_p,tab_pb=_projection_line_group_score(im,6)
        note_h,note_hb=_line_group_score(im,5); note_p,note_pb=_projection_line_group_score(im,5)
        diag_s,diag_b=_diagram_score(im)
        # A strong exact six-line projection is highly characteristic of a conventional TAB strip.
        if tab_p>=.76:return {'kind':'tab','score':max(tab_p,tab_h),'bbox':tab_pb or tab_hb,'evidence':'projection'}
        # Hough also catches dashed TAB. If the grid detector overlaps the same object strongly,
        # call it a diagram; if the grid is elsewhere (often the physical guitar neck), keep TAB.
        if tab_h>=.64:
            ov=_bbox_overlap(tab_hb,diag_b)
            if not (diag_s>=.66 and ov>=.50):return {'kind':'tab','score':tab_h,'bbox':tab_hb,'evidence':'hough'}
        note_s=max(note_h,note_p);note_b=note_pb if note_p>=note_h else note_hb
        if note_s>=.68:
            ov=_bbox_overlap(note_b,diag_b)
            if not (diag_s>=.68 and ov>=.52):return {'kind':'notation','score':note_s,'bbox':note_b,'evidence':('projection' if note_p>=note_h else 'hough')}
        if diag_s>=.62:return {'kind':'diagram','score':diag_s,'bbox':diag_b,'evidence':'grid'}
        return {'kind':'video','score':max(tab_h,tab_p,note_h,note_p,diag_s),'bbox':None,'evidence':'none'}
    except Exception:return {'kind':'video','score':0.0,'bbox':None,'evidence':'none'}

def _crop_notation(src:Path,bbox,out:Path,kind='tab'):
    import cv2
    im=cv2.imread(str(src))
    if im is None: raise RuntimeError('Could not read source frame')
    h,w=im.shape[:2]
    if bbox:
        x0,y0,x1,y1=[int(v) for v in bbox]
        # For TAB/notation keep generous context around the staff, including labels/chord names.
        if kind in ('tab','notation'):
            px=max(14,int((x1-x0)*.04)); py=max(14,int((y1-y0)*.25))
        else:
            px=max(12,int((x1-x0)*.16)); py=max(12,int((y1-y0)*.22))
        x0=max(0,x0-px); x1=min(w,x1+px); y0=max(0,y0-py); y1=min(h,y1+py)
        crop=im[y0:y1,x0:x1]
    else:crop=im
    if crop.size==0:crop=im
    cv2.imwrite(str(out),crop,[int(cv2.IMWRITE_JPEG_QUALITY),94])

def _phash(path:Path):
    import cv2, numpy as np
    im=cv2.imread(str(path),cv2.IMREAD_GRAYSCALE)
    if im is None:return None
    small=cv2.resize(im,(24,24)); return (small>small.mean()).flatten()

def _build_notation_sheet(lid:str, captures:list[tuple], kind:str):
    """Build one readable source-faithful sheet from ordered crops. No notes are transcribed or invented."""
    if not captures:return None
    import cv2, numpy as np
    rendered=[]; target=1500
    label={'tab':'SOURCE TAB','notation':'SOURCE NOTATION','diagram':'SOURCE DIAGRAM'}.get(kind,'SOURCE')
    for t,crop_path in captures:
        im=cv2.imread(str(crop_path))
        if im is None:continue
        h,w=im.shape[:2]
        scale=min(1.8,target/max(1,w))
        nw=max(320,int(w*scale)); nh=max(80,int(h*scale))
        im=cv2.resize(im,(nw,nh),interpolation=cv2.INTER_CUBIC if scale>1 else cv2.INTER_AREA)
        canvas=np.full((nh+54,target,3),255,np.uint8)
        x=(target-nw)//2; canvas[54:54+nh,x:x+nw]=im
        cv2.putText(canvas,f'{label}   {int(t//60)}:{int(t%60):02d}',(24,35),cv2.FONT_HERSHEY_SIMPLEX,.72,(20,20,20),2,cv2.LINE_AA)
        rendered.append(canvas)
    if not rendered:return None
    gap=np.full((18,target,3),235,np.uint8)
    pieces=[]
    for i,im in enumerate(rendered):
        pieces.append(im)
        if i<len(rendered)-1:pieces.append(gap)
    sheet=np.vstack(pieces)
    out=SHEETS/f'{lid}_{kind}.jpg'
    cv2.imwrite(str(out),sheet,[int(cv2.IMWRITE_JPEG_QUALITY),95])
    return out.name

def analyze_tabs(lid:str, force=False):
    """Compatibility name: v2.7 analyzes all source notation, not just TAB."""
    c=con(); row=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone(); c.close()
    if not row or not row['video_file']:return
    video=VIDEOS/row['video_file']; meta=ffprobe(video); dur=meta.get('duration') or 0
    if dur<=0:return
    if not force and int(row['notation_version'] or 0)>=27:return
    update_lesson(lid,analysis_status='running',analysis_progress=0,error=None)
    work=DATA/'_scan'/lid; shutil.rmtree(work,ignore_errors=True); work.mkdir(parents=True,exist_ok=True)
    try:
        # v2.7 replaces old auto detector results. Manual captures are preserved.
        c=con(); old=c.execute('SELECT * FROM tab_captures WHERE lesson_id=? AND auto_detected=1',(lid,)).fetchall()
        for r in old:
            (TABS/r['image_file']).unlink(missing_ok=True)
            if r['crop_file']:(TABS/r['crop_file']).unlink(missing_ok=True)
        c.execute('DELETE FROM tab_captures WHERE lesson_id=? AND auto_detected=1',(lid,)); c.commit(); c.close()
        if row['notation_sheet_file']:(SHEETS/row['notation_sheet_file']).unlink(missing_ok=True)
        interval=max(.65,min(1.35,dur/75))
        times=[min(max(.05,dur-.05),i*interval+.18) for i in range(max(1,int(math.ceil(dur/interval))))]
        by_kind={'tab':[],'notation':[],'diagram':[]}
        for idx,t in enumerate(times):
            f=work/f'{idx:04d}.jpg'
            try:capture_frame(video,f,t)
            except Exception:continue
            a=analyze_notation_frame(f)
            if a['kind']!='video':
                crop=work/f'{idx:04d}_{a["kind"]}_crop.jpg'
                try:_crop_notation(f,a['bbox'],crop,a['kind'])
                except Exception:crop=f
                by_kind[a['kind']].append((float(a['score']),float(t),f,crop,a.get('evidence',''),a.get('bbox')))
            if idx%4==0:update_lesson(lid,analysis_progress=min(.90,(idx+1)/max(1,len(times))))
        # Determine the dominant source type from evidence across the whole Reel.
        # A strong exact-six-line projection is privileged because it catches small conventional TAB strips.
        tabs=by_kind['tab']; diags=by_kind['diagram']; notes=by_kind['notation']
        proj=[z for z in tabs if z[4]=='projection' and z[0]>=.78]
        tab_weight=sum(z[0] for z in tabs)+.70*len(proj)
        diag_weight=sum(z[0] for z in diags)
        note_weight=sum(z[0] for z in notes)
        kind='video'
        if (proj and max(z[0] for z in proj)>=.90) or len(proj)>=2:
            kind='tab'
        elif len(diags)>=2 and len(proj)<=1 and diag_weight>=sum(z[0] for z in tabs)*.90:
            kind='diagram'
        elif len(tabs)>=3 and tab_weight>=diag_weight*.92:
            kind='tab'
        elif len(diags)>=2 and diag_weight>=tab_weight*.85:
            kind='diagram'
        elif len(tabs)>=2 and max(z[0] for z in tabs)>=.82:
            kind='tab'
        elif len(notes)>=2 and note_weight>=max(tab_weight,diag_weight)*.90:
            kind='notation'
        elif len(diags)>=1 and max(z[0] for z in diags)>=.92:
            kind='diagram'
        cand=by_kind.get(kind,[]) if kind!='video' else []
        # Chronological perceptual dedup. Retain changed measures/diagrams, remove repeated frames.
        kept=[]; hashes=[]
        for s,t,frame,crop,evidence,bbox in sorted(cand,key=lambda z:z[1]):
            ph=_phash(crop)
            duplicate=False
            if ph is not None:
                for (ps,pt,pf,pc,pe,pb),oldh in zip(kept,hashes):
                    if oldh is None:continue
                    sim=float((ph==oldh).mean())
                    # Dynamic TAB can change in place; only near-identical crops are removed.
                    if sim>.975 or (abs(t-pt)<3.0 and sim>.955):duplicate=True;break
            if not duplicate:
                kept.append((s,t,frame,crop,evidence,bbox));hashes.append(ph)
        # Keep a useful complete sequence while preventing pathological sheets.
        if len(kept)>18:
            # preserve chronology but choose representatives across the entire reel
            import numpy as np
            ids=sorted(set(int(round(x)) for x in np.linspace(0,len(kept)-1,18)))
            kept=[kept[i] for i in ids]
        c=con();now=time.time();sheet_parts=[]
        for s,t,frame,crop,evidence,bbox in kept:
            tid=str(uuid.uuid4()); full=TABS/f'{tid}.jpg'; cr=TABS/f'{tid}_crop.jpg'
            shutil.copy2(frame,full); shutil.copy2(crop,cr)
            label={'tab':'Source TAB','notation':'Source notation','diagram':'Source diagram'}.get(kind,'Source')
            c.execute('INSERT INTO tab_captures(id,lesson_id,image_file,timestamp,label,confidence,auto_detected,kind,crop_file,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',(tid,lid,full.name,t,label,round(s,3),1,kind,cr.name,now))
            sheet_parts.append((t,cr))
        c.commit();c.close()
        sheet=_build_notation_sheet(lid,sheet_parts,kind) if kept else None
        confidence=max([z[0] for z in kept],default=0.0)
        c2=con(); rr=c2.execute('SELECT title,source_description,content_text,author FROM lessons WHERE id=?',(lid,)).fetchone(); c2.close()
        at=derive_source_tags(rr['title'] or '', '\n'.join([rr['source_description'] or '',rr['content_text'] or '']),rr['author'] or '') if rr else []
        update_lesson(lid,analysis_status='done',analysis_progress=1,has_tab=1 if kind=='tab' else 0,notation_type=kind,notation_confidence=confidence,notation_sheet_file=sheet,notation_version=27,auto_tags=json.dumps(at,ensure_ascii=False))
        print(f'[NOTATION {lid[:8]}] type={kind} captures={len(kept)} confidence={confidence:.2f}',flush=True)
    except Exception as e:
        update_lesson(lid,analysis_status='error',analysis_progress=0,error=f'Notation analysis: {e}')
        print(f'[NOTATION {lid[:8]}] FAILED {e}',flush=True)
    finally:shutil.rmtree(work,ignore_errors=True)

def maybe_analyze(lid, force=False):
    if load_settings().get('auto_analyze',True):
        threading.Thread(target=analyze_tabs,args=(lid,force),daemon=True).start()

def backfill_notation_library():
    """Re-analyze already downloaded local videos in place. Never re-downloads Instagram media."""
    c=con();rows=c.execute("SELECT id,notation_version FROM lessons WHERE deleted=0 AND status='ready' AND video_file IS NOT NULL ORDER BY created_at").fetchall();c.close()
    for r in rows:
        if int(r['notation_version'] or 0)<27: analyze_tabs(r['id'],True)

# ---------- import URL ----------
def try_download_url(lid,url):
    update_lesson(lid,status='importing',error='Reading source…')
    template=str(VIDEOS/f'{lid}.%(ext)s')
    settings=load_settings(); browser=settings.get('cookie_browser','').strip()
    cmd=['yt-dlp','--no-playlist','--merge-output-format','mp4','--restrict-filenames','--write-info-json','--newline','-o',template,url]
    if browser and browser!='none': cmd[1:1]=['--cookies-from-browser',browser]
    safe_cmd=' '.join(['yt-dlp'] + [('[browser cookies]' if x==browser and browser else x) for x in cmd[1:]])
    print(f"\n[IMPORT {lid[:8]}] Starting: {url}", flush=True)
    print(f"[IMPORT {lid[:8]}] Browser cookies: {browser or 'none'}", flush=True)
    try:
      p=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,bufsize=1)
      lines=[]
      start=time.time()
      for line in iter(p.stdout.readline,''):
        line=line.rstrip()
        if line:
          lines.append(line); lines=lines[-80:]
          print(f"[yt-dlp {lid[:8]}] {line}", flush=True)
          low=line.lower()
          if '[download]' in low and '%' in line: update_lesson(lid,error='Downloading video…')
          elif 'extracting url' in low or 'downloading webpage' in low: update_lesson(lid,error='Reading Instagram…')
        if time.time()-start>240:
          p.kill(); raise subprocess.TimeoutExpired(cmd,240)
      rc=p.wait()
      if rc!=0:
        err='\n'.join(lines[-25:]).strip() or 'Source did not provide the video'
        print(f"[IMPORT {lid[:8]}] FAILED rc={rc}:\n{err}\n", flush=True)
        update_lesson(lid,status='waiting',error=err[-4000:]); return
      matches=[x for x in VIDEOS.glob(f'{lid}.*') if x.suffix.lower() not in ('.json','.part','.ytdl')]
      path=sorted(matches,key=lambda x:x.stat().st_mtime,reverse=True)[0] if matches else None
      if not path or not path.exists():
        err='Download finished without a playable file'; print(f"[IMPORT {lid[:8]}] {err}", flush=True); update_lesson(lid,status='waiting',error=err); return
      update_lesson(lid,error='Processing video…')
      meta=ffprobe(path)
      if not meta.get('width'):
        err='Downloaded media is not a playable video'; print(f"[IMPORT {lid[:8]}] {err}", flush=True); update_lesson(lid,status='waiting',error=err); return
      infofile=next(iter(VIDEOS.glob(f'{lid}*.info.json')),None)
      title='Imported Reel'; author=None; description=''; auto_tags=[]
      if infofile:
        try:
          inf=json.loads(infofile.read_text()); title=inf.get('title') or title; author=inf.get('uploader') or inf.get('channel'); description=inf.get('description') or '';
          if (title.lower().startswith('video by ') or title.lower().startswith('reel by ')) and description.strip():
              first=next((ln.strip() for ln in description.splitlines() if ln.strip()),'')
              if 4 <= len(first) <= 120: title=first
          auto_tags=derive_source_tags(title,description,author or '')
        except Exception as e: print(f"[IMPORT {lid[:8]}] info json warning: {e}", flush=True)
        infofile.unlink(missing_ok=True)
      thumb=make_thumb(path,lid)
      update_lesson(lid,status='ready',video_file=path.name,thumb_file=thumb,duration=meta.get('duration'),width=meta.get('width'),height=meta.get('height'),title=title[:180],author=(author or '')[:120] or None,source_description=description[:6000] or None,auto_tags=json.dumps(auto_tags,ensure_ascii=False),error=None)
      print(f"[IMPORT {lid[:8]}] READY: {path.name}", flush=True)
      maybe_analyze(lid)
      maybe_analyze_content(lid)
    except FileNotFoundError:
      err='yt-dlp is missing. Run install.command.'; print(f"[IMPORT {lid[:8]}] {err}", flush=True); update_lesson(lid,status='waiting',error=err)
    except subprocess.TimeoutExpired:
      err='Import timed out after 4 minutes.'; print(f"[IMPORT {lid[:8]}] {err}", flush=True); update_lesson(lid,status='waiting',error=err)
    except Exception as e:
      err=f'{type(e).__name__}: {e}'; print(f"[IMPORT {lid[:8]}] EXCEPTION: {err}", flush=True); update_lesson(lid,status='waiting',error=err)

def friendly_import_error(raw:str):
    t=(raw or '').lower()
    if 'could not resolve host' in t or 'nodename nor servname' in t:
      return 'Instagram is unavailable from the Mac system network. Turn on a system-wide VPN and retry.'
    if 'login required' in t or 'rate-limit reached' in t or 'requested content is not available' in t:
      return 'Instagram did not accept the current browser session. Open Instagram in the selected browser, sign in, then retry.'
    if 'cookies' in t and ('decrypt' in t or 'keyring' in t or 'safe storage' in t):
      return 'Reel Practice could not read browser cookies. Allow macOS Keychain access, then retry.'
    if '403' in t or 'forbidden' in t:
      return 'Instagram refused this download request. Check VPN and browser login, then retry.'
    if 'timed out' in t:
      return 'Instagram import timed out. Check the connection and retry.'
    return 'Instagram could not provide this Reel automatically. Open Details for the technical message or attach the video file.'

# ---------- models ----------
class LinkIn(BaseModel): url:str
class UpdateIn(BaseModel): title:Optional[str]=None; note:Optional[str]=None; tags:Optional[list[str]]=None; favorite:Optional[bool]=None
class CaptureIn(BaseModel): timestamp:float; label:Optional[str]=None
class SectionIn(BaseModel): start_time:float; end_time:float; title:Optional[str]=None
class SettingsIn(BaseModel): cookie_browser:str='chrome'; auto_analyze:bool=True
class CollectionIn(BaseModel): name:str

# ---------- API ----------
@app.get('/api/health')
def health(): return {'ok':True,'version':'2.7.0','ffmpeg':bool(shutil.which('ffmpeg')),'ytdlp':bool(shutil.which('yt-dlp'))}

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
    c=con(); existing=c.execute("SELECT id,status FROM lessons WHERE source_url=? AND deleted=0 ORDER BY created_at DESC LIMIT 1",(url,)).fetchone(); c.close()
    if existing:
      return {'id':existing['id'],'status':existing['status'],'duplicate':True}
    lid=create_lesson('Instagram Reel' if 'instagram.com' in url else 'Imported link',source_url=url,source_type='link',status='queued')
    threading.Thread(target=try_download_url,args=(lid,url),daemon=True).start();return {'id':lid,'status':'queued','duplicate':False}

@app.post('/api/lessons/{lid}/retry-import')
def retry_import(lid:str):
    c=con(); r=c.execute('SELECT source_url,status FROM lessons WHERE id=? AND deleted=0',(lid,)).fetchone(); c.close()
    if not r: raise HTTPException(404,'Lesson not found')
    if not r['source_url']: raise HTTPException(400,'This lesson has no source URL')
    if r['status'] in ('queued','importing'): return {'ok':True,'already_running':True}
    update_lesson(lid,status='queued',error=None)
    threading.Thread(target=try_download_url,args=(lid,r['source_url']),daemon=True).start()
    return {'ok':True}

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
    c.execute('''INSERT INTO lessons(id,title,source_url,source_type,status,video_file,thumb_file,duration,width,height,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)''',(lid,title,source_url,'upload','ready',target.name,thumb,meta.get('duration'),meta.get('width'),meta.get('height'),now,now));c.commit();c.close();maybe_analyze(lid);maybe_analyze_content(lid);return {'id':lid,'status':'ready'}

@app.post('/api/lessons/{lid}/attach')
async def attach_video(lid:str,file:UploadFile=File(...)):
    c=con();r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r:raise HTTPException(404)
    suffix=Path(file.filename or 'video.mp4').suffix.lower() or '.mp4';target=VIDEOS/f'{lid}{suffix}';await save_upload(file,target);meta=ffprobe(target)
    if not meta.get('width'):target.unlink(missing_ok=True);raise HTTPException(400,'This is not a playable video')
    thumb=make_thumb(target,lid);update_lesson(lid,status='ready',video_file=target.name,thumb_file=thumb,duration=meta.get('duration'),width=meta.get('width'),height=meta.get('height'),error=None);maybe_analyze(lid);maybe_analyze_content(lid);return {'ok':True}

@app.patch('/api/lessons/{lid}')
def patch_lesson(lid:str,p:UpdateIn):
    f={}
    if p.title is not None:f['title']=p.title.strip() or 'Untitled lesson'
    if p.note is not None:f['note']=p.note
    if p.tags is not None:
        clean=[]
        for t in p.tags:
            t=' '.join(str(t).strip().split())
            if t and t.lower() not in [x.lower() for x in clean]: clean.append(t[:40])
        f['tags']=json.dumps(clean[:30],ensure_ascii=False)
    if p.favorite is not None:f['favorite']=1 if p.favorite else 0
    update_lesson(lid,**f);return {'ok':True}

@app.post('/api/lessons/{lid}/analyze')
def analyze(lid:str):
    c=con();r=c.execute('SELECT video_file FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r or not r['video_file']:raise HTTPException(400,'Attach a video first')
    threading.Thread(target=analyze_tabs,args=(lid,True),daemon=True).start();return {'ok':True}

@app.post('/api/lessons/{lid}/analyze-content')
def analyze_content_endpoint(lid:str):
    c=con(); r=c.execute('SELECT id FROM lessons WHERE id=?',(lid,)).fetchone(); c.close()
    if not r: raise HTTPException(404,'Lesson not found')
    maybe_analyze_content(lid,True); return {'ok':True}

@app.post('/api/analyze-library-content')
def analyze_library_content():
    threading.Thread(target=backfill_content_library,daemon=True).start(); return {'ok':True}

@app.post('/api/reanalyze-library')
def reanalyze_library():
    def work():
        c=con(); rows=c.execute("SELECT id FROM lessons WHERE deleted=0 AND status='ready' AND video_file IS NOT NULL ORDER BY created_at").fetchall(); c.close()
        for r in rows:
            analyze_content(r['id'],True)
            analyze_tabs(r['id'],True)
    threading.Thread(target=work,daemon=True).start(); return {'ok':True}

@app.post('/api/lessons/{lid}/capture-tab')
def manual_capture(lid:str,p:CaptureIn):
    c=con();r=c.execute('SELECT video_file FROM lessons WHERE id=?',(lid,)).fetchone();c.close()
    if not r or not r['video_file']:raise HTTPException(404,'No local video')
    tid=str(uuid.uuid4());out=TABS/f'{tid}.jpg';capture_frame(VIDEOS/r['video_file'],out,p.timestamp);c=con();now=time.time();c.execute('INSERT INTO tab_captures(id,lesson_id,image_file,timestamp,label,confidence,auto_detected,created_at) VALUES(?,?,?,?,?,?,?,?)',(tid,lid,out.name,p.timestamp,p.label or 'Source TAB',1.0,0,now));c.commit();c.close();update_lesson(lid,has_tab=1,notation_type='tab',notation_version=27);return {'ok':True}

@app.post('/api/lessons/{lid}/practice-sections')
def add_practice_section(lid:str,p:SectionIn):
    c=con();r=c.execute('SELECT duration FROM lessons WHERE id=?',(lid,)).fetchone()
    if not r: c.close(); raise HTTPException(404,'Lesson not found')
    dur=float(r['duration'] or 0); a=max(0,float(p.start_time)); b=float(p.end_time)
    if dur: b=min(dur,b)
    if b<=a: c.close(); raise HTTPException(400,'End must be after start')
    sid=str(uuid.uuid4()); now=time.time(); title=(p.title or f'Practice {a:.1f}–{b:.1f}s').strip()[:120]
    c.execute('INSERT INTO practice_sections(id,lesson_id,title,start_time,end_time,created_at) VALUES(?,?,?,?,?,?)',(sid,lid,title,a,b,now));c.commit();c.close();return {'id':sid}

@app.delete('/api/practice-sections/{sid}')
def delete_practice_section(sid:str):
    c=con();c.execute('DELETE FROM practice_sections WHERE id=?',(sid,));c.commit();c.close();return {'ok':True}

@app.delete('/api/tabs/{tid}')
def delete_tab(tid:str):
    c=con();r=c.execute('SELECT * FROM tab_captures WHERE id=?',(tid,)).fetchone()
    if r:
        (TABS/r['image_file']).unlink(missing_ok=True)
        if r['crop_file']:(TABS/r['crop_file']).unlink(missing_ok=True)
        c.execute('DELETE FROM tab_captures WHERE id=?',(tid,));c.commit()
        cnt=c.execute('SELECT COUNT(*) n FROM tab_captures WHERE lesson_id=?',(r['lesson_id'],)).fetchone()['n']
        if not cnt:
            lr=c.execute('SELECT notation_sheet_file FROM lessons WHERE id=?',(r['lesson_id'],)).fetchone()
            if lr and lr['notation_sheet_file']:(SHEETS/lr['notation_sheet_file']).unlink(missing_ok=True)
            update_lesson(r['lesson_id'],has_tab=0,notation_type='video',notation_sheet_file=None,notation_confidence=0)
    c.close();return {'ok':True}

@app.delete('/api/lessons/{lid}')
def delete_lesson(lid:str,hard:bool=False):
    c=con();r=c.execute('SELECT * FROM lessons WHERE id=?',(lid,)).fetchone()
    if not r:c.close();raise HTTPException(404)
    if not hard:c.execute('UPDATE lessons SET deleted=1,updated_at=? WHERE id=?',(time.time(),lid));c.commit();c.close();return {'ok':True}
    tabs=c.execute('SELECT * FROM tab_captures WHERE lesson_id=?',(lid,)).fetchall()
    for t in tabs:
        (TABS/t['image_file']).unlink(missing_ok=True)
        if t['crop_file']:(TABS/t['crop_file']).unlink(missing_ok=True)
    if r['notation_sheet_file']:(SHEETS/r['notation_sheet_file']).unlink(missing_ok=True)
    if r['video_file']:(VIDEOS/r['video_file']).unlink(missing_ok=True)
    if r['thumb_file']:(THUMBS/r['thumb_file']).unlink(missing_ok=True)
    c.execute('DELETE FROM tab_captures WHERE lesson_id=?',(lid,));c.execute('DELETE FROM practice_sections WHERE lesson_id=?',(lid,));c.execute('DELETE FROM lesson_collections WHERE lesson_id=?',(lid,));c.execute('DELETE FROM lessons WHERE id=?',(lid,));c.commit();c.close();return {'ok':True}
@app.post('/api/lessons/{lid}/restore')
def restore(lid:str):update_lesson(lid,deleted=0);return {'ok':True}

@app.get('/media/{kind}/{filename}')
def media(kind:str,filename:str):
    root={'videos':VIDEOS,'thumbs':THUMBS,'tabs':TABS,'sheets':SHEETS}.get(kind)
    if not root:raise HTTPException(404)
    p=(root/filename).resolve()
    if root.resolve() not in p.parents or not p.exists():raise HTTPException(404)
    return FileResponse(p)


@app.get('/api/collections')
def list_collections():
    c=con()
    rows=c.execute('''SELECT c.id,c.name,c.created_at,SUM(CASE WHEN l.id IS NOT NULL THEN 1 ELSE 0 END) AS count FROM collections c LEFT JOIN lesson_collections lc ON lc.collection_id=c.id LEFT JOIN lessons l ON l.id=lc.lesson_id AND l.deleted=0 GROUP BY c.id ORDER BY c.name COLLATE NOCASE''').fetchall()
    out=[dict(r) for r in rows]; c.close(); return out

@app.post('/api/collections')
def create_collection(p:CollectionIn):
    name=' '.join(p.name.strip().split())
    if not name: raise HTTPException(400,'Collection name is required')
    c=con(); existing=c.execute('SELECT * FROM collections WHERE name=? COLLATE NOCASE',(name,)).fetchone()
    if existing:
        out=dict(existing); c.close(); return out
    cid=str(uuid.uuid4()); now=time.time(); c.execute('INSERT INTO collections(id,name,created_at) VALUES(?,?,?)',(cid,name[:80],now)); c.commit(); c.close(); return {'id':cid,'name':name[:80],'created_at':now}

@app.patch('/api/collections/{cid}')
def rename_collection(cid:str,p:CollectionIn):
    name=' '.join(p.name.strip().split())
    if not name: raise HTTPException(400,'Collection name is required')
    c=con()
    try: c.execute('UPDATE collections SET name=? WHERE id=?',(name[:80],cid)); c.commit()
    except sqlite3.IntegrityError: c.close(); raise HTTPException(409,'Collection with this name already exists')
    c.close(); return {'ok':True}

@app.delete('/api/collections/{cid}')
def delete_collection(cid:str):
    c=con(); c.execute('DELETE FROM lesson_collections WHERE collection_id=?',(cid,)); c.execute('DELETE FROM collections WHERE id=?',(cid,)); c.commit(); c.close(); return {'ok':True}

@app.post('/api/lessons/{lid}/collections/{cid}')
def add_lesson_collection(lid:str,cid:str):
    c=con()
    if not c.execute('SELECT 1 FROM lessons WHERE id=?',(lid,)).fetchone(): c.close(); raise HTTPException(404,'Lesson not found')
    if not c.execute('SELECT 1 FROM collections WHERE id=?',(cid,)).fetchone(): c.close(); raise HTTPException(404,'Collection not found')
    c.execute('INSERT OR IGNORE INTO lesson_collections(lesson_id,collection_id) VALUES(?,?)',(lid,cid)); c.commit(); c.close(); return {'ok':True}

@app.delete('/api/lessons/{lid}/collections/{cid}')
def remove_lesson_collection(lid:str,cid:str):
    c=con(); c.execute('DELETE FROM lesson_collections WHERE lesson_id=? AND collection_id=?',(lid,cid)); c.commit(); c.close(); return {'ok':True}

@app.post('/api/lessons/{lid}/practiced')
def mark_practiced(lid:str):
    update_lesson(lid,last_practiced=time.time()); return {'ok':True}


app.mount('/static',StaticFiles(directory=STATIC),name='static')
@app.on_event('startup')
def startup_library_backfill():
    # Existing local videos are analyzed in place; nothing is re-downloaded.
    def work():
        backfill_content_library()
        backfill_notation_library()
    threading.Timer(2.0, lambda: threading.Thread(target=work,daemon=True).start()).start()

@app.get('/')
def index():return FileResponse(STATIC/'index.html')
@app.get('/{path:path}')
def spa(path:str):return FileResponse(STATIC/'index.html')
