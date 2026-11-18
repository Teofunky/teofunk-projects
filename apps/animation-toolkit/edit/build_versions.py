import cv2, numpy as np, subprocess, sys, json, os
FPS=24; W,H=854,480; S=2; X=0.5
def dur(p): return float(subprocess.run(['ffprobe','-v','error','-show_entries','format=duration','-of','csv=p=0',p],capture_output=True,text=True).stdout)
def frames(src,a,b):
    raw=subprocess.run(['ffmpeg','-v','error','-ss',f'{a:.3f}','-i',src,'-t',f'{b-a:.3f}','-f','rawvideo','-pix_fmt','bgr24','-'],capture_output=True).stdout
    return np.frombuffer(raw,np.uint8).reshape(-1,H,W,3)
def onef(src,t): return frames(src,t,t+0.05)[0]
def ss(a,b,v):
    t=np.clip((v-a)/(b-a),0,1); return t*t*(3-2*t)
yy,xx=np.mgrid[0:H*S,0:W*S].astype(np.float32); y0=yy/S; x0=xx/S
def lowfreq(seed,scale):
    n=np.random.default_rng(seed).standard_normal((H//scale+3,W//scale+3)).astype(np.float32)
    return cv2.resize(cv2.GaussianBlur(n,(0,0),1),(W*S,H*S),interpolation=cv2.INTER_CUBIC)
N1,N2,N3=lowfreq(11,40),lowfreq(12,40),lowfreq(13,40)
# procedural continuation of a still landscape frame: a gust rises and calms, identity at both ends
EXT={'E02':dict(wind=(0,480),A=3.4,sky=None),
     'E05':dict(wind=(90,480),A=3.0,sky=(0,60)),
     'E06':dict(wind=(300,480),A=2.6,sky=(0,300)),
     'E08':dict(wind=(200,480),A=3.6,sky=(0,170))}
def ext_frames(f,d,kind):
    p=EXT[kind]; B=cv2.resize(f,(W*S,H*S),interpolation=cv2.INTER_LANCZOS4).astype(np.float32)
    wy=ss(p['wind'][0],p['wind'][0]+40,y0)*(1-ss(p['wind'][1]-10,p['wind'][1]+30,y0))
    sk=(1-ss(p['sky'][1]-20,p['sky'][1]+20,y0)) if p['sky'] else 0
    out=[]; n=int(round(d*FPS))
    for i in range(n):
        u=(i+1)/(n+1); env=0.5-0.5*np.cos(2*np.pi*u); t=i/FPS
        wave=np.sin(2*np.pi*(0.45*t-x0/240))+0.45*np.sin(2*np.pi*(1.1*t-x0/85)+1.7)
        gust=0.6+0.4*np.sin(2*np.pi*(t/3.5-x0/900))
        dx=env*(wy*p['A']*gust*wave + sk*(5.0*np.sin(np.pi*u)+1.5*N1))
        dy=env*(-0.15*np.abs(wy*p['A']*gust*wave)+sk*1.0*N2)
        o=cv2.remap(B,xx-(dx*S).astype(np.float32),yy-(dy*S).astype(np.float32),cv2.INTER_CUBIC,borderMode=cv2.BORDER_REFLECT)
        out.append(np.clip(cv2.resize(o,(W,H),interpolation=cv2.INTER_AREA),0,255).astype(np.uint8))
    return np.array(out)
def build_group(name,src,a,b,ins=None):
    """ins: (t, d, kind) procedural continuation inserted at t (hard joins, seamless)"""
    vpath=f'cut3/{name}.mp4'; apath=f'cut3/{name}.wav'
    p=subprocess.Popen(['ffmpeg','-v','error','-y','-f','rawvideo','-pix_fmt','bgr24','-s',f'{W}x{H}','-r',str(FPS),'-i','-','-c:v','libx264','-crf','15','-preset','fast','-pix_fmt','yuv420p',vpath],stdin=subprocess.PIPE)
    apieces=[]
    if ins and ins[1]>0.05:
        t,d,kind=ins
        A=frames(src,a,t); p.stdin.write(A.tobytes())
        E=ext_frames(onef(src,t),d,kind); p.stdin.write(E.tobytes())
        Bf=frames(src,t,b); p.stdin.write(Bf.tobytes())
        dl=dur(src); ea=(t,t+d) if t+d<=dl-0.1 else (t-d,t)
        apieces=[(a,t,len(A)),(ea[0],ea[1],len(E)),(t,b,len(Bf))]
    else:
        A=frames(src,a,b); p.stdin.write(A.tobytes()); apieces=[(a,b,len(A))]
    p.stdin.close(); p.wait()
    nfr=sum(x[2] for x in apieces)
    # audio: matching pieces, tiny fades at joins, then loudness -23 LUFS
    parts=[]; fc=[]
    for k,(s0,s1,nf) in enumerate(apieces):
        L=nf/FPS
        fc.append(f'[0:a]atrim={s0:.3f}:{s0+L:.3f},asetpts=PTS-STARTPTS,aresample=48000,apad=whole_dur={L:.3f},atrim=0:{L:.3f},afade=t=in:d=0.03,afade=t=out:st={max(L-0.03,0):.3f}:d=0.03[p{k}]')
        parts.append(f'[p{k}]')
    fc.append(''.join(parts)+f'concat=n={len(parts)}:v=0:a=1,loudnorm=I=-23:TP=-2:LRA=11,aresample=48000[o]')
    subprocess.run(['ffmpeg','-v','error','-y','-i',src,'-filter_complex',';'.join(fc),'-map','[o]','-ac','2',apath],check=True)
    return vpath,apath,nfr/FPS
def assemble(groups,music,out,hold,total_target):
    n=len(groups); inp=[]; fc=[]
    for g in groups: inp+=['-i',g[0]]
    for g in groups: inp+=['-i',g[1]]
    for i in range(n):
        v=f'[{i}:v]fps=24,settb=1/24,setpts=PTS-STARTPTS,format=yuv420p'
        if i==n-1: v+=f',tpad=stop_mode=clone:stop_duration={hold:.3f}'
        fc.append(v+f'[v{i}]')
        a=f'[{n+i}:a]asetpts=PTS-STARTPTS'
        if i==n-1: a+=f',apad=pad_dur={hold:.3f}'
        fc.append(a+f'[a{i}]')
    cur='v0'; cura='a0'; t=groups[0][2]; starts=[0]
    for i in range(1,n):
        off=t-X; starts.append(round(off,2))
        fc.append(f'[{cur}][v{i}]xfade=transition=fade:duration={X}:offset={off:.3f}[vx{i}]'); cur=f'vx{i}'
        fc.append(f'[{cura}][a{i}]acrossfade=d={X}[ax{i}]'); cura=f'ax{i}'
        t=off+groups[i][2]
    total=t+hold
    fc.append(f'[{cur}]fade=t=in:st=0:d=1.5,fade=t=out:st={total-2.5:.3f}:d=2.5[vout]')
    fc.append(f'[{2*n}:a]loudnorm=I=-24:TP=-2:LRA=11,aresample=48000,afade=t=in:st=0:d=1.5,apad=whole_dur={total:.3f},atrim=0:{total:.3f},afade=t=out:st={total-2:.3f}:d=2[mus]')
    fc.append(f'[{cura}]afade=t=out:st={total-2.5:.3f}:d=2.5[amb]')
    fc.append('[amb][mus]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.9[aout]')
    cmd=['ffmpeg','-v','error','-y']+inp+['-i',music,'-filter_complex',';'.join(fc),'-map','[vout]','-map','[aout]','-c:v','libx264','-crf','18','-preset','medium','-pix_fmt','yuv420p','-c:a','aac','-b:a','224k','-t',f'{total:.3f}',out]
    subprocess.run(cmd,check=True)
    return total,starts
