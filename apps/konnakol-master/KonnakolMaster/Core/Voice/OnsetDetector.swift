import Foundation
import Accelerate

// MARK: - OnsetDetector
//
// Accumulates audio frames and emits onset timestamps using
// spectral-flux + adaptive thresholding + peak picking.
//
// Not thread-safe: call all methods from the same serial audio queue.

final class OnsetDetector {

    // MARK: Configuration

    let sampleRate: Double

    /// Minimum gap between two reported onsets (seconds).
    /// Prevents double-triggering on a single consonant burst.
    private let minOnsetGap: Double = 0.08   // 80 ms

    /// Threshold multiplier above the running mean.
    /// Higher = fewer false positives; lower = more sensitive.
    private var thresholdK: Float = 1.5

    /// How many flux frames to keep for the running statistics window.
    private let statsWindowSize: Int = 120   // ~1.4 seconds of frames

    // MARK: State

    private var analysisBuffer: [Float] = []  // samples accumulator
    private var prevMagnitude: SpectralFlux.Magnitude = Array(
        repeating: 0, count: SpectralFlux.windowSize / 2
    )
    private var fluxHistory: [Float] = []     // for adaptive threshold
    private var hopCount: Int = 0             // frames processed since reset
    private var lastOnsetHop: Int = -1000     // for minimum-gap enforcement
    private var noiseFloorFlux: Float = 0     // set during calibration

    // MARK: Init

    init(sampleRate: Double = 44_100) {
        self.sampleRate = sampleRate
    }

    // MARK: Calibration
    //
    // Feed 1 second of silence (or room noise) before the lesson begins.
    // Establishes the noise floor so background noise doesn't trigger onsets.

    func calibrate(samples: [Float]) {
        var frames: [Float] = []
        var pos = 0
        while pos + SpectralFlux.windowSize <= samples.count {
            let chunk = Array(samples[pos..<(pos + SpectralFlux.windowSize)])
            let mag   = chunk.withUnsafeBufferPointer { SpectralFlux.magnitude(from: $0.baseAddress!) }
            let flux  = SpectralFlux.flux(current: mag, previous: prevMagnitude)
            frames.append(flux)
            prevMagnitude = mag
            pos += SpectralFlux.hopSize
        }
        if !frames.isEmpty {
            noiseFloorFlux = frames.reduce(0, +) / Float(frames.count) * 1.5
        }
        reset()
    }

    /// Reset internal state but keep calibrated noise floor.
    func reset() {
        analysisBuffer = []
        prevMagnitude  = Array(repeating: 0, count: SpectralFlux.windowSize / 2)
        fluxHistory    = []
        hopCount       = 0
        lastOnsetHop   = -1000
    }

    // MARK: Processing
    //
    // Feed raw PCM samples (Float32, mono, sampleRate Hz) from the mic tap.
    // Returns onset timestamps in seconds since the session started,
    // relative to the startTimestamp anchor.

    func process(samples: [Float], startTimestamp: Double, currentHostTime: Double) -> [Double] {
        analysisBuffer.append(contentsOf: samples)
        var onsets: [Double] = []

        while analysisBuffer.count >= SpectralFlux.windowSize {
            let window = Array(analysisBuffer.prefix(SpectralFlux.windowSize))
            analysisBuffer.removeFirst(SpectralFlux.hopSize)

            let mag  = window.withUnsafeBufferPointer { SpectralFlux.magnitude(from: $0.baseAddress!) }
            let flux = SpectralFlux.flux(current: mag, previous: prevMagnitude)
            prevMagnitude = mag
            fluxHistory.append(flux)
            if fluxHistory.count > statsWindowSize { fluxHistory.removeFirst() }
            hopCount += 1

            if isOnset(flux: flux) {
                let hopTime = Double(hopCount) * Double(SpectralFlux.hopSize) / sampleRate
                let timestamp = startTimestamp + hopTime
                onsets.append(timestamp)
                lastOnsetHop = hopCount
            }
        }
        return onsets
    }

    // MARK: Private — Onset Decision

    private func isOnset(flux: Float) -> Bool {
        guard fluxHistory.count > 10 else { return false }

        // Must exceed noise floor
        guard flux > noiseFloorFlux else { return false }

        // Adaptive threshold: mean + k * std of recent history
        let threshold = adaptiveThreshold()
        guard flux > threshold else { return false }

        // Minimum gap enforcement
        let minHopGap = Int(minOnsetGap * sampleRate / Double(SpectralFlux.hopSize))
        guard hopCount - lastOnsetHop >= minHopGap else { return false }

        // Local maximum check: this frame > previous flux (rising edge only)
        if fluxHistory.count >= 2 {
            let prev = fluxHistory[fluxHistory.count - 2]
            guard flux > prev else { return false }
        }

        return true
    }

    private func adaptiveThreshold() -> Float {
        let n     = Float(fluxHistory.count)
        let mean  = fluxHistory.reduce(0, +) / n
        let variance = fluxHistory.reduce(0) { $0 + ($1 - mean) * ($1 - mean) } / n
        let std   = variance.squareRoot()
        return mean + thresholdK * std + noiseFloorFlux
    }
}
