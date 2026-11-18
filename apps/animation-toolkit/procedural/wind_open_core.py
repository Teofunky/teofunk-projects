import cv2, numpy as np, subprocess, sys
SRC='clips/P01.mp4'; T_END=6.6; XF0=5.8
NF=int(round(T_END*FPS))            # frames we replace: 0..NF-1
# decode first NF original frames

B=cv2.resize(base,(W*S,H*S),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
hh,ww=H*S,W*S
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32)
y0=yy/S; x0=xx/S                     # coords in 854x480 units
def sstep(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
sky=1-sstep(172,192,y0)                                   # sky mask
grass=sstep(345,382,y0)*(1-0.75*sstep(440,480,y0))        # flower band, heads move most
hsv=cv2.cvtColor(base,cv2.COLOR_BGR2HSV).astype(np.float32)
blue=((hsv[...,0]>85)&(hsv[...,0]<130)&(hsv[...,1]>18)).astype(np.float32)
blue=cv2.GaussianBlur(blue,(0,0),3); blue=cv2.resize(blue,(ww,hh))*sstep(205,225,y0)*(1-sstep(345,360,y0))
rng=np.random.default_rng(7)
def lowfreq(seed,scale):
    n=np.random.default_rng(seed).standard_normal((H//scale+3,W//scale+3)).astype(np.float32)
    return cv2.resize(cv2.GaussianBlur(n,(0,0),1),(ww,hh),interpolation=cv2.INTER_CUBIC)
n1,n2,n3,n4=lowfreq(1,40),lowfreq(2,40),lowfreq(3,40),lowfreq(4,40)
SKY_SHIFT=24.0; AW=4.2

RA=A

NR=int(NR_SEC*FPS)
outp=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p','e09/segA_river_long.mp4'],stdin=subprocess.PIPE)
for i in range(NR):
    t=i/FPS
    env=1.0-0.45*sstep(4.5,T_END,np.float32(t))
    s=-SKY_SHIFT*(1-t/T_END)
    a=np.cos(2*np.pi*t/9); b=np.sin(2*np.pi*t/9)
    sdx=s+1.6*(a*n1+b*n2); sdy=1.0*(a*n3+b*n4)
    gust=0.55+0.45*np.sin(2*np.pi*(t/5.0-x0/900))
    wave=np.sin(2*np.pi*(0.45*t-x0/260))+0.45*np.sin(2*np.pi*(1.1*t-x0/90)+1.7)
    gdx=AW*env*gust*wave
    gdy=-0.18*np.abs(gdx)
    rdx=0.7*np.sin(2*np.pi*(0.5*t+y0/6.0))
    dx=(sky*sdx+grass*gdx+blue*rdx)*S
    dy=(sky*sdy+grass*gdy)*S
    fr=cv2.remap(B,(xx-dx).astype(np.float32),(yy-dy).astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
    rv=river(t)
    fr=fr*(1-RA)+rv*RA
    fr=cv2.resize(fr,(W,H),interpolation=cv2.INTER_AREA)
    outp.stdin.write(np.clip(fr,0,255).astype(np.uint8).tobytes())
outp.stdin.close(); outp.wait(); print('river ok')
