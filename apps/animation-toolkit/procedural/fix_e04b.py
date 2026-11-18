import cv2, numpy as np, subprocess
FPS=24; W,H=854,480; S=2; hh,ww=H*S,W*S
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32); y0=yy/S; x0=xx/S
def ss(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
def ell(cx,cy,rx,ry): return np.clip(1-((x0-cx)/rx)**2-((y0-cy)/ry)**2,0,1)
def writer(path):
    return subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p',path],stdin=subprocess.PIPE)
def up(img): return cv2.resize(img,(ww,hh),interpolation=cv2.INTER_LANCZOS4)
def down(img): return cv2.resize(img,(W,H),interpolation=cv2.INTER_AREA)
def remap(B,dx,dy): return cv2.remap(B,(xx-dx*S).astype(np.float32),(yy-dy*S).astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
def wind(t,amp,mask):
    gust=0.55+0.45*np.sin(2*np.pi*(t/4.5-x0/900))
    wave=np.sin(2*np.pi*(0.42*t-x0/250))+0.45*np.sin(2*np.pi*(1.05*t-x0/85)+1.7)
    dx=amp*mask*gust*wave; return dx,-0.18*np.abs(dx)
# ---------- A: women + swimming fish (cut-out sprites) ----------
img=cv2.imread('e04/f1.png')
def poly(pts):
    m=np.zeros((H,W),np.uint8); cv2.fillPoly(m,[np.array(pts,np.int32)],255); return m
bigP=poly([(110,90),(140,74),(195,60),(262,58),(300,68),(338,84),(347,91),(332,101),(300,112),(250,126),(190,128),(140,116),(110,102),(86,106),(60,110),(74,94),(60,78),(86,79)])
smlP=poly([(72,25),(95,12),(140,9),(185,15),(224,23),(205,35),(150,43),(100,40),(80,34)])
hsv=cv2.cvtColor(img,cv2.COLOR_BGR2HSV)
ink=((hsv[...,1]>45)|(hsv[...,2]<95)).astype(np.uint8)*255
def refine(P):
    m=cv2.bitwise_and(P,ink); m=cv2.morphologyEx(m,cv2.MORPH_CLOSE,np.ones((9,9),np.uint8))
    cnts,_=cv2.findContours(m,cv2.RETR_EXTERNAL,cv2.CHAIN_APPROX_SIMPLE)
    f=np.zeros_like(m); cv2.drawContours(f,cnts,-1,255,-1)
    return cv2.dilate(f,np.ones((3,3),np.uint8))
bigM=refine(bigP); smlM=refine(smlP)
hole=cv2.dilate(cv2.bitwise_or(bigM,smlM),np.ones((7,7),np.uint8))
bg=cv2.inpaint(img,hole,9,cv2.INPAINT_TELEA)
cv2.imwrite('e04/bg_inpaint.png',bg)
def sprite(M):
    a=cv2.GaussianBlur(M.astype(np.float32)/255,(0,0),0.8)
    return img.astype(np.float32), a
plants=ss(235,300,y0)*(1-0.4*ss(400,480,y0))
BGu=up(bg)
x=np.arange(W,dtype=np.float32); yv=np.arange(H,dtype=np.float32)
X,Y=np.meshgrid(x,yv)
def place(t,M,off,amp,ph,xmin,xmax):
    # backward map: output pixel (X,Y) samples sprite at (X-off - und_x, Y - und_y)
    rel=np.clip((xmax-(X-off))/(xmax-xmin),0,1)       # 0 at head (right), 1 at tail (left)
    und=amp*(0.25+rel**1.6)*np.sin(2*np.pi*(1.3*t)-rel*3.2+ph)
    mx=(X-off).astype(np.float32); my=(Y-und).astype(np.float32)
    col=cv2.remap(img,mx,my,cv2.INTER_CUBIC,borderMode=cv2.BORDER_CONSTANT)
    al=cv2.remap(cv2.GaussianBlur(M.astype(np.float32)/255,(0,0),0.8),mx,my,cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
    return col.astype(np.float32),al[...,None]
TA=4.6; p=writer('e04/segA2.mp4')
for i in range(int(TA*FPS)):
    t=i/FPS; e=1-(1-t/TA)**2              # ease-out toward the painted position
    dx,dy=wind(t,3.8,plants)
    frame=down(remap(BGu,dx,dy)).astype(np.float32)
    for M,off,amp,ph,xmin,xmax in ((smlM,-35*(1-e),2.0,1.1,72,224),(bigM,-50*(1-e),4.0,0.0,60,347)):
        col,al=place(t,M,off,amp,ph,xmin,xmax)
        frame=frame*(1-al)+col*al
    p.stdin.write(np.clip(frame,0,255).astype(np.uint8).tobytes())
p.stdin.close(); p.wait()
# ---------- C: hunter + bison, heads move (cut-out rotation), breathing, plants ----------
raw=subprocess.run(['ffmpeg','-v','error','-ss','16.5','-i','clips/E04.mp4','-f','rawvideo','-pix_fmt','bgr24','-'],capture_output=True).stdout
fr=np.frombuffer(raw,np.uint8).reshape(-1,H,W,3)
shr=ss(300,345,y0)
body=np.sqrt(ell(545,195,290,140)); cy=195
headA=cv2.GaussianBlur(np.clip(ell(345,222,92,98)*2.2,0,1).astype(np.float32),(0,0),6)
headC=cv2.GaussianBlur(np.clip(ell(462,258,70,66)*2.2,0,1).astype(np.float32),(0,0),6)
def rot(mask,px,py,ang):
    c,s=np.cos(ang),np.sin(ang); rx=x0-px; ry=y0-py
    # backward rotation displacement weighted by mask
    return mask*((c*rx+s*ry)-rx), mask*((-s*rx+c*ry)-ry)
p=writer('e04/segC2.mp4')
for i,f in enumerate(fr):
    t=i/FPS; env=float(ss(0,0.8,np.float32(t)))
    dx,dy=wind(t,3.2*env,shr)
    dy=dy+env*body*1.4*np.sin(2*np.pi*t/3.2)*(y0-cy)/140
    a1=env*np.deg2rad(3.2)*np.sin(2*np.pi*t/3.6)                 # adult: slow grazing nod
    a2=env*np.deg2rad(4.0)*np.sin(2*np.pi*(t/2.7)+1.3)           # calf: turns its head
    rx1,ry1=rot(headA,418,205,a1); rx2,ry2=rot(headC,515,232,a2)
    dx=dx+rx1+rx2; dy=dy+ry1+ry2
    p.stdin.write(down(remap(up(f),dx,dy)).tobytes())
p.stdin.close(); p.wait()
print('ok', int(bigM.sum()/255), int(smlM.sum()/255))
