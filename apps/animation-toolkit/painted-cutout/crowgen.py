# Ворона, нарисованная кодом: 3D-скелет крыла (плечо, кисть, маховые «пальцы»), проекция в силуэт,
# фактура с нарисованной вороны. Цикл: взмах вниз 56 %, на подъёме кисть складывается и уходит назад.
import numpy as np, cv2, math
from film2 import ease
TIP=[(0.0,0.075),(0.25,0.065),(0.50,0.0),(0.455,-0.03),(0.505,-0.05),(0.445,-0.075),(0.48,-0.10),(0.42,-0.112),
     (0.44,-0.135),(0.39,-0.135),(0.385,-0.15),(0.32,-0.14),(0.27,-0.148),(0.21,-0.138),(0.15,-0.146),(0.09,-0.136),(0.04,-0.142),(0.0,-0.13)]
def rotx(a): c,s=math.cos(a),math.sin(a); return np.array([[1,0,0],[0,c,-s],[0,s,c]])
def roty(a): c,s=math.cos(a),math.sin(a); return np.array([[c,0,s],[0,1,0],[-s,0,c]])
def rotz(a): c,s=math.cos(a),math.sin(a); return np.array([[c,-s,0],[s,c,0],[0,0,1]])
def wing_state(c,glide=False,flare=0.0,deep=1.0):
    if glide: return math.radians(6),math.radians(4),0.0
    up,down=math.radians(52*deep),math.radians(-36*deep)
    if c<0.56:
        u=ease(c/0.56); phi=up+(down-up)*u; fold=0.0
    else:
        u=ease((c-0.56)/0.44); phi=down+(up-down)*u; fold=math.sin(math.pi*(c-0.56)/0.44)
    phih=phi-math.radians(28)*fold
    return phi,phih,fold
def wing_pts(side,phi,phih,fold,flare=0.0):
    sh=np.array([0.05,0.055*side,0.035])
    pts=[]
    for (u,v) in TIP:
        if u<=0.26:
            p=np.array([v,u*side,0.0]); p=rotx(phi*side)@p
            pts.append(sh+p)
        else:
            wr=rotx(phi*side)@np.array([0.0,0.26*side,0.0])
            du=(u-0.26)*(1-0.38*fold); dv=v
            psi=math.radians(48)*fold+math.radians(20)*flare
            # кисть уходит назад (вдоль хорды) и чуть складывается
            q=np.array([dv-math.sin(psi)*du, math.cos(psi)*du*side,0.0])
            q=rotx(phih*side)@q
            pts.append(sh+wr+q)
    return np.array(pts)
def body_polys(c,flare=0.0):
    out=[]
    # тело: эллипсоид, голова, клюв, хвост
    t=np.linspace(0,2*math.pi,24)
    for z0 in (0.0,):
        pass
    ell=[]
    for a in np.linspace(0,math.pi,9):
        for b in np.linspace(0,2*math.pi,14):
            ell.append([0.02+0.17*math.cos(a),0.068*math.sin(a)*math.cos(b),0.078*math.sin(a)*math.sin(b)])
    out.append(('hull',np.array(ell)))
    head=[]
    for a in np.linspace(0,math.pi,7):
        for b in np.linspace(0,2*math.pi,10):
            head.append([0.22+0.062*math.cos(a),0.052*math.sin(a)*math.cos(b),0.035+0.058*math.sin(a)*math.sin(b)])
    out.append(('hull',np.array(head)))
    out.append(('hull',np.array([[0.26,0,0.06],[0.36,0,0.028],[0.26,0,0.005],[0.26,0.022,0.033],[0.26,-0.022,0.033]])))
    tl=-0.36-0.03*flare; tw=0.085+0.05*flare; tz=-0.015-0.08*flare
    out.append(('hull',np.array([[-0.12,0.04,0.02],[-0.12,-0.04,0.02],[-0.12,0,-0.03],[tl,tw,tz+0.015],[tl,-tw,tz+0.015],[tl,tw,tz-0.015],[tl,-tw,tz-0.015],[tl-0.012,0,tz]])))
    return out
def project(P,yaw,pitch,bank,elev):
    R=rotx(elev)@rotz(yaw)@roty(-pitch)@rotx(bank)
    Q=(R@P.T).T
    return np.stack([Q[:,0],-Q[:,2]],1),Q[:,1]
def crow_alpha(c,yaw=0.0,pitch=0.0,bank=0.0,elev=math.radians(-24),S=100.0,size=None,glide=False,flare=0.0,deep=1.0,ss=4):
    phi,phih,fold=wing_state(c,glide,flare,deep)
    bob=0.02*math.sin(2*math.pi*min(1,c/0.56)) if not glide else 0.0
    items=[]
    for side in (1,-1):
        items.append(('poly',wing_pts(side,phi,phih,fold,flare)))
    items+=body_polys(c,flare)
    size=size or int(S*1.3)
    can=np.zeros((size*ss,size*ss),np.uint8); cx=cy=size*ss/2
    for kind,P in items:
        P=P+np.array([0,0,bob])
        xy,_=project(P,yaw,pitch,bank,elev)
        xy=xy*S*ss+np.array([cx,cy])
        if kind=='hull': xy=cv2.convexHull(np.float32(xy)).reshape(-1,2)
        cv2.fillPoly(can,[np.int32(np.round(xy))],255,lineType=cv2.LINE_AA)
    a=cv2.resize(can,(size,size),interpolation=cv2.INTER_AREA).astype(np.float32)/255.0
    return a
class CrowTexture:
    """Цвет и зерно чёрных ворон картины: средний цвет тёмных пикселей + мягкий шум без полос."""
    def __init__(self,img,box):
        x0,y0,x1,y1=box; p=img[y0:y1,x0:x1].astype(np.float32).reshape(-1,3)
        lum=p.mean(1); d=p[lum<np.percentile(lum,40)]
        self.mean=d.mean(0); sd=float(d.mean(1).std())
        rng=np.random.default_rng(3)
        n1=cv2.GaussianBlur(rng.normal(0,1,(1100,1100)).astype(np.float32),(0,0),1.2)
        n2=cv2.GaussianBlur(rng.normal(0,1,(1100,1100)).astype(np.float32),(0,0),6)
        n=(0.7*n1/n1.std()+0.6*n2/n2.std())
        self.tile=self.mean[None,None,:]+n[...,None]*min(14.0,sd)
    def get(self,h,w,ox=0,oy=0):
        return self.tile[oy%50:oy%50+h,ox%50:ox%50+w]

class ProcCrow:
    """Спрайт для PScene: ворона рисуется кодом каждый кадр. params(t) -> c, yaw, pitch, bank, glide, flare, deep."""
    def __init__(self,name,center,span_px,tex,params,pose,z=5,ovs=2.0,op=None):
        self.name,self.z,self.parent=name,z,None
        self.S=span_px/1.0; self.size=int(span_px*1.5)+8; self.ovs=ovs
        self.x0=center[0]-self.size/2; self.y0=center[1]-self.size/2
        self.pivot=(center[0],center[1]); self.cx,self.cy=center
        self.tex=tex; self.params=params; self.opf=op or (lambda t:1.0)
        self._pose=pose
        self.rgb=np.zeros((1,1,3),np.float32); self.a=np.zeros((1,1),np.float32)
        self.rng=np.random.default_rng(abs(hash(name))%2**32)
        n=int(self.size*ovs); self.grain=cv2.GaussianBlur(self.rng.normal(0,1,(n,n)).astype(np.float32),(0,0),0.8*ovs)
    def pose(self,t):
        d=dict(self._pose(t)); d['op']=self.opf(t); return d
    def deform(self,sp,t):
        pr=self.params(t); n=int(self.size*self.ovs)
        a=crow_alpha(pr.get('c',0.0),pr.get('yaw',0.3),pr.get('pitch',0.0),pr.get('bank',0.0),pr.get('elev',math.radians(-24)),
                     S=self.S*self.ovs,size=n,glide=pr.get('glide',False),flare=pr.get('flare',0.0),deep=pr.get('deep',1.0),ss=3)
        a=cv2.GaussianBlur(a,(0,0),0.85*self.ovs)
        a=np.clip(a*(0.9+0.16*self.grain),0,1)
        rgb=self.tex.get(n,n,int(self.cx),int(self.cy))
        return rgb,a*255.0
def crow_params(f,phase,heading_fn,glides=(),deep_fn=None,f_fn=None,flare_fn=None,yaw_bias=0.45):
    """Фаза взмаха и ракурс из направления полёта на экране."""
    clock=[0.0,0.0]
    def p(t):
        ff=f_fn(t) if f_fn else f
        c=(ff*t+phase)%1.0
        gl=any(a<=t<b for a,b in glides)
        hx,hy=heading_fn(t)
        right=hx>=0
        yaw=(yaw_bias if right else math.pi-yaw_bias)
        pitch=-math.atan2(hy,abs(hx)+1e-6)*0.55
        return dict(c=c,yaw=yaw,pitch=pitch,glide=gl,deep=deep_fn(t) if deep_fn else 1.0,flare=flare_fn(t) if flare_fn else 0.0)
    return p
