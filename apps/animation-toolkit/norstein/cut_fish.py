import cv2, numpy as np
exec(open('w3/cut.py').read().split('# ---------- hand contours')[0])
d=dict(np.load('w3/masks.npz'))
lab=cv2.cvtColor(P,cv2.COLOR_BGR2LAB).astype(np.float32); L=lab[...,0]*100/255
Lloc=cv2.medianBlur(L.astype(np.uint8),21).astype(np.float32)
strokes=np.clip((Lloc-L-7)/10,0,1)
BODY=[(46,182),(62,172),(100,161),(140,152),(200,145),(238,147),(270,155),(301,162),(336,172),(347,177),(336,185),(301,203),(262,221),(216,226),(160,224),(120,217),(78,203),(50,193)]
body=snap(spoly(BODY),5,6)
fins=poly([(146,148),(150,128),(200,121),(265,129),(278,152),(240,148),(200,146)])|poly([(54,196),(128,214),(132,236),(54,237)])|poly([(146,223),(216,226),(218,241),(146,241)])|poly([(338,168),(370,168),(370,188),(336,188)])
fin_a=strokes*fins
fb=np.clip(gf(body)+fin_a,0,1)
d['fish_b']=(fb*255).astype(np.uint8); d['fish_b_soft']=1
np.savez_compressed('w3/masks.npz',**d)
v=P.astype(np.float32)*(0.35+0.65*fb[...,None])
cv2.imwrite('w3/chk_fish.jpg',cv2.resize(v.astype(np.uint8)[40:250,0:380],None,fx=2,fy=2),[cv2.IMWRITE_JPEG_QUALITY,90])
