# Norstein-style cut-out test: flying owl from the painting, multiplane landscape, puppet animated on twos
import cv2, numpy as np, glob, subprocess, math
FPS=24; OW,OH=1920,1080; DUR=16.0
P1=cv2.imread(glob.glob('/mnt/user-data/uploads/55befe4c*')[0])
plate1=cv2.imread('cut/owl_plate.png'); fg=np.load('cut/owl_fg2.npy').astype(np.uint8)
sr=cv2.dnn_superres.DnnSuperResImpl_create(); sr.readModel('sr/ESPCN_x2.pb'); sr.setModel('espcn',2)
P2=sr.upsample(P1); PL2=sr.upsample(plate1)
H1,W1=P1.shape[:2]
def poly(pts):
    m=np.zeros((H1,W1),np.uint8); cv2.fillPoly(m,[np.array(pts,np.int32)],1); return m
head=poly([(506,300),(512,248),(540,236),(566,290),(664,292),(692,236),(708,292),(700,400),(678,456),(622,478),(560,478),(516,436),(506,360)])&fg
body=poly([(30,366),(300,360),(446,372),(512,376),(522,440),(564,470),(568,520),(30,520)])&fg&(1-head)
back=np.zeros((H1,W1),np.uint8); cv2.ellipse(back,(497,336),(44,36),-20,0,360,1,-1); back=back&fg&(1-head)
body=body&(1-back)
rwing=fg&(1-head)&(1-body)&(1-back); rwing[:, :686]=0
lw=fg&(1-head)&(1-body)&(1-rwing)&(1-back)
def biggest(m):
    n,l,st,_=cv2.connectedComponentsWithStats(m.astype(np.uint8))
    return (l==1+np.argmax(st[1:,4])).astype(np.uint8) if n>1 else m
lw=biggest(lw); body=biggest(body); rwing=biggest(rwing); head=biggest(head)
yy,xx=np.mgrid[0:H1,0:W1].astype(np.float32)
E=np.array([262.,170.]); ax=np.array([-0.8,-0.6])
proj=(xx-E[0])*ax[0]+(yy-E[1])*ax[1]
outer=biggest(lw*(proj>0)); inner=biggest(lw*(proj<=0))
def ext(m,into,px):                      # overlap (нахлёст) into neighbours that are drawn above it
    d=cv2.dilate(m,np.ones((2*px+1,2*px+1),np.uint8))
    return (m|(d&into)).astype(np.uint8)
near_sh=np.zeros((H1,W1),np.uint8); cv2.circle(near_sh,(472,322),80,1,-1)
near_el=np.zeros((H1,W1),np.uint8); cv2.circle(near_el,(262,170),130,1,-1)
inner_x=ext(inner,(body&near_sh)|head|back,30)
outer_x=ext(outer,inner&near_el,44)
rwing_x=ext(rwing,head,32)
pieces={'rwing':rwing_x,'outer':outer_x,'inner':inner_x,'body':body,'back':back,'head':head}
def rgba(m):
    a=cv2.GaussianBlur(cv2.resize(m.astype(np.float32),(2*W1,2*H1),interpolation=cv2.INTER_LINEAR),(0,0),1.2)
    rim=np.clip(a-cv2.erode(a,np.ones((5,5),np.uint8)),0,1)          # paper edge, toned slightly darker
    c=P2.astype(np.float32)*(1-0.22*rim[...,None])
    ys,xs=np.nonzero(a>0.004); y0,y1,x0,x1=ys.min(),ys.max()+1,xs.min(),xs.max()+1
    return np.dstack([c,a*255])[y0:y1,x0:x1].astype(np.float32),(x0,y0)
PC={k:rgba(m) for k,m in pieces.items()}
# ---- multiplane strips (looped horizontally with a cross-fade seam) ----
def strip(r0,r1,fe_top,fe_bot,ov=300):
    B=PL2[2*r0:2*r1].astype(np.float32); h,w=B.shape[:2]; Wp=w-ov
    T=B[:, :Wp].copy(); r=np.linspace(0,1,ov,dtype=np.float32)[None,:,None]
    T[:, :ov]=B[:, :ov]*r+B[:, Wp:Wp+ov]*(1-r)
    a=np.ones((h,Wp),np.float32)
    if fe_top: a*=np.clip(np.arange(h)/(2*fe_top),0,1)[:,None]
    if fe_bot: a*=np.clip((h-1-np.arange(h))/(2*fe_bot),0,1)[:,None]
    pad=1400
    T=np.vstack([np.zeros((pad,Wp,3),np.float32),T,np.zeros((pad,Wp,3),np.float32)])
    a=np.vstack([np.zeros((pad,Wp),np.float32),a,np.zeros((pad,Wp),np.float32)])
    return np.dstack([T,a*255]),r0,pad
L_sky=strip(0,420,0,30); L_b1=strip(372,566,28,22); L_b2=strip(526,770,26,18); L_near=strip(760,900,22,0)
def fbm(seed,h,w):
    r=np.random.default_rng(seed); acc=np.zeros((h,w),np.float32); amp=1
    for sc in (3,6,12,24):
        n=r.random((sc+1,sc*3+1)).astype(np.float32)
        n=np.hstack([n,n[:, :1]])
        acc+=amp*cv2.resize(n,(w,h),interpolation=cv2.INTER_CUBIC); amp*=0.5
    return (acc-acc.min())/(acc.max()-acc.min())
MIST1=fbm(11,400,2400); MIST2=fbm(12,400,2400)
# ---- motion ----
def ss(a,b,v):
    t=min(max((v-a)/(b-a),0),1); return t*t*(3-2*t)
FLAPS=[(2.0,6.5,0.9),(9.5,12.2,0.9)]
def wpos(t):                        # 0 = wings up (as painted), 1 = down
    glide=0.42+0.04*math.sin(2*math.pi*t/2.3)
    for a,b,T in FLAPS:
        if a-0.4<=t<=b+0.4:
            ph=((t-a)/T)%1.0
            u=ph/0.42 if ph<0.42 else 1-(ph-0.42)/0.58       # fast downstroke, slower upstroke
            w=0.5-0.5*math.cos(math.pi*u)
            k=ss(a-0.4,a+0.2,t)*(1-ss(b-0.2,b+0.4,t))
            return glide*(1-k)+w*k
    return glide
def M_rot(cx,cy,ang,sx=1.0,sy=1.0):
    a=math.radians(ang); c,s=math.cos(a),math.sin(a)
    R=np.array([[c,s,0],[-s,c,0],[0,0,1]],np.float64)       # positive = counter-clockwise on screen
    S=np.diag([sx,sy,1.0]); T1=np.array([[1,0,-cx],[0,1,-cy],[0,0,1]]); T2=np.array([[1,0,cx],[0,1,cy],[0,0,1]])
    return T2@R@S@T1
def M_tr(dx,dy): return np.array([[1,0,dx],[0,1,dy],[0,0,1]],np.float64)
def rig(t):
    w=wpos(t); wl=wpos(t-3/FPS); wb=wpos(t-2/FPS); wh=wpos(t-4/FPS)
    bob=-13*(wb-0.5)+6*math.sin(2*math.pi*t/5.5)
    bank=-2.5*ss(6.6,7.6,t)*(1-ss(8.6,9.6,t))+1.5*ss(12.4,13.6,t)
    G=M_rot(470,400,bank)@M_tr(0,bob)
    body=G@M_rot(300,440,0.8*(wb-0.5))
    inner=G@M_rot(472,322,56*w,1.0,1-0.10*w)
    outer=inner@M_rot(262,170,float(np.clip(-45*(w-wl),-11,11))+1.0*math.sin(2*math.pi*t*1.7))
    rw=G@M_rot(694,352,12-42*w,1.0,1-0.22*w)
    head=G@M_tr(0,-3*(wh-wb))@M_rot(600,470,1.4*math.sin(2*math.pi*t/3.1))
    return {'rwing':rw,'outer':outer,'inner':inner,'body':body,'back':G,'head':head}
def cam(t):
    k=ss(11.5,16.0,t)
    width=1138-170*k; cx=512+40*k; cy=318+20*k-6*ss(0,6,t)
    return width,cx,cy
def view(width,cx,cy,depth):
    w0,c0x,c0y=1138,512,318
    wl=w0*(width/w0)**depth; lx=c0x+(cx-c0x)*depth; ly=c0y+(cy-c0y)*depth
    s=OW/wl
    return np.array([[s,0,OW/2-s*lx],[0,s,OH/2-s*ly],[0,0,1]],np.float64)
HALF=np.diag([0.5,0.5,1.0])
def over(dst,src):
    a=src[...,3:4]/255.0; dst*=(1-a); dst+=src[...,:3]*a
def draw_layer(dst,L,t,speed,depth,blur=0):
    T,r0,pad=L; width,cx,cy=cam(t)
    V=view(width,cx,cy,depth)
    A=M_tr(speed*t,0)@np.array([[1,0,0],[0,1,r0-pad/2.0],[0,0,1]])     # strip coords (2x) -> painting 1x, scrolled
    M=(V@np.linalg.inv(np.linalg.inv(A)@np.eye(3))@HALF) if False else (V@M_tr(-speed*t,0)@M_tr(0,r0-pad/2.0)@HALF)
    out=cv2.warpAffine(T,M[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_WRAP)
    if blur: out=cv2.GaussianBlur(out,(0,0),blur)
    over(dst,out)
def draw_mist(dst,N,t,speed,depth,yc,hh,amt,col=(205,208,210)):
    width,cx,cy=cam(t); V=view(width,cx,cy,depth)
    Mn=V@M_tr(-speed*t,yc-hh/2)@np.diag([1138*2.1/N.shape[1],hh/N.shape[0],1])
    n=cv2.warpAffine(N,Mn[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_WRAP)
    band=cv2.warpAffine(np.ones_like(N),Mn[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
    band=cv2.GaussianBlur(band,(0,0),60)
    a=np.clip((n*1.7-0.55),0,1)*band*amt
    dst*=(1-a[...,None]); dst+=np.array(col,np.float32)*a[...,None]
VIG=None
def frame(i):
    global VIG
    t=i/FPS; tp=(i//2)*2/FPS                     # puppet on twos
    dst=np.zeros((OH,OW,3),np.float32)
    draw_layer(dst,L_sky,t,4,0.35,blur=1.0)
    draw_mist(dst,MIST1,t,9,0.45,350,140,0.32)
    draw_layer(dst,L_b1,t,18,0.6,blur=0.6)
    draw_mist(dst,MIST2,t,22,0.7,520,120,0.22)
    draw_layer(dst,L_b2,t,34,0.8,blur=1.2)
    width,cx,cy=cam(t); V=view(width,cx,cy,1.0); R=rig(tp)
    for k in ['rwing','outer','inner','body','back','head']:
        img,(x0,y0)=PC[k]
        M=V@R[k]@HALF@M_tr(x0,y0)
        over(dst,cv2.warpAffine(img,M[:2],(OW,OH),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT))
    draw_layer(dst,L_near,t,64,1.35,blur=5.0)
    draw_mist(dst,MIST1,t,30,1.2,250,520,0.07)
    if VIG is None:
        y,x=np.mgrid[0:OH,0:OW].astype(np.float32); r=np.hypot((x-OW/2)/(OW/2),(y-OH/2)/(OH/2))
        VIG=(1-0.10*np.clip(r-0.55,0,1)**1.5)[...,None]
    dst*=VIG
    fi=ss(0,0.8,t)*(1-ss(DUR-1.2,DUR,t)); dst*=fi
    return np.clip(dst,0,255).astype(np.uint8)
if __name__=='__main__':
    import sys
    if len(sys.argv)>1 and sys.argv[1]=='--stills':
        for t in [0.5,2.6,3.0,7.5,14.0]:
            cv2.imwrite(f'chk3/nor_{t}.jpg',cv2.resize(frame(int(t*FPS)),(960,540)))
        sys.exit()
    p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{OW}x{OH}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','16','-preset','medium','-pix_fmt','yuv420p','nor/owl_norstein.mp4'],stdin=subprocess.PIPE)
    for i in range(int(DUR*FPS)):
        p.stdin.write(frame(i).tobytes())
        if i%48==0: print('t',i/FPS,flush=True)
    p.stdin.close(); p.wait(); print('video ok')
