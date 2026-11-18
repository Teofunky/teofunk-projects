import cv2, numpy as np, subprocess
FPS=24; W,H=854,480
raw=subprocess.run(['ffmpeg','-v','error','-i','clips/E08_fix.mp4','-f','rawvideo','-pix_fmt','bgr24','-'],capture_output=True).stdout
fr=np.frombuffer(raw,np.uint8).reshape(-1,H,W,3).copy(); N=len(fr); print('frames',N)
S0=int(15.0*FPS); REF=S0+48
mask=np.load('chk3/creature_mask.npy')
ref=fr[REF].astype(np.float32)
clean=cv2.inpaint(fr[REF],mask*255,6,cv2.INPAINT_TELEA).astype(np.float32)
# grain matched to nearby snow
ring=cv2.dilate(mask,np.ones((31,31),np.uint8))-cv2.dilate(mask,np.ones((5,5),np.uint8))
ring[:214]=0
hp=ref-cv2.GaussianBlur(ref,(0,0),2.0)
sd=hp[ring>0].std(axis=0); print('grain sd',sd)
rng=np.random.default_rng(5); nz=rng.standard_normal(ref.shape).astype(np.float32)
nz=cv2.GaussianBlur(nz,(0,0),0.8); nz/=nz.std()
clean=clean+nz*sd*0.6
feather=cv2.GaussianBlur(cv2.dilate(mask,np.ones((3,3),np.uint8)).astype(np.float32),(0,0),2.0)[...,None]
feather=np.clip(feather*1.3,0,1)
# alpha of the crows layer during the dissolve from the owl shot
box=(slice(180,300),slice(540,840)); o=fr[S0].astype(np.float32)[box]; c=ref[box]; m2=(1-cv2.dilate(mask,np.ones((15,15),np.uint8)))[box][...,None].astype(np.float32)
d=(c-o)*m2; den=(d*d).sum()
O=fr[S0-6].astype(np.float32).copy()
rb=ring>0
for i in range(S0-6,N):
    f=fr[i].astype(np.float32)
    a=float(np.clip((((f[box]-o)*m2)*d).sum()/den,0,1)) if S0<=i<S0+30 else (0.0 if i<S0 else 1.0)
    if i<S0+30 and i%3==0: print(i,round((i)/FPS,2),round(a,2))
    # brightness tracking on the ring
    db=(f[ring>0].mean(axis=0)-ref[ring>0].mean(axis=0))*a
    if i<S0+30:
        a=float(np.clip(a*1.08,0,1))
        model=(1-a)*O+a*ref
        corr=f[rb].mean(axis=0)-model[rb].mean(axis=0)
        patch=(1-a)*O+a*clean+corr
        out=f*(1-feather)+feather*patch
    else:
        out=f*(1-feather)+feather*(clean+db)
    fr[i]=np.clip(out,0,255).astype(np.uint8)
p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-i','clips/E08_fix.mp4','-map','0:v','-map','1:a','-c:v','libx264','-crf','14','-pix_fmt','yuv420p','-c:a','copy','-shortest','clips/E08_fix_crow.mp4'],stdin=subprocess.PIPE)
p.stdin.write(fr.tobytes()); p.stdin.close(); p.wait()
for t in [15.5,16.0,17.5,19.3]:
    k=int(t*FPS); cv2.imwrite(f'chk3/crow_fix_{t}.jpg',cv2.resize(fr[k][170:300,520:840],(640,260)))
print('ok')
