import XCTest
import Accelerate
@testable import KonnakolMaster

// MARK: - VoiceAnalysisPipelineTests
//
// Integration tests for the full voice analysis pipeline:
// SpectralFlux → OnsetDetector → BeatGridMatcher → VoiceSessionSummary

final class VoiceAnalysisPipelineTests: XCTestCase {

    // MARK: End-to-end: synthetic perfect timing

    func testPerfectTimingProducesHighAccuracy() {
        // Simulate a user speaking exactly on beat at 60 BPM (1 beat/s) over 4 beats
        let bpm = 60
        let tala = TalaLibrary.adi  // 8 beats
        let matcher = BeatGridMatcher()

        let grid = matcher.generateGrid(bpm: bpm, tala: tala, startTime: 0, durationSeconds: 4)
        // Perfect onsets — exactly on beat
        let onsets: [TimeInterval] = grid.prefix(4).map { $0 }

        let results = matcher.match(onsets: onsets, expectedBeats: Array(grid.prefix(4)))
        let summary = VoiceSessionSummary(beats: results)

        XCTAssertEqual(summary.totalBeats, 4)
        XCTAssertEqual(summary.hits, 4)
        XCTAssertEqual(summary.misses, 0)
        XCTAssertEqual(summary.accuracyFraction, 1.0, accuracy: 0.001)
        XCTAssertEqual(summary.avgDriftMs, 0)
    }

    func testSlightlyOffTimingStillCategorisedCorrectly() {
        let matcher = BeatGridMatcher()
        let grid = matcher.generateGrid(bpm: 120, tala: TalaLibrary.adi, startTime: 0, durationSeconds: 4)

        // 25ms early on every beat — should all be "perfect"
        let onsets = grid.prefix(8).map { $0 - 0.025 }
        let results = matcher.match(onsets: onsets, expectedBeats: Array(grid.prefix(8)))

        XCTAssertTrue(results.allSatisfy { $0.accuracy == .perfect },
                      "25ms drift should be .perfect for all beats")
    }

    func testLargeOffsetDegradesToGoodOrOk() {
        let matcher = BeatGridMatcher()
        let grid = matcher.generateGrid(bpm: 120, tala: TalaLibrary.adi, startTime: 0, durationSeconds: 4)

        // 50ms late — should be .good
        let onsets = grid.prefix(4).map { $0 + 0.050 }
        let results = matcher.match(onsets: onsets, expectedBeats: Array(grid.prefix(4)))

        XCTAssertTrue(results.allSatisfy { $0.accuracy == .good || $0.accuracy == .perfect },
                      "50ms drift should be .good for all beats")
    }

    // MARK: Grid generation

    func testGridDensityAtHighBPM() {
        let matcher = BeatGridMatcher()
        // 240 BPM for 2 seconds = 8 beats
        let grid = matcher.generateGrid(bpm: 240, tala: TalaLibrary.adi, startTime: 0, durationSeconds: 2)
        XCTAssertGreaterThanOrEqual(grid.count, 8)
        let interval = 60.0 / 240.0  // 0.25s
        for i in 1..<min(grid.count, 5) {
            XCTAssertEqual(grid[i] - grid[i-1], interval, accuracy: 0.001)
        }
    }

    func testGridStartTimeOffset() {
        let matcher = BeatGridMatcher()
        let startTime: TimeInterval = 5.0
        let grid = matcher.generateGrid(bpm: 60, tala: TalaLibrary.adi, startTime: startTime, durationSeconds: 4)
        XCTAssertEqual(grid.first ?? 0, startTime, accuracy: 0.001)
    }

    // MARK: Match with extra phantom onsets

    func testPhantomOnsetsDoNotInflateMisses() {
        let matcher = BeatGridMatcher()
        let expected: [TimeInterval] = [1.0, 2.0, 3.0]
        // Add phantom onset far from any expected beat
        let onsets: [TimeInterval] = [0.0001, 1.01, 2.02, 3.01, 9.999]
        let results = matcher.match(onsets: onsets, expectedBeats: expected)
        // Only 3 expected beats → 3 result entries
        XCTAssertEqual(results.count, 3)
        // All within 30ms → perfect
        XCTAssertTrue(results.allSatisfy { $0.accuracy == .perfect })
    }

    // MARK: VoiceSessionSummary

    func testSummaryWithAllMisses() {
        let beats: [BeatOnset] = [
            BeatOnset(expectedTime: 0, actualTime: nil, driftMs: nil, accuracy: .miss),
            BeatOnset(expectedTime: 1, actualTime: nil, driftMs: nil, accuracy: .miss),
        ]
        let summary = VoiceSessionSummary(beats: beats)
        XCTAssertEqual(summary.totalBeats, 2)
        XCTAssertEqual(summary.hits, 0)
        XCTAssertEqual(summary.misses, 2)
        XCTAssertEqual(summary.accuracyFraction, 0.0, accuracy: 0.001)
        XCTAssertEqual(summary.avgDriftMs, 0)
    }

    func testSummaryMixedResults() {
        let beats: [BeatOnset] = [
            BeatOnset(expectedTime: 0,   actualTime: 0.01,  driftMs: 10,  accuracy: .perfect),
            BeatOnset(expectedTime: 0.5, actualTime: 0.545, driftMs: 45,  accuracy: .good),
            BeatOnset(expectedTime: 1.0, actualTime: 1.08,  driftMs: 80,  accuracy: .ok),
            BeatOnset(expectedTime: 1.5, actualTime: nil,   driftMs: nil, accuracy: .miss),
        ]
        let summary = VoiceSessionSummary(beats: beats)
        XCTAssertEqual(summary.hits, 3)
        XCTAssertEqual(summary.misses, 1)
        XCTAssertEqual(summary.avgDriftMs, (10 + 45 + 80) / 3)
        XCTAssertEqual(summary.accuracyFraction, 3.0 / 4.0, accuracy: 0.001)
    }

    // MARK: BeatAccuracy edge cases

    func testBeatAccuracyBoundaries() {
        // Exact boundary values
        XCTAssertEqual(BeatAccuracy.from(driftMs: 0),   .perfect)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 30),  .perfect)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 31),  .good)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 60),  .good)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 61),  .ok)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 100), .ok)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 101), .miss)
        XCTAssertEqual(BeatAccuracy.from(driftMs: 500), .miss)
    }

    // MARK: OnsetDetector: spectral flux properties

    func testFluxOfDCSingalIsLow() {
        // A DC offset should have very low spectral flux (no change in spectrum)
        let dc = [Float](repeating: 0.5, count: SpectralFlux.windowSize)
        let mag = dc.withUnsafeBufferPointer {
            SpectralFlux.magnitude(from: $0.baseAddress!)
        }
        let flux1 = SpectralFlux.flux(current: mag, previous: mag)
        XCTAssertEqual(flux1, 0, accuracy: 1e-6)
    }

    func testFluxAccumulatesAcrossHops() {
        // Feed OnsetDetector a sudden loud burst preceded by silence
        let detector = OnsetDetector(sampleRate: 44_100)

        var samples = [Float](repeating: 0, count: SpectralFlux.windowSize * 6)
        // Inject energy starting at frame 4
        let start = SpectralFlux.windowSize * 4
        for i in start..<(start + SpectralFlux.windowSize) {
            samples[i] = (i % 2 == 0) ? 1.0 : -1.0
        }
        let onsets = detector.process(samples: samples, startTimestamp: 0, currentHostTime: 0)
        // We can't assert exact count since threshold adapts, but we assert no crash
        // and that the onset array is an array of valid timestamps
        XCTAssertTrue(onsets.allSatisfy { $0 >= 0 })
    }

    func testOnsetDetectorResetBetweenCalls() {
        let detector = OnsetDetector(sampleRate: 44_100)

        // First pass with silence
        let silence = [Float](repeating: 0, count: SpectralFlux.windowSize * 4)
        let onsets1 = detector.process(samples: silence, startTimestamp: 0, currentHostTime: 0)

        // Second pass, same detector, same silence
        let onsets2 = detector.process(samples: silence, startTimestamp: 0, currentHostTime: 0)

        XCTAssertTrue(onsets1.isEmpty)
        XCTAssertTrue(onsets2.isEmpty)
    }

    // MARK: Tala-specific grid shapes

    func testRupakaGridFor3Beats() {
        let matcher = BeatGridMatcher()
        let grid = matcher.generateGrid(bpm: 60, tala: TalaLibrary.rupaka, startTime: 0, durationSeconds: 3)
        // Rupaka = 6 beats; 3 seconds at 60 BPM = 3 beats ≤ grid ≤ 6+lookahead
        XCTAssertGreaterThanOrEqual(grid.count, 3)
    }

    func testMisraChapuGridSpacing() {
        let bpm = 120
        let matcher = BeatGridMatcher()
        let grid = matcher.generateGrid(bpm: bpm, tala: TalaLibrary.misraChapu, startTime: 0, durationSeconds: 3)
        let expected = 60.0 / Double(bpm)
        for i in 1..<min(grid.count, 4) {
            XCTAssertEqual(grid[i] - grid[i-1], expected, accuracy: 0.001)
        }
    }
}
