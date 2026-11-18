# Hand-traced cut of "three women" with edge snapping (GrabCut in a narrow band) and soft guided-filter edges
import cv2, numpy as np
P=cv2.imread('w3/women.png'); H,W=P.shape[:2]
def poly(pts):
    m=np.zeros((H,W),np.uint8); cv2.fillPoly(m,[np.array(pts,np.int32)],1); return m
def smooth_poly(pts,n=12):          # Catmull-Rom through hand points -> smooth contour
    p=np.array(pts,np.float64); out=[]
    for i in range(len(p)):
        p0,p1,p2,p3=p[i-1],p[i],p[(i+1)%len(p)],p[(i+2)%len(p)]
        for t in np.linspace(0,1,n,endpoint=False):
            t2,t3=t*t,t*t*t
            out.append(0.5*((2*p1)+(-p0+p2)*t+(2*p0-5*p1+4*p2-p3)*t2+(-p0+3*p1-3*p2+p3)*t3))
    return np.array(out)
def spoly(pts): return poly(smooth_poly(pts))
def snap(outer,band_out=7,band_in=10,keep_bottom=None):
    mask=np.full((H,W),cv2.GC_BGD,np.uint8)
    big=cv2.dilate(outer,np.ones((2*band_out+1,)*2,np.uint8)); core=cv2.erode(outer,np.ones((2*band_in+1,)*2,np.uint8))
    mask[big>0]=cv2.GC_PR_BGD; mask[outer>0]=cv2.GC_PR_FGD; mask[core>0]=cv2.GC_FGD
    bg=np.zeros((1,65),np.float64); fg=np.zeros((1,65),np.float64)
    cv2.grabCut(P,mask,None,bg,fg,5,cv2.GC_INIT_WITH_MASK)
    m=((mask==cv2.GC_FGD)|(mask==cv2.GC_PR_FGD)).astype(np.uint8)
    if keep_bottom is not None: m=m&keep_bottom          # hand line stays where the cut goes through the figure
    n,l,st,_=cv2.connectedComponentsWithStats(m); m=(l==1+np.argmax(st[1:,4])).astype(np.uint8)
    inv=1-m; n2,l2,st2,_=cv2.connectedComponentsWithStats(inv)
    for k in range(1,n2):
        if st2[k,4]<400: m[l2==k]=1
    return m
gf=lambda m: np.clip(cv2.ximgproc.guidedFilter(P,m.astype(np.float32),3,1e-3),0,1)
# ---------- hand contours (painting px) ----------
LEFT=[(254,256),(286,257),(316,276),(336,302),(348,335),(356,364),(364,395),(374,424),(372,440),(346,439),(318,431),(300,427),(262,424),(225,429),(204,440),(180,459),(150,474),(120,487),(98,489),(92,476),(104,460),(121,439),(129,417),(136,388),(144,357),(157,325),(177,297),(209,271),(238,259)]
CENTRE=[(548,110),(580,115),(607,136),(624,168),(633,203),(637,238),(646,277),(659,311),(672,342),(677,362),(668,368),(640,369),(612,360),(605,345),(580,338),(550,335),(520,338),(500,346),(482,368),(455,390),(430,402),(400,412),(378,410),(380,397),(392,372),(399,346),(408,320),(417,290),(426,259),(439,224),(447,190),(460,159),(482,132),(512,115)]
RIGHT=[(833,153),(874,163),(896,185),(914,221),(927,260),(935,299),(944,334),(953,360),(959,378),(958,392),(920,396),(880,386),(866,372),(830,368),(800,370),(790,376),(760,395),(725,399),(722,384),(726,352),(730,318),(737,277),(746,243),(754,208),(772,181),(798,159)]
EAR_L=[(198,360),(208,366),(213,385),(213,410),(208,428),(199,434),(190,426),(186,405),(187,382),(191,366)]
EAR_R=[(313,362),(323,368),(328,388),(328,414),(322,432),(313,437),(304,428),(301,406),(302,384),(306,368)]
FISH_B=[(42,176),(62,168),(100,158),(140,149),(146,128),(200,121),(265,129),(276,149),(301,159),(336,169),(368,171),(368,186),(336,186),(301,205),(262,224),(216,239),(146,240),(120,233),(55,235),(46,200)]
FISH_T=[(0,158),(22,162),(46,174),(52,186),(46,200),(22,206),(0,212)]
FISH_S=[(0,56),(32,58),(62,63),(86,70),(101,78),(98,89),(80,97),(50,101),(20,101),(0,97)]
FIG=[(0,1024),(0,642),(30,606),(65,572),(90,512),(108,470),(122,440),(130,418),(137,388),(145,358),(158,326),(178,298),(210,272),(240,260),(254,258),(285,259),(314,278),(334,303),(346,336),(354,365),(362,396),(372,425),(366,432),(355,430),(385,405),(410,385),(400,347),(409,321),(418,291),(427,260),(440,225),(448,191),(461,160),(483,134),(513,117),(548,112),(579,117),(605,138),(622,169),(631,204),(635,238),(644,278),(657,312),(670,343),(676,362),(688,398),(705,420),(724,398),(723,383),(727,352),(731,318),(738,278),(747,244),(755,209),(773,183),(799,161),(833,155),(873,165),(894,187),(912,222),(925,261),(933,300),(942,335),(951,361),(962,380),(990,390),(1024,395),(1024,1024)]
heads={}
for name,pts in [('left',LEFT),('centre',CENTRE),('right',RIGHT)]:
    o=spoly(pts); heads[name]=snap(o,8,10,keep_bottom=cv2.dilate(o,np.ones((3,3),np.uint8)))
ears={'ear_l':spoly(EAR_L),'ear_r':spoly(EAR_R)}
fish_b=snap(spoly(FISH_B),6,6); fish_t=snap(poly(FISH_T),5,4); fish_s=snap(spoly(FISH_S),5,5)
fig=poly(FIG)
fig=snap(fig,10,14,keep_bottom=cv2.dilate(fig,np.ones((3,3),np.uint8)))
anyhead=np.clip(heads['left']+heads['centre']+heads['right'],0,1)
fig=np.clip(fig|anyhead,0,1)
# foreground plants: lighter than the local dress, low chroma, below the heads
lab=cv2.cvtColor(P,cv2.COLOR_BGR2LAB).astype(np.float32); L=lab[...,0]*100/255; C=np.hypot(lab[...,1]-128,lab[...,2]-128)
Lloc=cv2.medianBlur(L.astype(np.uint8),31).astype(np.float32)
zone=np.zeros((H,W),np.float32); zone[400:]=1; zone=cv2.GaussianBlur(zone,(0,0),6)*(1-cv2.GaussianBlur(anyhead.astype(np.float32),(0,0),3))
pl=np.clip((L-Lloc-6)/14,0,1)*np.clip((18-C)/8,0,1)*zone*fig
pl=np.clip(cv2.GaussianBlur(pl,(0,0),0.6)*1.25,0,1)
np.savez_compressed('w3/masks.npz',left=heads['left'],centre=heads['centre'],right=heads['right'],ear_l=ears['ear_l'],ear_r=ears['ear_r'],fish_b=fish_b,fish_t=fish_t,fish_s=fish_s,fig=fig,plants=pl)
# overlay check
v=P.astype(np.float32)*0.55
cols={'left':(0,0,255),'centre':(0,255,0),'right':(255,0,0)}
for k,c in cols.items():
    e=heads[k]-cv2.erode(heads[k],np.ones((3,3),np.uint8)); v[e>0]=c
for m,c in [(fish_b,(0,255,255)),(fish_t,(255,0,255)),(fish_s,(0,255,255)),(ears['ear_l'],(255,255,0)),(ears['ear_r'],(255,255,0))]:
    e=m-cv2.erode(m,np.ones((3,3),np.uint8)); v[e>0]=c
e=fig-cv2.erode(fig,np.ones((3,3),np.uint8)); v[e>0]=(255,255,255)
cv2.imwrite('w3/chk_cut.jpg',v.astype(np.uint8)[0:640],[cv2.IMWRITE_JPEG_QUALITY,90])
pv=(P.astype(np.float32)*pl[...,None]+np.array([60,20,20],np.float32)*(1-pl[...,None]))
cv2.imwrite('w3/chk_plants.jpg',cv2.resize(pv.astype(np.uint8),(768,768)),[cv2.IMWRITE_JPEG_QUALITY,88])
print('ok')
