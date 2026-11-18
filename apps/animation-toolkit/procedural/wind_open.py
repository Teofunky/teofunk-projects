import cv2, numpy as np, subprocess, sys
SRC='clips/P01.mp4'; FPS=24; W,H=854,480; T_END=6.6; XF0=5.8
NF=int(round(T_END*FPS))            # frames we replace: 0..NF-1
# decode first NF original frames
raw=subprocess.run(['ffmpeg','-v','error','-i',SRC,'-frames:v',str(NF),'-f','rawvideo','-pix_fmt','bgr24','-'],capture_output=True).stdout
orig=np.frombuffer(raw,np.uint8).reshape(NF,H,W,3)
base=orig[int(3*FPS)].copy()
S=2; B=cv2.resize(base,(W*S,H*S),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
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
SKY_SHIFT=24.0; A=4.2
out=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-',
    '-c:v','libx264','-crf','16','-preset','medium','-pix_fmt','yuv420p','p01/segA.mp4'],stdin=subprocess.PIPE)
for i in range(NF):
    t=i/FPS
    env=1.0-0.45*sstep(4.5,T_END,np.float32(t))
    # clouds: drift left->right ending at original position, plus slow breathing warp
    s=-SKY_SHIFT*(1-t/T_END)
    a=np.cos(2*np.pi*t/9); b=np.sin(2*np.pi*t/9)
    sdx=s+1.6*(a*n1+b*n2); sdy=1.0*(a*n3+b*n4)
    # wind in flowers: travelling gust + two waves
    gust=0.55+0.45*np.sin(2*np.pi*(t/5.0-x0/900))
    wave=np.sin(2*np.pi*(0.45*t-x0/260))+0.45*np.sin(2*np.pi*(1.1*t-x0/90)+1.7)
    gdx=A*env*gust*wave
    gdy=-0.18*np.abs(gdx)
    # river shimmer
    rdx=0.7*np.sin(2*np.pi*(0.5*t+y0/6.0))
    dx=(sky*sdx+grass*gdx+blue*rdx)*S
    dy=(sky*sdy+grass*gdy)*S
    fr=cv2.remap(B,(xx-dx).astype(np.float32),(yy-dy).astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
    fr=cv2.resize(fr,(W,H),interpolation=cv2.INTER_AREA)
    if t>=XF0:   # crossfade into the original clip
        k=float(sstep(XF0,T_END,np.float32(t)))
        fr=fr*(1-k)+orig[i].astype(np.float32)*k
    out.stdin.write(np.clip(fr,0,255).astype(np.uint8).tobytes())
out.stdin.close(); out.wait()
# join: segA + original from frame NF, original audio
subprocess.run(['ffmpeg','-v','error','-y','-i','p01/segA.mp4','-i',SRC,'-filter_complex',
 f'[1:v]trim=start_frame={NF},setpts=PTS-STARTPTS[b];[0:v][b]concat=n=2:v=1:a=0[v]',
 '-map','[v]','-map','1:a','-c:v','libx264','-crf','16','-preset','medium','-pix_fmt','yuv420p','-c:a','copy','clips/P01_wind.mp4'],check=True)
print('done')
