import numpy as np,subprocess,sys,json
f=sys.argv[1]
raw=subprocess.run(['ffmpeg','-v','error','-i',f,'-vf','scale=160:90','-f','rawvideo','-pix_fmt','gray','-'],capture_output=True).stdout
g=np.frombuffer(raw,np.uint8).reshape(-1,90,160).astype(np.float32)
d=np.array([np.abs(g[i+1]-g[i]).mean() for i in range(len(g)-1)])
runs=[];s=None
for i,v in enumerate(d):
    if v<0.15: s=i if s is None else s
    else:
        if s is not None and i-s>36: runs.append((round(s/24,1),round(i/24,1)))
        s=None
r=subprocess.run(['ffmpeg','-v','info','-i',f,'-af','ebur128=framelog=quiet','-f','null','-'],capture_output=True,text=True).stderr
I=[l.strip() for l in r.splitlines() if 'I:' in l][-1]
print(f, 'dur', round(len(g)/24,2), 'static', runs, I)
