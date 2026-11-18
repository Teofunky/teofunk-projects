import cv2, numpy as np
T={k:cv2.imread(f'w3/tex_{k}.png').astype(np.float32) for k in ['body_tex','head_tex','paint','plant']}
A={k:v.astype(np.float32) for k,v in np.load('w3/alpha2x.npz').items()}
def piece(tex,a):
    ys,xs=np.nonzero(a>0.01); y0,y1,x0,x1=ys.min(),ys.max()+1,xs.min(),xs.max()+1
    return np.dstack([tex[y0:y1,x0:x1],a[y0:y1,x0:x1]])
Wc,Hc=3000,1700; rng=np.random.default_rng(3)
bg=np.full((Hc,Wc,3),28,np.float32)+cv2.GaussianBlur(rng.standard_normal((Hc,Wc)).astype(np.float32),(0,0),1.5)[...,None]*5
bg+=cv2.resize(rng.random((10,18)).astype(np.float32),(Wc,Hc),interpolation=cv2.INTER_CUBIC)[...,None]*12
items=[('body',T['body_tex'],A['body'],(60,640,0.62,-2)),('plants',T['plant'],A['plants'],(1560,620,0.62,1.5)),
       ('head_left',T['head_tex'],A['head_left'],(150,90,0.9,-3)),('head_centre',T['head_tex'],A['head_centre'],(720,40,0.9,1)),
       ('head_right',T['head_tex'],A['head_right'],(1300,70,0.9,2)),('ear_l',T['paint'],A['ear_l'],(1880,140,1.2,-8)),
       ('ear_r',T['paint'],A['ear_r'],(2010,150,1.2,6)),('fish_b',T['paint'],A['fish_b'],(2200,90,0.95,-3)),
       ('fish_t',T['paint'],A['fish_t'],(2240,390,1.2,10)),('fish_s',T['paint'],A['fish_s'],(2460,420,1.2,4))]
for name,tex,a,(x,y,s,ang) in items:
    p=piece(tex,a); h,w=p.shape[:2]
    M=cv2.getRotationMatrix2D((w/2,h/2),ang,s); M[0,2]+=x+(s-1)*w/2; M[1,2]+=y+(s-1)*h/2
    o=cv2.warpAffine(p,M,(Wc,Hc),flags=cv2.INTER_LINEAR,borderMode=cv2.BORDER_CONSTANT)
    al=o[...,3:4]; sh=np.roll(np.roll(cv2.GaussianBlur(al[...,0],(0,0),10),12,0),12,1)[...,None]
    bg=bg*(1-0.55*sh); bg=bg*(1-al)+o[...,:3]*al
cv2.imwrite('w3/women_puppet_layout.jpg',cv2.resize(np.clip(bg,0,255).astype(np.uint8),(2400,1360)),[cv2.IMWRITE_JPEG_QUALITY,92])
