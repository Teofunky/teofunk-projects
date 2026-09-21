#!/usr/bin/env python3
import csv, io, json, math, os, sqlite3, statistics, threading, urllib.parse, urllib.request
from datetime import date, datetime, timedelta, timezone
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DB_PATH = ROOT / 'data' / 'barometer.sqlite3'
STATIC = ROOT / 'static'
HOST = os.getenv('BAROMETER_HOST', '127.0.0.1')
PORT = int(os.getenv('BAROMETER_PORT', '8765'))
MOSCOW_LAT, MOSCOW_LON = 55.7558, 37.6176

SEGMENTS = {
    'total': 'Общая тенденция',
    'concerts': 'Концертная индустрия',
    'jazz': 'Джазовые клубы',
    'theatres': 'Театры',
    'foodservice': 'Общепит',
    'restaurants': 'Рестораны',
    'retail': 'Магазины / ритейл',
    'search': 'Поисковый спрос',
    'macro': 'Экономика',
    'event_stress': 'Event Stress',
    'weather': 'Погода',
}

SCHEMA = '''
PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS observations(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  observed_date TEXT NOT NULL,
  segment TEXT NOT NULL,
  metric TEXT NOT NULL,
  value REAL NOT NULL,
  unit TEXT NOT NULL DEFAULT 'index',
  geography TEXT NOT NULL DEFAULT 'Москва',
  source_name TEXT NOT NULL,
  source_url TEXT,
  source_tier TEXT NOT NULL DEFAULT 'C',
  kind TEXT NOT NULL DEFAULT 'observed',
  retrieved_at TEXT NOT NULL,
  UNIQUE(observed_date, segment, metric, source_name, kind)
);
CREATE TABLE IF NOT EXISTS forecasts(
  created_at TEXT NOT NULL,
  segment TEXT NOT NULL,
  horizon_days INTEGER NOT NULL,
  target_date TEXT NOT NULL,
  point REAL NOT NULL,
  low REAL NOT NULL,
  high REAL NOT NULL,
  model_version TEXT NOT NULL,
  PRIMARY KEY(created_at, segment, horizon_days, model_version)
);
CREATE TABLE IF NOT EXISTS events(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_date TEXT NOT NULL,
  category TEXT NOT NULL,
  title TEXT NOT NULL,
  severity REAL NOT NULL DEFAULT 0,
  confirmed INTEGER NOT NULL DEFAULT 0,
  source_name TEXT,
  source_url TEXT,
  notes TEXT,
  UNIQUE(event_date, category, title)
);
CREATE TABLE IF NOT EXISTS settings(
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
'''

def db():
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    return con

def init_db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    with db() as con:
        con.executescript(SCHEMA)
        con.execute("INSERT OR IGNORE INTO settings(key,value) VALUES('mode','live')")

def now_iso():
    return datetime.now(timezone.utc).isoformat()

def upsert_obs(d, segment, metric, value, unit, source_name, source_url='', tier='C', kind='observed'):
    if segment not in SEGMENTS:
        raise ValueError(f'Unknown segment: {segment}')
    with db() as con:
        con.execute('''INSERT INTO observations(observed_date,segment,metric,value,unit,geography,source_name,source_url,source_tier,kind,retrieved_at)
        VALUES(?,?,?,?,?,'Москва',?,?,?,?,?)
        ON CONFLICT(observed_date,segment,metric,source_name,kind) DO UPDATE SET
          value=excluded.value, unit=excluded.unit, source_url=excluded.source_url, source_tier=excluded.source_tier, retrieved_at=excluded.retrieved_at''',
          (d,segment,metric,float(value),unit,source_name,source_url,tier,kind,now_iso()))

def import_csv_bytes(raw):
    text = raw.decode('utf-8-sig')
    r = csv.DictReader(io.StringIO(text))
    required = {'date','segment','value','source_name'}
    if not required.issubset(set(r.fieldnames or [])):
        raise ValueError('CSV должен содержать колонки: date, segment, value, source_name. Дополнительно: metric, unit, source_url, source_tier, kind.')
    n=0
    for row in r:
        upsert_obs(row['date'], row['segment'], row.get('metric') or 'demand_index', float(row['value']), row.get('unit') or 'index', row['source_name'], row.get('source_url') or '', row.get('source_tier') or 'C', row.get('kind') or 'observed')
        n += 1
    return n

def fetch_json(url, timeout=12):
    req=urllib.request.Request(url,headers={'User-Agent':'ConcertBarometer/0.1'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode('utf-8'))

def ingest_weather():
    # Open-Meteo is used as a no-key operational source. It can be replaced by a licensed/official adapter.
    today = date.today()
    start = today - timedelta(days=6)
    url = ('https://archive-api.open-meteo.com/v1/archive?latitude=%s&longitude=%s&start_date=%s&end_date=%s'
           '&daily=temperature_2m_mean,precipitation_sum,wind_speed_10m_max&timezone=Europe%%2FMoscow') % (MOSCOW_LAT,MOSCOW_LON,start.isoformat(),today.isoformat())
    try:
        j=fetch_json(url)
        ds=j.get('daily',{})
        dates=ds.get('time',[])
        vals=[]
        temps=ds.get('temperature_2m_mean',[]); rain=ds.get('precipitation_sum',[]); wind=ds.get('wind_speed_10m_max',[])
        for i,d in enumerate(dates):
            t = temps[i] if i < len(temps) and temps[i] is not None else 10
            p = rain[i] if i < len(rain) and rain[i] is not None else 0
            w = wind[i] if i < len(wind) and wind[i] is not None else 0
            # friction index: 100=neutral, above 100 worse weather. Transparent deterministic transform.
            friction = 100 + min(20,max(0,p))*1.2 + max(0,w-20)*0.35 + max(0,5-t)*0.8
            upsert_obs(d,'weather','friction_index',friction,'index','Open-Meteo','https://open-meteo.com/','B','observed')
            vals.append(friction)
        return {'ok':True,'rows':len(vals),'latest':vals[-1] if vals else None}
    except Exception as e:
        return {'ok':False,'error':str(e)}

def seed_demo():
    # Explicit synthetic fixture, never represented as live market data.
    today=date.today()
    with db() as con:
        n=con.execute("SELECT COUNT(*) c FROM observations WHERE source_name='SYNTHETIC DEMO'").fetchone()['c']
        if n: return 0
    seg_base={'concerts':98,'jazz':102,'theatres':100,'foodservice':95,'restaurants':93,'retail':97,'search':99,'macro':96,'event_stress':104,'weather':100}
    for days_ago in range(120,-1,-1):
        d=today-timedelta(days=days_ago)
        trend=(120-days_ago)/120
        season=5*math.sin((120-days_ago)/15)
        for idx,(seg,base) in enumerate(seg_base.items()):
            drift=(-4*trend if seg in ('foodservice','restaurants','macro') else 2*trend if seg=='jazz' else -1.5*trend)
            wave=season*math.sin((idx+1)*0.37)
            v=base+drift+wave
            upsert_obs(d.isoformat(),seg,'demand_index',round(v,2),'index','SYNTHETIC DEMO','', 'D','synthetic')
    return 121*len(seg_base)

def series(segment, days=400, include_synthetic=False):
    cutoff=(date.today()-timedelta(days=days)).isoformat()
    q="SELECT observed_date,value,source_name,kind,metric FROM observations WHERE segment=? AND observed_date>=?"
    args=[segment,cutoff]
    if not include_synthetic:
        q += " AND kind!='synthetic'"
    q += " ORDER BY observed_date"
    with db() as con:
        rows=con.execute(q,args).fetchall()
    by_date={}
    for r in rows:
        # If multiple real observations exist for same date/segment, average normalized index metrics only.
        if r['metric'].endswith('index') or r['metric']=='demand_index':
            by_date.setdefault(r['observed_date'],[]).append(float(r['value']))
    return [{'date':d,'value':sum(v)/len(v)} for d,v in sorted(by_date.items())]

def calc_stats(points):
    if not points:
        return {'now':None,'wow':None,'momentum':None,'seasonal_deviation':None,'confidence':0,'forecast':[]}
    vals=[p['value'] for p in points]
    now=vals[-1]
    wow=None
    if len(vals)>=8:
        base=vals[-8]
        wow=((now/base)-1)*100 if base else None
    recent=vals[-14:]
    prev=vals[-28:-14] if len(vals)>=28 else []
    momentum=(statistics.mean(recent)-statistics.mean(prev)) if prev else None
    baseline=statistics.mean(vals[-84:-28]) if len(vals)>=84 else statistics.mean(vals)
    seasonal=now-baseline
    # Simple transparent trend forecast. Production model will replace this after backtest.
    window=vals[-28:] if len(vals)>=28 else vals
    xs=list(range(len(window)))
    xm=statistics.mean(xs); ym=statistics.mean(window)
    den=sum((x-xm)**2 for x in xs)
    slope=sum((x-xm)*(y-ym) for x,y in zip(xs,window))/den if den else 0
    resid=[y-(ym+slope*(x-xm)) for x,y in zip(xs,window)]
    sigma=statistics.pstdev(resid) if len(resid)>2 else 3.0
    f=[]
    for h in (7,14,30,90):
        point=now+slope*h
        width=1.64*sigma*math.sqrt(1+h/max(7,len(window)))
        f.append({'horizon':h,'point':round(point,2),'low':round(point-width,2),'high':round(point+width,2)})
    confidence=min(92, max(20, 35+len(points)*0.15 - sigma*2))
    return {'now':round(now,2),'wow':None if wow is None else round(wow,2),'momentum':None if momentum is None else round(momentum,2),'seasonal_deviation':round(seasonal,2),'confidence':round(confidence,1),'forecast':f,'model':'transparent_linear_v0.1'}

def composite(use_demo=False):
    weights={'concerts':1.4,'jazz':1.0,'theatres':0.8,'foodservice':1.2,'restaurants':1.0,'retail':0.7,'search':1.3,'macro':1.0}
    all_dates={}
    for seg,w in weights.items():
        for p in series(seg,400,use_demo):
            all_dates.setdefault(p['date'],[]).append((p['value'],w))
    pts=[]
    for d,items in sorted(all_dates.items()):
        if len(items)>=2:
            pts.append({'date':d,'value':round(sum(v*w for v,w in items)/sum(w for v,w in items),2)})
    return pts

def dashboard(include_demo=False):
    data={}
    for key,label in SEGMENTS.items():
        pts=composite(include_demo) if key=='total' else series(key,400,include_demo)
        data[key]={'label':label,'series':pts,'stats':calc_stats(pts)}
    with db() as con:
        sources=[dict(r) for r in con.execute("SELECT source_name,source_tier,MAX(retrieved_at) last_seen,COUNT(*) observations FROM observations GROUP BY source_name,source_tier ORDER BY source_tier,source_name").fetchall()]
        events=[dict(r) for r in con.execute("SELECT * FROM events ORDER BY event_date DESC LIMIT 20").fetchall()]
    live_real = sum(1 for s in sources if s['source_name']!='SYNTHETIC DEMO')
    return {'generated_at':now_iso(),'mode':'demo+live' if include_demo else 'live-only','segments':data,'sources':sources,'events':events,'real_source_count':live_real}

class Handler(BaseHTTPRequestHandler):
    server_version='ConcertBarometer/0.1'
    def log_message(self, fmt,*args):
        print('[http]',fmt%args)
    def send_json(self,obj,status=200):
        raw=json.dumps(obj,ensure_ascii=False).encode('utf-8')
        self.send_response(status); self.send_header('Content-Type','application/json; charset=utf-8'); self.send_header('Content-Length',str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def send_file(self,path,ctype):
        raw=path.read_bytes(); self.send_response(200); self.send_header('Content-Type',ctype); self.send_header('Content-Length',str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def do_GET(self):
        u=urllib.parse.urlparse(self.path); qs=urllib.parse.parse_qs(u.query)
        if u.path=='/': return self.send_file(STATIC/'index.html','text/html; charset=utf-8')
        if u.path=='/api/dashboard': return self.send_json(dashboard(qs.get('demo',['0'])[0]=='1'))
        if u.path=='/api/segments': return self.send_json(SEGMENTS)
        if u.path=='/api/health': return self.send_json({'ok':True,'db':str(DB_PATH),'version':'0.1.0'})
        if u.path=='/api/template.csv':
            raw=('date,segment,metric,value,unit,source_name,source_url,source_tier,kind\n'
                 '2026-09-01,concerts,demand_index,101.2,index,My licensed source,https://example.com,A,observed\n').encode('utf-8')
            self.send_response(200);self.send_header('Content-Type','text/csv; charset=utf-8');self.send_header('Content-Disposition','attachment; filename="barometer_import_template.csv"');self.end_headers();self.wfile.write(raw);return
        self.send_error(404)
    def do_POST(self):
        u=urllib.parse.urlparse(self.path)
        if u.path=='/api/run':
            res={'weather':ingest_weather()}
            return self.send_json(res)
        if u.path=='/api/seed-demo':
            return self.send_json({'inserted':seed_demo()})
        if u.path=='/api/import':
            length=int(self.headers.get('Content-Length','0')); raw=self.rfile.read(length)
            try: return self.send_json({'inserted':import_csv_bytes(raw)})
            except Exception as e: return self.send_json({'error':str(e)},400)
        self.send_error(404)

def main():
    init_db()
    print(f'Concert Barometer 0.1 -> http://{HOST}:{PORT}')
    print('Live mode never invents missing market data. Use Demo only for interface testing.')
    ThreadingHTTPServer((HOST,PORT),Handler).serve_forever()

if __name__=='__main__': main()
