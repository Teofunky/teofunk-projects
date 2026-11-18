import cv2, numpy as np, subprocess
FPS=24; W,H=854,480; S=2; hh,ww=H*S,W*S
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32); y0=yy/S; x0=xx/S
def ss(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
def frames(src,start=None,dur=None):
    cmd=['ffmpeg','-v','error']+(['-ss',str(start)] if start is not None else [])+['-i',src]+(['-t',str(dur)] if dur else [])+['-f','rawvideo','-pix_fmt','bgr24','-']
    raw=subprocess.run(cmd,capture_output=True).stdout
    return np.frombuffer(raw,np.uint8).reshape(-1,H,W,3)
def writer(path):
    return subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p',path],stdin=subprocess.PIPE)
# ---- E09: vary the repeated paintings with camera moves (bison, women, hunter)
fr=frames('clips/E09_fix.mp4')
K=[(0.0,1.0,.5,.5),(3.7,1.0,.5,.5),(7.45,1.18,.30,.55),(7.55,1.26,.50,.34),(11.25,1.12,.50,.40),(11.45,1.36,.64,.62),(14.25,1.18,.56,.46),(14.45,1.0,.5,.5),(30,1.0,.5,.5)]
def cam(t):
    for (a,za,xa,ya),(b,zb,xb,yb) in zip(K,K[1:]):
        if a<=t<=b:
            u=(t-a)/(b-a) if b>a else 0; u=u*u*(3-2*u)
            return za+(zb-za)*u, xa+(xb-xa)*u, ya+(yb-ya)*u
    return 1,.5,.5
p=writer('e09/kb.mp4')
for i,f in enumerate(fr):
    z,cxn,cyn=cam(i/FPS)
    cw,chh=W/z,H/z; cx=np.clip(cxn*W,cw/2,W-cw/2); cy=np.clip(cyn*H,chh/2,H-chh/2)
    M=np.float32([[z,0,W/2-z*cx],[0,z,H/2-z*cy]])
    p.stdin.write(cv2.warpAffine(f,M,(W,H),flags=cv2.INTER_LANCZOS4,borderMode=cv2.BORDER_REFLECT).tobytes())
p.stdin.close(); p.wait(); print('ok')
