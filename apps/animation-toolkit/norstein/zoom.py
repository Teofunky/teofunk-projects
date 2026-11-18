import cv2, numpy as np, glob, sys
P=cv2.imread('w3/women.png')
def zoom(name,x0,y0,x1,y1,f):
    c=P[y0:y1,x0:x1].copy(); c=cv2.resize(c,None,fx=f,fy=f,interpolation=cv2.INTER_CUBIC)
    for y in range((y0//10)*10,y1,10):
        if y<y0: continue
        yy=int((y-y0)*f); col=(0,255,255) if y%50==0 else (0,180,180)
        cv2.line(c,(0,yy),(c.shape[1],yy),col,1 if y%50 else 1,cv2.LINE_AA) if y%50==0 else cv2.line(c,(0,yy),(8,yy),col,1)
        if y%50==0: cv2.putText(c,str(y),(2,yy-3),cv2.FONT_HERSHEY_SIMPLEX,0.45,(0,255,255),1,cv2.LINE_AA)
    for x in range((x0//10)*10,x1,10):
        if x<x0: continue
        xx=int((x-x0)*f); col=(255,255,0)
        if x%50==0:
            cv2.line(c,(xx,0),(xx,c.shape[0]),col,1,cv2.LINE_AA); cv2.putText(c,str(x),(xx+2,14),cv2.FONT_HERSHEY_SIMPLEX,0.45,col,1,cv2.LINE_AA)
        else: cv2.line(c,(xx,0),(xx,8),col,1)
    cv2.imwrite(f'w3/z_{name}.jpg',c,[cv2.IMWRITE_JPEG_QUALITY,90])
import sys
if len(sys.argv)>1:
    zoom('sh_left',0,380,150,720,2.0)
    zoom('sh_c1',320,320,440,470,2.0)
    zoom('sh_c2',640,320,780,440,2.0)
    zoom('sh_r',920,320,1024,500,2.0)
    ims=[cv2.imread(f'w3/z_{n}.jpg') for n in ['sh_left','sh_c1','sh_c2','sh_r']]
    h=max(i.shape[0] for i in ims)
    ims=[cv2.copyMakeBorder(i,0,h-i.shape[0],0,10,cv2.BORDER_CONSTANT,value=(0,0,0)) for i in ims]
    cv2.imwrite('w3/z_shoulders.jpg',np.hstack(ims))
