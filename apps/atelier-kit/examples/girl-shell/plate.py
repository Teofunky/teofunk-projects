import sys,glob,cv2,numpy as np
sys.path.insert(0,'/home/claude/teofunk-projects/apps/atelier-kit'); import mark
P='painting.png'; im=cv2.imread(P)
names=['body','legB','legF','hand','shell']+[f'f{i:02d}' for i in range(1,12)]
A={k:np.load(f'alpha_{k}.npy') for k in names}
for k,a in A.items(): mark.cutout_alpha(P,a,f'L_{k}.png')
Mg=np.zeros(im.shape[:2],np.uint8); Mf=np.zeros_like(Mg)
for k,a in A.items():
    if k=='body':  # почти не двигается: подложку меняем только под плотной частью, край остаётся картиной
        Mg|=((a>0.35)*255).astype(np.uint8); continue
    g=3
    d=cv2.dilate(((a>0.004)*255).astype(np.uint8),cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(g,g)))
    if k=='body': Mg|=d
    else: Mf|=d
mark._fill(im,Mf,'plate_f.png',7,7)                    # рыбы: Telea
mark._fill(cv2.imread('plate_f.png'),Mg,'plate.png',7,8,method='rows')   # девочка: по строкам
pl=cv2.imread('plate.png'); cv2.imwrite('plate_check.jpg',np.hstack([im[40:800,180:600],pl[40:800,180:600]]))
# под рукой и раковиной небо: перенос соседнего чистого неба справа (Telea тянул сюда цвет лица и рукава)
pl=cv2.imread('plate.png').astype(np.float32); src=cv2.imread('plate_f.png').astype(np.float32)
R=np.zeros(im.shape[:2],np.uint8)
for k in ('hand','shell'): R|=((A[k]>0.004)*255).astype(np.uint8)
R=cv2.dilate(R,np.ones((7,7),np.uint8))
R[A['body']>0.6]=0
SH=82
shifted=np.zeros_like(src); shifted[:, :-SH]=src[:, SH:]
ring=(cv2.dilate(R,np.ones((25,25),np.uint8))>0)&(R==0)&(A['body']<0.05)&(A['hand']<0.05)&(A['shell']<0.05)
m=R>0
mu_t=pl[ring].mean(0); sd_t=pl[ring].std(0); mu_s=shifted[m].mean(0); sd_s=shifted[m].std(0)
shifted=(shifted-mu_s)*np.clip(sd_t/np.maximum(sd_s,1),0.6,1.0)+mu_t   # тон как у неба вокруг, зерно не усиливать
w=cv2.GaussianBlur(R.astype(np.float32)/255,(0,0),1.5)[...,None]
pl=pl*(1-w)+shifted*w
cv2.imwrite('plate.png',np.clip(pl,0,255).round().astype(np.uint8))
cv2.imwrite('plate_hand.jpg',cv2.resize(np.clip(pl[170:370,420:640],0,255).astype(np.uint8),None,fx=2,fy=2))
