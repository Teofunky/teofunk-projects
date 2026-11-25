import cv2, numpy as np, json
from scipy import ndimage as ndi
D=cv2.imread('D.png',0); h,w=D.shape; k=1080/1024
fig=np.load('fig.npy'); names=json.load(open('names.json'))
disk=lambda s: cv2.getStructuringElement(cv2.MORPH_ELLIPSE,(s,s))
inkish=(D<245)
out={}
taken=np.zeros((h,w),bool)
for i,n in enumerate(names):
    reg=(fig==i+1)
    closed=cv2.morphologyEx(reg.astype(np.uint8),cv2.MORPH_CLOSE,disk(31 if n in ('f_long','f_small','f_top') else 15))>0
    filled=ndi.binary_fill_holes(closed)
    # do not steal other figures' ink
    other_ink=inkish&(fig>0)&(fig!=i+1)
    m=filled&~other_ink
    m=ndi.binary_fill_holes(m)&~other_ink
    ys,xs=np.nonzero(m)
    x0,x1,y0,y1=max(0,xs.min()-6),min(w,xs.max()+7),max(0,ys.min()-6),min(h,ys.max()+7)
    a=cv2.GaussianBlur((m[y0:y1,x0:x1]*255).astype(np.uint8),(3,3),0)
    rgba=np.dstack([D[y0:y1,x0:x1]]*3+[a])
    cv2.imwrite(f'spr_{n}.png',rgba[...,[2,1,0,3]] if False else rgba)
    out[n]=dict(x0=int(x0),y0=int(y0),w=int(x1-x0),h=int(y1-y0),cx=float(xs.mean()),cy=float(ys.mean()),
                bottom=float(ys.max()),area=int(m.sum()))
json.dump(out,open('sprites.json','w'),indent=0)
# check: composite all sprites at rest over white = drawing?
can=np.full((h,w),255.0)
order=sorted(names,key=lambda n:-out[n]['area'])
for n in order:
    s=cv2.imread(f'spr_{n}.png',cv2.IMREAD_UNCHANGED).astype(float); o=out[n]
    a=s[...,3:4]/255; reg=can[o['y0']:o['y0']+o['h'],o['x0']:o['x0']+o['w']]
    can[o['y0']:o['y0']+o['h'],o['x0']:o['x0']+o['w']]=reg*(1-a[...,0])+s[...,0]*a[...,0]
diff=np.abs(can-D)
print('rest diff mean',diff.mean(),'px>40',(diff>40).sum())
cv2.imwrite('rest_diff.png',np.clip(diff*3,0,255).astype(np.uint8))
