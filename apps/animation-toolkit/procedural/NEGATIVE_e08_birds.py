import cv2, numpy as np, subprocess
img=cv2.imread('/mnt/user-data/uploads/cce4c18c-image.png'); H0,W0=img.shape[:2]
cv=[(40,100),(190,75),(35,182),(148,200),(205,175),(180,210),(212,225),(180,258),(410,88),(515,88),(545,78),(568,60),(555,102),(530,140),(565,135),(497,170),(537,178),(595,178),(800,52)]
hsv=cv2.cvtColor(img,cv2.COLOR_BGR2HSV).astype(np.float32)
V=hsv[...,2]
birds=[]; hole=np.zeros((H0,W0),np.uint8)
for (vx,vy) in cv:
    cx,cy=int(vx/0.9),int(vy/0.9); r=26
    x0,y0,x1,y1=max(cx-r,0),max(cy-r,0),min(cx+r,W0),min(cy+r,H0)
    patch=V[y0:y1,x0:x1]; bgv=np.median(patch)
    m=(patch>bgv+38).astype(np.uint8)*255
    m=cv2.morphologyEx(m,cv2.MORPH_CLOSE,np.ones((3,3),np.uint8))
    n,lab,st,_=cv2.connectedComponentsWithStats(m)
    if n<=1: continue
    # keep components near the centre
    cc=np.zeros_like(m)
    for i in range(1,n):
        x,y,w,h,a=st[i]
        if a>=6 and abs((x+w/2)-(cx-x0))<r*0.8 and abs((y+h/2)-(cy-y0))<r*0.8: cc[lab==i]=255
    if cc.sum()==0: continue
    cc=cv2.dilate(cc,np.ones((3,3),np.uint8))
    full=np.zeros((H0,W0),np.uint8); full[y0:y1,x0:x1]=cc
    hole|=cv2.dilate(full,np.ones((5,5),np.uint8))
    birds.append((x0,y0,x1,y1,cc))
print('birds',len(birds))
bg=cv2.inpaint(img,hole,7,cv2.INPAINT_TELEA)
cv2.imwrite('e08/night_black_bg.png',bg)
# render
FPS=24; W,H=854,480; S=2
crop_y0=0; ch=int(W0*9/16)
rng=np.random.default_rng(11)
params=[(rng.uniform(14,34)*rng.choice([1,1,1,-1]),rng.uniform(-8,4),rng.uniform(2.4,3.6),rng.uniform(0,6.28)) for _ in birds]
T=4.6; N=int(T*FPS)
outW,outH=W*S,H*S; sc=outW/W0
bgc=cv2.resize(bg[crop_y0:crop_y0+ch],(outW,outH),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
yy,xx=np.mgrid[0:outH,0:outW].astype(np.float32); y0n=yy/S; x0n=xx/S
trees=np.clip((y0n-150)/120,0,1)
p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p','e08/night_birds.mp4'],stdin=subprocess.PIPE)
stars=(cv2.cvtColor(bgc.astype(np.uint8),cv2.COLOR_BGR2GRAY)>200).astype(np.float32)*(1-trees)
for i in range(N):
    t=i/FPS
    dx=trees*1.6*np.sin(2*np.pi*(0.4*t-x0n/90)); 
    fr=cv2.remap(bgc,(xx-dx*S).astype(np.float32),yy,cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
    tw=1+0.35*np.sin(2*np.pi*(1.7*t)+xx*0.37+yy*0.21)
    fr=fr*(1-stars[...,None])+fr*stars[...,None]*tw[...,None]
    for (x0,y0,x1,y1,cc),(vx,vy,f,ph) in zip(birds,params):
        spr=img[y0:y1,x0:x1].astype(np.float32); a=cc.astype(np.float32)/255
        h,w=a.shape; flap=1+0.28*np.sin(2*np.pi*f*t+ph)
        nh=max(2,int(round(h*sc*flap))); nw=int(round(w*sc))
        spr2=cv2.resize(spr,(nw,nh),interpolation=cv2.INTER_CUBIC); a2=cv2.resize(a,(nw,nh),interpolation=cv2.INTER_LINEAR)[...,None]
        cxp=(x0+w/2+vx*t-0)*sc; cyp=(y0+h/2-crop_y0+vy*t+2.0*np.sin(2*np.pi*0.6*t+ph))*sc
        X0=int(round(cxp-nw/2)); Y0=int(round(cyp-nh/2))
        xa,ya=max(X0,0),max(Y0,0); xb,yb=min(X0+nw,outW),min(Y0+nh,outH)
        if xb<=xa or yb<=ya: continue
        sx,sy=xa-X0,ya-Y0
        reg=fr[ya:yb,xa:xb]; s=spr2[sy:sy+(yb-ya),sx:sx+(xb-xa)]; al=a2[sy:sy+(yb-ya),sx:sx+(xb-xa)]
        fr[ya:yb,xa:xb]=reg*(1-al)+s*al
    o=cv2.resize(np.clip(fr,0,255).astype(np.uint8),(W,H),interpolation=cv2.INTER_AREA)
    p.stdin.write(o.tobytes())
p.stdin.close(); p.wait(); print('ok')
