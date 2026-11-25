import cv2, numpy as np, json, math, subprocess, sys
W,H,FPS=1920,1080,24; SEC=float(sys.argv[1]) if len(sys.argv)>1 else 5.0; OUT=sys.argv[2] if len(sys.argv)>2 else 'scene1_v2.mp4'
OX=420
names=json.load(open('names.json')); sp=json.load(open('sprites.json'))
S={n:cv2.imread(f'spr_{n}.png',cv2.IMREAD_UNCHANGED).astype(np.float32) for n in names}
fish=[n for n in names if n.startswith('f_')]; faces=[n for n in names if not n.startswith('f_')]
rng=np.random.default_rng(7)
# ---- face rig
F={}
for n in faces:
    o=sp[n]
    F[n]=dict(px=o['cx']-o['x0'], py=o['bottom']-o['y0'],  # pivot at chin, sprite-local
              A=rng.uniform(3.0,5.0), T=rng.uniform(1.3,2.1), ph=rng.uniform(0,6.28),
              bobA=rng.uniform(3,6), bobT=rng.uniform(0.9,1.5), bph=rng.uniform(0,6.28),
              th=0.0, om=0.0, dy=0.0, vy=0.0)
# ---- fish paths: (dir, speed px/s, start speed frac, vertical meander amp, meander period, vertical drift px/s, wiggle freq)
P={'f_big':(-1,330,0.5,26,2.2,8,2.2),'f_top':(-1,290,0.6,18,1.6,-4,3.0),'f_small':(-1,300,0.6,30,1.4,45,3.4),
   'f_stripe':(-1,360,0.5,34,1.8,-14,3.0),'f_long':(-1,260,0.4,22,2.0,14,2.4),'f_bl':(1,330,0.5,24,1.7,-22,3.2)}
def fish_state(n,t):
    d,v,s0,am,mt,vy,wf=P[n]
    acc=0.6  # seconds to reach full speed
    dist=v*(s0*t+(1-s0)*(t*t/(2*acc) if t<acc else acc/2+(t-acc)))
    x=d*dist; y=am*math.sin(2*math.pi*t/mt)+vy*t
    slope=(am*2*math.pi/mt*math.cos(2*math.pi*t/mt)+vy)/max(1,v)
    ang=-math.degrees(math.atan2(slope*1.0, 1.0))*d*0.8
    return x,y,ang,wf
def wiggle(n,t,wf):
    s=S[n]; h,w=s.shape[:2]; d=P[n][0]
    xs=np.arange(w,dtype=np.float32)
    u=(xs/(w-1)) if d<0 else (1-xs/(w-1))   # 0 at head, 1 at tail
    amp=0.07*h*u**1.6
    off=amp*np.sin(2*math.pi*wf*t-2.4*u)
    mx=np.tile(xs,(h,1)); my=(np.arange(h,dtype=np.float32)[:,None]-off[None,:]).astype(np.float32)
    return cv2.remap(s,mx,my,cv2.INTER_LINEAR,borderValue=(255,255,255,0))
def paste(can,spr,M):
    h,w=spr.shape[:2]
    c=np.array([[0,0,1],[w,0,1],[0,h,1],[w,h,1]],np.float32)@M.T
    x0,y0=np.floor(c.min(0)).astype(int); x1,y1=np.ceil(c.max(0)).astype(int)
    x0,y0=max(x0,0),max(y0,0); x1,y1=min(x1,W),min(y1,H)
    if x1<=x0 or y1<=y0: return
    M2=M.copy(); M2[:,2]-=[x0,y0]
    wp=cv2.warpAffine(spr,M2,(x1-x0,y1-y0),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT,borderValue=(255,255,255,0))
    a=wp[...,3:4]/255.0; reg=can[y0:y1,x0:x1]
    can[y0:y1,x0:x1]=reg*(1-a)+wp[...,:3]*a
def M_of(o,px,py,ang,dx,dy,sc=1.0):
    # rotate+scale about sprite-local pivot, place at canvas
    a=math.radians(ang); ca,sa=math.cos(a)*sc,math.sin(a)*sc
    X=OX+o['x0']+px+dx; Y=o['y0']+py+dy
    return np.array([[ca,-sa,X-ca*px+sa*py],[sa,ca,Y-sa*px-ca*py]],np.float32)
# boil fields (3 cycling), smooth noise
def noise_field(seed):
    r=np.random.default_rng(seed); g=r.normal(0,1,(H//40+2,W//40+2)).astype(np.float32)
    return cv2.resize(g,(W,H),interpolation=cv2.INTER_CUBIC)
BX=[noise_field(i) for i in range(3)]; BY=[noise_field(10+i) for i in range(3)]
BOIL=0.9
gx,gy=np.meshgrid(np.arange(W,dtype=np.float32),np.arange(H,dtype=np.float32))
order_faces=sorted(faces,key=lambda n:(n!='M',sp[n]['bottom']))  # M first (big white), then by depth
ff=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-',
    '-c:v','libx264','-preset','veryfast','-crf','17','-pix_fmt','yuv420p',OUT],stdin=subprocess.PIPE)
N=int(SEC*FPS); dt=2/FPS; last=None
for f in range(N):
    if f%2==0:
        t=f/FPS
        # fish positions (canvas centers)
        fpos={}
        for n in fish:
            x,y,ang,wf=fish_state(n,t); o=sp[n]
            fpos[n]=(OX+o['cx']+x, o['cy']+y, P[n][0])
        # face springs: impulses from nearby fish
        for n in faces:
            st=F[n]; o=sp[n]; cx,cy=OX+o['cx'],o['cy']
            push=0.0; lift=0.0
            for m,(fx,fy,d) in fpos.items():
                dd=math.hypot(fx-cx,fy-cy)
                if dd<260: 
                    wgt=(1-dd/260)**2
                    push+=d*wgt*12.0; lift+=-wgt*8
            w0=2*math.pi/0.9; z=0.35
            acc=-w0*w0*(st['th']-push)-2*z*w0*st['om']
            st['om']+=acc*dt; st['th']+=st['om']*dt
            if abs(t-3.6)<1e-6 or (t>=3.6 and t-dt<3.6): st['vy']-=260
            accy=-w0*w0*(st['dy']-lift)-2*z*w0*st['vy']; st['vy']+=accy*dt; st['dy']+=st['vy']*dt
        can=np.full((H,W,3),255.0,np.float32)
        for n in order_faces:
            st=F[n]; o=sp[n]
            ang=st['A']*math.sin(2*math.pi*t/st['T']+st['ph'])+st['th']
            bob=st['bobA']*math.sin(2*math.pi*t/st['bobT']+st['bph'])+st['dy']
            sc=1+0.018*math.sin(2*math.pi*t/st['bobT']*0.5+st['ph'])
            paste(can,S[n],M_of(o,st['px'],st['py'],ang,0,bob,sc))
        for n in fish:
            x,y,ang,wf=fish_state(n,t); o=sp[n]
            spr=wiggle(n,t,wf)
            paste(can,spr,M_of(o,o['cx']-o['x0'],o['cy']-o['y0'],ang,x,y))
        i=(f//2)%3
        frame=cv2.remap(can,gx+BX[i]*BOIL,gy+BY[i]*BOIL,cv2.INTER_LINEAR,borderMode=cv2.BORDER_REPLICATE)
        last=np.clip(frame,0,255).astype(np.uint8)
        if f in (0,24,48,72,96,118): cv2.imwrite(f'fr_{f:03d}.png',last)
    ff.stdin.write(last.tobytes())
ff.stdin.close(); ff.wait(); print('done',OUT)
