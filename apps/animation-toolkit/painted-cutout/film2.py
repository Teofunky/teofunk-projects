# Перекладка и оживление для живописи 9:16: фигуры с заплатками, поля ветра/воды, глаза.
import cv2, numpy as np, math, subprocess
from scipy import ndimage as ndi
W,H,FPS=1080,1502,24   # пропорции картин (912×1268 без полосы мусора)
YMAX=1268   # ниже полоса цифрового мусора
disk=lambda s: cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(s,s))
def ease(x): x=min(max(x,0.0),1.0); return x*x*(3-2*x)
def poly_mask(shape,poly):
    m=np.zeros(shape[:2],np.uint8); cv2.fillPoly(m,[np.int32(np.round(poly))],1); return m>0
def ell_mask(shape,c,r,ang=0):
    m=np.zeros(shape[:2],np.uint8); cv2.ellipse(m,(int(c[0]),int(c[1])),(int(r[0]),int(r[1])),ang,0,360,1,-1); return m>0
def feather(m,r=2.0):
    return cv2.GaussianBlur(m.astype(np.float32),(0,0),r) if r>0 else m.astype(np.float32)

class PSheet:
    def __init__(self,path):
        im=cv2.imread(path).astype(np.float32)[:YMAX]
        self.img=im; self.plate=im.copy(); self.shape=im.shape
        self.lum=cv2.cvtColor(im.astype(np.uint8),cv2.COLOR_BGR2GRAY).astype(np.float32)
    def fill(self,mask,grow=3):
        """Заплатка под вырезанной фигурой: inpaint + фактура соседнего участка."""
        m=cv2.dilate(mask.astype(np.uint8),disk(2*grow+1))
        p8=np.clip(self.plate,0,255).astype(np.uint8)
        r=int(max(5,min(25,math.sqrt(m.sum())/6)))
        inp=cv2.inpaint(p8,m,r,cv2.INPAINT_TELEA).astype(np.float32)
        blur=cv2.GaussianBlur(self.img,(0,0),4)
        hp=self.img-blur
        ys,xs=np.nonzero(m); h=ys.max()-ys.min()+1; w=xs.max()-xs.min()+1
        best=None
        for dx,dy in [(w+6,0),(-w-6,0),(0,h+6),(0,-h-6),(w+6,h+6),(-w-6,-h-6),(w+6,-h-6),(-w-6,h+6)]:
            ys2,xs2=ys+dy,xs+dx
            ok=(ys2>=0)&(ys2<self.shape[0])&(xs2>=0)&(xs2<self.shape[1])
            if ok.mean()<0.98: continue
            ov=mask[ys2[ok],xs2[ok]].mean()
            if best is None or ov<best[0]: best=(ov,dx,dy)
        out=inp.copy()
        if best:
            _,dx,dy=best
            sh=np.roll(np.roll(hp,dy,0),dx,1)
            a=feather(m>0,2)[...,None]
            out=inp+sh*a*0.9
        a=feather(m>0,1.5)[...,None]
        self.plate=self.plate*(1-a)+out*a
    def matte(self,blur=61,lo=12,hi=55):
        bg=cv2.medianBlur(self.lum.astype(np.uint8),blur).astype(np.float32)
        return np.clip((bg-self.lum-lo)/(hi-lo),0,1)
    def lumdiff_mask(self,region,thr=40,blur=41):
        bg=cv2.medianBlur(self.lum.astype(np.uint8),blur).astype(np.float32)
        return (bg-self.lum>thr)&region

class Sprite:
    def __init__(self,sheet,name,mask,z=1,pivot=None,parent=None,pose=None,deform=None,fill=True,fr=1.2,alpha=None):
        ys,xs=np.nonzero(mask); pad=6
        x0,x1=max(0,xs.min()-pad),min(sheet.shape[1],xs.max()+pad+1); y0,y1=max(0,ys.min()-pad),min(sheet.shape[0],ys.max()+pad+1)
        self.x0,self.y0=x0,y0
        self.rgb=sheet.img[y0:y1,x0:x1].copy()
        if alpha is not None:
            self.a=np.clip(alpha[y0:y1,x0:x1]*255,0,255).astype(np.float32)*feather(mask[y0:y1,x0:x1],0.8)
        else:
            self.a=np.clip(feather(mask[y0:y1,x0:x1],fr)*255*1.15,0,255).astype(np.float32)
        self.cx,self.cy=float(xs.mean()),float(ys.mean())
        self.pivot=pivot if pivot is not None else (self.cx,self.cy)
        self.name,self.z,self.parent=name,z,parent
        self.pose=pose or (lambda t:{}); self.deform=deform
        if fill: sheet.fill(mask)

def T(x,y): return np.array([[1,0,x],[0,1,y],[0,0,1]],np.float64)
def R(deg,sx=1.0,sy=None):
    a=math.radians(deg); c,s=math.cos(a),math.sin(a); sy=sx if sy is None else sy
    return np.array([[c,-s,0],[s,c,0],[0,0,1]],np.float64)@np.diag([sx,sy,1.0])

class PScene:
    def __init__(self,sheet,view,seconds):
        x0,y0,vw=view; s=W/vw; self.s=s; self.view=(x0,y0,vw,vw*H/W)
        self.V=np.array([[s,0,-x0*s],[0,s,-y0*s],[0,0,1]],np.float64)
        self.sheet,self.seconds=sheet,seconds
        self.sprites={}; self.fields=[]; self.paints=[]; self.overlay=None
        X,Y=np.meshgrid(np.arange(W,dtype=np.float32),np.arange(H,dtype=np.float32))
        self.sx=x0+X/s; self.sy=y0+Y/s   # координаты листа для каждого пикселя кадра
        # сетка листа в пределах вида (для полей)
        self.bx0=int(max(0,math.floor(x0))); self.by0=int(max(0,math.floor(y0)))
        self.bx1=int(min(sheet.shape[1],math.ceil(x0+vw)+1)); self.by1=int(min(sheet.shape[0],math.ceil(y0+vw*H/W)+1))
        gy,gx=np.mgrid[self.by0:self.by1,self.bx0:self.bx1].astype(np.float32)
        self.gx,self.gy=gx,gy
    def add(self,sp): self.sprites[sp.name]=sp; return sp
    def world(self,sp,t,cache):
        if sp.name in cache: return cache[sp.name]
        st=sp.pose(t); px,py=sp.pivot
        M=T(px+st.get('dx',0),py+st.get('dy',0))@R(st.get('ang',0),st.get('sc',1),st.get('scy',None))@T(-px,-py)
        if sp.parent: M=self.world(self.sprites[sp.parent],t,cache)@M
        cache[sp.name]=M; return M
    def frame(self,t):
        # фон: заплатанный лист с полями смещения и подкраской
        bg=self.sheet.plate
        if self.paints:
            bg=bg.copy()
            for p in self.paints: p(bg,t)
        dx=np.zeros_like(self.gx); dy=np.zeros_like(self.gy)
        for f in self.fields:
            a,b=f(self.gx,self.gy,t); dx+=a; dy+=b
        # перевести поле в кадр
        if self.fields:
            DX=cv2.remap(dx,(self.sx-self.bx0).astype(np.float32),(self.sy-self.by0).astype(np.float32),cv2.INTER_LINEAR,borderMode=cv2.BORDER_REPLICATE)
            DY=cv2.remap(dy,(self.sx-self.bx0).astype(np.float32),(self.sy-self.by0).astype(np.float32),cv2.INTER_LINEAR,borderMode=cv2.BORDER_REPLICATE)
        else: DX=DY=0
        can=cv2.remap(bg,(self.sx-DX).astype(np.float32),(self.sy-DY).astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
        cache={}
        for sp in sorted(self.sprites.values(),key=lambda q:(q.z(t) if callable(q.z) else q.z)):
            rgb,a=sp.rgb,sp.a
            if sp.deform: rgb,a=sp.deform(sp,t)
            op=sp.pose(t).get('op',1.0)
            if op<=0.003: continue
            if op<1: a=a*op
            k=getattr(sp,'ovs',1.0)
            M=(self.V@self.world(sp,t,cache)@T(sp.x0,sp.y0)@np.diag([1.0/k,1.0/k,1.0]))[:2]
            hh,ww=a.shape
            c=np.array([[0,0,1],[ww,0,1],[0,hh,1],[ww,hh,1]],np.float64)@M.T
            x0,y0=np.floor(c.min(0)).astype(int); x1,y1=np.ceil(c.max(0)).astype(int)
            x0,y0=max(x0,0),max(y0,0); x1,y1=min(x1,W),min(y1,H)
            if x1<=x0 or y1<=y0: continue
            M2=M.copy(); M2[:,2]-=[x0,y0]
            st=np.dstack([rgb,a])
            wp=cv2.warpAffine(st,M2.astype(np.float32),(x1-x0,y1-y0),flags=cv2.INTER_CUBIC,borderMode=cv2.BORDER_CONSTANT,borderValue=(0,0,0,0))
            al=(np.clip(wp[...,3],0,255)/255.0)[...,None]
            can[y0:y1,x0:x1]=can[y0:y1,x0:x1]*(1-al)+wp[...,:3]*al
        if self.overlay: self.overlay(can,t)
        return np.clip(can,0,255).astype(np.uint8)
    def render(self,out,snap=(),step=2):
        N=int(round(self.seconds*FPS))
        ff=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-',
            '-c:v','libx264','-preset','veryfast','-crf','17','-pix_fmt','yuv420p',out],stdin=subprocess.PIPE)
        last=None
        for f in range(N):
            if f%step==0: last=self.frame(f/FPS)
            if f in snap: cv2.imwrite(out.replace('.mp4',f'_{f:03d}.png'),last)
            ff.stdin.write(last.tobytes())
        ff.stdin.close(); ff.wait()

# ---- поля смещения (координаты листа)
def region_w(sheet,mask,r=12):
    return feather(mask,r)
def wind(weight_full,amp,freq,kx=0.012,ky=0.02,gust=None,vert=0.25,bx0=0,by0=0):
    """Ветер: смещение по x с бегущей волной; weight_full: вес на всём листе (0..1)."""
    def f(gx,gy,t,wf=weight_full):
        w=wf[int(gy[0,0]):int(gy[-1,0])+1,int(gx[0,0]):int(gx[0,-1])+1]
        g=gust(t) if gust else 1.0
        ph=2*math.pi*freq*t
        s=np.sin(ph+kx*gx+ky*gy)+0.45*np.sin(2.3*ph+0.7*kx*gx-0.5*ky*gy+1.3)
        return amp*g*w*s, amp*g*vert*w*np.cos(1.3*ph+ky*gy)
    return f
def ripple(weight_full,amp,freq,k=0.09,vamp=0.6):
    def f(gx,gy,t,wf=weight_full):
        w=wf[int(gy[0,0]):int(gy[-1,0])+1,int(gx[0,0]):int(gx[0,-1])+1]
        ph=2*math.pi*freq*t
        return amp*w*np.sin(ph+k*gy+0.01*gx), vamp*w*np.sin(0.8*ph+0.5*k*gy+0.03*gx)
    return f
def gaze(c,r,target_dir,amount_fn,ang=0):
    """Взгляд: зрачок сдвигается внутри эллипса глаза (формула как в ATELIER)."""
    cx,cy=c; rx,ry=r; ux,uy=target_dir; n=math.hypot(ux,uy); ux,uy=ux/n,uy/n
    def f(gx,gy,t):
        v=amount_fn(t)
        u=(gx-cx)/rx; q=(gy-cy)/ry
        env=np.clip(1-u*u,0,1)*np.clip(1-q*q,0,1)
        return v*0.42*rx*ux*env, v*0.36*ry*uy*env
    return f
def smile(c,r,amount_fn):
    cx,cy=c; rx,ry=r
    def f(gx,gy,t):
        v=amount_fn(t); u=(gx-cx)/rx; q=(gy-cy)/ry
        mid=np.clip(1-q*q,0,1); k=np.clip(np.abs(u),0,1)
        inside=(np.abs(u)<1.0)
        return -v*rx*0.05*np.sign(u)*mid*np.clip(1-u*u,0,1), -v*ry*0.5*np.sin(np.pi*k*0.92)**1.4*mid*inside
    return f
def blink(c,r,amount_fn,lash=0.35):
    """Моргание: веко цвета кожи над глазом опускается сверху; цвет века сглажен по ширине."""
    cx,cy=c; rx,ry=r; cache={}
    def p(bg,t):
        v=amount_fn(t)
        if v<=0.01: return
        x0,x1=int(cx-rx-2),int(cx+rx+3)
        if 'col' not in cache:
            cols=[]
            for x in range(x0,x1):
                u=(x-cx)/rx; arch=math.sqrt(max(0.0,1-u*u)); top=cy-ry*max(arch,0.3)
                sy=int(top-max(3,ry*0.45)); cols.append(bg[max(0,sy-6):sy+1,x].mean(0))
            cols=np.array(cols,np.float32)
            cache['col']=cv2.GaussianBlur(cols[None],(0,0),sigmaX=5)[0]
        cols=cache['col']
        for i,x in enumerate(range(x0,x1)):
            u=(x-cx)/rx
            if abs(u)>=1: continue
            arch=math.sqrt(1-u*u); top=cy-ry*arch-1; bot=cy+ry*arch
            close=top+(bot-top+1)*v*0.95
            side=min(1.0,(1-abs(u))*5)
            for y in range(int(math.floor(top)),int(math.ceil(close))+2):
                a=min(1.0,max(0.0,close-y+0.8))*side
                shade=1.0-0.10*max(0.0,(y-top)/max(1.0,bot-top))
                bg[y,x]=bg[y,x]*(1-a)+cols[i]*shade*a
            yl=close
            for y in range(int(yl)-1,int(yl)+2):
                d=max(0.0,1-abs(y-yl)/1.6)*lash*min(1,v*1.5)*side
                bg[y,x]=bg[y,x]*(1-d)+np.array([45,45,48],np.float32)*d
    return p
def pulse(mask_full,amp,freq,base=0.0):
    w=feather(mask_full,6)[...,None]
    def p(bg,t):
        k=base+amp*(0.5+0.5*math.sin(2*math.pi*freq*t))
        bg[:]=bg*(1+k*w)
    return p

# ---- деформации спрайтов
def flap(freq,depth=0.45,phase=0.0):
    """Взмах: сжатие по вертикали (летящая птица)."""
    def d(sp,t):
        h,w=sp.a.shape; k=1-depth*(0.5+0.5*math.sin(2*math.pi*freq*t+phase))
        M=np.float32([[1,0,0],[0,k,(1-k)*h/2]])
        return (cv2.warpAffine(sp.rgb,M,(w,h),flags=cv2.INTER_LINEAR,borderValue=(0,0,0)),
                cv2.warpAffine(sp.a,M,(w,h),flags=cv2.INTER_LINEAR,borderValue=0))
    return d
def fish_wiggle(direction,freq,amp=0.06,phase=0.0,amp_fn=None):
    def d(sp,t):
        h,w=sp.a.shape; xs=np.arange(w,dtype=np.float32)
        u=(xs/(w-1)) if direction<0 else (1-xs/(w-1))
        A=amp*(amp_fn(t) if amp_fn else 1.0)
        off=A*h*u**1.6*np.sin(2*math.pi*freq*t-2.4*u+phase)
        mx=np.tile(xs,(h,1)); my=(np.arange(h,dtype=np.float32)[:,None]-off[None,:]).astype(np.float32)
        return (cv2.remap(sp.rgb,mx,my,cv2.INTER_LINEAR,borderValue=(0,0,0)),cv2.remap(sp.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d
def flutter(amp,freq,anchor='left',k=0.05,power=1.3):
    def d(sp,t):
        h,w=sp.a.shape; ys=np.arange(h,dtype=np.float32)[:,None]; xs=np.arange(w,dtype=np.float32)[None,:]
        if anchor=='bottom': u=(h-1-ys)/(h-1); off=amp*u**power*np.sin(2*math.pi*freq*t+k*xs); mx=(xs-off); my=np.broadcast_to(ys,(h,w))
        else:
            u=(xs/(w-1)) if anchor=='left' else (1-xs/(w-1))
            off=amp*u**power*np.sin(2*math.pi*freq*t-k*ys-3*u); mx=np.broadcast_to(xs,(h,w)); my=ys-off
        mx=np.ascontiguousarray(mx,dtype=np.float32); my=np.ascontiguousarray(my,dtype=np.float32)
        return (cv2.remap(sp.rgb,mx,my,cv2.INTER_LINEAR,borderValue=(0,0,0)),cv2.remap(sp.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d

def wsl(wf,gx,gy): return wf[int(gy[0,0]):int(gy[-1,0])+1,int(gx[0,0]):int(gx[0,-1])+1]
def rotfield(weight_full,pivot,ang_fn):
    px,py=pivot
    def f(gx,gy,t):
        w=wsl(weight_full,gx,gy); a=math.radians(ang_fn(t)); c,s=math.cos(a),math.sin(a)
        X=gx-px; Y=gy-py
        return w*(X-(c*X+s*Y)), w*(Y-(-s*X+c*Y))
    return f
def shiftfield(weight_full,fn):
    def f(gx,gy,t):
        w=wsl(weight_full,gx,gy); dx,dy=fn(t); return w*dx, w*dy
    return f
def title_overlay(text,xy,size,t0,fade=0.8,color=(38,38,40)):
    from PIL import Image, ImageDraw, ImageFont
    font=ImageFont.truetype('/usr/share/fonts/truetype/google-fonts/Lora-Italic-Variable.ttf',size)
    im=Image.new('L',(W,H),0); ImageDraw.Draw(im).text(xy,text,font=font,fill=255)
    A=np.asarray(im,np.float32)/255.0
    col=np.array(color,np.float32)
    def ov(can,t):
        k=ease((t-t0)/fade)
        if k<=0: return
        a=(A*k*0.92)[...,None]; can[:]=can*(1-a)+col*a
    return ov
def blinkfield(c,r,amount_fn,k=1.7):
    """Моргание без заплатки: глаз сжимается к линии ресниц, сверху и снизу наползает кожа."""
    cx,cy=c; rx,ry=r
    def f(gx,gy,t):
        v=amount_fn(t)
        if v<=0.01: return 0.0*gx,0.0*gy
        u=(gx-cx)/(rx*1.35); q=(gy-cy)/(ry*3.2)
        w=np.clip(1-u*u-q*q,0,1)**2.0
        return 0.0*gx, -(gy-cy)*k*v*w
    return f

def fill2(sheet,mask,tex_box=None,grow=3,sigma=None):
    """Большая заплатка: плавный цвет из окружения (нормированная свёртка) + фактура из чистого участка."""
    m=cv2.dilate(mask.astype(np.uint8),disk(2*grow+1))>0
    valid=(~m).astype(np.float32)
    area=m.sum(); sg=sigma or max(8.0,0.22*math.sqrt(area))
    num=cv2.GaussianBlur(sheet.plate*valid[...,None],(0,0),sg)
    den=cv2.GaussianBlur(valid,(0,0),sg)[...,None]+1e-4
    low=num/den
    # вторая, более мелкая ступень, чтобы у краёв цвет совпадал
    num2=cv2.GaussianBlur(sheet.plate*valid[...,None],(0,0),sg/3); den2=cv2.GaussianBlur(valid,(0,0),sg/3)[...,None]+1e-4
    w2=np.clip(den2*3,0,1); low=low*(1-w2)+(num2/den2)*w2
    ys,xs=np.nonzero(m); y0,y1,x0,x1=ys.min(),ys.max()+1,xs.min(),xs.max()+1
    if tex_box is None:
        tex=sheet.img
        hp=tex-cv2.GaussianBlur(tex,(0,0),6)
        hpT=np.roll(np.roll(hp,0,0),x1-x0+10,1)
    else:
        bx0,by0,bx1,by1=tex_box; patch=sheet.img[by0:by1,bx0:bx1]
        hpp=patch-cv2.GaussianBlur(patch,(0,0),6); sd=float(hpp.std())
        rng=np.random.default_rng(int(bx0*7+by0))
        n1=cv2.GaussianBlur(rng.normal(0,1,sheet.shape[:2]).astype(np.float32),(0,0),1.0)
        n2=cv2.GaussianBlur(rng.normal(0,1,sheet.shape[:2]).astype(np.float32),(0,0),3.5)
        n=0.7*n1/n1.std()+0.5*n2/n2.std()
        hpT=n[...,None]*sd*np.array([1.0,1.0,1.0],np.float32)
    a=feather(m,2.5)[...,None]
    out=low+hpT*1.0
    sheet.plate=sheet.plate*(1-a)+out*a


# ---- полёт вороны (по наблюдениям: ровные «гребущие» взмахи 3,4–4,2 Гц, редкие короткие планирования,
#      взмах идёт от плеча, вниз дольше чем вверх, тело подбрасывает на взмахе вниз)
def wing_angle(t,f,phase=0.0,up=50.0,down=-28.0,down_frac=0.56):
    c=(f*t+phase/(2*math.pi))%1.0
    if c<down_frac: u=ease(c/down_frac); return up+(down-up)*u          # взмах вниз: от верха к низу
    u=ease((c-down_frac)/(1-down_frac)); return down+(up-down)*u        # подъём крыла
def body_bob(t,f,phase=0.0,amp=1.0):
    c=(f*t+phase/(2*math.pi))%1.0
    return -amp*math.sin(math.pi*min(1,c/0.56))   # вверх во время взмаха вниз
def speck_flap(f,phase=0.0,glides=()):
    """Дальняя ворона-пятнышко: силуэт выше, когда крылья вверху или внизу, и плоский посередине."""
    def d(sp,t):
        gl=any(a<=t<b for a,b in glides)
        if gl: k=0.55
        else:
            ang=wing_angle(t,f,phase)
            k=0.5+0.75*min(1.0,abs(ang)/45.0)
        h,w=sp.a.shape
        M=np.float32([[1,0,0],[0,k,(1-k)*h/2+body_bob(t,f,phase,1.0)*(0 if gl else 1)]])
        return (cv2.warpAffine(sp.rgb,M,(w,h),flags=cv2.INTER_LINEAR,borderValue=(0,0,0)),
                cv2.warpAffine(sp.a,M,(w,h),flags=cv2.INTER_LINEAR,borderValue=0))
    return d
def flock_paths(starts,center,seconds,rng,v=(110,170),climb=(10,28),leave=(1.6,4.2),turn_noise=0.35,fps=24):
    """Свободная стая: каждая ворона кружит у колокольни на своём радиусе, набирает высоту и в своё время уходит по прямой."""
    out=[]
    n=int(seconds*fps)+2
    for (x0,y0) in starts:
        sp=rng.uniform(*v); cl=rng.uniform(*climb); tl=rng.uniform(*leave)
        d=1 if rng.random()<0.75 else -1
        R=max(40.0,math.hypot(x0-center[0],y0-center[1]))
        hd=math.atan2(y0-center[1],x0-center[0])+d*math.pi/2
        ph=rng.uniform(0,6.28); fn=rng.uniform(0.25,0.6)
        xs=[x0]; ys=[y0]; hs=[hd]; x,y=x0,y0
        for i in range(1,n):
            t=i/fps; ease_in=ease(t/0.4)
            om=(d*sp/R)*(1-ease((t-tl)/0.8)) + turn_noise*math.sin(2*math.pi*fn*t+ph)
            hd+=om/fps
            x+=math.cos(hd)*sp*ease_in/fps; y+=math.sin(hd)*sp*ease_in/fps-cl*ease_in/fps
            xs.append(x); ys.append(y); hs.append(hd)
        out.append((np.array(xs),np.array(ys),np.array(hs)))
    return out

def wingbend(shL,shR,f,phase=0.0,up=30.0,down=-20.0,reach=45.0):
    """Взмах без разрезов: крыло плавно изгибается от плеча, кончик ходит сильнее всего."""
    def d(sp,t):
        h,w=sp.a.shape
        X,Y=np.meshgrid(np.arange(w,dtype=np.float32)+sp.x0,np.arange(h,dtype=np.float32)+sp.y0)
        a=math.radians(wing_angle(t,f,phase,up,down))
        mx=X.copy(); my=Y.copy()
        for (sx,sy),sgn,side in [(shL,1,X<shL[0]),(shR,-1,X>shR[0])]:
            dist=np.abs(X-sx)/reach
            k=np.clip(dist,0,1); k=k*k*(3-2*k)
            th=sgn*a*k
            c,s=np.cos(-th),np.sin(-th)
            dx,dy=X-sx,Y-sy
            mx=np.where(side,sx+c*dx-s*dy,mx); my=np.where(side,sy+s*dx+c*dy,my)
        mx=(mx-sp.x0).astype(np.float32); my=(my-sp.y0).astype(np.float32)
        return (cv2.remap(sp.rgb,mx,my,cv2.INTER_LINEAR,borderValue=(0,0,0)),cv2.remap(sp.a,mx,my,cv2.INTER_LINEAR,borderValue=0))
    return d
