import cv2, numpy as np, subprocess
from skimage.graph import MCP_Geometric
FPS=24; W,H=854,480; S=2; hh,ww=H*S,W*S
base=cv2.imread('p01/base.png')
m=np.load('chk3/river_m.npy').astype(np.uint8)
# geodesic distance along the river from its left end -> flow direction (left to right, like the clouds)
cost=np.where(m>0,1.0,np.inf)
ys,xs=np.nonzero(m); xmin=xs.min()
starts=[(y,x) for y,x in zip(ys,xs) if x<=xmin+2]
dist,_=MCP_Geometric(cost).find_costs(starts)
dist=np.where(np.isfinite(dist),dist,0).astype(np.float32)
ext=cv2.dilate(dist,np.ones((5,5),np.float32))*(1-m)+dist*m
gx=cv2.Sobel(cv2.GaussianBlur(ext,(0,0),4),cv2.CV_32F,1,0,ksize=5)
gy=cv2.Sobel(cv2.GaussianBlur(ext,(0,0),4),cv2.CV_32F,0,1,ksize=5)
gx=cv2.GaussianBlur(gx,(0,0),6); gy=cv2.GaussianBlur(gy,(0,0),6)
nrm=np.sqrt(gx*gx+gy*gy)+1e-6; dxv=gx/nrm; dyv=gy/nrm
weak=nrm<1e-3; dxv[weak]=1; dyv[weak]=0
# river texture extended outward with nearest river pixels
_,lab=cv2.distanceTransformWithLabels((1-m).astype(np.uint8),cv2.DIST_L2,5,labelType=cv2.DIST_LABEL_PIXEL)
idx=np.zeros(lab.max()+1,np.int64); idx[lab[m>0]]=np.arange(len(ys))
near=idx[lab]; tex=base.copy(); outside=m==0
tex[outside]=base[ys[near[outside]],xs[near[outside]]]
# upsample
up=lambda a,inter=cv2.INTER_LINEAR: cv2.resize(a,(ww,hh),interpolation=inter)
T=up(tex,cv2.INTER_LANCZOS4).astype(np.float32); DX=up(dxv); DY=up(dyv)
alpha=cv2.GaussianBlur(cv2.erode(m,np.ones((3,3),np.uint8)).astype(np.float32),(0,0),1.6); A=up(alpha)[...,None]
yy,xx=np.mgrid[0:hh,0:ww].astype(np.float32)
ph=cv2.resize(np.random.default_rng(9).random((8,14)).astype(np.float32),(ww,hh),interpolation=cv2.INTER_CUBIC)
L=40.0*S; P=1.4
def river(t):
    acc=0; wsum=0
    for j in (0.0,0.5):
        phi=np.mod(t/P+j+0.6*ph,1.0); s=(phi-0.5)*L; w=1-np.abs(2*phi-1)
        smp=cv2.remap(T,xx-DX*s,yy-DY*s,cv2.INTER_LINEAR,borderMode=cv2.BORDER_REFLECT)
        acc=acc+smp*w[...,None]; wsum=wsum+w
    out=acc/wsum[...,None]
    # gentle glints travelling with the current
    g=0.5+0.5*np.sin(2*np.pi*((xx*DX+yy*DY)/(26*S)-t/P*1.2))
    return out*(1+0.05*(g[...,None]-0.5))
np.save('chk3/river_parts.npy',np.array([0]))
if __name__=='__main__':
    import sys
    # reproduce segA exactly (same code as wind_open.py) and lay the flowing river over it
    NR_SEC=float(sys.argv[1]) if len(sys.argv)>1 else 4.6
    exec(open("wind_open_core.py").read())
