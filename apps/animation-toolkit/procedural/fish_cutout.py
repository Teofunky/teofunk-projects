import cv2, numpy as np
img=cv2.imread('e04/f1.png'); H,W=img.shape[:2]
def grab(rect):
    m=np.zeros((H,W),np.uint8); bg=np.zeros((1,65),np.float64); fg=np.zeros((1,65),np.float64)
    cv2.grabCut(img,m,rect,bg,fg,6,cv2.GC_INIT_WITH_RECT)
    return np.where((m==1)|(m==3),255,0).astype(np.uint8)
big=grab((52,52,290,82)); small=grab((70,6,140,38))
for nm,mk in (('big',big),('small',small)):
    n,lab,st,_=cv2.connectedComponentsWithStats(mk)
    if n>1:
        k=1+np.argmax(st[1:,cv2.CC_STAT_AREA]); mk[:]=np.where(lab==k,255,0)
    mk[:]=cv2.morphologyEx(mk,cv2.MORPH_CLOSE,np.ones((7,7),np.uint8))
    print(nm, cv2.boundingRect(mk), int(mk.sum()/255))
cv2.imwrite('e04/fish_big_mask.png',big); cv2.imwrite('e04/fish_small_mask.png',small)
vis=img.copy(); vis[big>0]=(0,255,0); vis[small>0]=(255,0,0)
cv2.imwrite('e04/fish_masks_vis.jpg',vis[0:160,0:380])
