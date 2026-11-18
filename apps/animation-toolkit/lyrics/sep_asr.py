import sherpa_onnx as so, numpy as np, soundfile as sf, sys
M='/home/claude/models/'
cfg=so.OfflineSourceSeparationConfig(model=so.OfflineSourceSeparationModelConfig(
    spleeter=so.OfflineSourceSeparationSpleeterModelConfig(vocals=M+'sherpa-onnx-spleeter-2stems-fp16/vocals.fp16.onnx',accompaniment=M+'sherpa-onnx-spleeter-2stems-fp16/accompaniment.fp16.onnx'),num_threads=4))
sep=so.OfflineSourceSeparation(cfg)
x,sr=sf.read('lyr/concrete.wav',dtype='float32')
out=sep.process(sample_rate=sr,samples=np.ascontiguousarray(x.T))
voc=out.stems[0].data; print(type(voc), np.array(voc).shape, out.sample_rate)
v=np.array(voc); v=v.T if v.shape[0]==2 else v
sf.write('lyr/vocals.wav',v,out.sample_rate)
print('ok')
