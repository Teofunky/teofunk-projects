import zipfile, pickle, numpy as np, onnx
from onnx import helper, numpy_helper, TensorProto
z=zipfile.ZipFile('realesr-general-x4v3.pth'); pre='archive/'
class St:
    def __init__(s,key,dtype): s.key=key; s.dtype=dtype
def rebuild(storage,offset,size,stride,*a):
    buf=np.frombuffer(z.read(pre+'data/'+storage.key),dtype=storage.dtype)
    n=int(np.prod(size)) if len(size) else 1
    arr=np.lib.stride_tricks.as_strided(buf[offset:],shape=size,strides=[s*buf.itemsize for s in stride]) if len(size) else buf[offset:offset+1]
    return np.array(arr)
class U(pickle.Unpickler):
    def find_class(self,mod,name):
        if name=='_rebuild_tensor_v2': return rebuild
        if name=='FloatStorage': return np.float32
        if name=='HalfStorage': return np.float16
        if mod=='collections' and name=='OrderedDict':
            import collections; return collections.OrderedDict
        return super().find_class(mod,name)
    def persistent_load(self,pid):
        _,cls,key,loc,n=pid; return St(key,np.dtype(cls) if not isinstance(cls,type) else cls)
sd=U(z.open(pre+'data.pkl')).load()
sd=sd.get('params',sd)
keys=sorted(sd.keys(),key=lambda k:(int(k.split('.')[1]),k)); print(len(keys),keys[:4],keys[-2:])
nodes=[];inits=[];cur='x'
idx=sorted(set(int(k.split('.')[1]) for k in keys))
for i in idx:
    w=sd[f'body.{i}.weight'].astype(np.float32)
    if w.ndim==4:
        b=sd[f'body.{i}.bias'].astype(np.float32)
        inits+= [numpy_helper.from_array(w,f'w{i}'),numpy_helper.from_array(b,f'b{i}')]
        nodes.append(helper.make_node('Conv',[cur,f'w{i}',f'b{i}'],[f'c{i}'],pads=[1,1,1,1],kernel_shape=[3,3])); cur=f'c{i}'
    else:
        inits.append(numpy_helper.from_array(w.reshape(-1,1,1),f'p{i}'))
        nodes.append(helper.make_node('PRelu',[cur,f'p{i}'],[f'a{i}'])); cur=f'a{i}'
nodes.append(helper.make_node('DepthToSpace',[cur],['ps'],blocksize=4,mode='CRD'))
inits.append(numpy_helper.from_array(np.array([1,1,4,4],np.float32),'scales'))
inits.append(numpy_helper.from_array(np.array([],np.float32),'roi'))
nodes.append(helper.make_node('Resize',['x','roi','scales'],['up'],mode='nearest'))
nodes.append(helper.make_node('Add',['ps','up'],['y']))
g=helper.make_graph(nodes,'srvgg',[helper.make_tensor_value_info('x',TensorProto.FLOAT,[1,3,None,None])],[helper.make_tensor_value_info('y',TensorProto.FLOAT,[1,3,None,None])],inits)
m=helper.make_model(g,opset_imports=[helper.make_opsetid('',13)]); m.ir_version=8
onnx.save(m,'srvgg_x4.onnx'); print('saved')
