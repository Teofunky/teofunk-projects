import json,sys,math,cv2,numpy as np
sys.path.insert(0,'/home/claude/teofunk-projects/apps/atelier-kit'); import mark
P='painting.png'; im=cv2.imread(P).astype(np.float32); H,W=im.shape[:2]
G=json.load(open('girl_polys.json'))
M={}
M['dress']=mark.refine(P,[G['dress'],G['neck']],band=7)
M['legB']=mark.refine(P,[G['legB']],band=4)
M['legF']=mark.refine(P,[G['legF']],band=4)
M['hand']=mark.refine(P,[G['hand']],band=4)
M['shell']=mark.refine(P,[G['shell']],band=4)
for k,m in M.items(): cv2.imwrite(f'mask_{k}.png',m)
head=mark.matte(P,(205,65,478,252),fins=22,lo_pct=60); head[247:,:]=0
body=np.maximum(head,mark.soft(M['dress']))
hs=((M['hand']>0)|(M['shell']>0)).astype(np.uint8)
body[cv2.dilate(hs,np.ones((3,3),np.uint8))>0]=0
body[622:,:]=0
A={'body':body,'legB':mark.soft(M['legB']),'legF':mark.soft(M['legF']),'hand':mark.soft(M['hand'],grow=1),'shell':mark.soft(M['shell'],grow=1)}
A['hand'][M['shell']>0]=0
for k,a in A.items(): np.save(f'alpha_{k}.npy',a)
x0,y0,x1,y1=200,60,580,790; c=im[y0:y1,x0:x1]
tot=np.maximum.reduce([A[k] for k in A])[y0:y1,x0:x1,None]
comp=c*tot+np.array([255,0,255],np.float32)*(1-tot)
cv2.imwrite('girl_all.jpg',np.hstack([c,comp]).astype(np.uint8))
