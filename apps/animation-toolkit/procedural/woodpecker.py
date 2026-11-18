import cv2, numpy as np, subprocess, sys
FPS=24; W,H=854,480; S=2; ww,hh=W*S,H*S
DUR=float(sys.argv[1]) if len(sys.argv)>1 else 5.0
A0=7.3                                   # audio offset in E10 used for this shot
ons=[7.83,7.99,8.12,8.3,8.4,8.53,8.71,8.92,9.16,9.29,9.49,9.64,9.81,10.0,10.17,10.33,10.51,10.68,10.87,11.05,11.21,11.39,12.73]
ons=[o-A0 for o in ons]
P=cv2.imread('/mnt/user-data/uploads/a701f248-image.png')   # 1024x1024 painting
Y0=250; crop=P[Y0:Y0+576]                                    # 16:9 crop, full width
sc=ww/1024.0
B=cv2.resize(crop,(ww,hh),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32)
px=xx/sc; py=yy/sc+Y0                                        # painting coords
def ss(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
# static zones: trunk and bird body
trunk=(1-ss(330,380,px))
bird=np.zeros((hh,ww),np.float32)
cv2.ellipse(bird,(int(375*sc),int((620-Y0)*sc)),(int(110*sc),int(210*sc)),-12,0,360,1,-1)
bird=cv2.GaussianBlur(bird,(0,0),10*S)
free=np.clip(1-np.maximum(trunk,bird*1.3),0,1)
lab=cv2.cvtColor(np.clip(B,0,255).astype(np.uint8),cv2.COLOR_BGR2LAB).astype(np.float32)
bluish=cv2.GaussianBlur(ss(2,-6,lab[...,2]-128),(0,0),3*S)
twigs=ss(420,470,px)*(1-ss(540,600,py))
grass=ss(620,680,py)*(1-ss(800,840,py))
water=bluish*ss(520,560,py)
# head: rotation about the neck, masked
cx,cy=395*sc,(515-Y0)*sc
head=np.zeros((hh,ww),np.float32)
cv2.ellipse(head,(int(398*sc),int((474-Y0)*sc)),(int(52*sc),int(44*sc)),0,0,360,1,-1)
cv2.ellipse(head,(int(352*sc),int((440-Y0)*sc)),(int(22*sc),int(10*sc)),33,0,360,1,-1)   # beak
head=cv2.GaussianBlur(head,(0,0),5*S)
def tap_angle(t):
    a=0.0
    for o in ons:
        u=(t-o)*FPS                     # frames since onset
        if -1.0<=u<=4.0:
            v= (u+1)/1.5 if u<0.5 else max(0,1-(u-0.5)/3.5)
            a=max(a,v)
    return a
out=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','14','-pix_fmt','yuv420p','v4/woodpecker.mp4'],stdin=subprocess.PIPE)
n=int(round(DUR*FPS))
for i in range(n):
    t=i/FPS
    gust=0.6+0.4*np.sin(2*np.pi*(t/4.0-px/1100))
    hang=np.clip((py-180)/380,0,1)
    dx=free*(twigs*hang*5.0*gust*np.sin(2*np.pi*(0.42*t-px/450))
             +grass*3.2*gust*(np.sin(2*np.pi*(0.5*t-px/210))+0.4*np.sin(2*np.pi*(1.2*t-px/75)+1.3))
             +water*1.2*np.sin(2*np.pi*(px/48-0.8*t)+py/5.0))
    dy=free*(-0.15*np.abs(grass*3.2*gust*np.sin(2*np.pi*(0.5*t-px/210))))
    dx=dx*S; dy=dy*S
    # peck: rotate head toward the bark (negative angle) and thrust a little
    k=tap_angle(t); th=np.deg2rad(-14.0*k); tx=-4.0*S*k
    c,s=np.cos(-th),np.sin(-th)                       # inverse rotation for backward mapping
    rx=cx+c*(xx-tx-cx)-s*(yy-cy)
    ry=cy+s*(xx-tx-cx)+c*(yy-cy)
    mx=(xx-dx)*(1-head)+rx*head
    my=(yy-dy)*(1-head)+ry*head
    # slow push-in toward the bird
    z=1.0+0.035*(t/DUR); fx,fy=380*sc,(520-Y0)*sc
    mx=fx+(mx-fx)/z; my=fy+(my-fy)/z
    o=cv2.remap(B,mx.astype(np.float32),my.astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
    out.stdin.write(np.clip(cv2.resize(o,(W,H),interpolation=cv2.INTER_AREA),0,255).astype(np.uint8).tobytes())
out.stdin.close(); out.wait()
subprocess.run(['ffmpeg','-v','error','-y','-i','v4/woodpecker.mp4','-ss',str(A0),'-t',str(DUR),'-i','clips/E10.mp4','-map','0:v','-map','1:a','-c:v','copy','-c:a','aac','-b:a','192k','-shortest','v4/woodpecker_av.mp4'],check=True)
print('ok')
