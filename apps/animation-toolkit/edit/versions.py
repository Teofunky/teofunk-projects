import sys, json
from build_versions import *
TR={'A':('audio/Desert_Echoes.mp3','Prairie_v3_Desert_Echoes'),'B':('audio/Untitled.mp3','Prairie_v3_Untitled'),
    'C':('audio/Concrete_A.mp3','Prairie_v3_Concrete_at_Our_Feet'),'D':('audio/Bison.mp3','Prairie_v3_Bison')}
def long_plan(T):
    base=163.39+4.2-11*X+4.0          # R=4.2, hold 4
    extra=T-base; caps={'R':4.8,'E05':3.0,'E06':3.0,'E08':2.5}; cs=sum(caps.values())
    f=min(max(extra/cs,0),1); e={k:v*f for k,v in caps.items()}
    hold=4.0+max(0,extra-cs)
    return e,hold
def run(v):
    music,name=TR[v]; T=dur(music)
    if v=='C':
        x=None; hold=3.5
        fixed=[('E01','clips/E01.mp4',0,20.06,None),('E02','clips/E02_fix.mp4',0,None,None),('E10','clips/E10w.mp4',0,11.4,None),
               ('E03','clips/E03_cut.mp4',5.8,14.62,None),('E04','clips/E04_fix2.mp4',0,12.8,None),('E05','clips/E05_fix.mp4',4.9,13.8,None),
               ('E06','clips/E06_fix.mp4',0,18.26,None),('E07','clips/E07_cut.mp4',0,14.92,None),('E08a','clips/E08_fix_crow.mp4',0,7.42,None),
               ('E08b','clips/E08_fix_crow.mp4',12.0,19.46,None),('E09r','clips/E09_river_long.mp4',0,4.5,None),('E09o','clips/E09_fix3.mp4',14.9,19.62,None)]
        known=sum(b-a for _,_,a,b,_ in fixed if b is not None)
        x=T-hold-(known-(len(fixed)-1)*X)
        plan=[(n,s,a,(x if b is None else b),ins) for n,s,a,b,ins in fixed]
    else:
        e,hold=long_plan(T)
        plan=[('E01','clips/E01.mp4',0,20.06,None),('E02','clips/E02_fix.mp4',0,15.46,None),('E10','clips/E10w.mp4',0,13.29,None),
              ('E03','clips/E03_cut.mp4',0,14.62,None),('E04','clips/E04_fix2.mp4',0,17.86,None),('E05','clips/E05_fix.mp4',0,18.81,(7.0,e['E05'],'E05')),
              ('E06','clips/E06_fix.mp4',0,18.26,(12.0,e['E06'],'E06')),('E07','clips/E07_cut.mp4',0,14.92,None),
              ('E08a','clips/E08_fix_crow.mp4',0,7.42,(2.0,e['E08'],'E08')),('E08b','clips/E08_fix_crow.mp4',12.0,19.46,None),
              ('E09r','clips/E09_river_long.mp4',0,4.2+e['R'],None),('E09b','clips/E09_fix3.mp4',4.4,19.62,None)]
    groups=[]
    for n,s,a,b,ins in plan:
        groups.append(build_group(f'{v}_{n}',s,a,b,ins)); print(v,n,round(groups[-1][2],2),flush=True)
    t=sum(g[2] for g in groups)-(len(groups)-1)*X
    hold=T-t                                      # exact fit to the track
    total,starts=assemble(groups,music,f'cut3/{name}.mp4',hold,T)
    info={'version':v,'music':music,'track':round(T,2),'total':round(total,2),'hold':round(hold,2),'starts':starts,'plan':[(n,a,round(b,2),ins) for n,s,a,b,ins in plan]}
    print(json.dumps(info,ensure_ascii=False),flush=True)
    json.dump(info,open(f'cut3/{name}.json','w'),ensure_ascii=False)
for v in sys.argv[1:]: run(v)
