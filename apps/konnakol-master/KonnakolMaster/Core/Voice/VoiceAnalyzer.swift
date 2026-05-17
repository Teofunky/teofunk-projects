import AVFoundation
import Observation

// MARK: - VoiceAnalyzer
//
// Public @Observable interface for voice onset detection.
//
// Usage (from a lesson step):
//   1. await analyzer.requestPermission()
//   2. await analyzer.calibrate()         — 1 second of room noise
//   3. analyzer.startListening(bpm:tala:)
//   4. ... user speaks ...
//   5. let result = analyzer.stopListening()
//
// Thread model:
//   - AVAudioEngine mic tap fires on a background audio thread.
//   - All DSP happens on `processingQueue` (serial, userInteractive).
//   - @Observable mutations happen on @MainActor via Task { @MainActor }.

@Observable
@MainActor
final class VoiceAnalyzer {

    // MARK: State

    enum State: Equatable {
        case idle
        case requestingPermission
        case calibrating
        case listening
        case stopped
        case permissionDenied
    }

    private(set) var state: State = .idle
    private(set) var detectedOnsets: [TimeInterval] = []
    private(set) var lastResult: VoiceSessionSummary?
    private(set) var permissionGranted: Bool = false

    // MARK: Private

    private let avEngine       = AVAudioEngine()
    private let detector       = OnsetDetector(sampleRate: 44_100)
    private let matcher        = BeatGridMatcher()
    private let processingQueue = DispatchQueue(
        label: "com.konnakolmaster.voice",
        qos: .userInteractive
    )

    private var sessionStartTime: TimeInterval = 0
    private var expectedBeats:    [TimeInterval] = []
    private var capturedOnsets:   [TimeInterval] = []
    private var isCapturingOnsets = false

    // MARK: - Public API

    func requestPermission() async {
        state = .requestingPermission
        let granted = await withCheckedContinuation { continuation in
            AVAudioApplication.requestRecordPermission { allowed in
                continuation.resume(returning: allowed)
            }
        }
        permissionGranted = granted
        state = granted ? .idle : .permissionDenied
    }

    /// Records ~1 second of ambient noise to establish the noise floor.
    /// Call before `startListening` at the beginning of each lesson session.
    func calibrate() async {
        guard permissionGranted else { return }
        state = .calibrating

        var calibrationSamples: [Float] = []
        let calibrationDuration: TimeInterval = 1.0

        do {
            try activateMicTap { [weak self] samples in
                self?.processingQueue.sync {
                    calibrationSamples.append(contentsOf: samples)
                }
            }
        } catch {
            state = .idle
            return
        }

        // Collect for 1 second
        try? await Task.sleep(for: .seconds(calibrationDuration))
        removeMicTap()

        processingQueue.sync {
            self.detector.calibrate(samples: calibrationSamples)
        }
        state = .idle
    }

    /// Begin onset detection aligned to a beat grid.
    func startListening(bpm: Int, tala: Tala, nadai: Nadai) {
        guard permissionGranted, state == .idle else { return }

        sessionStartTime  = Date().timeIntervalSinceReferenceDate
        capturedOnsets    = []
        isCapturingOnsets = true

        // Pre-generate beat grid for up to 3 minutes
        expectedBeats = matcher.generateGrid(
            bpm: bpm,
            tala: tala,
            startTime: sessionStartTime,
            durationSeconds: 180
        )

        processingQueue.sync { detector.reset() }

        do {
            try activateMicTap { [weak self] samples in
                guard let self, self.isCapturingOnsets else { return }
                let now = Date().timeIntervalSinceReferenceDate
                let newOnsets = self.processingQueue.sync {
                    self.detector.process(
                        samples: samples,
                        startTimestamp: self.sessionStartTime,
                        currentHostTime: now
                    )
                }
                if !newOnsets.isEmpty {
                    Task { @MainActor in
                        self.capturedOnsets.append(contentsOf: newOnsets)
                        self.detectedOnsets = self.capturedOnsets
                    }
                }
            }
            state = .listening
        } catch {
            state = .idle
        }
    }

    /// Stop detection, match onsets to beat grid, return summary.
    @discardableResult
    func stopListening() -> VoiceSessionSummary {
        isCapturingOnsets = false
        removeMicTap()
        state = .stopped

        // Only match beats that fall within the actual listening window
        let duration = Date().timeIntervalSinceReferenceDate - sessionStartTime
        let relevantBeats = expectedBeats.filter { $0 <= sessionStartTime + duration + 0.5 }
        let beatResults   = matcher.match(onsets: capturedOnsets, expectedBeats: relevantBeats)
        let summary       = VoiceSessionSummary(beats: beatResults)

        lastResult = summary
        state      = .idle
        return summary
    }

    // MARK: - Mic Tap

    private func activateMicTap(handler: @escaping ([Float]) -> Void) throws {
        let inputNode = avEngine.inputNode
        // Use the hardware's native format — guarantees format validity.
        // We read only channel 0, giving us the first (or only) channel.
        let nativeFormat = inputNode.outputFormat(forBus: 0)

        inputNode.installTap(onBus: 0, bufferSize: 512, format: nativeFormat) { buffer, _ in
            guard let channelData = buffer.floatChannelData?[0] else { return }
            let samples = Array(UnsafeBufferPointer(start: channelData, count: Int(buffer.frameLength)))
            handler(samples)
        }

        if !avEngine.isRunning {
            avEngine.prepare()
            try avEngine.start()
        }
    }

    private func removeMicTap() {
        avEngine.inputNode.removeTap(onBus: 0)
    }
}

// MARK: - Latency Compensation
//
// AVAudioSession.inputLatency on modern iPhones is typically 20–50ms.
// The VoiceAnalyzer timestamps onsets at system time, but the sound was
// actually produced inputLatency seconds earlier.
// When comparing to the AudioEngine beat grid (which uses sample-accurate
// AVAudioTime), subtract inputLatency from each onset timestamp.

extension VoiceAnalyzer {
    var inputLatencySeconds: TimeInterval {
        AVAudioSession.sharedInstance().inputLatency
    }
}
