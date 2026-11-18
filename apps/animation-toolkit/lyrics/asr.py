import sherpa_onnx as so, numpy as np, soundfile as sf
M='/home/claude/models/sherpa-onnx-whisper-medium.en/'
rec=so.OfflineRecognizer.from_whisper(encoder=M+'medium.en-encoder.int8.onnx',decoder=M+'medium.en-decoder.int8.onnx',tokens=M+'medium.en-tokens.txt',num_threads=6)
x,sr=sf.read('lyr/voc16.wav',dtype='float32')
# energy-based vocal segments
hop=int(0.05*sr); e=np.array([np.sqrt(np.mean(x[i:i+hop]**2)) for i in range(0,len(x)-hop,hop)])
thr=max(np.percentile(e,60)*1.2,0.01)
act=e>thr
# windows of 4 s with 2 s step
for t0 in np.arange(0,len(x)/sr,2.0):
    a=int(t0*sr); b=int(min((t0+4.0)*sr,len(x)))
    if act[int(t0/0.05):int((t0+4)/0.05)].mean()<0.15: continue
    s=rec.create_stream(); s.accept_waveform(sr,x[a:b]); rec.decode_stream(s)
    print(f'{t0:6.1f}-{t0+4:6.1f}  {s.result.text.strip()}',flush=True)
