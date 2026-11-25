import sys
from scenes import SCENES
for n in sys.argv[1:]:
    S=SCENES[n](); N=int(round(S.seconds*24))
    S.render(f'{n}.mp4',snap=(0,N//4,N//2,3*N//4,N-2)); print('rendered',n)
