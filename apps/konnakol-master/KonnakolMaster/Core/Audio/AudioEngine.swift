import Foundation
import AVFoundation
import Observation

// MARK: - AudioEngine
//
// Stub for Task 1. Full sample-accurate implementation is Task 2.
// All public API surface is defined here so call-sites compile today.

@Observable
final class AudioEngine {

    // MARK: Published State

    /// Zero-indexed beat within the current tala cycle.
    private(set) var currentBeat: Int = 0

    /// Whether the metronome is actively running.
    private(set) var isPlaying: Bool = false

    // MARK: Private

    private var avEngine = AVAudioEngine()

    // MARK: Public Interface

    /// Start the metronome for the given tala, nadai, and BPM.
    /// - Parameters:
    ///   - bpm: Beats per minute (30–300).
    ///   - tala: The rhythmic cycle to use.
    ///   - nadai: Subdivision count per beat.
    func start(bpm: Int, tala: Tala, nadai: Nadai) {
        // Full implementation in Task 2 (AudioEngine deep-dive).
        isPlaying = true
    }

    /// Stop playback immediately.
    func stop() {
        isPlaying = false
        currentBeat = 0
    }

    /// Smoothly transition to a new BPM without a timing glitch.
    /// The change takes effect at the next beat boundary.
    func changeBPM(_ newBPM: Int) {
        // Full implementation in Task 2.
    }
}
