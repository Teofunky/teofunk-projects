import AVFoundation
import Observation

// MARK: - AudioEngine
//
// Public interface for all audio in Konnakol Master.
// Owns the AVAudioEngine and MetronomeScheduler; exposed to SwiftUI
// views via the environment as an @Observable object.
//
// Thread model
// ------------
// - All `@Observable` property mutations happen on @MainActor.
// - MetronomeScheduler's internal scheduling runs on its own high-priority
//   queue; it calls back to AudioEngine via `onBeatDidChange` which dispatches
//   to main automatically.
// - AVAudioEngine start/stop is called synchronously on the calling actor;
//   calling from a Task is fine.

@Observable
@MainActor
final class AudioEngine {

    // MARK: Observable State (drives UI)

    /// Zero-indexed beat within the current tala cycle (updates ~every beat).
    private(set) var currentBeat: Int = 0

    /// Whether the metronome is actively running.
    private(set) var isPlaying: Bool = false

    /// Current BPM, kept in sync after `changeBPM(_:)` calls.
    private(set) var bpm: Int = 80

    /// Active tala.
    private(set) var tala: Tala = TalaLibrary.adi

    /// Active nadai.
    private(set) var nadai: Nadai = .chatusra

    /// Non-nil when the engine failed to start — surface to the user.
    private(set) var engineError: AudioEngineError?

    // MARK: Private

    private let avEngine       = AVAudioEngine()
    private let scheduler      = MetronomeScheduler()
    private var isEngineReady  = false

    // MARK: Init

    init() {
        scheduler.onBeatDidChange = { [weak self] beat in
            self?.currentBeat = beat
        }
    }

    // MARK: - Public API

    /// Start the metronome.
    /// Activates AVAudioSession and starts the engine on first call.
    func start(bpm: Int? = nil, tala: Tala? = nil, nadai: Nadai? = nil) {
        if let bpm   { self.bpm   = bpm   }
        if let tala  { self.tala  = tala  }
        if let nadai { self.nadai = nadai }

        do {
            try prepareEngineIfNeeded()
        } catch {
            engineError = .setupFailed(error)
            return
        }

        scheduler.start(bpm: self.bpm, tala: self.tala, nadai: self.nadai)
        isPlaying = true
        engineError = nil
    }

    /// Stop the metronome. Does not tear down AVAudioEngine.
    func stop() {
        scheduler.stop()
        isPlaying = false
        currentBeat = 0
    }

    /// Change BPM while running. Transition is sample-accurate:
    /// the new tempo takes effect at the next beat boundary.
    func changeBPM(_ newBPM: Int) {
        bpm = newBPM.clamped(to: 30...300)
        guard isPlaying else { return }
        scheduler.changeBPM(bpm, tala: tala, nadai: nadai)
    }

    /// Switch tala. Restarts scheduling from beat 0.
    func changeTala(_ newTala: Tala) {
        tala = newTala
        guard isPlaying else { return }
        scheduler.start(bpm: bpm, tala: tala, nadai: nadai)
        currentBeat = 0
    }

    /// Switch nadai subdivision. Restarts scheduling.
    func changeNadai(_ newNadai: Nadai) {
        nadai = newNadai
        guard isPlaying else { return }
        scheduler.start(bpm: bpm, tala: tala, nadai: nadai)
    }

    // MARK: - Private

    private func prepareEngineIfNeeded() throws {
        guard !isEngineReady else { return }

        try AudioSessionManager.activate()

        // Attach scheduler nodes before starting the engine.
        scheduler.attach(to: avEngine)

        avEngine.prepare()
        try avEngine.start()

        isEngineReady = true

        // Re-configure if AVAudioSession resets (e.g. phone call interruption).
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleInterruption),
            name: AVAudioSession.interruptionNotification,
            object: nil
        )
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(handleRouteChange),
            name: AVAudioSession.routeChangeNotification,
            object: nil
        )
    }

    // MARK: Interruption Handling

    @objc private func handleInterruption(_ notification: Notification) {
        guard
            let info = notification.userInfo,
            let typeValue = info[AVAudioSessionInterruptionTypeKey] as? UInt,
            let type = AVAudioSession.InterruptionType(rawValue: typeValue)
        else { return }

        switch type {
        case .began:
            // System interrupted (call, Siri). Stop gracefully.
            if isPlaying { stop() }

        case .ended:
            // Reactivate session; user must tap play again.
            let shouldResume = (info[AVAudioSessionInterruptionOptionKey] as? UInt)
                .flatMap(AVAudioSession.InterruptionOptions.init)
                .map { $0.contains(.shouldResume) } ?? false

            try? AudioSessionManager.activate()
            if shouldResume && isPlaying {
                start()
            }

        @unknown default:
            break
        }
    }

    @objc private func handleRouteChange(_ notification: Notification) {
        guard
            let info = notification.userInfo,
            let reasonValue = info[AVAudioSessionRouteChangeReasonKey] as? UInt,
            let reason = AVAudioSession.RouteChangeReason(rawValue: reasonValue)
        else { return }

        // Headphones unplugged — pause to avoid blasting speakers unexpectedly.
        if reason == .oldDeviceUnavailable && isPlaying {
            stop()
        }
    }
}

// MARK: - AudioEngineError

enum AudioEngineError: Error, LocalizedError {
    case setupFailed(Error)

    var errorDescription: String? {
        switch self {
        case .setupFailed(let underlying):
            return "Audio engine failed to start: \(underlying.localizedDescription)"
        }
    }
}

// MARK: - Int Clamp Helper

private extension Int {
    func clamped(to range: ClosedRange<Int>) -> Int {
        max(range.lowerBound, min(self, range.upperBound))
    }
}
