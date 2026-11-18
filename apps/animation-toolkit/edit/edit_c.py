import sys; sys.path.insert(0,'v4'); sys.path.insert(0,'.')
from compose import *
TRACK='audio/Concrete_A.mp3'; NTOT=int(130.8*FPS)        # 3139 frames
E='clips/'
EDL=[ # name, src, a, b, transition-in, regularize, ext insert, audio override (src, a)
 ('open',      E+'E01.mp4',        0.0,14.6,'cut',True, None,None),
 ('fox',       E+'E10w.mp4',       0.0, 4.8,'fog',False,None,None),
 ('woodpecker','v4/woodpecker_av.mp4',0.0,6.0,'fog',False,None,None),
 ('bison',     E+'E07_cut.mp4',    0.0, 4.0,'fog',False,None,None),
 ('bison_walk',E+'E03_cut.mp4',    5.6,10.0,'fog',True, None,None),
 ('calf_close',E+'E07_cut.mp4',    4.7, 7.7,'cut',False,None,None),
 ('calf_walk', E+'E03_cut.mp4',   10.6,13.6,'fog',True, None,None),
 ('horns',     E+'E09_fix3.mp4',   4.4, 7.4,'fog',False,None,None),
 ('herd',      E+'E04_fix2.mp4',  14.3,17.86,'fog',False,None,None),
 ('hunter',    E+'E07_cut.mp4',   12.0,14.9,'fog',False,None,None),
 ('hunter_cl', E+'E09_fix3.mp4',  11.5,14.1,'cut',False,None,None),
 ('land_red',  E+'E02_fix.mp4',    0.0, 5.2,'fog',False,None,None),
 ('land_path', E+'E02_fix.mp4',    7.8,15.46,'fog',True, None,None),
 ('rider',     E+'E06_fix.mp4',   14.6,18.2,'fog',False,None,None),
 ('women_b',   E+'E05_fix.mp4',    0.0, 4.4,'fog',False,None,None),
 ('valley_cc', 'v4/valley_concrete_av.mp4',0.0,8.6,'fog',False,None,None),
 ('women_fish',E+'E04_fix2.mp4',   0.0, 7.6,'fog',False,None,None),
 ('faces',     E+'E04_fix2.mp4',   9.2,12.6,'fog',False,None,None),
 ('women_a',   E+'E07_cut.mp4',    8.3,10.8,'fog',False,None,None),
 ('umbels',    E+'E06_fix.mp4',    0.0, 4.5,'fog',False,None,None),
 ('fish_coral',E+'E06_fix.mp4',    5.5, 9.3,'fog',False,None,None),
 ('sky_coral', E+'E06_fix.mp4',   10.0,14.2,'fog',False,(12.0,2.0,'E06'),None),
 ('land_moon', E+'E05_fix.mp4',    9.9,13.9,'fog',False,(11.9,1.5,'E05m'),None),
 ('dusk',      E+'E08_fix_crow.mp4',0.0,3.8,'fog',False,None,None),
 ('night_river',E+'E08_fix_crow.mp4',4.2,7.42,'fog',False,None,None),
 ('owl_flight',E+'E08_fix_crow.mp4',12.0,15.1,'fog',True,None,None),
 ('crows',     E+'E08_fix_crow.mp4',15.6,19.46,'fog',False,None,(E+'E08_fix_crow.mp4',2.6)),
 ('river',     E+'E09_river_long.mp4',0.0,9.0,'fog',False,None,None),
 ('owl_final', E+'E09_fix3.mp4',  14.9,19.62,'fog',False,None,None),
 ('hold',      None,0,0,'cut',False,None,(E+'E01.mp4',0.0)),
]
def seglen(s):
    n=int(round((s[3]-s[2])*FPS))
    if s[6]: n+=int(round(s[6][1]*FPS))
    return n
# timeline
starts=[];t=0
for k,s in enumerate(EDL[:-1]):
    if k>0 and s[4]=='fog': t-=FT
    starts.append(t); t+=seglen(s)
hold_n=NTOT-t; starts.append(t)
print('hold frames',hold_n, round(hold_n/FPS,2))
for s,st in zip(EDL,starts): print(f'{st/FPS:7.2f}  {s[0]}')
if '--dry' in sys.argv: sys.exit()
noise=cv2.resize(np.random.default_rng(3).random((6,10)).astype(np.float32),(W,H),interpolation=cv2.INTER_CUBIC)
noise=(noise-noise.min())/(noise.max()-noise.min())
p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','16','-preset','medium','-pix_fmt','yuv420p','v4/c6_video.mp4'],stdin=subprocess.PIPE)
written=0; pending=None; last=None; log={}
def write(fr):
    global written
    for f in fr:
        g=f.astype(np.float32)
        if written<36: g*=written/36.0                              # fade in from black
        if written>=NTOT-72: g*=max(0.0,(NTOT-1-written)/72.0)      # fade to black, last 3 s
        p.stdin.write(np.clip(g,0,255).astype(np.uint8).tobytes()); written+=1
for k,s in enumerate(EDL):
    name,src,a,b,tin,reg,ext,_=s
    if name=='hold': F=owl_hold(last,hold_n)
    else:
        if ext:
            t0,d,kind=ext
            A=frames(src,a,t0); X=ext_frames(A[-1],d,kind); Bf=frames(src,t0,b)
            F=np.concatenate([A,X,Bf])
        else: F=frames(src,a,b)
        if reg: F,nf=regularize(F,1.12 if name=='open' else 1.3); log[name]=nf
    assert len(F)==(hold_n if name=='hold' else seglen(s)), (name,len(F),seglen(s))
    if pending is not None:
        if tin=='fog':
            blend=[fog(pending[i],F[i],(i+1)/(FT+1),noise) for i in range(FT)]
            write(blend); F=F[FT:]
        else: write(pending)
    last=F[-1]
    if k<len(EDL)-1 and EDL[k+1][4]=='fog': write(F[:-FT]); pending=F[-FT:]
    else: write(F); pending=None
    print('seg',name,len(F),written,flush=True)
if pending is not None: write(pending)
p.stdin.close(); p.wait(); print('video frames',written,'reg',log)
# audio timeline
N=int(round(NTOT/FPS*SR)); amb=np.zeros((N,2),np.float32)
ov=int(round(FT/FPS*SR))
for k,(s,st) in enumerate(zip(EDL,starts)):
    name,src,a,b,tin,reg,ext,aud=s
    n_fr=hold_n if name=='hold' else seglen(s); dur=n_fr/FPS
    asrc,aa=(aud if aud else (src,a))
    x=load_audio(asrc,aa,dur)
    i0=int(round(st/FPS*SR)); L=min(len(x),N-i0); x=x[:L]
    fin=ov if tin=='fog' and k>0 else int(0.02*SR)
    fout=ov if (k<len(EDL)-1 and EDL[k+1][4]=='fog') else int(0.02*SR)
    r=np.ones(L,np.float32)
    r[:fin]=np.sin(np.linspace(0,np.pi/2,fin))
    if name!='hold': r[L-fout:]=np.minimum(r[L-fout:],np.cos(np.linspace(0,np.pi/2,fout)))
    amb[i0:i0+L]+=x*r[:,None]
# tail of ambience fades over the last 3.5 s
tl=int(3.5*SR); amb[-tl:]*=np.linspace(1,0,tl)[:,None]**1.5
mus=load_audio(TRACK,0.0,NTOT/FPS,norm=-24)
fl=int(3.0*SR); mus[-fl:]*=np.linspace(1,0,fl)[:,None]
fi=int(1.5*SR); mus[:fi]*=np.linspace(0,1,fi)[:,None]
mix=amb+mus
import soundfile as sf
sf.write('v4/c6_mix.wav',mix,SR,subtype='FLOAT')
subprocess.run(['ffmpeg','-v','error','-y','-i','v4/c6_video.mp4','-i','v4/c6_mix.wav','-af','alimiter=limit=0.9:level=false','-map','0:v','-map','1:a','-c:v','copy','-c:a','aac','-b:a','256k','-shortest','v4/Prairie_v6_Concrete_at_Our_Feet.mp4'],check=True)
json.dump({'starts':[(s[0],round(st/FPS,2)) for s,st in zip(EDL,starts)],'reg':log},open('v4/c6_edl.json','w'),ensure_ascii=False)
print('done')
