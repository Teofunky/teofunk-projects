import cv2, numpy as np
exec(open('w3/cut.py').read().split('# ---------- hand contours')[0])
d=np.load('w3/masks.npz')
heads={k:d[k] for k in ['left','centre','right']}; fig=d['fig']; pl=d['plants'].astype(np.float32)
fish_b=d['fish_b'].astype(np.float32)/255; fish_s=d['fish_s']
lab=cv2.cvtColor(P,cv2.COLOR_BGR2LAB).astype(np.float32); L=lab[...,0]*100/255
Lloc=cv2.medianBlur(L.astype(np.uint8),21).astype(np.float32); strokes=np.clip((Lloc-L-7)/10,0,1)
tailpoly=poly([(0,156),(24,160),(48,174),(54,187),(48,201),(24,208),(0,214)])
red=np.clip((np.hypot(lab[...,1]-128,lab[...,2]-128)-12)/8,0,1)
fish_t=np.clip(strokes+red,0,1)*cv2.GaussianBlur(tailpoly.astype(np.float32),(0,0),1.0)
fish_t=fish_t*(1-fish_b)                                # tail = what is not body
sp=cv2.GaussianBlur(spoly([(0,56),(32,58),(62,63),(86,70),(101,78),(98,89),(80,97),(50,101),(20,101),(0,97)]).astype(np.float32),(0,0),1.0)
fish_s=np.clip(strokes+red,0,1)*sp
fish_s=np.maximum(fish_s,cv2.erode((fish_s>0.5).astype(np.uint8),np.ones((3,3),np.uint8)).astype(np.float32))
anyhead=np.clip(sum(heads.values()),0,1).astype(np.uint8)
# ---- sky plate: figures + fish removed (horizontal band shifts in the sky, haze where none) ----
hole=(cv2.dilate(fig,np.ones((25,25),np.uint8))|cv2.dilate((fish_b>0.05).astype(np.uint8)|(fish_t>0.05).astype(np.uint8)|(fish_s>0.05).astype(np.uint8),np.ones((17,17),np.uint8))).astype(np.uint8)
Pf=P.astype(np.float32); clean=1-hole; T=32; ST=16
acc=np.zeros_like(Pf); wsum=np.zeros((H,W),np.float32)
win=np.outer(np.hanning(T+2)[1:-1],np.hanning(T+2)[1:-1]).astype(np.float32)
rng=np.random.default_rng(5)
for y in range(0,H-T+1,ST):
    for x in range(0,W-T+1,ST):
        if hole[y:y+T,x:x+T].sum()==0 or y>110: continue
        ok=[dd for dd in range(-700,701,8) if dd and 0<=x+dd<=W-T and clean[y:y+T,x+dd:x+dd+T].mean()>0.995]
        if not ok: continue
        ok=sorted(ok,key=abs)[:12]; dd=int(rng.choice(ok))
        acc[y:y+T,x:x+T]+=Pf[y:y+T,x+dd:x+dd+T]*win[...,None]; wsum[y:y+T,x:x+T]+=win
fill=acc/np.maximum(wsum,1e-6)[...,None]
cm=clean.astype(np.float32)
den1=cv2.GaussianBlur(cm,(0,0),40); low1=cv2.GaussianBlur(Pf*cm[...,None],(0,0),40)/np.maximum(den1,1e-6)[...,None]
den2=cv2.GaussianBlur(cm,(0,0),140); low2=cv2.GaussianBlur(Pf*cm[...,None],(0,0),140)/np.maximum(den2,1e-6)[...,None]
a=np.clip(den1/0.08,0,1)[...,None]; low=low1*a+low2*(1-a)
hp=Pf-cv2.GaussianBlur(Pf,(0,0),2.2)
srcy,srcx=np.nonzero(clean[:-12,:-12]>0)
G=np.zeros_like(Pf); gw=np.zeros((H,W),np.float32); B=12
wb=np.outer(np.hanning(B+2)[1:-1],np.hanning(B+2)[1:-1]).astype(np.float32)
for y in range(0,H-B+1,B//2):
    for x in range(0,W-B+1,B//2):
        if hole[y:y+B,x:x+B].sum()==0: continue
        k=rng.integers(0,len(srcy)); sy,sx=srcy[k],srcx[k]
        G[y:y+B,x:x+B]+=hp[sy:sy+B,sx:sx+B]*wb[...,None]; gw[y:y+B,x:x+B]+=wb
haze=low+G/np.maximum(gw,1e-6)[...,None]
ws=np.clip(wsum/0.25,0,1)[...,None]
objs=(fig|(fish_b>0.03).astype(np.uint8)|(fish_t>0.03).astype(np.uint8)|(fish_s>0.03).astype(np.uint8)).astype(np.uint8)
hb=np.clip(cv2.GaussianBlur(cv2.dilate(objs,np.ones((7,7),np.uint8)).astype(np.float32),(0,0),1.5),0,1)[...,None]
sky=Pf*(1-hb)+(fill*ws+haze*(1-ws))*hb
# ---- body layer: plants inpainted out, overlap band under the heads inpainted ----
body_only=(fig&(1-cv2.dilate(anyhead,np.ones((5,5),np.uint8)))).astype(np.uint8)
body_only=cv2.morphologyEx(body_only,cv2.MORPH_OPEN,np.ones((11,11),np.uint8))
n_,l_,st_,_=cv2.connectedComponentsWithStats(body_only); body_only=np.isin(l_,[k for k in range(1,n_) if st_[k,4]>5000]).astype(np.uint8)
band=(anyhead&cv2.dilate(body_only,np.ones((33,33),np.uint8))).astype(np.uint8)
body_tex=Pf.copy()   # under the head's lower edge: the painting itself (1-2 px reveals stay seamless)
# under the plants: low-frequency dress colour from plant-free pixels + dress grain, not a smear
Cb=np.hypot(lab[...,1]-128,lab[...,2]-128)
free=((pl<0.03)&(fig>0)&(anyhead==0)&(Cb>22)&(L<68)).astype(np.float32)
free=cv2.erode(free,np.ones((3,3),np.uint8))
lowb=cv2.GaussianBlur(body_tex*free[...,None],(0,0),10)/np.maximum(cv2.GaussianBlur(free,(0,0),10),1e-4)[...,None]
lowc=cv2.GaussianBlur(body_tex*free[...,None],(0,0),40)/np.maximum(cv2.GaussianBlur(free,(0,0),40),1e-4)[...,None]
den=cv2.GaussianBlur(free,(0,0),10); lowb=np.where((den>0.05)[...,None],lowb,lowc)
hpb=body_tex-cv2.GaussianBlur(body_tex,(0,0),2.0)
fy,fx=np.nonzero(cv2.erode(free.astype(np.uint8),np.ones((9,9),np.uint8))[:-12,:-12]>0)
Gb=np.zeros_like(body_tex); gwb=np.zeros((H,W),np.float32)
pmask=(pl>0.04).astype(np.uint8); pmask=cv2.dilate(pmask,np.ones((5,5),np.uint8))
for y in range(400,H-B+1,B//2):
    for x in range(0,W-B+1,B//2):
        if pmask[y:y+B,x:x+B].sum()==0: continue
        k=rng.integers(0,len(fy)); sy,sx=fy[k],fx[k]
        Gb[y:y+B,x:x+B]+=hpb[sy:sy+B,sx:sx+B]*wb[...,None]; gwb[y:y+B,x:x+B]+=wb
under=lowb+Gb/np.maximum(gwb,1e-6)[...,None]
wpl=np.clip((cv2.GaussianBlur(pl,(0,0),1.2)-0.02)/0.12,0,1)[...,None]
body_tex=body_tex*(1-wpl)+under*wpl
apl=np.clip(1.6*pl,0,1)
plant_tex=np.clip((Pf-(1-apl[...,None])*body_tex)/np.maximum(apl,0.2)[...,None],0,255)
plant_tex=np.where((apl>0.2)[...,None],plant_tex,Pf)
pl=apl
body_a=gf(np.clip(((fig>0)&(anyhead==0)).astype(np.uint8)+band,0,1))   # no gap between the head's lower edge and the dress
body_a=np.minimum(body_a,cv2.GaussianBlur(fig.astype(np.float32),(0,0),0.8))
# ---- heads: earrings inpainted out of the head texture ----
ear_l=d['ear_l']; ear_r=d['ear_r']
em=cv2.dilate(ear_l|ear_r,np.ones((7,7),np.uint8))
Ch=np.hypot(lab[...,1]-128,lab[...,2]-128)
hair=((L<48)&(heads['left']>0)&(em==0)).astype(np.float32)
lowh=cv2.GaussianBlur(Pf*hair[...,None],(0,0),7)/np.maximum(cv2.GaussianBlur(hair,(0,0),7),1e-4)[...,None]
hy,hx=np.nonzero(cv2.erode(hair.astype(np.uint8),np.ones((7,7),np.uint8))[:-8,:-8]>0)
Gh=np.zeros_like(Pf); gwh=np.zeros((H,W),np.float32); bb=8
wbh=np.outer(np.hanning(bb+2)[1:-1],np.hanning(bb+2)[1:-1]).astype(np.float32)
for y in range(340,450,bb//2):
    for x in range(170,340,bb//2):
        if em[y:y+bb,x:x+bb].sum()==0: continue
        k=rng.integers(0,len(hy)); Gh[y:y+bb,x:x+bb]+=(Pf[hy[k]:hy[k]+bb,hx[k]:hx[k]+bb]-lowh[hy[k]:hy[k]+bb,hx[k]:hx[k]+bb])*wbh[...,None]; gwh[y:y+bb,x:x+bb]+=wbh
emb=cv2.GaussianBlur(em.astype(np.float32),(0,0),1.5)[...,None]
head_tex=(Pf*(1-emb)+(lowh+Gh/np.maximum(gwh,1e-6)[...,None])*emb)
out={'sky':sky,'body_tex':body_tex,'head_tex':head_tex,'paint':Pf,'plant':plant_tex}
sr=cv2.dnn_superres.DnnSuperResImpl_create(); sr.readModel('sr/ESPCN_x2.pb'); sr.setModel('espcn',2)
for k,v in out.items(): cv2.imwrite(f'w3/tex_{k}.png',sr.upsample(np.clip(v,0,255).astype(np.uint8)))
up=lambda m: cv2.resize(m.astype(np.float32),(2*W,2*H),interpolation=cv2.INTER_LINEAR)
alph={'body':body_a,'plants':pl,'fish_b':fish_b,'fish_t':fish_t,'fish_s':fish_s,'ear_l':gf(ear_l),'ear_r':gf(ear_r)}
outside=cv2.dilate((1-fig).astype(np.uint8),np.ones((9,9),np.uint8)).astype(np.float32)
outside=cv2.GaussianBlur(outside,(0,0),2)
for k in heads:
    hard=cv2.GaussianBlur(heads[k].astype(np.float32),(0,0),0.7)
    alph['head_'+k]=gf(heads[k])*outside+hard*(1-outside)
np.savez_compressed('w3/alpha2x.npz',**{k:np.clip(up(v),0,1).astype(np.float16) for k,v in alph.items()})
cv2.imwrite('w3/chk_sky.jpg',cv2.resize(np.clip(sky,0,255).astype(np.uint8),(768,768)),[cv2.IMWRITE_JPEG_QUALITY,88])
bt=body_tex.astype(np.float32)*body_a[...,None]+np.array([40,40,40],np.float32)*(1-body_a[...,None])
cv2.imwrite('w3/chk_body2.jpg',cv2.resize(np.clip(body_tex,0,255).astype(np.uint8),(768,768)),[cv2.IMWRITE_JPEG_QUALITY,88]); cv2.imwrite('w3/chk_body.jpg',cv2.resize(bt.astype(np.uint8),(768,768)),[cv2.IMWRITE_JPEG_QUALITY,88])
print('built')
