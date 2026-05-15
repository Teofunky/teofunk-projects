import AVFoundation

// MARK: - MetronomeScheduler
//
// Sample-accurate beat scheduler built on AVAudioPlayerNode.
//
// Precision target: ±2 ms on iPhone 12+, ±5 ms on iPhone X/11.
//
// Strategy
// --------
// Beats are scheduled as pre-calculated AVAudioPCMBuffer instances at
// absolute AVAudioTime positions derived from a fixed sample-time anchor.
// A DispatchSourceTimer (1 ms resolution, high-priority queue) keeps the
// schedule filled `lookaheadBeats` beats ahead of the current render position.
// Buffer completion handlers update `currentBeat` on the main actor.
//
// BPM changes
// -----------
// 1. Stop the player node (flushes all pending buffers).
// 2. Re-derive a new anchor at the next beat boundary using the current
//    render time, so the transition sounds seamless.
// 3. Restart scheduling with the new tempo.
//
// Thread safety
// -------------
// - All mutable scheduling state is confined to `schedulingQueue`.
// - UI-visible state (currentBeat) is published via `Task { @MainActor in }.
// - `generationID` is an atomic sentinel that invalidates callbacks from
//   a previous run after stop() or BPM change.

final class MetronomeScheduler {

    // MARK: Constants

    /// Beats pre-scheduled ahead of current render position.
    private static let lookaheadBeats = 24

    /// Timer interval for the scheduling loop.
    private static let timerIntervalMs: Int = 8

    // MARK: Nodes

    private let clickNode = AVAudioPlayerNode()
    private let samNode   = AVAudioPlayerNode()   // separate node for Sam accent
    private let waveNode  = AVAudioPlayerNode()   // drutam wave sound

    // MARK: State accessed on schedulingQueue only

    private var sampleRate: Double = 44_100
    private var samplesPerBeat: Double = 44_100   // updated in start()
    private var anchorSampleTime: Int64 = 0       // sample index of beat 0
    private var nextBeatToSchedule: Int = 0       // absolute beat index
    private var currentTala: Tala = TalaLibrary.adi
    private var currentNadai: Nadai = .chatusra

    /// Incremented every time scheduling is torn down. Callbacks that carry
    /// a stale generation are silently ignored.
    private var generationID: Int = 0

    // MARK: Buffers

    private var clickBuffer: AVAudioPCMBuffer?
    private var samBuffer: AVAudioPCMBuffer?
    private var waveBuffer: AVAudioPCMBuffer?

    // MARK: Timer

    private let schedulingQueue = DispatchQueue(
        label: "com.konnakolmaster.metronome",
        qos: .userInteractive
    )
    private var schedulingTimer: DispatchSourceTimer?

    // MARK: Callback to AudioEngine

    var onBeatDidChange: ((Int) -> Void)?

    // MARK: Init

    init() {}

    // MARK: Setup

    func attach(to engine: AVAudioEngine) {
        engine.attach(clickNode)
        engine.attach(samNode)
        engine.attach(waveNode)

        let format = engine.mainMixerNode.outputFormat(forBus: 0)
        sampleRate = format.sampleRate

        engine.connect(clickNode, to: engine.mainMixerNode, format: format)
        engine.connect(samNode,   to: engine.mainMixerNode, format: format)
        engine.connect(waveNode,  to: engine.mainMixerNode, format: format)

        loadBuffers(format: format)
    }

    private func loadBuffers(format: AVAudioFormat) {
        clickBuffer = SamplePlayer.buffer(named: "Click.wav", format: format)
        samBuffer   = SamplePlayer.buffer(named: "Sam.wav",   format: format)
        waveBuffer  = SamplePlayer.buffer(named: "Wave.wav",  format: format)
    }

    // MARK: Start / Stop

    func start(bpm: Int, tala: Tala, nadai: Nadai) {
        schedulingQueue.sync {
            _stop()
            generationID += 1

            currentTala = tala
            currentNadai = nadai
            samplesPerBeat = sampleRate * 60.0 / Double(bpm)

            clickNode.play()
            samNode.play()
            waveNode.play()

            // Anchor: current render position + short warm-up (50ms) to guarantee
            // the first scheduled buffer has time to reach the hardware.
            let warmupSamples = Int64(sampleRate * 0.05)
            anchorSampleTime = (clickNode.lastRenderTime?.sampleTime ?? 0) + warmupSamples
            nextBeatToSchedule = 0

            fillSchedule(generation: generationID)
            startTimer(generation: generationID)
        }
    }

    func stop() {
        schedulingQueue.sync { _stop() }
    }

    func changeBPM(_ newBPM: Int, tala: Tala, nadai: Nadai) {
        schedulingQueue.sync {
            guard let renderTime = clickNode.lastRenderTime else { return }

            _stop()
            generationID += 1

            currentTala = tala
            currentNadai = nadai
            let oldSPB = samplesPerBeat
            samplesPerBeat = sampleRate * 60.0 / Double(newBPM)

            // Find the next beat boundary in the old timeline, then re-anchor there.
            let elapsed = renderTime.sampleTime - anchorSampleTime
            let beatsElapsed = max(0, Int(Double(elapsed) / oldSPB))
            let nextBeatAbsolute = beatsElapsed + 1
            anchorSampleTime = renderTime.sampleTime +
                Int64(Double(nextBeatAbsolute) * samplesPerBeat - Double(elapsed))
            nextBeatToSchedule = nextBeatAbsolute

            clickNode.play()
            samNode.play()
            waveNode.play()

            fillSchedule(generation: generationID)
            startTimer(generation: generationID)
        }
    }

    // MARK: Private Scheduling (call only on schedulingQueue)

    private func _stop() {
        schedulingTimer?.cancel()
        schedulingTimer = nil
        clickNode.stop()
        samNode.stop()
        waveNode.stop()
    }

    private func startTimer(generation: Int) {
        let timer = DispatchSource.makeTimerSource(queue: schedulingQueue)
        timer.schedule(
            deadline: .now(),
            repeating: .milliseconds(Self.timerIntervalMs),
            leeway: .milliseconds(1)
        )
        timer.setEventHandler { [weak self] in
            guard let self, self.generationID == generation else { return }
            self.fillSchedule(generation: generation)
            self.updateCurrentBeat()
        }
        timer.resume()
        schedulingTimer = timer
    }

    /// Schedule beats until the lookahead window is filled.
    private func fillSchedule(generation: Int) {
        guard let renderTime = clickNode.lastRenderTime else { return }
        let currentSample = renderTime.sampleTime
        let lookaheadSamples = Int64(samplesPerBeat * Double(Self.lookaheadBeats))
        let scheduleUpTo = currentSample + lookaheadSamples

        while sampleTime(forAbsoluteBeat: nextBeatToSchedule) <= scheduleUpTo {
            scheduleBeat(absoluteIndex: nextBeatToSchedule, generation: generation)
            nextBeatToSchedule += 1
        }
    }

    private func scheduleBeat(absoluteIndex: Int, generation: Int) {
        let beatInCycle = absoluteIndex % currentTala.totalBeats
        let st = sampleTime(forAbsoluteBeat: absoluteIndex)
        let audioTime = AVAudioTime(sampleTime: st, atRate: sampleRate)

        let isSam = beatInCycle == 0
        let angaGesture = gesture(forBeat: beatInCycle, in: currentTala)

        // Sam beat: play the accent tone
        if isSam, let buf = samBuffer {
            samNode.scheduleBuffer(buf, at: audioTime, options: [], completionCallbackType: .dataConsumed) { _ in }
        } else if let buf = clickBuffer {
            clickNode.scheduleBuffer(buf, at: audioTime, options: [], completionCallbackType: .dataConsumed) { _ in }
        }

        // Wave sound for drutam (wave gesture)
        if angaGesture == .wave, let buf = waveBuffer {
            let waveOffset = Int64(samplesPerBeat * 0.5) // play wave on the half-beat
            let waveTime = AVAudioTime(sampleTime: st + waveOffset, atRate: sampleRate)
            waveNode.scheduleBuffer(buf, at: waveTime, options: [], completionCallbackType: .dataConsumed) { _ in }
        }
    }

    private func updateCurrentBeat() {
        guard let renderTime = clickNode.lastRenderTime else { return }
        let elapsed = renderTime.sampleTime - anchorSampleTime
        guard elapsed >= 0 else { return }
        let absoluteBeat = Int(Double(elapsed) / samplesPerBeat)
        let beatInCycle  = absoluteBeat % currentTala.totalBeats
        let cb = onBeatDidChange
        DispatchQueue.main.async { cb?(beatInCycle) }
    }

    private func sampleTime(forAbsoluteBeat n: Int) -> Int64 {
        anchorSampleTime + Int64(Double(n) * samplesPerBeat)
    }
}

// MARK: - Gesture Helper

private enum BeatGesture { case clap, finger, wave }

private func gesture(forBeat beat: Int, in tala: Tala) -> BeatGesture {
    var cursor = 0
    for anga in tala.angas {
        let end = cursor + anga.beatCount
        if beat >= cursor && beat < end {
            switch anga {
            case .anudrutam:       return beat == cursor ? .clap : .clap
            case .drutam:          return beat == cursor ? .clap : .wave
            case .laghu:           return beat == cursor ? .clap : .finger
            }
        }
        cursor = end
    }
    return .clap
}
