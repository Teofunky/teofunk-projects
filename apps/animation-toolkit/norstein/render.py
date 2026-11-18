# Three women: Norstein-style cut-out with multiplane, on twos
import cv2, numpy as np, subprocess, math, sys
FPS=24; OW,OH=1920,1080; DUR=16.0; REST='--rest' in sys.argv
T={k:cv2.imread(f'w3/tex_{k}.png').astype(np.float32) for k in ['sky','body_tex','head_tex','paint','plant']}
A={k:v.astype(np.float32) for k,v in np.load('w3/alpha2x.npz').items()}
H2,W2=T['paint'].shape[:2]
def rgba(tex,a,box=None):
    if box is None:
        ys,xs=np.nonzero(a>0.004); box=(xs.min(),ys.min(),xs.max()+1,ys.max()+1)
    x0,y0,x1,y1=box
    return np.dstack([tex[y0:y1,x0:x1],a[y0:y1,x0:x1]*255]),(x0,y0)
PC={}
for k in ['left','centre','right']: PC['head_'+k]=rgba(T['head_tex'],A['head_'+k])
for k in ['ear_l','ear_r','fish_b','fish_t','fish_s']: PC[k]=rgba(T['paint'],A[k])
# hair weight inside each head: 0 on the face and neck, growing toward the hair ends
yy2,xx2=np.mgrid[0:H2,0:W2].astype(np.float32); X1=xx2/2; Y1=yy2/2
def ell(cx,cy,ax,ay): return np.clip(1-((X1-cx)/ax)**2-((Y1-cy)/ay)**2,0,1)
face=np.clip(ell(258,365,56,70)+ell(262,420,40,24)+ell(548,232,66,112)+ell(550,318,52,30)+ell(828,268,64,104)+ell(830,350,46,30),0,1)
face=cv2.GaussianBlur((face>0).astype(np.float32),(0,0),10)
HW={}
for k,(top,bot) in {'left':(300,490),'centre':(160,410),'right':(200,400)}.items():
    img,(x0,y0)=PC['head_'+k]; h,w=img.shape[:2]
    yl=Y1[y0:y0+h,x0:x0+w]; HW[k]=(np.clip((yl-top)/(bot-top),0,1)**1.4)*(1-face[y0:y0+h,x0:x0+w])
# breathing blobs on the body
def blob(cx,cy,sx,sy): return np.exp(-((X1-cx)**2/(2*sx*sx)+(Y1-cy)**2/(2*sy*sy))).astype(np.float32)
BL=[blob(240,500,110,120),blob(550,440,130,130),blob(850,450,120,120)]
PH=[0.0,2.1,4.0]; PIV={'left':(262,428),'centre':(550,338),'right':(830,370)}
plant_amp=(3.2*np.clip((1024-Y1)/620,0,1)**1.5).astype(np.float32)
def fbm(seed,h,w):
    r=np.random.default_rng(seed); acc=np.zeros((h,w),np.float32); amp=1
    for sc in (3,6,12,24):
        n=r.random((sc+1,sc*3+1)).astype(np.float32); n=np.hstack([n,n[:, :1]])
        acc+=amp*cv2.resize(n,(w,h),interpolation=cv2.INTER_CUBIC); amp*=0.5
    return (acc-acc.min())/(acc.max()-acc.min())
MIST1=fbm(21,300,2400); MIST2=fbm(22,300,2400)
def ss(a,b,v):
    t=min(max((v-a)/(b-a),0),1); return t*t*(3-2*t)
def M_rot(cx,cy,ang):
    a=math.radians(ang); c,s=math.cos(a),math.sin(a)
    return np.array([[1,0,cx],[0,1,cy],[0,0,1]])@np.array([[c,s,0],[-s,c,0],[0,0,1]])@np.array([[1,0,-cx],[0,1,-cy],[0,0,1]])
def M_tr(dx,dy): return np.array([[1,0,dx],[0,1,dy],[0,0,1]],np.float64)
HALF=np.diag([0.5,0.5,1.0])
def cam(t):
    if REST: return 1024,512,332
    k=ss(0,DUR,t); return 960-60*k,512+28*k,332-14*k
def view(t,depth):
    w,cx,cy=cam(t); w0,c0x,c0y=960,512,332
    if REST: w0,c0x,c0y=1024,512,332
    wl=w0*(w/w0)**depth; lx=c0x+(cx-c0x)*depth; ly=c0y+(cy-c0y)*depth; s=OW/wl
    return np.array([[s,0,OW/2-s*lx],[0,s,OH/2-s*ly],[0,0,1]],np.float64)
def over(dst,src,cov=None):
    a=src[...,3:4]/255.0; dst*=(1-a); dst+=src[...,:3]*a
    if cov is not None: cov[:]=1-(1-cov)*(1-a[...,0])
def warp(img,M,blur=0):
    o=cv2.warpAffine(img,M[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
    return cv2.GaussianBlur(o,(0,0),blur) if blur else o
def mist(dst,N,t,speed,depth,yc,hh,amt,col=(208,210,212)):
    V=view(t,depth); Mn=V@M_tr(-speed*t-100,yc-hh/2)@np.diag([1300/N.shape[1],hh/N.shape[0],1])
    n=cv2.warpAffine(N,Mn[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_WRAP)
    band=cv2.GaussianBlur(cv2.warpAffine(np.ones_like(N),Mn[:2],(OW,OH),borderMode=cv2.BORDER_CONSTANT),(0,0),50)
    a=np.clip(n*1.7-0.55,0,1)*band*amt
    dst*=(1-a[...,None]); dst+=np.array(col,np.float32)*a[...,None]
VIG=None
def frame(i):
    global VIG
    t=i/FPS; tp=(i//2)*2/FPS; m=0.0 if REST else 1.0
    dst=np.zeros((OH,OW,3),np.float32); cov=np.zeros((OH,OW),np.float32)
    # 6: sky
    sk=np.dstack([T['sky'],np.full((H2,W2),255,np.float32)])
    dst[:]=warp(sk,view(t,0.55)@M_tr(-0.35*t*m,0)@HALF,blur=0.8)[...,:3]
    if not REST: mist(dst,MIST1,t,5,0.65,390,200,0.22)
    # 5: fish
    Gf=M_tr(2.0*tp*m,1.6*math.sin(2*math.pi*tp/5.3)*m)@M_rot(190,190,0.8*math.sin(2*math.pi*tp/5.3+1)*m)
    Vf=view(t,0.7)
    for k,M in [('fish_t',Gf@M_rot(47,186,10*math.sin(2*math.pi*1.2*tp)*m)),('fish_b',Gf),
                ('fish_s',M_tr(2.4*tp*m,1.2*math.sin(2*math.pi*tp/4.1+2)*m)@M_rot(50,80,2*math.sin(2*math.pi*1.7*tp)*m))]:
        img,(x0,y0)=PC[k]; over(dst,warp(img,Vf@M@HALF@M_tr(x0,y0)))
    # 4: figures (body breathing, heads, hair, earrings)
    br=[math.sin(2*math.pi*tp/4.6+p)*m for p in PH]
    lift=[-1.1*b for b in br]
    dy=(BL[0]*lift[0]+BL[1]*lift[1]+BL[2]*lift[2])*2
    bt=np.dstack([T['body_tex'],A['body']*255])
    bw=cv2.remap(bt,xx2,(yy2-dy).astype(np.float32),cv2.INTER_LINEAR,borderMode=cv2.BORDER_REPLICATE)
    V1=view(t,1.0); over(dst,warp(bw,V1@HALF),cov)
    for j,k in enumerate(['left','centre','right']):
        img,(x0,y0)=PC['head_'+k]; h,w=img.shape[:2]
        sway=(2.0*HW[k]*math.sin(2*math.pi*0.32*tp+PH[j])*m*2).astype(np.float32)
        gx,gy=np.meshgrid(np.arange(w,dtype=np.float32),np.arange(h,dtype=np.float32))
        img2=cv2.remap(img,gx-sway,gy,cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
        lag=math.sin(2*math.pi*(tp-2/FPS)/4.6+PH[j])*m
        th=0.6*math.sin(2*math.pi*tp/7.3+PH[j]*1.3)*m
        px,py=PIV[k]; Gh=M_tr(0,-1.0*lag)@M_rot(px,py,th)
        over(dst,warp(img2,V1@Gh@HALF@M_tr(x0,y0)),cov)
        if k=='left':
            for e,(ex,ey) in [('ear_l',(198,362)),('ear_r',(313,364))]:
                ang=4.0*math.sin(2*math.pi*0.85*(tp-3/FPS)+(0 if e=='ear_l' else 0.6))*m - 6*th
                imgE,(ex0,ey0)=PC[e]; over(dst,warp(imgE,V1@Gh@M_rot(ex,ey,ang)@HALF@M_tr(ex0,ey0)),cov)
    if not REST: mist(dst,MIST2,t,7,1.15,640,220,0.12)
    # 2: plants (wind, slight defocus)
    gust=0.65+0.35*math.sin(2*math.pi*tp/6.0)
    pdx=(plant_amp*gust*(np.sin(2*math.pi*(0.27*tp)-X1/320)+0.35*np.sin(2*math.pi*(0.75*tp)-X1/95+1.3))*2*m).astype(np.float32)
    pt=np.dstack([T['plant'],A['plants']*255])
    pw=cv2.remap(pt,xx2-pdx,yy2+0.12*np.abs(pdx),cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
    over(dst,warp(pw,view(t,1.3)@HALF,blur=0 if REST else 1.5),cov)
    if not REST:
        # light: a slow cloud shadow crossing the figures
        V=view(t,1.0); s=V[0,0]; uu,vv=np.meshgrid(np.arange(OW,dtype=np.float32),np.arange(OH,dtype=np.float32))
        x1=(uu-V[0,2])/s; y1=(vv-V[1,2])/s; xc=-250+1500*(t/DUR)
        sh=0.09*np.exp(-(((x1-xc)+0.35*(y1-300))**2)/(2*170*170))
        dst*=(1-sh*cov)[...,None]
        mist(dst,MIST1,t,12,1.4,330,600,0.05)
        if VIG is None:
            r=np.hypot((uu-OW/2)/(OW/2),(vv-OH/2)/(OH/2)); VIG=(1-0.10*np.clip(r-0.55,0,1)**1.5)[...,None]
        dst*=VIG; dst*=ss(0,0.8,t)*(1-ss(DUR-1.2,DUR,t))
    return np.clip(dst,0,255).astype(np.uint8)
if __name__=='__main__':
    if REST:
        f=frame(0); cv2.imwrite('w3/rest.png',f)
        ref=cv2.warpAffine(T['paint'],(view(0,1.0)@HALF)[:2],(OW,OH),flags=cv2.INTER_LINEAR)
        d=np.abs(f.astype(np.float32)-ref.astype(np.float32)).mean(2)
        print('rest diff mean',d.mean().round(2),'p99',np.percentile(d,99).round(1))
        cv2.imwrite('w3/rest_diff.jpg',cv2.resize(np.clip(d*4,0,255).astype(np.uint8),(960,540)))
        sys.exit()
    if '--stills' in sys.argv:
        for t in [1.0,6.0,11.0,15.0]: cv2.imwrite(f'w3/st_{t}.jpg',cv2.resize(frame(int(t*FPS)),(960,540)),[cv2.IMWRITE_JPEG_QUALITY,90])
        sys.exit()
    p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{OW}x{OH}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','16','-preset','medium','-pix_fmt','yuv420p','w3/women_norstein.mp4'],stdin=subprocess.PIPE)
    for i in range(int(DUR*FPS)):
        p.stdin.write(frame(i).tobytes())
        if i%48==0: print('t',i/FPS,flush=True)
    p.stdin.close(); p.wait(); print('video ok')
