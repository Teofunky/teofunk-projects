import cv2, numpy as np, subprocess, sys, json
sys.path.insert(0,'.')
from build_versions import ext_frames, EXT
FPS=24; W,H=854,480; SR=48000
FT=17                                   # fog transition, frames (0.708 s)
EXT['E05m']=dict(wind=(230,480),A=3.0,sky=(0,190))
def frames(src,a,b):
    raw=subprocess.run(['ffmpeg','-v','error','-ss',f'{a:.4f}','-i',src,'-t',f'{b-a:.4f}','-f','rawvideo','-pix_fmt','bgr24','-'],capture_output=True).stdout
    f=np.frombuffer(raw,np.uint8).reshape(-1,H,W,3)
    n=int(round((b-a)*FPS)); return f[:n]
dis=cv2.DISOpticalFlow_create(cv2.DISOPTICAL_FLOW_PRESET_MEDIUM)
yy,xx=np.mgrid[0:H,0:W].astype(np.float32)
def interp(a,b):
    ga=cv2.cvtColor(a,cv2.COLOR_BGR2GRAY); gb=cv2.cvtColor(b,cv2.COLOR_BGR2GRAY)
    fab=dis.calc(ga,gb,None); fba=dis.calc(gb,ga,None)
    wa=cv2.remap(a,xx-0.5*fab[...,0],yy-0.5*fab[...,1],cv2.INTER_LINEAR,borderMode=cv2.BORDER_REFLECT)
    wb=cv2.remap(b,xx-0.5*fba[...,0],yy-0.5*fba[...,1],cv2.INTER_LINEAR,borderMode=cv2.BORDER_REFLECT)
    return ((wa.astype(np.uint16)+wb)//2).astype(np.uint8)
def regularize(F,spk=1.3):
    F=F.copy(); n=len(F)
    g=[cv2.resize(cv2.cvtColor(f,cv2.COLOR_BGR2GRAY),(320,180)).astype(np.float32) for f in F]
    d=np.array([np.abs(g[i+1]-g[i]).mean() for i in range(n-1)])
    fixed=0
    for i in range(1,n-1):
        lo=max(0,i-12); med=np.median(d[lo:i+12])
        if med<0.3: continue
        dup = d[i-1]<0.25*med and d[i]>0.6*med          # frame i repeats frame i-1
        spike = d[i-1]>spk*med and d[i]<1.05*med          # big jump into frame i
        if dup or spike:
            F[i]=interp(F[i-1],F[i+1]); fixed+=1
    return F,fixed
def fog(A,B,u,noise):
    m=u*u*(3-2*u)                      # plain soft dissolve, no defocus, no veil
    return np.clip(A.astype(np.float32)*(1-m)+B.astype(np.float32)*m,0,255).astype(np.uint8)
def owl_hold(f0,n):
    S=2; B=cv2.resize(f0,(W*S,H*S),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
    Y,X=np.mgrid[0:H*S,0:W*S].astype(np.float32); x0=X/S; y0=Y/S
    owl=np.zeros((H*S,W*S),np.float32)
    pts=np.array([[300,60],[340,35],[420,35],[460,80],[470,120],[600,170],[670,300],[670,400],[600,425],[480,420],[420,400],[330,250],[300,150]],np.int32)*S
    cv2.fillPoly(owl,[pts],1); owl=cv2.GaussianBlur(owl,(0,0),12*S)
    free=np.clip(1-owl*1.4,0,1)
    out=[]
    for i in range(n):
        t=i/FPS
        gust=0.6+0.4*np.sin(2*np.pi*(t/4.5-x0/900))
        wave=np.sin(2*np.pi*(0.4*t-x0/230))+0.4*np.sin(2*np.pi*(1.0*t-x0/80)+1.7)
        ramp=min(1.0,t/1.2)
        dx=free*ramp*3.0*gust*wave*np.clip((y0-30)/400,0.2,1)
        dy=-0.15*np.abs(dx)
        br=0.004*np.sin(2*np.pi*t/4.0)*ramp            # breathing of the owl body
        dy=dy+owl*br*(y0-410)
        z=1.0+0.045*(t/(n/FPS)); fx,fy=420,200
        mx=(fx+(X/S-dx-fx)/z)*S; my=(fy+(Y/S-dy-fy)/z)*S
        o=cv2.remap(B,mx.astype(np.float32),my.astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
        out.append(np.clip(cv2.resize(o,(W,H),interpolation=cv2.INTER_AREA),0,255).astype(np.uint8))
    return np.array(out)
def load_audio(src,a,dur,norm=-23):
    af=f'atrim=0:{dur:.4f},asetpts=PTS-STARTPTS,aresample={SR}'
    if norm is not None: af+=f',loudnorm=I={norm}:TP=-2:LRA=11,aresample={SR}'
    raw=subprocess.run(['ffmpeg','-v','error','-ss',f'{a:.4f}','-i',src,'-af',af,'-ac','2','-f','f32le','-'],capture_output=True).stdout
    x=np.frombuffer(raw,np.float32).reshape(-1,2).copy(); n=int(round(dur*SR))
    if len(x)<n: x=np.vstack([x,np.zeros((n-len(x),2),np.float32)])
    return x[:n]
