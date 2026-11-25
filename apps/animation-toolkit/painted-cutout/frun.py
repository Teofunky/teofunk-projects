import sys, time
from fscenes import SCENES
for n in sys.argv[1:]:
    t=time.time(); S=SCENES[n](); N=int(round(S.seconds*24))
    S.render(f'{n}.mp4',snap=(0,N//4,N//2,3*N//4,N-2),step=getattr(S,'step',2)); print('rendered',n,round(time.time()-t,1),'s')
