"""Подложка v8: ноги (тесная маска, заливка по строкам, тень только под самой стопой), рука и раковина
(тон неба без девочки + фактура звёздного неба). Основа: подложка v5 (рыбы, девочка)."""
import sys,cv2,numpy as np
sys.path.insert(0,'/home/claude/teofunk-projects/apps/atelier-kit'); import mark
im8=cv2.imread('painting.png'); im=im8.astype(np.float32); H,W=im.shape[:2]
aB=np.load('alpha_legB3.npy'); aF=np.load('alpha_legF3.npy')
pl=cv2.imread('plate_v7.png')
old=cv2.dilate(((np.load('alpha_legB.npy')>0.004)|(np.load('alpha_legF.npy')>0.004)).astype(np.uint8)*255,np.ones((5,5),np.uint8)); old[:626]=0
pl[old>0]=im8[old>0]
R=cv2.dilate(((np.maximum(aB,aF)>0.02)*255).astype(np.uint8),np.ones((3,3),np.uint8)); R[:626]=0
mark._fill(pl,R,'plate_tmp.png',5,9,method='rows')
pl=cv2.imread('plate_tmp.png').astype(np.float32)
core=cv2.erode(((np.maximum(aB,aF)>0.9)*255).astype(np.uint8),np.ones((3,3),np.uint8)).astype(np.float32)/255
sh=np.zeros((H,W),np.float32); cv2.ellipse(sh,(428,770),(56,10),0,0,360,1.0,-1); sh=cv2.GaussianBlur(sh,(0,0),6)
pl=pl*(1-0.28*(sh*cv2.GaussianBlur(core,(0,0),1.0))[...,None])
# рука и раковина
A={k:np.load(f'alpha_{k}.npy') for k in ['body','hand','shell']}
reg=np.maximum(A['hand'],A['shell']); Rh=cv2.dilate(((reg>0.004)*255).astype(np.uint8),np.ones((9,9),np.uint8)); Rh[A['body']>0.6]=0
M=Rh|cv2.dilate(((A['body']>0.02)*255).astype(np.uint8),np.ones((7,7),np.uint8))
s=4; small=cv2.resize(cv2.GaussianBlur(im8,(0,0),3),(W//s,H//s),interpolation=cv2.INTER_AREA)
low=cv2.resize(cv2.inpaint(small,cv2.resize(M,(W//s,H//s),interpolation=cv2.INTER_NEAREST),6,cv2.INPAINT_TELEA),(W,H),interpolation=cv2.INTER_CUBIC).astype(np.float32)
low=cv2.GaussianBlur(low,(0,0),4)
hp=im-cv2.GaussianBlur(im,(0,0),5)
ys,xs=np.nonzero(Rh); y0,y1,x0,x1=ys.min(),ys.max()+1,xs.min(),xs.max()+1
tex=np.zeros_like(im); tex[y0:y1,x0:x1]=hp[186:186+(y1-y0),714:714+(x1-x0)]
m=cv2.GaussianBlur(Rh.astype(np.float32)/255,(0,0),1.5)[...,None]
pl=pl*(1-m)+np.clip(low+tex,0,255)*m
cv2.imwrite('plate.png',np.clip(pl,0,255).round().astype(np.uint8))
# раковина плотнее: без тёмного ободка неба
ms=cv2.imread('../mask_shell.png',0); ms=cv2.erode(ms,np.ones((3,3),np.uint8))
a=mark.soft(ms,grow=0,feather=0.6); np.save('alpha_shell2.npy',a)
mark.cutout_alpha('painting.png',a,'L_shell_v8.png')
comp=pl.copy()
for n in ('L_legB.png','L_legF.png'):
    L=cv2.imread(n,cv2.IMREAD_UNCHANGED).astype(np.float32); al=L[...,3:4]/255; comp=comp*(1-al)+L[...,:3]*al
d=np.abs(comp-im).max(2)[626:800,340:540]; print('тождество под ногами: средняя',round(float(d.mean()),2),'p99',int(np.percentile(d,99)))
