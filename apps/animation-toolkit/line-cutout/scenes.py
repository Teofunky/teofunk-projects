import sys, math, json, numpy as np, cv2
from film import *
SHEET={k:f'sheet{k}.png' for k in '1234567'}
FULL=lambda: (-(1920/1.0547-1024)/2,0,1920/1.0547)   # весь лист по высоте кадра

def take(sh,m): sh.taken|=m; return m
def rest_piece(sh,name='rest',z=0,pivot=(512,1000),pose=None,solid_fill=True):
    m=sh.ink&~sh.taken
    if solid_fill:
        f=cv2.morphologyEx(m.astype(np.uint8),cv2.MORPH_CLOSE,disk(9))>0
        f=ndi.binary_fill_holes(f)
        m=(f|(cv2.dilate(m.astype(np.uint8),disk(3))>0))&~sh.taken|(m)
    return Piece(sh,name,m,z=z,pivot=pivot,pose=pose)

# ---------------- сцена 3: очки-спирали, средний, 5 с
def man_pieces(S,sh,lens=False,t_off=0.0):
    def head(t):
        t+=t_off; rise=-18*ease((t-2.6)/2.0)
        return dict(dy=4*math.sin(2*math.pi*1.3*t)+rise,ang=1.6*math.sin(2*math.pi*0.7*t))
    def fin_amp(t):
        t+=t_off; return 8+16*ease(t/2.2)
    def fin_f(t): t+=t_off; return 2.2+1.6*ease(t/3.0)
    finL=take(sh,sh.ink_region([[135,245],[289,262],[289,322],[135,385]]))
    finR=take(sh,sh.ink_region([[726,212],[912,152],[912,348],[726,306]]))
    hair=take(sh,sh.ink_region([[290,92],[725,92],[725,152],[290,152]],close=3))
    S.add(rest_piece(sh,'head',z=1,pivot=(505,1000),pose=head))
    ph=lambda t: 2*math.pi*(t+t_off)*fin_f(t)
    S.add(Piece(sh,'finL',finL,z=2,pivot=(288,292),parent='head',pose=lambda t:dict(ang=fin_amp(t)*math.sin(ph(t)))))
    S.add(Piece(sh,'finR',finR,z=2,pivot=(727,258),parent='head',pose=lambda t:dict(ang=-fin_amp(t)*math.sin(ph(t)+0.4))))
    S.add(Piece(sh,'hair',hair,z=2,pivot=(505,152),parent='head',deform=sway(7,1.7,k=0.045,power=1.2)))
    if lens:
        sp=lambda t: 60*t+260*t*t
        S.add(Piece(sh,'lensL',sh.solid(ell=((372,271),(58,51))),z=3,pivot=(372,271),parent='head',deform=spin((372,271),sp)))
        S.add(Piece(sh,'lensR',sh.solid(ell=((624,270),(82,58))),z=3,pivot=(624,270),parent='head',deform=spin((624,270),lambda t:-sp(t))))

def s3():
    sh=Sheet(level(SHEET['2'])); S=Scene(sh,(-22,70,1068),5.0)
    man_pieces(S,sh); return S
def s4():
    sh=Sheet(level(SHEET['2'])); S=Scene(sh,(262,128,500),2.5)
    man_pieces(S,sh,lens=True,t_off=5.0); return S

SCENES={'s3':s3,'s4':s4}


# ---------------- сцена 2: три лица крупно, рты как у рыб, 2,5 с
def gulp(times,dur_open=0.09,hold=0.07,dur_close=0.16):
    def g(t):
        v=0.0
        for t0 in times:
            u=t-t0
            if u<0: continue
            if u<dur_open: v=max(v,ease(u/dur_open))
            elif u<dur_open+hold: v=max(v,1.0)
            elif u<dur_open+hold+dur_close: x=(u-dur_open-hold)/dur_close; v=max(v,1-ease(x)-0.18*math.sin(math.pi*x))
        return v
    return g
def s2():
    import json as js
    sh=Sheet(level(SHEET['1']))
    fig=np.load('/home/claude/work/nem/s1v2/fig.npy'); names=js.load(open('/home/claude/work/nem/s1v2/names.json'))
    fig=cv2.resize(fig.astype(np.int32).astype(np.float32),(1024,1024),interpolation=cv2.INTER_NEAREST).astype(np.int32)
    S=Scene(sh,(230,700,515),2.5)
    rng=np.random.default_rng(4)
    jaws={'O':([[240,877],[382,877],[382,975],[240,975]],891,(312,880)),
          'P':([[424,883],[552,883],[552,945],[424,945]],897,(488,885)),
          'Q':([[632,918],[728,918],[728,985],[632,985]],932,(680,920))}
    G={'O':gulp([0.12,1.30,2.0]),'P':gulp([0.42,1.30,2.0]),'Q':gulp([0.72,1.30,2.0])}
    for i,n in enumerate(names):
        if n not in ('K','L','M','N','O','P','Q','R','f_bl'): continue
        m=fig==i+1
        if n=='f_bl':
            S.add(Piece(sh,n,m,z=10,pivot=(160,855),pose=swim(1,300,s0=1.0,start=0.55,mean_amp=10,mean_T=0.9,vy=-8),deform=fish_wiggle(1,4.0,0.09)))
            # рыба стартует за левым краем кадра
            p=S.pieces[n]; base=p.pose; p.pose=lambda t,b=base:{**b(t),'dx':b(t)['dx']-60}
            continue
        ys,xs=np.nonzero(m); piv=(float(xs.mean()),float(ys.max()))
        A,Tp,ph=rng.uniform(2,3.2),rng.uniform(1.0,1.5),rng.uniform(0,6.3)
        pose=(lambda A,Tp,ph,n: (lambda t: dict(ang=A*math.sin(2*math.pi*t/Tp+ph),dy=3*math.sin(2*math.pi*t/0.9+ph)-(6*G[n](t) if n in G else 0))))(A,Tp,ph,n)
        if n in jaws:
            poly,cut,jp=jaws[n]
            jm=sh.solid(poly)&(cv2.dilate(m.astype(np.uint8),disk(5))>0)
            cutm=sh.solid([[poly[0][0],cut],[poly[1][0],cut],[poly[2][0],poly[2][1]],[poly[3][0],poly[3][1]]])
            parent_m=m&~cutm
            S.add(Piece(sh,n,parent_m,z=2+i*0.01,pivot=piv,pose=pose))
            S.add(Piece(sh,n+'_jaw',jm,z=1.5+i*0.01,pivot=jp,parent=n,pose=(lambda n: lambda t: dict(dy=11*G[n](t),ang=-3*G[n](t)))(n)))
        else:
            S.add(Piece(sh,n,m,z=1+i*0.01,pivot=piv,pose=pose))
    return S
SCENES['s2']=s2

# ---------------- сцена 7: женщина, крупный, 2,5 с
def s7():
    sh=Sheet(level(SHEET['3'])); S=Scene(sh,(47,85,907),2.5)
    finL=take(sh,sh.ink_region([[158,246],[257,283],[257,319],[158,374]]))
    finR=take(sh,sh.ink_region([[718,277],[828,226],[828,362],[718,317]]))
    earL=take(sh,sh.ink_region([[274,362],[333,362],[333,425],[274,425]],close=5))
    earR=take(sh,sh.ink_region([[650,354],[708,354],[708,432],[650,432]],close=5))
    hair=take(sh,sh.ink_region([[246,86],[720,86],[720,212],[246,212]],close=3))
    head=lambda t: dict(ang=4.5*math.sin(2*math.pi*t/1.25),dy=3*math.sin(2*math.pi*t/0.62))
    S.add(rest_piece(sh,'head',z=1,pivot=(500,640),pose=head))
    fa=lambda t: 26*math.sin(2*math.pi*3.2*t)
    S.add(Piece(sh,'finL',finL,z=2,pivot=(256,300),parent='head',pose=lambda t:dict(ang=fa(t))))
    S.add(Piece(sh,'finR',finR,z=2,pivot=(720,297),parent='head',pose=lambda t:dict(ang=-fa(t+0.05))))
    sw=lambda t,ph: dict(ang=24*math.sin(2*math.pi*t/0.95+ph)-6*math.sin(2*math.pi*t/1.25))
    S.add(Piece(sh,'earL',earL,z=2,pivot=(312,371),parent='head',pose=lambda t:sw(t,0.0)))
    S.add(Piece(sh,'earR',earR,z=2,pivot=(668,365),parent='head',pose=lambda t:sw(t,0.7)))
    S.add(Piece(sh,'hair',hair,z=2,pivot=(483,212),parent='head',deform=sway(9,1.9,k=0.05,power=1.1)))
    return S
SCENES['s7']=s7

# ---------------- сцена 5: подводный лист, общий, 5 с
def s5():
    sh=Sheet(level(SHEET['4'])); S=Scene(sh,FULL(),5.0)
    big=take(sh,sh.ink_region([[190,55],[905,55],[905,312],[300,312],[300,255],[190,255]]))
    mid=take(sh,sh.ink_region([[590,300],[850,300],[850,414],[590,414]]))
    bird=take(sh,sh.ink_region([[698,408],[908,408],[908,537],[698,537]]))
    weedR=take(sh,sh.ink_region([[795,664],[892,664],[892,948],[795,948]],close=3))
    weedM=take(sh,sh.ink_region([[497,712],[588,712],[588,936],[497,936]],close=3))
    left=take(sh,sh.ink_region([[126,255],[300,255],[300,262],[480,262],[480,340],[425,362],[425,462],[338,472],[338,600],[126,600]]))
    bl=take(sh,sh.ink_region([[108,598],[486,598],[486,927],[108,927]]))
    cface=take(sh,sh.ink_region([[376,342],[596,342],[596,712],[376,712]]))
    rface=take(sh,sh.ink_region([[515,500],[796,500],[796,927],[515,927]]))
    S.add(rest_piece(sh,'ground',z=0,solid_fill=False))
    S.add(Piece(sh,'rface',rface,z=1,pivot=(650,927),pose=lambda t:dict(ang=2.2*math.sin(2*math.pi*t/1.6+1),dy=3*math.sin(2*math.pi*t/0.8))))
    S.add(Piece(sh,'cface',cface,z=2,pivot=(490,712),pose=lambda t:dict(ang=2.5*math.sin(2*math.pi*t/1.3),sc=1+0.03*math.sin(2*math.pi*t/0.7))))
    S.add(Piece(sh,'left',left,z=1.5,pivot=(230,600),pose=lambda t:dict(ang=3.5*math.sin(2*math.pi*t/1.5+2),dx=6*math.sin(2*math.pi*t/1.1))))
    S.add(Piece(sh,'bl',bl,z=1.6,pivot=(300,927),pose=lambda t:dict(ang=2*math.sin(2*math.pi*t/1.2+0.5),dy=4*math.sin(2*math.pi*t/0.7+1))))
    S.add(Piece(sh,'weedM',weedM,z=1.8,pivot=(540,936),deform=sway(16,1.1,k=0.03,power=1.6)))
    S.add(Piece(sh,'weedR',weedR,z=1.8,pivot=(840,948),deform=sway(20,0.9,k=0.025,power=1.6,phase=1.3)))
    S.add(Piece(sh,'bigfish',big,z=5,pivot=(540,190),pose=swim(-1,265,s0=0.35,acc=1.2,mean_amp=14,mean_T=2.2,vy=6),deform=fish_wiggle(-1,1.8,0.05)))
    S.add(Piece(sh,'midfish',mid,z=6,pivot=(715,355),pose=swim(-1,420,s0=0.5,mean_amp=22,mean_T=1.3,vy=30),deform=fish_wiggle(-1,3.0,0.08)))
    S.add(Piece(sh,'bird',bird,z=6,pivot=(800,470),pose=swim(1,300,s0=0.5,mean_amp=26,mean_T=1.1,vy=-20),deform=fish_wiggle(1,3.2,0.07)))
    return S
SCENES['s5']=s5

# ---------------- сцена 6: рыбьи люди, средний, 2,5 с
def s6():
    sh=Sheet(level(SHEET['5'])); S=Scene(sh,(150,52,730),2.5)
    fA=take(sh,sh.ink_region([[565,100],[850,100],[850,202],[565,202]]))
    fB=take(sh,sh.ink_region([[517,220],[852,220],[852,306],[517,306]]))
    fC=take(sh,sh.ink_region([[640,312],[868,312],[868,464],[640,464]]))
    fE=take(sh,sh.ink_region([[172,392],[404,392],[404,460],[172,460]]))
    bub1=take(sh,sh.ink_region([[526,108],[564,108],[564,136],[526,136]],close=1))
    bub2=take(sh,sh.ink_region([[450,272],[476,272],[476,310],[450,310]],close=1))
    t1=take(sh,sh.ink_region([[450,300],[646,300],[646,948],[450,948]]))
    h1=take(sh,sh.ink_region([[200,60],[526,60],[526,386],[200,386]]))
    S.add(rest_piece(sh,'rest',z=0,solid_fill=False,pose=lambda t:dict(dy=2*math.sin(2*math.pi*t/0.7))))
    S.add(Piece(sh,'t1',t1,z=1,pivot=(548,948),pose=lambda t:dict(ang=3*math.sin(2*math.pi*t/1.1),dy=-5*abs(math.sin(2*math.pi*t/0.9)))))
    S.add(Piece(sh,'h1',h1,z=2,pivot=(360,386),pose=lambda t:dict(ang=5*math.sin(2*math.pi*t/1.2+1),dy=4*math.sin(2*math.pi*t/0.6))))
    S.add(Piece(sh,'fA',fA,z=5,pivot=(700,150),pose=swim(-1,430,s0=0.6,mean_amp=12,mean_T=0.9,vy=-15),deform=fish_wiggle(-1,3.6,0.09)))
    S.add(Piece(sh,'fB',fB,z=5,pivot=(680,262),pose=swim(-1,360,s0=0.4,start=0.25,mean_amp=14,mean_T=1.0,vy=10),deform=fish_wiggle(-1,3.2,0.09)))
    S.add(Piece(sh,'fC',fC,z=5,pivot=(750,390),pose=swim(-1,330,s0=0.3,start=0.5,mean_amp=18,mean_T=1.2,vy=-25),deform=fish_wiggle(-1,3.0,0.08)))
    S.add(Piece(sh,'fE',fE,z=5,pivot=(290,425),pose=swim(1,300,s0=0.5,start=0.1,mean_amp=10,mean_T=0.8),deform=fish_wiggle(1,3.8,0.09)))
    up=lambda v,ph: (lambda t: dict(dy=-v*t,dx=8*math.sin(2*math.pi*t/0.6+ph)))
    S.add(Piece(sh,'bub1',bub1,z=6,pivot=(545,122),pose=up(110,0)))
    S.add(Piece(sh,'bub2',bub2,z=6,pivot=(463,290),pose=up(140,1.5)))
    return S
SCENES['s6']=s6

# ---------------- сцена 8: кудрявый, рыбы кружат, 2,5 с
def s8():
    sh=Sheet(level(SHEET['6'])); S=Scene(sh,(32,40,960),2.5)
    fish=[('f1',[[665,68],[938,68],[938,176],[665,176]],-1),('f2',[[748,234],[882,234],[882,292],[748,292]],1),
          ('f3',[[622,313],[928,313],[928,416],[622,416]],1),('f4',[[612,483],[918,483],[918,627],[612,627]],-1),
          ('f5',[[608,627],[868,627],[868,742],[608,742]],-1),('f6',[[114,260],[256,260],[256,316],[114,316]],1),
          ('f7',[[118,423],[238,423],[238,470],[118,470]],-1),('f8',[[138,603],[382,603],[382,714],[138,714]],1)]
    masks=[(n,take(sh,sh.ink_region(p)),d) for n,p,d in fish]
    hair=take(sh,sh.ink_region([[226,60],[656,60],[656,252],[578,216],[460,192],[338,206],[302,282],[226,282]],close=3))
    S.add(rest_piece(sh,'head',z=5,pivot=(470,1020),pose=lambda t:dict(ang=1.5*math.sin(2*math.pi*t/1.0),dy=3*math.sin(2*math.pi*t/0.5))))
    S.add(Piece(sh,'hair',hair,z=5.5,pivot=(440,250),parent='head',deform=wobble(4.5,1.4)))
    cx,cy,rx,ry=455,400,1.0,0.72
    w=1.9
    for n,m,d in masks:
        ys,xs=np.nonzero(m); x0,y0=float(xs.mean()),float(ys.mean())
        ux,uy=(x0-cx)/rx,(y0-cy)/ry; Rr=math.hypot(ux,uy); th0=math.atan2(uy,ux)
        def pose(t,x0=x0,y0=y0,Rr=Rr,th0=th0,d=d):
            th=th0+w*t; x=cx+Rr*rx*math.cos(th); y=cy+Rr*ry*math.sin(th)
            vx=-math.sin(th)  # по часовой на экране: вверху вправо, внизу влево
            flip=(vx>0)!=(d>0)
            return dict(dx=x-x0,dy=y-y0,flip=flip)
        def z(t,th0=th0): return 6 if math.sin(th0+w*t)>0 else 4
        S.add(Piece(sh,n,m,z=z,pivot=(x0,y0),pose=pose,deform=fish_wiggle(d,3.4,0.08,phase=th0)))
    return S
SCENES['s8']=s8

# ---------------- сцена 9: два лица, рыбы уходят, титр, 2,5 с
def s9():
    from PIL import Image, ImageDraw, ImageFont
    sh=Sheet(level(SHEET['7'])); S=Scene(sh,FULL(),2.5)
    fish=[('g1',[[198,104],[394,104],[394,186],[198,186]],1,0.05),('g2',[[560,96],[858,96],[858,212],[560,212]],-1,0.0),
          ('g3',[[552,218],[848,218],[848,342],[552,342]],-1,0.12),('g4',[[610,344],[802,344],[802,393],[610,393]],-1,0.24),
          ('g5',[[562,398],[835,398],[835,521],[562,521]],-1,0.36),('g6',[[610,520],[718,520],[718,548],[610,548]],-1,0.46),
          ('g7',[[566,549],[792,549],[792,628],[566,628]],-1,0.56),('g8',[[178,598],[288,598],[288,657],[178,657]],1,0.2)]
    masks=[(n,take(sh,sh.ink_region(p)),d,st) for n,p,d,st in fish]
    big=take(sh,sh.ink_region([[378,62],[578,62],[578,714],[378,714]]))
    lft=take(sh,sh.ink_region([[198,198],[364,198],[364,656],[198,656]]))
    S.add(rest_piece(sh,'body',z=0,solid_fill=False,pose=lambda t:dict(dy=2*math.sin(2*math.pi*t/0.8))))
    S.add(Piece(sh,'lft',lft,z=1,pivot=(300,650),pose=lambda t:dict(ang=3*math.sin(2*math.pi*t/1.1+1))))
    S.add(Piece(sh,'big',big,z=2,pivot=(470,712),pose=lambda t:dict(ang=2.5*math.sin(2*math.pi*t/1.3),sc=1+0.02*math.sin(2*math.pi*t/0.6))))
    for n,m,d,st in masks:
        ys,xs=np.nonzero(m)
        S.add(Piece(sh,n,m,z=5,pivot=(float(xs.mean()),float(ys.mean())),pose=swim(d,620,s0=0.25,acc=0.5,start=st,mean_amp=12,mean_T=0.8),deform=fish_wiggle(d,3.6,0.09)))
    font=ImageFont.truetype('/usr/share/fonts/truetype/google-fonts/Lora-Italic-Variable.ttf',92)
    text='Нем как рыба'
    def ov(can,t):
        k=int((t-1.25)/0.06)+1
        if k<=0: return
        s=text[:min(k,len(text))]
        im=Image.new('L',(W,H),0); dr=ImageDraw.Draw(im)
        dr.text((1290,520),s,font=font,fill=255)
        a=np.asarray(im,np.float32)/255.0
        can[:]=can*(1-a)+20*a
    S.overlay=ov
    return S
SCENES['s9']=s9
