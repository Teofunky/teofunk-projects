import json,sys,cv2,numpy as np
sys.path.insert(0,'/home/claude/teofunk-projects/apps/atelier-kit'); import mark
P='painting.png'
boxes={'f01':(155,22,292,108),'f02':(525,28,635,88),'f03':(640,38,735,98),'f04':(850,18,990,108),'f05':(705,108,790,162),
 'f06':(570,148,668,202),'f07':(12,152,95,212),'f08':(800,188,887,242),'f09':(135,218,225,278),'f10':(640,258,715,310),'f11':(1290,92,1368,148)}
im=cv2.imread(P).astype(np.float32); cells=[]; A={}
for k,b in boxes.items():
    a=mark.matte(P,b); A[k]=a; np.save(f'alpha_{k}.npy',a)
    x0,y0,x1,y1=b
    c=im[y0:y1,x0:x1]; al=a[y0:y1,x0:x1,None]
    comp=c*al+np.array([255,0,255],np.float32)*(1-al)   # fish over magenta
    row=np.hstack([c,comp]).astype(np.uint8)
    row=cv2.resize(row,(560,int(560*row.shape[0]/row.shape[1])),interpolation=cv2.INTER_CUBIC)
    row=cv2.copyMakeBorder(row,0,max(0,190-row.shape[0]),0,0,cv2.BORDER_CONSTANT)[:190]
    cv2.putText(row,k,(4,16),0,0.5,(0,255,255),1); cells.append(row)
cells.append(np.zeros_like(cells[0]))
cv2.imwrite('fish_matte.jpg',np.vstack([np.hstack(cells[i:i+2]) for i in range(0,len(cells),2)]))
