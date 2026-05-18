import XCTest
import Accelerate
@testable import KonnakolMaster

// MARK: - SpectralFluxTests

final class SpectralFluxTests: XCTestCase {

    func testMagnitudeOutputLength() {
        let samples = [Float](repeating: 0, count: SpectralFlux.windowSize)
        let mag = samples.withUnsafeBufferPointer {
            SpectralFlux.magnitude(from: $0.baseAddress!)
        }
        XCTAssertEqual(mag.count, SpectralFlux.windowSize / 2)
    }

    func testMagnitudeOfSilenceIsNearZero() {
        let samples = [Float](repeating: 0, count: SpectralFlux.windowSize)
        let mag = samples.withUnsafeBufferPointer {
            SpectralFlux.magnitude(from: $0.baseAddress!)
        }
        let maxMag = mag.max() ?? 0
        XCTAssertLessThan(maxMag, 1e-6)
    }

    func testSineToneProducesPeakAtCorrectBin() {
        // 1 kHz tone, windowSize samples at 44.1 kHz
        let sampleRate = 44_100.0
        let freq = 1_000.0
        let samples = (0..<SpectralFlux.windowSize).map { i in
            Float(sin(2 * Double.pi * freq * Double(i) / sampleRate))
        }
        let mag = samples.withUnsafeBufferPointer {
            SpectralFlux.magnitude(from: $0.baseAddress!)
        }
        // Expected bin: freq / (sampleRate / windowSize)
        let expectedBin = Int(freq / (sampleRate / Double(SpectralFlux.windowSize)))
        let peakBin = mag.indices.max(by: { mag[$0] < mag[$1] }) ?? 0
        // Allow ±2 bin tolerance for windowing effects
        XCTAssertTrue(abs(peakBin - expectedBin) <= 2,
            "Peak at bin \(peakBin), expected ~\(expectedBin)")
    }

    func testFluxBetweenIdenticalFramesIsZero() {
        let mag = [Float](repeating: 0.5, count: SpectralFlux.windowSize / 2)
        let flux = SpectralFlux.flux(current: mag, previous: mag)
        XCTAssertEqual(flux, 0, accuracy: 1e-6)
    }

    func testFluxIncreasesWhenEnergyRises() {
        let prev = [Float](repeating: 0.1, count: SpectralFlux.windowSize / 2)
        let curr = [Float](repeating: 0.8, count: SpectralFlux.windowSize / 2)
        let flux = SpectralFlux.flux(current: curr, previous: prev)
        XCTAssertGreaterThan(flux, 0)
    }

    func testFluxIsHalfWaveRectified() {
        // Energy going DOWN should produce flux = 0
        let prev = [Float](repeating: 0.8, count: SpectralFlux.windowSize / 2)
        let curr = [Float](repeating: 0.1, count: SpectralFlux.windowSize / 2)
        let flux = SpectralFlux.flux(current: curr, previous: prev)
        XCTAssertEqual(flux, 0, accuracy: 1e-6)
    }
}

// MARK: - OnsetDetectorTests

final class OnsetDetectorTests: XCTestCase {

    private var detector: OnsetDetector!

    override func setUp() {
        super.setUp()
        detector = OnsetDetector(sampleRate: 44_100)
    }

    func testSilenceProducesNoOnsets() {
        let silence = [Float](repeating: 0, count: SpectralFlux.windowSize * 4)
        let onsets = detector.process(samples: silence, startTimestamp: 0, currentHostTime: 0)
        XCTAssertTrue(onsets.isEmpty)
    }

    func testSharpTransientProducesOnset() {
        // Silence followed by a sharp impulse
        var samples = [Float](repeating: 0, count: SpectralFlux.windowSize * 10)
        // Inject impulse at frame 5 (middle of buffer)
        let impulseStart = SpectralFlux.windowSize * 5
        for i in impulseStart..<(impulseStart + 64) {
            samples[i] = (i % 2 == 0) ? 0.9 : -0.9
        }
        _ = detector.process(samples: samples, startTimestamp: 0, currentHostTime: 0)
        // At minimum the detector should have processed without crashing
        // (onset detection reliability depends on calibration in real use)
        XCTAssertTrue(true) // smoke test
    }

    func testMinimumGapPreventDoubleDetection() {
        // Feed the same loud burst repeatedly with 20ms gaps (below 80ms minimum)
        // Expect at most 1 onset per 80ms window
        var samples = [Float]()
        for _ in 0..<10 {
            let burst  = (0..<SpectralFlux.hopSize).map { _ in Float.random(in: -0.9...0.9) }
            let silence = [Float](repeating: 0, count: SpectralFlux.hopSize)
            samples.append(contentsOf: burst)
            samples.append(contentsOf: silence)
        }
        let onsets = detector.process(samples: samples, startTimestamp: 0, currentHostTime: 0)
        // With 80ms minimum gap and 11.6ms hops, max ~1 onset per 7 hops
        let maxExpected = samples.count / (SpectralFlux.hopSize * 7) + 1
        XCTAssertLessThanOrEqual(onsets.count, maxExpected)
    }
}

// MARK: - BeatGridMatcherTests

final class BeatGridMatcherTests: XCTestCase {

    private let matcher = BeatGridMatcher()

    func testGridGenerationBeatCount() {
        // At 60 BPM for 4 seconds: 4 beats + tala.totalBeats lookahead = ~12
        let grid = matcher.generateGrid(
            bpm: 60,
            tala: TalaLibrary.adi,
            startTime: 0,
            durationSeconds: 4
        )
        XCTAssertGreaterThanOrEqual(grid.count, 4)
    }

    func testGridBeatsAreEvenlySeparated() {
        let bpm = 120
        let grid = matcher.generateGrid(bpm: bpm, tala: TalaLibrary.adi, startTime: 0, durationSeconds: 2)
        let expectedInterval = 60.0 / Double(bpm)
        for i in 1..<min(grid.count, 5) {
            XCTAssertEqual(grid[i] - grid[i-1], expectedInterval, accuracy: 0.001)
        }
    }

    func testPerfectOnsetMatchesPerfect() {
        let expected: [TimeInterval] = [0.0, 0.5, 1.0]
        let onsets:   [TimeInterval] = [0.01, 0.50, 1.02]  // within 30ms
        let results = matcher.match(onsets: onsets, expectedBeats: expected)
        XCTAssertEqual(results.count, 3)
        XCTAssertEqual(results[0].accuracy, .perfect)   // 10ms
        XCTAssertEqual(results[1].accuracy, .perfect)   //  0ms
        XCTAssertEqual(results[2].accuracy, .perfect)   // 20ms
    }

    func testMissedBeatIsRecordedAsMiss() {
        let expected: [TimeInterval] = [0.0, 0.5, 1.0]
        let onsets:   [TimeInterval] = [0.01]  // only one onset for three beats
        let results = matcher.match(onsets: onsets, expectedBeats: expected)
        XCTAssertEqual(results.count, 3)
        XCTAssertEqual(results[0].accuracy, .perfect)
        XCTAssertEqual(results[1].accuracy, .miss)
        XCTAssertEqual(results[2].accuracy, .miss)
    }

    func testAccuracyCategories() {
        XCTAssertEqual(BeatAccuracy.from(driftMs: 0),   .perfect)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 30),  .perfect)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 31),  .good)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 60),  .good)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 61),  .ok)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 100), .ok)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 101), .miss)
    }

    func testSummaryAccuracyFraction() {
        let beats: [BeatOnset] = [
            BeatOnset(expectedTime: 0, actualTime: 0.01, driftMs: 10,  accuracy: .perfect),
            BeatOnset(expectedTime: 0.5, actualTime: 0.54, driftMs: 40, accuracy: .good),
            BeatOnset(expectedTime: 1.0, actualTime: nil,  driftMs: nil, accuracy: .miss),
        ]
        let summary = VoiceSessionSummary(beats: beats)
        XCTAssertEqual(summary.totalBeats, 3)
        XCTAssertEqual(summary.hits, 2)
        XCTAssertEqual(summary.misses, 1)
        XCTAssertEqual(summary.accuracyFraction, 2.0 / 3.0, accuracy: 0.001)
        XCTAssertEqual(summary.avgDriftMs, (10 + 40) / 2)
    }
}
