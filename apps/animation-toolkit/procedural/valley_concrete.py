# River valley: the living river petrifies into a concrete road ("With the concrete at our feet")
import cv2, numpy as np, subprocess, sys
FPS=24; W,H=854,480; S=2; hh,ww=H*S,W*S
T=float(sys.argv[1]) if len(sys.argv)>1 else 8.6
TA=float(sys.argv[2]) if len(sys.argv)>2 else 3.0     # petrification starts
TB=TA+4.0                                             # front reaches the near end
src=cv2.imread('/mnt/user-data/uploads/27a5c34f-image.png'); Hs,Ws=src.shape[:2]
crop=src[int(Hs*0.20):int(Hs*0.20)+int(Ws*9/16), :]
base=cv2.resize(crop,(ww,hh),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32); y0=yy/S; x0=xx/S
def ss(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
sky=1-ss(70,110,y0); land=ss(90,140,y0)
# river path, far end -> near end (854x480 units): centre and half-width
C=np.array([(560,185,5),(480,197,7),(400,208,9),(300,219,12),(200,233,16),(125,249,20),(72,268,21),(78,292,19),(132,316,15),
            (200,334,9),(270,347,6),(350,356,5),(425,362,6),(500,373,11),(580,391,22),(680,410,32),(780,425,38),(870,436,40)],np.float32)
pts=[];wid=[];sv=[]
seg=np.r_[0,np.cumsum(np.hypot(*np.diff(C[:,:2],axis=0).T))]
for k in range(len(C)-1):
    for u in np.linspace(0,1,40,endpoint=False):
        p=C[k]*(1-u)+C[k+1]*u; pts.append(p[:2]); wid.append(p[2]); sv.append(seg[k]*(1-u)+seg[k+1]*u)
pts=np.array(pts); wid=np.array(wid); sv=np.array(sv)/seg[-1]
buf=np.zeros((H,W),np.float32); sfield=np.zeros((H,W),np.float32); dist=np.full((H,W),1e9,np.float32)
for (x,y),w,s in zip(pts,wid,sv):
    r=int(w*1.35)+2; x0i,y0i=int(x),int(y)
    ys,xs=np.mgrid[max(0,y0i-r):min(H,y0i+r+1),max(0,x0i-r):min(W,x0i+r+1)]
    d=np.hypot(xs-x,ys-y)/max(w,1)
    sub=dist[ys,xs]; upd=d<sub
    dist[ys[upd],xs[upd]]=d[upd]; sfield[ys[upd],xs[upd]]=s
lab=cv2.cvtColor(cv2.resize(crop,(W,H),interpolation=cv2.INTER_AREA),cv2.COLOR_BGR2LAB).astype(np.float32)
dark=ss(118,88,lab[...,0])                                   # painted water is the dark band
riv=np.clip(ss(1.35,0.85,dist)*np.maximum(dark,ss(1.0,0.6,dist)),0,1)
riv=cv2.GaussianBlur(riv,(0,0),1.2)
up=lambda a: cv2.resize(a,(ww,hh),interpolation=cv2.INTER_LINEAR)
R=up(riv); SF=up(sfield); D=up(dist)
# concrete: pale cement grey carrying the painting's own plaster grain
g=cv2.cvtColor(base.astype(np.uint8),cv2.COLOR_BGR2GRAY).astype(np.float32)
grain=g-cv2.GaussianBlur(g,(0,0),3*S)
rng=np.random.default_rng(4); speck=cv2.GaussianBlur(rng.standard_normal((hh,ww)).astype(np.float32),(0,0),0.7*S)
concrete=np.array([122,126,127],np.float32)[None,None,:]+(2.2*grain+6*speck)[...,None]
concrete=concrete*0.75+0.25*(cv2.cvtColor(cv2.cvtColor(base.astype(np.uint8),cv2.COLOR_BGR2GRAY),cv2.COLOR_GRAY2BGR).astype(np.float32)*0.85)
concrete=concrete*(0.9+0.1*ss(0,1,SF))[...,None]               # far end slightly lighter (aerial haze)
# centre line: dashes along the path, painted bone white, drawn on behind the petrification front
line=np.zeros((H,W),np.float32); lphase=np.zeros((H,W),np.float32)
acc=0
for k in range(len(pts)-1):
    (x1,y1),(x2,y2)=pts[k],pts[k+1]; w=wid[k]
    L=np.hypot(x2-x1,y2-y1); acc+=L/max(w,1.0)
    if (acc%2.2)<1.15 and w>4.5:
        cv2.line(line,(int(x1*4),int(y1*4)),(int(x2*4),int(y2*4)),1.0,max(3,int(w*0.11*4)),cv2.LINE_AA,shift=2)
        cv2.line(lphase,(int(x1*4),int(y1*4)),(int(x2*4),int(y2*4)),float(sv[k]),max(3,int(w*0.11*4)),cv2.LINE_AA,shift=2)
LN=up(cv2.GaussianBlur(line,(0,0),0.5)); LP=up(lphase)
rough=0.75+0.25*cv2.resize(rng.random((60,107)).astype(np.float32),(ww,hh),interpolation=cv2.INTER_CUBIC)
bone=np.array([206,214,222],np.float32)
out=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p','v4/valley_concrete.mp4'],stdin=subprocess.PIPE)
cx,cy=ww*0.52,hh*0.58
for i in range(int(round(T*FPS))):
    t=i/FPS; z=1+0.07*(t/T)
    front=np.clip((t-TA)/(TB-TA),0,1)*1.25-0.12                # wave from the far end toward the viewer
    pet=ss(front+0.10,front-0.10,SF)*R                          # 1 where the water has turned to stone
    gust=0.55+0.45*np.sin(2*np.pi*(t/4.0-x0/900))
    wave=np.sin(2*np.pi*(0.5*t-x0/70))+0.5*np.sin(2*np.pi*(1.2*t-x0/31)+1.3)
    dx=land*(1-R)*2.0*gust*wave + sky*(-14+14*t/T)
    dy=R*(1-pet)*0.9*np.sin(2*np.pi*(0.8*t+y0/5.0))
    mx=(cx+(xx-cx)/z-dx*S).astype(np.float32); my=(cy+(yy-cy)/z-dy*S).astype(np.float32)
    o=cv2.remap(base,mx,my,cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
    # stone layer follows the same camera
    mxs=(cx+(xx-cx)/z).astype(np.float32); mys=(cy+(yy-cy)/z).astype(np.float32)
    P=cv2.remap(pet,mxs,mys,cv2.INTER_LINEAR); Cn=cv2.remap(concrete,mxs,mys,cv2.INTER_LINEAR)
    o=o*(1-P[...,None])+Cn*P[...,None]
    lfront=np.clip((t-TA-0.8)/(TB-TA),0,1)*1.25-0.12
    Lm=cv2.remap(LN*ss(lfront+0.03,lfront-0.03,LP)*rough,mxs,mys,cv2.INTER_LINEAR)*cv2.remap(R,mxs,mys,cv2.INTER_LINEAR)
    o=o*(1-0.85*Lm[...,None])+bone*0.85*Lm[...,None]
    out.stdin.write(np.clip(cv2.resize(o,(W,H),interpolation=cv2.INTER_AREA),0,255).astype(np.uint8).tobytes())
out.stdin.close(); out.wait()
subprocess.run(['ffmpeg','-v','error','-y','-i','v4/valley_concrete.mp4','-ss','4.9','-i','clips/E05_fix.mp4','-map','0:v','-map','1:a','-c:v','copy','-c:a','aac','-b:a','192k','-t',str(T),'v4/valley_concrete_av.mp4'],check=True)
print('ok')
