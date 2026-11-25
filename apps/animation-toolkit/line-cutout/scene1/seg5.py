import cv2, numpy as np, json
from scipy import ndimage as ndi
D=cv2.imread('D.png',0); h,w=D.shape; k=1080/1024
ink=(D<215).astype(np.uint8)
disk=lambda s: cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(s,s))
seeds={"A":[(185,180)],"B":[(345,190),(320,250)],"C":[(497,250),(470,300)],"D":[(612,240)],"E":[(163,410)],
"F":[(245,450),(300,470),(240,330),(265,300)],"G":[(418,440),(400,380)],"H":[(572,465)],"I":[(707,440)],"J":[(843,365),(800,340)],
"K":[(155,740)],"L":[(340,630)],"M":[(497,630),(470,600)],"N":[(604,690)],"O":[(312,835)],"P":[(535,830)],"Q":[(685,865)],"R":[(790,880)],
"f_top":[(535,105),(600,110)],"f_big":[(735,175)],"f_small":[(775,268)],"f_stripe":[(800,515)],"f_long":[(720,615)],"f_bl":[(160,855)]}
names=list(seeds); S={n:[(int(x*k),int(y*k)) for x,y in v] for n,v in seeds.items()}
outs={}
for ks in (3,5,7,9,11,13):
    bar=cv2.dilate(ink,disk(ks)); ff=(1-bar).astype(np.uint8); m=np.zeros((h+2,w+2),np.uint8)
    cv2.floodFill(ff,m,(0,0),2); outs[ks]=(cv2.connectedComponents((ff!=2).astype(np.uint8))[1],bar)
def interior(X,Y):
    for ks in (3,5,7,9,11,15,19):
        bar=cv2.dilate(ink,disk(ks))>0
        fy,fx=np.nonzero(~bar[max(0,Y-25):Y+25,max(0,X-25):X+25])
        if not len(fy): continue
        j=np.argmin((fy-25)**2+(fx-25)**2); sx,sy=max(0,X-25)+fx[j],max(0,Y-25)+fy[j]
        ff=(~bar).astype(np.uint8); m=np.zeros((h+2,w+2),np.uint8)
        cv2.floodFill(ff,m,(int(sx),int(sy)),2); reg=ff==2
        if reg[0].any() or reg[-1].any() or reg[:,0].any() or reg[:,-1].any() or reg.sum()>90000: continue
        return cv2.dilate(reg.astype(np.uint8),disk(ks))>0
    return None
sil={}
for nme,pts in S.items():
    X,Y=pts[0]; got=None
    for ks in (3,5,7,9,11,13):
        lab,_=outs[ks]; l=lab[Y,X]
        if l==0: continue
        reg=lab==l
        if any(lab[y2,x2]==l for o,v in S.items() if o!=nme for (x2,y2) in v) or reg.sum()>70000 or reg.sum()<3000: continue
        got=reg; break
    if got is None:
        got=np.zeros((h,w),bool)
        for X,Y in pts:
            r=interior(X,Y)
            if r is not None: got|=r
    sil[nme]=got
# f_long: convex hull of ink in its box
x0,y0,x1,y1=[int(v*k) for v in (662,572,915,660)]
ys,xs=np.nonzero(ink[y0:y1,x0:x1]); pts=np.stack([xs+x0,ys+y0],1).astype(np.int32)
hull=cv2.convexHull(pts); m=np.zeros((h,w),np.uint8); cv2.fillPoly(m,[hull],1); sil['f_long']=m>0
L=np.zeros((h,w),np.int32)
order=sorted(names,key=lambda n: sil[n].sum())  # small first get priority
for n in order: L[sil[n]&(L==0)]=names.index(n)+1
for n in names: print(n,int((L==names.index(n)+1).sum()))
dist,(iy,ix)=ndi.distance_transform_edt(L==0,return_indices=True)
near=L[iy,ix]
inkish=cv2.dilate((D<245).astype(np.uint8),np.ones((3,3),np.uint8))>0
fig=np.where(L>0,L,np.where((inkish&(dist<45))|((D<215)&(dist<400)),near,0))
np.save('fig.npy',fig); np.save('L.npy',L); json.dump(names,open('names.json','w'))
vis=cv2.cvtColor(D,cv2.COLOR_GRAY2BGR); rng=np.random.default_rng(5)
for i,n in enumerate(names):
    mm=fig==i+1
    if not mm.any(): continue
    col=rng.integers(40,230,3); vis[mm]=(vis[mm]*0.45+col*0.55).astype(np.uint8)
    ys,xs=np.nonzero(mm); cv2.putText(vis,n,(int(xs.mean())-15,int(ys.mean())),cv2.FONT_HERSHEY_SIMPLEX,0.7,(0,0,255),2)
cv2.imwrite('seg5.png',cv2.resize(vis,(900,900)))
