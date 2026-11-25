import math, numpy as np, cv2
from scipy import ndimage as ndi
from film2 import *
FULLW=912.0      # вся картина целиком
def comps(mask,min_area=6,join=3):
    j=cv2.dilate(mask.astype(np.uint8),disk(join))
    n,lab,st,cen=cv2.connectedComponentsWithStats(j)
    out=[]
    for i in range(1,n):
        if st[i][4]<min_area: continue
        m=(lab==i)&mask
        if m.sum()<min_area: continue
        out.append(cv2.dilate(m.astype(np.uint8),disk(5))>0)
    return out

def crowsA(sh):
    sky=poly_mask(sh.shape,[[225,25],[598,25],[598,345],[225,345]])
    small=comps(sh.lumdiff_mask(sky,thr=38,blur=45),min_area=8)
    big1=sh.lumdiff_mask(poly_mask(sh.shape,[[55,126],[178,126],[178,208],[55,208]]),thr=22,blur=121)
    big2=sh.lumdiff_mask(poly_mask(sh.shape,[[142,78],[265,78],[265,198],[142,198]]),thr=22,blur=121)
    big1=ndi.binary_fill_holes(cv2.dilate(big1.astype(np.uint8),disk(5))>0); big2=ndi.binary_fill_holes(cv2.dilate(big2.astype(np.uint8),disk(5))>0)
    ground_boxes=[(172,600,214,678),(466,810,530,894),(346,912,394,992),(92,942,150,1028),(733,1038,808,1132)]
    ground=[]
    for (x0,y0,x1,y1) in ground_boxes:
        m=sh.lumdiff_mask(poly_mask(sh.shape,[[x0,y0],[x1,y0],[x1,y1],[x0,y1]]),thr=34,blur=101)
        n,lab,st,_=cv2.connectedComponentsWithStats(m.astype(np.uint8))
        if n>1: m=lab==(1+np.argmax(st[1:,4]))
        ground.append(ndi.binary_fill_holes(cv2.dilate(m.astype(np.uint8),disk(3))>0))
    return small,big1,big2,ground

def s3():
    sh=PSheet('A.png'); S=PScene(sh,(70,0,FULLW),5.0)
    MT=sh.matte(); MTg=sh.matte(blur=101,lo=22,hi=70)**1.6
    small,big1,big2,ground=crowsA(sh)
    rng=np.random.default_rng(3); C=(420,230)
    for i,m in enumerate(small):
        sp=Sprite(sh,f'c{i}',m,z=3,alpha=MT)
        x0,y0=sp.cx,sp.cy; r0=math.hypot(x0-C[0],y0-C[1]); th0=math.atan2(y0-C[1],x0-C[0])
        om=rng.uniform(0.8,1.6)*rng.choice([1,1,-1]); g=rng.uniform(0.0,0.35); out=rng.uniform(8,30); st=rng.uniform(0,0.4)
        def pose(t,x0=x0,y0=y0,r0=r0,th0=th0,om=om,g=g,out=out,st=st):
            tt=max(0.0,t-st)*ease(t/0.5)
            r=r0*(1+0.2*tt)+out*tt; th=th0+om*tt
            y=C[1]+r*math.sin(th)*0.6-28*tt**1.6
            return dict(dx=C[0]+r*math.cos(th)-x0,dy=y-y0,sc=1+g*tt)
        sp.pose=pose; sp.deform=flap(rng.uniform(5,8),0.55,rng.uniform(0,6)); S.add(sp)
    MTb=sh.matte(blur=121,lo=12,hi=50)
    b1=Sprite(sh,'big1',big1,z=6,alpha=MTb); b2=Sprite(sh,'big2',big2,z=5,alpha=MTb)
    b1.pose=lambda t: dict(dx=640*ease(t/4.2)*t/4.2*1.6,dy=90*math.sin(t*1.3)-40*t,sc=1+0.9*ease(t/4.5),ang=-6*math.sin(t*2))
    b2.pose=lambda t: dict(dx=420*t*ease(t/1.0),dy=-75*t,sc=1+0.35*t,ang=4*math.sin(t*3))
    b1.deform=flap(3.2,0.6); b2.deform=flap(3.8,0.6,1.0)
    S.add(b1); S.add(b2)
    for k,(m,t0) in enumerate(zip(ground,[0.9,1.5,2.0,2.6,3.2])):
        sp=Sprite(sh,f'g{k}',m,z=4,alpha=MTg)
        def pose(t,t0=t0,k=k):
            hop=-6*max(0,math.sin(math.pi*min(1,max(0,(t-(t0-0.25))/0.25))))
            u=max(0.0,t-t0)
            dirx=[-1.0,0.35,-0.6,-0.2,1.4][k]
            return dict(dx=dirx*(240+40*k)*u**1.25,dy=hop-(380+30*k)*u**1.15,sc=1+0.35*u)
        sp.pose=pose
        fl=flap(7.0,0.5,k)
        sp.deform=(lambda fl,t0: (lambda s,t: fl(s,t) if t>t0 else (s.rgb,s.a)))(fl,t0)
        S.add(sp)
    coat=poly_mask(sh.shape,[[600,240],[800,240],[800,1010],[600,1010]])
    ramp=np.clip((np.arange(sh.shape[1])-640)/140.0,0,1)[None,:]
    wcoat=feather(coat,14)*ramp
    S.fields.append(wind(wcoat,5.5,1.7,kx=0.02,ky=0.025,gust=lambda t:0.6+0.4*ease(t/1.5)))
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),1.6,0.9))
    return S
SCENES={'s3':s3}

def largest(m):
    n,lab,st,_=cv2.connectedComponentsWithStats(m.astype(np.uint8))
    return lab==(1+np.argmax(st[1:,4])) if n>1 else m
def box(x0,y0,x1,y1): return [[x0,y0],[x1,y0],[x1,y1],[x0,y1]]

# ---------------- Дурак с вороной: сцены 1, 2, 15
def fool_setup(view,secs):
    sh=PSheet('D.png'); S=PScene(sh,view,secs)
    m=(sh.lum<100)&poly_mask(sh.shape,box(60,368,380,655))
    crow=ndi.binary_fill_holes(cv2.dilate(largest(m).astype(np.uint8),disk(4))>0)
    MT=sh.matte(blur=121,lo=15,hi=70)
    head=crow&poly_mask(sh.shape,box(250,368,385,448))
    S.crow_mask=crow; S.MT=MT
    return sh,S,crow,head,MT
def fool_face(S,gaze_fn,blinks,smile_fn=None):
    eyes=[((497,327),(30,14)),((607,322),(30,14))]
    for c,r in eyes:
        S.fields.append(gaze(c,(r[0]*0.85,r[1]*0.9),(-1,0.15),lambda t,g=gaze_fn: 0.65*g(t)))
        S.fields.append(blinkfield(c,(r[0]+2,r[1]+1),(lambda bl: (lambda t: max([max(0.0,1-abs(t-b)/0.11) for b in bl]+[0.0])))(blinks)))
    if smile_fn: S.fields.append(smile((555,440),(42,14),smile_fn))
def snap_turn(t,keys):
    """Резкие повороты головы птицы: держит, щёлк, держит."""
    a=0.0
    for (t0,ang) in keys:
        if t>=t0: a=a+(ang-a)*ease((t-t0)/0.08)
    return a
def s1():
    sh,S,crow,head,MT=fool_setup((30,90,660),5.0)
    body=crow&~head
    c=Sprite(sh,'crow',crow,z=2,pivot=(245,630),fr=1.5)
    c.rgb=c.rgb.copy(); 
    # голову рисуем отдельным спрайтом поверх, тело без головы
    hy0,hx0=c.y0,c.x0
    c.a=c.a*(~head[hy0:hy0+c.a.shape[0],hx0:hx0+c.a.shape[1]])
    c.pose=lambda t: dict(sc=1+0.025*math.sin(2*math.pi*t/0.9),scy=1+0.04*max(0,math.sin(2*math.pi*(t-1.8)/0.6))*(1.8<t<2.4))
    S.add(c)
    keys=[(0.0,0),(0.5,-14),(1.1,10),(1.7,-4),(2.6,18),(3.3,-10),(4.1,6)]
    h=Sprite(sh,'head',head,z=3,pivot=(287,432),parent='crow',fill=False,fr=1.5)
    h.pose=lambda t: dict(ang=snap_turn(t,keys),dy=-4*max(0,math.sin(math.pi*(t-2.6)/0.3))*(2.6<t<2.9))
    S.add(h)
    fool_face(S,lambda t: 0.25+0.6*ease((t-0.6)/0.5)-0.6*ease((t-3.4)/0.5)+0.6*ease((t-4.0)/0.4),[1.6,3.9])
    return S
def s2():
    sh,S,crow,head,MT=fool_setup((20,300,420),2.5)
    c=Sprite(sh,'crow',crow,z=2,pivot=(245,630),fr=1.5)
    def pose(t):
        crouch=0.12*math.sin(math.pi*min(1,max(0,(t-0.7)/0.5)))
        u=max(0.0,t-1.2)
        return dict(dx=-520*u**1.3,dy=-760*u**1.25,ang=-28*ease(u/0.6),scy=1-crouch,sc=1+0.25*u)
    c.pose=pose
    fl=flap(6.5,0.5); c.deform=lambda s,t: fl(s,t) if t>0.15 else (s.rgb,s.a)
    S.add(c)
    return S
def s15():
    sh,S,crow,head,MT=fool_setup((30,90,660),5.0)
    c=Sprite(sh,'crow',crow,z=2,pivot=(245,630),fr=1.5)
    def pose(t):
        u=max(0.0,1.3-t)   # прилёт: обратный прыжок
        land=0.1*math.sin(math.pi*min(1,max(0,(t-1.3)/0.3)))
        return dict(dx=-560*u**1.3,dy=-820*u**1.25,ang=-28*ease(u/0.6),scy=1-land,sc=1+0.25*u)
    c.pose=pose
    fl=flap(6.5,0.5); c.deform=lambda s,t: fl(s,t) if t<1.35 else (s.rgb,s.a)
    S.add(c)
    fool_face(S,lambda t: 0.6-1.0*ease((t-1.8)/0.6),[1.2,2.7,4.4],smile_fn=lambda t: 0.9*ease((t-2.4)/0.9))
    S.overlay=title_overlay('Дурак на холме',(70,150),84,3.1)
    return S
SCENES.update({'s1':s1,'s2':s2,'s15':s15})

# ---------------- Мальчик на холме: 4, 13, 14
def boy_wind(S,sh,amp_fn):
    coat=poly_mask(sh.shape,[[600,240],[800,240],[800,1010],[600,1010]])
    ramp=np.clip((np.arange(sh.shape[1])-640)/140.0,0,1)[None,:]
    S.fields.append(wind(feather(coat,14)*ramp,6.5,1.8,kx=0.02,ky=0.025,gust=amp_fn))
    hair=poly_mask(sh.shape,[[615,100],[760,100],[760,175],[615,175]])
    S.fields.append(wind(feather(hair,8),3.0,2.6,kx=0.05,ky=0.05,gust=amp_fn,vert=0.6))
def s4():
    sh=PSheet('A.png'); S=PScene(sh,(432,40,480),2.5)
    MT=sh.matte(); small,big1,big2,ground=crowsA(sh)
    for i,m in enumerate(small): sh.fill(m)          # небо пустое: стая уже ушла
    MTb=sh.matte(blur=121,lo=12,hi=50)
    b=Sprite(sh,'big1',big1,z=6,alpha=MTb)
    b.pose=lambda t: dict(dx=-180+1100*ease(t/1.6),dy=40-120*t,sc=2.6,ang=-8)
    b.deform=flap(3.6,0.6); S.add(b)
    sh.fill(big2)
    for k,m in enumerate(small[:6]):
        sp=Sprite(sh,f'c{k}',m,z=4,alpha=MT,fill=False)
        st=0.2+0.25*k
        sp.pose=(lambda st,k: lambda t: dict(dx=900*(t-st)-150+20*k,dy=-60+25*k-140*(t-st),sc=1.8))(st,k)
        sp.deform=flap(7,0.55,k); S.add(sp)
    boy_wind(S,sh,lambda t: 1.0+0.4*math.sin(2*math.pi*t/0.9))
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),1.6,0.9))
    return S
def s13():
    S=s3(); S.seconds=5.0
    T=5.0
    for sp in S.sprites.values():
        f=sp.pose; sp.pose=(lambda f: lambda t: f(T-t))(f)
    S.fields=S.fields[:0]
    sh=S.sheet
    boy_wind(S,sh,lambda t: 1.0-0.7*ease(t/5.0))
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),1.4,0.8))
    return S
def s14():
    sh=PSheet('A.png'); S=PScene(sh,(520,60,380),2.5)
    small,big1,big2,ground=crowsA(sh)
    boy_wind(S,sh,lambda t: 0.6*(1-ease(t/1.8))+0.08)
    return S

# ---------------- Цилиндр и ребёнок: 5, 6
def hat_setup(view,secs):
    sh=PSheet('B.png'); S=PScene(sh,view,secs)
    hat=poly_mask(sh.shape,[[352,0],[500,0],[500,118],[512,152],[330,152],[345,118]])
    hat&=(sh.lum<120)|poly_mask(sh.shape,box(352,0,500,140))
    hat=ndi.binary_fill_holes(cv2.dilate(hat.astype(np.uint8),disk(3))>0)
    sp=Sprite(sh,'hat',hat,z=5,pivot=(425,140),fr=1.5,fill=False); fill2(sh,hat,tex_box=(560,20,780,200))
    # под шляпой: волосы тёмным пятном фактуры сюртука
    cap=ell_mask(sh.shape,(428,152),(48,26))
    tex=np.roll(np.roll(sh.img,-160,0),-10,1)
    a=feather(cap,4)[...,None]*0.95
    sh.plate=sh.plate*(1-a)+tex*a
    return sh,S,sp
def child_wind(S,sh,g):
    hair=poly_mask(sh.shape,[[230,330],[360,330],[360,420],[230,420]])
    S.fields.append(wind(feather(hair,10),3.5,2.4,kx=0.05,ky=0.05,gust=g,vert=0.5))
    coatR=poly_mask(sh.shape,[[540,300],[660,300],[660,820],[540,820]])
    ramp=np.clip((np.arange(sh.shape[1])-560)/90.0,0,1)[None,:]
    S.fields.append(wind(feather(coatR,12)*ramp,6,1.6,kx=0.02,ky=0.03,gust=g))
def s5():
    sh,S,hat=hat_setup((200,0,440),2.5)
    def pose(t):
        lift=-10*ease(t/0.35)
        u=max(0.0,t-0.45)
        return dict(dx=-620*u**1.4,dy=lift-260*u**1.2+40*u*u,ang=-55*ease(u/1.2)+6*math.sin(2*math.pi*3*t)*(t<0.45),sc=1+0.3*u)
    hat.pose=pose; S.add(hat)
    child_wind(S,sh,lambda t: 0.7+0.5*ease(t/0.6))
    S.fields.append(gaze((292,438),(16,7),(0,-1),lambda t: ease((t-0.6)/0.4)))
    S.fields.append(gaze((326,438),(16,7),(0,-1),lambda t: ease((t-0.6)/0.4)))
    return S
def s6():
    sh,S,hat=hat_setup((0,0,FULLW),5.0)   # шляпы уже нет: спрайт не добавляем
    tree=poly_mask(sh.shape,[[690,310],[912,310],[912,665],[690,665]])
    ramp=np.clip((650-np.arange(sh.shape[0]))/300.0,0,1)[:,None]
    g=lambda t: 0.75+0.45*math.sin(2*math.pi*t/1.4)**2
    S.fields.append(wind(feather(tree,10)*ramp,9,1.5,kx=0.03,ky=0.02,gust=g,vert=0.3))
    bush=poly_mask(sh.shape,[[600,585],[700,585],[700,665],[600,665]])
    ramp2=np.clip((662-np.arange(sh.shape[0]))/70.0,0,1)[:,None]
    S.fields.append(wind(feather(bush,6)*ramp2,4,2.2,kx=0.06,ky=0.04,gust=g))
    child_wind(S,sh,g)
    S.fields.append(gaze((292,438),(16,7),(0,-1),lambda t: 1.0))
    S.fields.append(gaze((326,438),(16,7),(0,-1),lambda t: 1.0))
    S.fields.append(blinkfield((292,438),(16,7),lambda t: max(0.0,1-abs(t-2.9)/0.11)))
    S.fields.append(blinkfield((326,438),(16,7),lambda t: max(0.0,1-abs(t-2.9)/0.11)))
    return S

# ---------------- Котелок: 7
def s7():
    sh=PSheet('C.png'); S=PScene(sh,(0,0,700),5.0)
    arm=poly_mask(sh.shape,[[362,300],[470,288],[532,318],[566,410],[580,560],[522,578],[480,470],[420,440],[366,370]])
    arm&=~poly_mask(sh.shape,box(380,180,520,300))
    sp=Sprite(sh,'arm',arm,z=4,pivot=(548,560),fr=1.5,fill=False); fill2(sh,arm,tex_box=(330,450,450,600))
    def pose(t):
        down=ease((t-1.0)/1.4)
        return dict(ang=8*down-1.5*math.sin(2*math.pi*t/1.1)*(1-down))
    sp.pose=pose; S.add(sp)
    tree=poly_mask(sh.shape,[[0,20],[310,20],[310,720],[0,720]])
    ramp=np.clip((700-np.arange(sh.shape[0]))/450.0,0,1)[:,None]
    S.fields.append(wind(feather(tree,10)*ramp,10,1.3,kx=0.025,ky=0.02,gust=lambda t: 0.8+0.4*math.sin(2*math.pi*t/1.7)**2,vert=0.3))
    red=(sh.img[...,2]>sh.img[...,1]*1.35)&(sh.img[...,2]>90)&poly_mask(sh.shape,box(100,560,420,900))
    S.paints.append(pulse(red,0.35,1.1))
    S.fields.append(blinkfield((432,222),(18,10),lambda t: max(0.0,1-abs(t-3.3)/0.11)))
    S.fields.append(blinkfield((487,220),(18,10),lambda t: max(0.0,1-abs(t-3.3)/0.11)))
    return S

# ---------------- Девочка с рыбой: 8, 9, 10
def fish_setup(view,secs,fish1_on=True):
    sh=PSheet('E.png'); S=PScene(sh,view,secs)
    hand=poly_mask(sh.shape,[[552,770],[600,738],[640,700],[692,704],[714,762],[702,832],[662,862],[600,884],[548,872],[548,800]])
    f1=poly_mask(sh.shape,[[252,700],[270,650],[320,600],[400,565],[480,540],[560,522],[640,516],[720,532],[780,556],[860,578],[908,598],[908,762],[860,742],[780,762],[720,782],[640,802],[560,806],[500,800],[440,790],[380,776],[320,752],[270,726]])&~hand
    hand2=poly_mask(sh.shape,[[438,1168],[532,1158],[545,1268],[438,1268]])
    f2=poly_mask(sh.shape,[[272,1136],[330,1080],[420,1055],[520,1048],[600,1062],[660,1090],[682,1130],[660,1180],[600,1210],[520,1222],[420,1216],[330,1196]])&~hand2
    fish1=Sprite(sh,'fish1',f1,z=3,pivot=(560,670),fr=2.0,fill=False); fill2(sh,f1,tex_box=(20,560,240,900))
    fish2=Sprite(sh,'fish2',f2,z=3,pivot=(470,1135),fr=2.0,fill=False); fill2(sh,f2,tex_box=(700,950,900,1200))
    return sh,S,fish1,fish2
def girl_eyes(S,gz,blinks):
    for c in [(337,232),(441,232)]:
        S.fields.append(gaze(c,(22,10),(0.2,1),gz))
        S.fields.append(blinkfield(c,(24,10),(lambda bl: lambda t: max([max(0.0,1-abs(t-b)/0.11) for b in bl]+[0.0]))(blinks)))
def s8():
    sh,S,f1,f2=fish_setup((110,40,700),5.0)
    thr=lambda t: 0.4+0.6*ease((t-0.4)/1.6)
    f1.pose=lambda t: dict(ang=4*thr(t)*math.sin(2*math.pi*2.6*t),dy=5*thr(t)*math.sin(2*math.pi*5.2*t+1))
    f1.deform=fish_wiggle(-1,2.6,0.05,amp_fn=thr); S.add(f1); S.add(f2)
    girl_eyes(S,lambda t: 0.8*ease((t-0.5)/0.6),[1.3,3.6])
    return S
def s9():
    sh,S,f1,f2=fish_setup((220,330,560),2.5)
    def pose(t):
        u=max(0.0,t-0.7)
        return dict(ang=6*math.sin(2*math.pi*3.2*t)*(t<0.7)-18*ease(u/0.5),dy=-980*u**1.3+6*math.sin(2*math.pi*6*t),dx=-140*u,sc=1+0.15*u)
    f1.pose=pose; f1.deform=fish_wiggle(-1,3.6,0.08); S.add(f1); S.add(f2)
    return S
def s10():
    sh,S,f1,f2=fish_setup((0,434,600),2.5)
    def pose(t):
        u=max(0.0,t-0.5)
        return dict(dx=-560*u**1.35,dy=40*u,ang=8*math.sin(2*math.pi*3*t)*(t<0.5)+6*ease(u/0.5))
    f2.pose=pose; f2.deform=fish_wiggle(-1,3.2,0.07); S.add(f2)
    knife=poly_mask(sh.shape,box(0,1230,320,1262))
    def glint(bg,t):
        x=-60+520*ease((t-0.9)/0.9)
        xs=np.arange(sh.shape[1],dtype=np.float32)
        band=np.exp(-((xs-x)/28.0)**2)[None,:,None]
        w=feather(knife,2)[...,None]
        bg[:]=bg*(1+1.1*band*w)
    S.paints.append(glint)
    return S

# ---------------- Лось и парусник: 11, 12
def s11():
    sh=PSheet('F.png'); S=PScene(sh,(312,20,600),5.0)
    head=feather(poly_mask(sh.shape,[[400,230],[912,230],[912,760],[480,760]]),40)
    S.fields.append(shiftfield(head,lambda t:(0,-16*ease((t-0.6)/1.2)+4*math.sin(2*math.pi*t/1.3))))
    antL=feather(poly_mask(sh.shape,[[230,40],[480,40],[480,400],[230,400]]),20)
    antR=feather(poly_mask(sh.shape,[[680,40],[912,40],[912,390],[680,390]]),20)
    S.fields.append(rotfield(antL,(470,380),lambda t: 3.5*math.sin(2*math.pi*t/1.6)))
    S.fields.append(rotfield(antR,(700,375),lambda t: -3.5*math.sin(2*math.pi*t/1.6+0.4)))
    earL=feather(poly_mask(sh.shape,[[430,230],[550,230],[550,345],[430,345]]),8)
    earR=feather(poly_mask(sh.shape,[[640,235],[712,235],[712,335],[640,335]]),8)
    tw=lambda t,t0: 12*math.sin(math.pi*min(1,max(0,(t-t0)/0.25)))
    S.fields.append(rotfield(earL,(530,335),lambda t: -tw(t,1.9)-tw(t,3.6)))
    S.fields.append(rotfield(earR,(660,330),lambda t: tw(t,2.4)))
    for c in [(526,466),(686,464)]:
        S.fields.append(blinkfield(c,(14,7),lambda t: max(0.0,1-abs(t-3.0)/0.11)))
    return S
def s12():
    sh=PSheet('F.png'); S=PScene(sh,(0,330,500),5.0)
    ship=poly_mask(sh.shape,[[78,445],[300,445],[392,620],[400,790],[392,838],[80,838],[78,700]])
    sp=Sprite(sh,'ship',ship,z=3,pivot=(240,830),fr=2.0,fill=False); fill2(sh,ship,tex_box=(0,300,230,440))
    # корабль уходит к горизонту: чуть влево, уменьшается, качается на волне
    sp.pose=lambda t: dict(dx=-70*ease(t/5.0)-8*t,dy=-14*ease(t/5.0)+3*math.sin(2*math.pi*t/0.9),sc=1-0.42*ease(t/5.0),ang=1.8*math.sin(2*math.pi*t/1.4))
    sp.deform=flutter(4,1.2,anchor='bottom',k=0.04,power=1.5)
    S.add(sp)
    sea=poly_mask(sh.shape,[[0,830],[500,830],[500,1268],[0,1268]])
    S.fields.append(ripple(feather(sea,8),2.5,0.9,k=0.12))
    antL=feather(poly_mask(sh.shape,[[230,40],[480,40],[480,400],[230,400]]),20)
    S.fields.append(rotfield(antL,(470,380),lambda t: 3.5*math.sin(2*math.pi*t/1.6)))
    return S
SCENES.update({'s4':s4,'s5':s5,'s6':s6,'s7':s7,'s8':s8,'s9':s9,'s10':s10,'s11':s11,'s12':s12,'s13':s13,'s14':s14})

# ======== полёт ворон по наблюдениям (замена прежней сцены 3)
def make_flyer(S,sh,name,mask,body_x,shL,shR,alpha,f,phase,traj_pose,z=6,fill=True,wing_y_max=None,op=None):
    """Ворона из картины: тело + два крыла на плечах; крылья бьют от плеча."""
    H_,W_=mask.shape
    xs=np.arange(W_)[None,:]
    bodym=mask&(xs>=body_x[0])&(xs<=body_x[1])
    lm=mask&(xs<body_x[0]+9); rm=mask&(xs>body_x[1]-9)
    if wing_y_max is not None:
        ys=np.arange(H_)[:,None]; bodym|=mask&(ys>wing_y_max)&~bodym; lm&=ys<=wing_y_max; rm&=ys<=wing_y_max
    opf=op or (lambda t:1.0)
    b=Sprite(sh,name,bodym,z=z,alpha=alpha,fill=fill)
    b.traj=True
    b.pose=lambda t: {**traj_pose(t),'op':opf(t)}
    b.flapf=f
    S.add(b)
    L=Sprite(sh,name+'_L',lm,z=z-0.1,alpha=alpha,pivot=shL,parent=name,fill=fill)
    Rr=Sprite(sh,name+'_R',rm,z=z-0.1,alpha=alpha,pivot=shR,parent=name,fill=fill)
    def wpose(sign):
        def p(t):
            a=wing_angle(t,f,phase,up=34.0,down=-22.0)
            return dict(ang=sign*a,sc=1-0.14*(a/34.0)**2,op=opf(b.tclock(t) if hasattr(b,'tclock') else t))
        return p
    L.pose=wpose(1); Rr.pose=wpose(-1)
    S.add(L); S.add(Rr)
    # тело подбрасывает на взмахе вниз
    base=b.pose; b.pose=lambda t,base=base: (lambda d: {**d,'dy':d.get('dy',0)+body_bob(t,f,phase,2.0*d.get('sc',1))})(base(t))
    return b
def s3():
    sh=PSheet('A.png'); S=PScene(sh,(0,0,FULLW),5.0); S.step=1
    MT=sh.matte(); MTg=sh.matte(blur=101,lo=22,hi=70)**1.6; MTb=sh.matte(blur=121,lo=12,hi=50)
    small,big1,big2,ground=crowsA(sh)
    rng=np.random.default_rng(7); C=(205,205)
    small=[m for m in small if m.sum()>=260]   # только птицы; мелкие брызги краски остаются в небе
    sprs=[Sprite(sh,f'c{i}',m,z=3,alpha=MT) for i,m in enumerate(small)]
    paths=flock_paths([(s.cx,s.cy) for s in sprs],C,5.2,rng,v=(120,175),climb=(15,35),leave=(0.8,2.6),turn_noise=0.18)
    for sp,(X,Y,Hh) in zip(sprs,paths):
        f=rng.uniform(3.6,4.4); ph=rng.uniform(0,6.28)
        g0=rng.uniform(0.8,3.8); glides=[(g0,g0+rng.uniform(0.25,0.5))] if rng.random()<0.5 else []
        def pose(t,X=X,Y=Y,Hh=Hh,x0=sp.cx,y0=sp.cy):
            i=min(len(X)-1,int(t*24)); bank=math.degrees(math.sin(Hh[i]))*0.12
            return dict(dx=X[i]-x0,dy=Y[i]-y0,ang=bank)
        sp.pose=pose; sp.traj=True; sp.deform=speck_flap(f,ph,glides); S.add(sp)
    # две большие вороны: взмах от плеча изгибом крыла, 3,4 Гц, тело подбрасывает на взмахе вниз
    def p1(t): return dict(dx=720*ease(t/4.6)+30*t,dy=-40*t+25*math.sin(t*0.9)+body_bob(t,3.4,0,2.5),sc=1+1.0*ease(t/4.6),ang=-3)
    def p2(t): return dict(dx=360*t*ease(t/0.6),dy=-95*t+body_bob(t,3.5,1.7,2.0),sc=1+0.3*t,ang=2)
    b1=Sprite(sh,'big1',big1,z=7,alpha=MTb,fill=False); fill2(sh,big1,tex_box=(300,360,560,440)); b1.pose=p1; b1.traj=True; b1.deform=wingbend((101,150),(128,151),3.4,0.0,28,-18,42); S.add(b1)
    b2=Sprite(sh,'big2',big2,z=6.5,alpha=MTb,fill=False); fill2(sh,big2,tex_box=(300,360,560,440)); b2.pose=p2; b2.traj=True; b2.deform=wingbend((178,102),(212,102),3.5,1.7,26,-16,45); S.add(b2)
    # вороны с холма: присед, прыжок, глубокие частые первые взмахи, набор высоты
    jumps=[0.9,1.5,2.0,2.6,3.2]; dirs=[-1.0,0.35,-0.6,-0.2,1.4]
    cx1,cy1=b1.cx,b1.cy
    for k,(m,tj) in enumerate(zip(ground,jumps)):
        g=Sprite(sh,f'g{k}',m,z=4,alpha=MTg)
        crouch=lambda t,tj=tj: 0.14*math.sin(math.pi*min(1,max(0,(t-(tj-0.22))/0.22)))
        g.pose=(lambda tj,cr: lambda t: dict(scy=1-cr(t),op=1.0 if t<tj else 0.0))(tj,crouch); g.traj=True
        S.add(g)
        ys,xs=np.nonzero(m); gx,gy=float(xs.mean()),float(ys.mean())
        scl=max(0.35,(xs.max()-xs.min())/105.0)
        fl=Sprite(sh,f'gf{k}',big1,z=5,alpha=MTb,fill=False)
        def tp(t,tj=tj,k=k,gx=gx,gy=gy,scl=scl):
            u=max(0.0,t-tj); dx=dirs[k]*(200+40*k)*u**1.2; dy=-12*ease(u/0.1)-(300+30*k)*u**1.1+60*u*u
            return dict(dx=gx-cx1+dx,dy=gy-cy1+dy+body_bob(t,4.4,k,2.0),sc=scl*(1+0.3*u),ang=-10*ease(u/0.3)+6*ease((u-0.6)/0.5),op=1.0 if t>=tj else 0.0)
        fl.pose=tp; fl.traj=True; fl.pivot=(cx1,cy1)
        fl.deform=wingbend((101,150),(128,151),4.4,k,32,-22,42)
        S.add(fl)
    coat=poly_mask(sh.shape,[[600,240],[800,240],[800,1010],[600,1010]])
    ramp=np.clip((np.arange(sh.shape[1])-640)/140.0,0,1)[None,:]
    S.fields.append(wind(feather(coat,14)*ramp,5.5,1.7,kx=0.02,ky=0.025,gust=lambda t:0.6+0.4*ease(t/1.5)))
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),1.6,0.9))
    return S
SCENES['s3']=s3

# ======== вороны, нарисованные кодом (выбор Teofunk 25.11)
from crowgen import ProcCrow, CrowTexture, crow_params
def path_heading(X,Y):
    def h(t):
        i=min(len(X)-2,int(t*24)); return (X[i+1]-X[i],Y[i+1]-Y[i])
    return h
def pose_heading(pose,dt=1/24):
    def h(t):
        a=pose(t); b=pose(t+dt); return (b.get('dx',0)-a.get('dx',0),b.get('dy',0)-a.get('dy',0))
    return h
def s3():
    sh=PSheet('A.png'); S=PScene(sh,(0,0,FULLW),5.0); S.step=1
    MTg=sh.matte(blur=101,lo=22,hi=70)**1.6
    tex=CrowTexture(sh.img,(180,110,210,175))
    small,big1,big2,ground=crowsA(sh)
    small=[m for m in small if m.sum()>=260]
    rng=np.random.default_rng(7); C=(205,205)
    centers=[]
    for m in small:
        ys,xs=np.nonzero(m); centers.append((float(xs.mean()),float(ys.mean()),max(16.0,(xs.max()-xs.min())*1.25))); sh.fill(m)
    paths=flock_paths([(c[0],c[1]) for c in centers],C,5.2,rng,v=(120,175),climb=(15,35),leave=(0.8,2.6),turn_noise=0.18)
    for i,((cx,cy,span),(X,Y,Hh)) in enumerate(zip(centers,paths)):
        f=rng.uniform(3.6,4.4); ph=rng.uniform(0,1)
        g0=rng.uniform(0.8,3.8); glides=[(g0,g0+rng.uniform(0.25,0.5))] if rng.random()<0.45 else []
        pose=(lambda X,Y,cx,cy: lambda t: dict(dx=X[min(len(X)-1,int(t*24))]-cx,dy=Y[min(len(Y)-1,int(t*24))]-cy))(X,Y,cx,cy)
        S.add(ProcCrow(f'c{i}',(cx,cy),span,tex,crow_params(f,ph,path_heading(X,Y),glides,yaw_bias=rng.uniform(0.2,0.7)),pose,z=3,ovs=3.0))
    fill2(sh,big1,tex_box=(300,360,560,440)); fill2(sh,big2,tex_box=(300,360,560,440))
    def p1(t): return dict(dx=720*ease(t/4.6)+30*t,dy=-40*t+25*math.sin(t*0.9),sc=1+1.0*ease(t/4.6))
    def p2(t): return dict(dx=360*t*ease(t/0.6),dy=-95*t,sc=1+0.3*t)
    S.add(ProcCrow('big1',(113,165),118,tex,crow_params(3.4,0.0,pose_heading(p1),yaw_bias=0.55),p1,z=7))
    S.add(ProcCrow('big2',(200,135),122,tex,crow_params(3.5,0.45,pose_heading(p2),yaw_bias=0.75),p2,z=6.5))
    jumps=[0.9,1.5,2.0,2.6,3.2]; dirs=[-1.0,0.35,-0.6,-0.2,1.4]
    for k,(m,tj) in enumerate(zip(ground,jumps)):
        g=Sprite(sh,f'g{k}',m,z=4,alpha=MTg)
        crouch=lambda t,tj=tj: 0.14*math.sin(math.pi*min(1,max(0,(t-(tj-0.22))/0.22)))
        g.pose=(lambda tj,cr: lambda t: dict(scy=1-cr(t),op=1.0 if t<tj else 0.0))(tj,crouch); S.add(g)
        ys,xs=np.nonzero(m); gx,gy=float(xs.mean()),float(ys.mean()); span=(xs.max()-xs.min())*1.9
        def tp(t,tj=tj,k=k):
            u=max(0.0,t-tj); return dict(dx=dirs[k]*(200+40*k)*u**1.2,dy=-12*ease(u/0.1)-(300+30*k)*u**1.1+60*u*u,sc=1+0.3*u)
        ff=(lambda tj: lambda t: 5.2-1.0*ease((t-tj-0.3)/0.4))(tj)
        dp=(lambda tj: lambda t: 1.25-0.25*ease((t-tj-0.3)/0.4))(tj)
        S.add(ProcCrow(f'gf{k}',(gx,gy),span,tex,crow_params(4.4,k*0.2,pose_heading(tp),deep_fn=dp,f_fn=ff,yaw_bias=0.5),tp,z=5,ovs=2.5,
                       op=(lambda tj: lambda t: 1.0 if t>=tj else 0.0)(tj)))
    coat=poly_mask(sh.shape,[[600,240],[800,240],[800,1010],[600,1010]])
    ramp=np.clip((np.arange(sh.shape[1])-640)/140.0,0,1)[None,:]
    S.fields.append(wind(feather(coat,14)*ramp,5.5,1.7,kx=0.02,ky=0.025,gust=lambda t:0.6+0.4*ease(t/1.5)))
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),1.6,0.9))
    return S
SCENES['s3']=s3

# ======== формат картин: плащ, стая, вороны во всех сценах (25.11, после «птицы хорошо, плащ плохо»)
def coat_edge_weight(sh):
    box=poly_mask(sh.shape,box_(596,96,812,1012))
    m=largest(cv2.morphologyEx(((sh.lum<85)&box).astype(np.uint8),cv2.MORPH_CLOSE,disk(9))>0)
    m=ndi.binary_fill_holes(m)
    band=(cv2.dilate(m.astype(np.uint8),disk(13))>0)&~(cv2.erode(m.astype(np.uint8),disk(9))>0)
    xs=np.arange(sh.shape[1])[None,:]; ys=np.arange(sh.shape[0])[:,None]
    right=np.clip((xs-700)/40.0,0,1)          # подветренный правый край
    hem=np.clip((ys-860)/80.0,0,1)            # подол
    top=np.clip((ys-260)/120.0,0,1)           # у плеч почти не двигается
    w=feather(band,4)*np.maximum(right,hem)*top
    return w
def box_(x0,y0,x1,y1): return [[x0,y0],[x1,y0],[x1,y1],[x0,y1]]
def cloak_wind(weight_full,amp,freq,gust):
    """Ветер в край плаща: ткань отжата в одну сторону и треплется, без резинового качания всей фигуры."""
    def f(gx,gy,t):
        w=wsl(weight_full,gx,gy); g=gust(t); ph=2*math.pi*freq*t
        flap=0.55+0.45*np.sin(ph-0.035*gy)+0.18*np.sin(2.7*ph-0.08*gy+1.1)
        return amp*g*w*flap, 0.35*amp*g*w*np.sin(1.3*ph-0.05*gy)
    return f
def boy_wind(S,sh,amp_fn):
    S.fields.append(cloak_wind(coat_edge_weight(sh),3.6,1.3,amp_fn))
    hair=poly_mask(sh.shape,[[615,100],[760,100],[760,175],[615,175]])
    S.fields.append(wind(feather(hair,8),1.8,1.9,kx=0.05,ky=0.05,gust=amp_fn,vert=0.5))
def sea_ripple(S,sh,a=1.6,f=0.9):
    sea=poly_mask(sh.shape,[[250,455],[600,455],[600,580],[250,580]])|poly_mask(sh.shape,[[790,455],[912,455],[912,580],[790,580]])
    S.fields.append(ripple(feather(sea,10),a,f))

def build_flock(S,sh,reverse=False,T=5.0):
    MTg=sh.matte(blur=101,lo=22,hi=70)**1.6
    tex=CrowTexture(sh.img,(180,110,210,175))
    small,big1,big2,ground=crowsA(sh)
    small=[m for m in small if m.sum()>=260]
    rng=np.random.default_rng(7); C=(205,205)
    R=(lambda f: (lambda t: f(T-t))) if reverse else (lambda f: f)
    def RH(h): return (lambda t: tuple(-v for v in h(T-t))) if reverse else h
    centers=[]
    for m in small:
        ys,xs=np.nonzero(m); centers.append((float(xs.mean()),float(ys.mean()),max(16.0,(xs.max()-xs.min())*1.25))); sh.fill(m)
    paths=flock_paths([(c[0],c[1]) for c in centers],C,T+0.2,rng,v=(120,175),climb=(15,35),leave=(0.8,2.6),turn_noise=0.18)
    for i,((cx,cy,span),(X,Y,Hh)) in enumerate(zip(centers,paths)):
        f=rng.uniform(3.6,4.4); ph=rng.uniform(0,1)
        g0=rng.uniform(0.8,3.8); glides=[(g0,g0+rng.uniform(0.25,0.5))] if rng.random()<0.45 else []
        pose=(lambda X,Y,cx,cy: lambda t: dict(dx=X[min(len(X)-1,max(0,int(t*24)))]-cx,dy=Y[min(len(Y)-1,max(0,int(t*24)))]-cy))(X,Y,cx,cy)
        S.add(ProcCrow(f'c{i}',(cx,cy),span,tex,crow_params(f,ph,RH(path_heading(X,Y)),glides,yaw_bias=rng.uniform(0.2,0.7)),R(pose),z=3,ovs=3.0))
    fill2(sh,big1,tex_box=(300,360,560,440)); fill2(sh,big2,tex_box=(300,360,560,440))
    def p1(t): return dict(dx=720*ease(t/4.6)+30*t,dy=-40*t+25*math.sin(t*0.9),sc=1+1.0*ease(t/4.6))
    def p2(t): return dict(dx=360*t*ease(t/0.6),dy=-95*t,sc=1+0.3*t)
    S.add(ProcCrow('big1',(113,165),118,tex,crow_params(3.4,0.0,RH(pose_heading(p1)),yaw_bias=0.55),R(p1),z=7))
    S.add(ProcCrow('big2',(200,135),122,tex,crow_params(3.5,0.45,RH(pose_heading(p2)),yaw_bias=0.75),R(p2),z=6.5))
    jumps=[0.9,1.5,2.0,2.6,3.2]; dirs=[-1.0,0.35,-0.6,-0.2,1.4]
    for k,(m,tj) in enumerate(zip(ground,jumps)):
        g=Sprite(sh,f'g{k}',m,z=4,alpha=MTg)
        crouch=lambda t,tj=tj: 0.14*math.sin(math.pi*min(1,max(0,(t-(tj-0.22))/0.22)))
        g.pose=R((lambda tj,cr: lambda t: dict(scy=1-cr(t),op=1.0 if t<tj else 0.0))(tj,crouch)); S.add(g)
        ys,xs=np.nonzero(m); gx,gy=float(xs.mean()),float(ys.mean()); span=(xs.max()-xs.min())*1.9
        def tp(t,tj=tj,k=k):
            u=max(0.0,t-tj); return dict(dx=dirs[k]*(200+40*k)*u**1.2,dy=-12*ease(u/0.1)-(300+30*k)*u**1.1+60*u*u,sc=1+0.3*u)
        ff=(lambda tj: lambda t: 5.2-1.0*ease((t-tj-0.3)/0.4))(tj)
        dp=(lambda tj: lambda t: 1.25-0.25*ease((t-tj-0.3)/0.4))(tj)
        fl=None
        if reverse:   # посадка: перед касанием крылья раскрыты и тормозят
            fl=(lambda tj: lambda t: ease(1-((T-t)-tj)/0.35) if (T-t)>=tj else 0.0)(tj)
            ffr=(lambda ff: lambda t: ff(T-t))(ff); dpr=(lambda dp: lambda t: dp(T-t))(dp)
            prm=crow_params(4.4,k*0.2,RH(pose_heading(tp)),deep_fn=dpr,f_fn=ffr,flare_fn=fl,yaw_bias=0.5)
        else:
            prm=crow_params(4.4,k*0.2,pose_heading(tp),deep_fn=dp,f_fn=ff,yaw_bias=0.5)
        opf=(lambda tj: lambda t: 1.0 if t>=tj else 0.0)(tj)
        S.add(ProcCrow(f'gf{k}',(gx,gy),span,tex,prm,R(tp),z=5,ovs=2.5,op=R(opf)))
def s3():
    sh=PSheet('A.png'); S=PScene(sh,(0,0,FULLW),5.0); S.step=1
    build_flock(S,sh)
    boy_wind(S,sh,lambda t:0.6+0.4*ease(t/1.5)); sea_ripple(S,sh)
    return S
def s13():
    sh=PSheet('A.png'); S=PScene(sh,(0,0,FULLW),5.0); S.step=1
    build_flock(S,sh,reverse=True)
    boy_wind(S,sh,lambda t: 1.0-0.75*ease(t/5.0)); sea_ripple(S,sh,1.4,0.8)
    return S
def s4():
    sh=PSheet('A.png'); S=PScene(sh,(432,40,480),2.5); S.step=1
    tex=CrowTexture(sh.img,(180,110,210,175))
    small,big1,big2,ground=crowsA(sh)
    for m in small:
        if m.sum()>=260: sh.fill(m)
    fill2(sh,big1,tex_box=(300,360,560,440)); fill2(sh,big2,tex_box=(300,360,560,440))
    # большая ворона проносится перед мальчиком, за ней несколько дальних
    pb=lambda t: dict(dx=-180+760*ease(t/1.9),dy=-30+60*math.sin(t*1.6)-60*t)
    S.add(ProcCrow('swoop',(470,330),330,tex,crow_params(3.4,0.2,pose_heading(pb),yaw_bias=0.35),pb,z=8,ovs=1.5))
    rng=np.random.default_rng(2)
    for k in range(6):
        st=0.15+0.28*k; y0=90+40*k
        p=(lambda st,y0: lambda t: dict(dx=520*(t-st),dy=-70*(t-st)))(st,y0)
        S.add(ProcCrow(f'f{k}',(420,y0),rng.uniform(34,60),tex,crow_params(rng.uniform(3.8,4.4),rng.uniform(0,1),pose_heading(p),yaw_bias=0.45),p,z=4,ovs=3.0))
    boy_wind(S,sh,lambda t: 1.0+0.3*math.sin(2*math.pi*t/0.9)); sea_ripple(S,sh)
    return S
def s14():
    sh=PSheet('A.png'); S=PScene(sh,(520,60,380),2.5)
    boy_wind(S,sh,lambda t: 0.7*(1-ease(t/1.8))+0.06)
    return S

# ---- ворона Дурака: взлёт (2) и посадка (15) нарисованной кодом вороной
def fool_tex(sh):
    m=(sh.lum<70)&poly_mask(sh.shape,box_(90,400,330,620)); return CrowTexture(sh.img,(120,450,300,600))
def s2():
    sh,S,crow,head,MT=fool_setup((20,300,420),2.5); S.step=1
    tex=fool_tex(sh)
    c=Sprite(sh,'crow',crow,z=2,pivot=(245,630),fr=1.5)
    crouch=lambda t: 0.12*math.sin(math.pi*min(1,max(0,(t-0.7)/0.5)))
    c.pose=lambda t: dict(scy=1-crouch(t),op=1.0 if t<1.2 else 0.0); S.add(c)
    tp=lambda t: (lambda u: dict(dx=-420*u**1.2,dy=-25*ease(u/0.1)-620*u**1.15,sc=1+0.35*u))(max(0.0,t-1.2))
    S.add(ProcCrow('fly',(225,520),360,tex,crow_params(3.3,0.0,pose_heading(tp),deep_fn=lambda t: 1.3-0.3*ease((t-1.5)/0.4),f_fn=lambda t: 4.2-0.9*ease((t-1.5)/0.4),yaw_bias=0.4),
                   tp,z=3,ovs=1.5,op=lambda t: 1.0 if t>=1.2 else 0.0))
    return S
def s15():
    sh,S,crow,head,MT=fool_setup((30,90,660),5.0); S.step=1
    tex=fool_tex(sh); TL=1.35
    c=Sprite(sh,'crow',crow,z=2,pivot=(245,630),fr=1.5)
    settle=lambda t: 0.10*math.sin(math.pi*min(1,max(0,(t-TL)/0.3)))
    c.pose=lambda t: dict(scy=1-settle(t),op=1.0 if t>=TL else 0.0); S.add(c)
    tp=lambda t: (lambda u: dict(dx=-460*u**1.2,dy=-680*u**1.15+20*ease(u/0.12),sc=1+0.35*u))(max(0.0,TL-t))
    def prm(t):
        u=max(0.0,TL-t); fl=ease(1-u/0.4)
        return dict(c=(3.6*t)%1.0,yaw=0.45,pitch=-0.5*(1-fl)+0.55*fl,glide=False,flare=fl,deep=1.0-0.45*fl)
    S.add(ProcCrow('fly',(225,520),360,tex,prm,tp,z=3,ovs=1.5,op=lambda t: 1.0 if t<TL else 0.0))
    fool_face(S,lambda t: 0.6-1.0*ease((t-1.8)/0.6),[1.2,2.7,4.4],smile_fn=lambda t: 0.9*ease((t-2.4)/0.9))
    S.overlay=title_overlay('Дурак на холме',(56,120),70,3.1)
    return S
SCENES.update({'s2':s2,'s3':s3,'s4':s4,'s13':s13,'s14':s14,'s15':s15})
