# Движок перекладки для линейных рисунков: фигуры, части, вид камеры, живая линия.
import cv2, numpy as np, math, subprocess
from scipy import ndimage as ndi
W,H,FPS=1920,1080,24
disk=lambda s: cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(s,s))

def level(path):
    g=cv2.imread(path,0).astype(np.float32)
    return np.clip(g*255/247,0,255).astype(np.uint8)

def poly_mask(shape,poly):
    m=np.zeros(shape,np.uint8); cv2.fillPoly(m,[np.int32(np.round(poly))],1); return m>0
def ellipse_mask(shape,c,r):
    m=np.zeros(shape,np.uint8); cv2.ellipse(m,(int(c[0]),int(c[1])),(int(r[0]),int(r[1])),0,0,360,1,-1); return m>0

class Sheet:
    def __init__(self,g):
        self.g=g; self.ink=g<215; self.taken=np.zeros(g.shape,bool)
    def ink_region(self,poly,close=9):
        """Линии внутри многоугольника плюс залитые ими замкнутые места (тело рыбы)."""
        pm=poly_mask(self.g.shape,poly)
        ink=self.ink&pm&~self.taken
        m=cv2.morphologyEx(ink.astype(np.uint8),cv2.MORPH_CLOSE,disk(close))>0
        m=ndi.binary_fill_holes(m)
        m|=cv2.dilate(ink.astype(np.uint8),disk(3))>0
        return m&pm
    def solid(self,poly=None,ell=None):
        return poly_mask(self.g.shape,poly) if poly is not None else ellipse_mask(self.g.shape,*ell)

class Piece:
    def __init__(self,sheet,name,mask,z=0,pivot=(0,0),parent=None,pose=None,deform=None,keep_white=True):
        g=sheet.g; ys,xs=np.nonzero(mask)
        x0,x1,y0,y1=max(0,xs.min()-4),min(g.shape[1],xs.max()+5),max(0,ys.min()-4),min(g.shape[0],ys.max()+5)
        self.x0,self.y0=x0,y0
        self.g=g[y0:y1,x0:x1].astype(np.float32)
        a=(mask[y0:y1,x0:x1]*255).astype(np.uint8)
        self.a=cv2.GaussianBlur(a,(3,3),0).astype(np.float32)
        self.name,self.z,self.pivot,self.parent=name,z,pivot,parent
        self.pose=pose or (lambda t:{}); self.deform=deform
        self.cx,self.cy=float(xs.mean()),float(ys.mean())
        self.w,self.h=x1-x0,y1-y0

def T(x,y): return np.array([[1,0,x],[0,1,y],[0,0,1]],np.float64)
def R(deg,sx=1.0,sy=None):
    a=math.radians(deg); c,s=math.cos(a),math.sin(a); sy=sx if sy is None else sy
    return np.array([[c*sx,-s*sy,0],[s*sx,c*sy,0],[0,0,1]],np.float64)

class Scene:
    def __init__(self,sheet,view,seconds,boil=1.0,crisp=None):
        """view: (x0,y0,width_in_sheet_px)."""
        self.sheet,self.seconds=sheet,seconds
        x0,y0,vw=view; s=W/vw; self.s=s
        self.V=np.array([[s,0,-x0*s],[0,s,-y0*s],[0,0,1]],np.float64)
        self.pieces={}; self.boil=boil
        self.crisp=crisp if crisp is not None else (s>1.25)
        self.overlay=None
        r=np.random.default_rng(11)
        def nf(seed):
            gg=np.random.default_rng(seed).normal(0,1,(H//45+2,W//45+2)).astype(np.float32)
            return cv2.resize(gg,(W,H),interpolation=cv2.INTER_CUBIC)
        self.BX=[nf(i) for i in range(3)]; self.BY=[nf(20+i) for i in range(3)]
        self.gx,self.gy=np.meshgrid(np.arange(W,dtype=np.float32),np.arange(H,dtype=np.float32))
    def add(self,p): self.pieces[p.name]=p; return p
    def world(self,p,t,cache):
        if p.name in cache: return cache[p.name]
        st=p.pose(t); px,py=p.pivot
        M=T(px+st.get('dx',0),py+st.get('dy',0))@R(st.get('ang',0),st.get('sc',1),st.get('scy',None))@T(-px,-py)
        if st.get('flip'):
            M=M@T(px,py)@np.diag([-1,1,1])@T(-px,-py)
        if p.parent: M=self.world(self.pieces[p.parent],t,cache)@M
        cache[p.name]=M; return M
    def frame(self,t,fi):
        can=np.full((H,W),255.0,np.float32); cache={}
        for p in sorted(self.pieces.values(),key=lambda q:(q.z(t) if callable(q.z) else q.z)):
            g,a=p.g,p.a
            if p.deform: g,a=p.deform(p,t)
            M=(self.V@self.world(p,t,cache)@T(p.x0,p.y0))[:2]
            hh,ww=g.shape
            c=np.array([[0,0,1],[ww,0,1],[0,hh,1],[ww,hh,1]],np.float64)@M.T
            x0,y0=np.floor(c.min(0)).astype(int); x1,y1=np.ceil(c.max(0)).astype(int)
            x0,y0=max(x0,0),max(y0,0); x1,y1=min(x1,W),min(y1,H)
            if x1<=x0 or y1<=y0: continue
            M2=M.copy(); M2[:,2]-=[x0,y0]
            st=np.dstack([g,a])
            wp=cv2.warpAffine(st,M2.astype(np.float32),(x1-x0,y1-y0),flags=cv2.INTER_CUBIC,borderMode=cv2.BORDER_CONSTANT,borderValue=(255,0))
            al=np.clip(wp[...,1],0,255)/255.0
            can[y0:y1,x0:x1]=can[y0:y1,x0:x1]*(1-al)+np.clip(wp[...,0],0,255)*al
        if self.overlay: self.overlay(can,t)
        i=(fi//2)%3; amp=0.8+0.25*self.s
        can=cv2.remap(can,self.gx+self.BX[i]*amp,self.gy+self.BY[i]*amp,cv2.INTER_LINEAR,borderMode=cv2.BORDER_REPLICATE)
        if self.crisp:
            can=255.0/(1.0+np.exp(-(can-150.0)/16.0))
        return np.clip(can,0,255).astype(np.uint8)
    def render(self,out,snap=()):
        N=int(round(self.seconds*FPS))
        ff=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','gray','-s',f'{W}x{H}','-r',str(FPS),'-i','-',
            '-c:v','libx264','-preset','veryfast','-crf','17','-pix_fmt','yuv420p',out],stdin=subprocess.PIPE)
        last=None
        for f in range(N):
            if f%2==0: last=self.frame(f/FPS,f)
            if f in snap: cv2.imwrite(out.replace('.mp4',f'_{f:03d}.png'),last)
            ff.stdin.write(last.tobytes())
        ff.stdin.close(); ff.wait()

# ---- деформации
def fish_wiggle(direction,freq,amp=0.07,phase=0.0):
    def d(p,t):
        h,w=p.g.shape; xs=np.arange(w,dtype=np.float32)
        u=(xs/(w-1)) if direction<0 else (1-xs/(w-1))
        off=amp*h*u**1.6*np.sin(2*math.pi*freq*t-2.4*u+phase)
        mx=np.tile(xs,(h,1)); my=(np.arange(h,dtype=np.float32)[:,None]-off[None,:]).astype(np.float32)
        return (cv2.remap(p.g,mx,my,cv2.INTER_LINEAR,borderValue=255),cv2.remap(p.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d
def sway(amp,freq,anchor='bottom',k=0.03,power=1.4,phase=0.0):
    """Изгиб водоросли или пряди: верх ходит сильнее, волна бежит по x."""
    def d(p,t):
        h,w=p.g.shape; ys=np.arange(h,dtype=np.float32)[:,None]; xs=np.arange(w,dtype=np.float32)[None,:]
        u=((h-1-ys)/(h-1)) if anchor=='bottom' else (ys/(h-1))
        off=amp*u**power*np.sin(2*math.pi*freq*t+k*(xs+p.x0)+phase)
        mx=(xs-off).astype(np.float32); my=np.broadcast_to(ys,(h,w)).astype(np.float32)
        return (cv2.remap(p.g,mx,my,cv2.INTER_LINEAR,borderValue=255),cv2.remap(p.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d
def wobble(amp,freq,cell=14):
    rng=np.random.default_rng(5)
    def d(p,t):
        h,w=p.g.shape
        if not hasattr(p,'_wb'):
            gs=[cv2.resize(rng.normal(0,1,(h//cell+2,w//cell+2)).astype(np.float32),(w,h),interpolation=cv2.INTER_CUBIC) for _ in range(4)]
            p._wb=gs; p._xy=np.meshgrid(np.arange(w,dtype=np.float32),np.arange(h,dtype=np.float32))
        a,b,c,e=p._wb; ph=2*math.pi*freq*t
        ox=amp*(a*math.sin(ph)+b*math.cos(ph)); oy=amp*(c*math.sin(ph*1.3)+e*math.cos(ph*1.3))
        mx,my=p._xy[0]+ox,p._xy[1]+oy
        return (cv2.remap(p.g,mx,my,cv2.INTER_LINEAR,borderValue=255),cv2.remap(p.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d
def spin(center,angle_fn):
    """Крутится содержимое, маска стоит на месте (линза)."""
    def d(p,t):
        cx,cy=float(center[0]-p.x0),float(center[1]-p.y0)
        M=cv2.getRotationMatrix2D((cx,cy),angle_fn(t),1.0)
        g=cv2.warpAffine(p.g,M,(p.g.shape[1],p.g.shape[0]),flags=cv2.INTER_CUBIC,borderValue=255)
        return g,p.a
    return d

def ease(x): x=min(max(x,0.0),1.0); return x*x*(3-2*x)
def swim(direction,speed,s0=0.5,acc=0.6,start=0.0,mean_amp=0.0,mean_T=1.6,vy=0.0):
    def dist(t):
        t=max(0.0,t-start)
        return speed*(s0*t+(1-s0)*(t*t/(2*acc) if t<acc else acc/2+(t-acc)))
    def pose(t):
        x=direction*dist(t); tt=max(0.0,t-start)
        y=mean_amp*math.sin(2*math.pi*tt/mean_T)+vy*tt
        slope=(mean_amp*2*math.pi/mean_T*math.cos(2*math.pi*tt/mean_T)+vy)/max(1.0,speed)
        return dict(dx=x,dy=y,ang=-math.degrees(math.atan(slope))*direction*0.8)
    return pose
