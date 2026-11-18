import cv2, numpy as np, subprocess, sys
src,dst=sys.argv[1],sys.argv[2]
W,H=854,480; OW,OH=1920,1080; FPS=24
sr=cv2.dnn_superres.DnnSuperResImpl_create(); sr.readModel('sr/ESPCN_x2.pb'); sr.setModel('espcn',2)
dec=subprocess.Popen(['ffmpeg','-v','error','-i',src,'-f','rawvideo','-pix_fmt','bgr24','-'],stdout=subprocess.PIPE)
enc=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{OW}x{OH}','-r',str(FPS),'-i','-','-i',src,'-map','0:v','-map','1:a',
                      '-c:v','libx264','-crf','17','-preset','medium','-profile:v','high','-pix_fmt','yuv420p','-c:a','copy','-movflags','+faststart',dst],stdin=subprocess.PIPE)
rng=np.random.default_rng(1); n=0
while True:
    b=dec.stdout.read(W*H*3)
    if len(b)<W*H*3: break
    f=np.frombuffer(b,np.uint8).reshape(H,W,3)
    y=sr.upsample(f)
    y=cv2.resize(y,(OW,OH),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
    bl=cv2.GaussianBlur(y,(0,0),1.3); y=y+0.28*(y-bl)                  # gentle sharpening
    g=cv2.GaussianBlur(rng.standard_normal((OH,OW)).astype(np.float32),(0,0),0.6)
    y=y+(g*2.2*(0.4+0.6*(y.mean(axis=2)/255)))[...,None]               # fine paint-like grain, weaker in shadows
    enc.stdin.write(np.clip(y,0,255).astype(np.uint8).tobytes()); n+=1
    if n%240==0: print('frames',n,flush=True)
enc.stdin.close(); enc.wait(); print('done',n)
