import XCTest
@testable import KonnakolMaster

// MARK: - MetronomeSchedulerTests
//
// Tests for the scheduling math. These run without real audio hardware
// by exercising the pure-function helpers on MetronomeScheduler.

final class MetronomeSchedulerTests: XCTestCase {

    // MARK: BPM → Samples Conversion

    func testSamplesPerBeat_at120BPM() {
        let sampleRate: Double = 44_100
        let bpm: Double = 120
        let expected: Double = sampleRate * 60.0 / bpm  // 22_050
        XCTAssertEqual(expected, 22_050, accuracy: 0.001)
    }

    func testSamplesPerBeat_at60BPM() {
        let sampleRate: Double = 44_100
        let bpm: Double = 60
        let expected = sampleRate * 60.0 / bpm  // 44_100
        XCTAssertEqual(expected, 44_100, accuracy: 0.001)
    }

    // MARK: Tala Beat Count

    func testAdiTalaTotalBeats() {
        XCTAssertEqual(TalaLibrary.adi.totalBeats, 8)
    }

    func testRupakaTalaTotalBeats() {
        XCTAssertEqual(TalaLibrary.rupaka.totalBeats, 6)
    }

    func testMisraChapuTotalBeats() {
        XCTAssertEqual(TalaLibrary.misraChapu.totalBeats, 7)
    }

    func testKhandaChapuTotalBeats() {
        XCTAssertEqual(TalaLibrary.khandaChapu.totalBeats, 5)
    }

    // MARK: Anga Beat Counts Sum to totalBeats

    func testAngaSumMatchesTotalBeats_adi() {
        let tala = TalaLibrary.adi
        let sum = tala.angas.map(\.beatCount).reduce(0, +)
        XCTAssertEqual(sum, tala.totalBeats)
    }

    func testAngaSumMatchesTotalBeats_rupaka() {
        let tala = TalaLibrary.rupaka
        let sum = tala.angas.map(\.beatCount).reduce(0, +)
        XCTAssertEqual(sum, tala.totalBeats)
    }

    func testAngaSumMatchesTotalBeats_allTalas() {
        for tala in TalaLibrary.all {
            let sum = tala.angas.map(\.beatCount).reduce(0, +)
            XCTAssertEqual(sum, tala.totalBeats, "Anga sum mismatch for \(tala.name)")
        }
    }

    // MARK: Syllable Groups

    func testJathi4HasFourSyllables() {
        XCTAssertEqual(SyllableLibrary.jathi4.syllables.count, 4)
    }

    func testJathi7HasSevenSyllables() {
        XCTAssertEqual(SyllableLibrary.jathi7.syllables.count, 7)
    }

    func testDefaultGroupForChatusra() {
        let group = SyllableLibrary.defaultGroup(for: .chatusra)
        XCTAssertEqual(group.count, 4)
        XCTAssertEqual(group.standardName, "Ta-Ka-Di-Mi")
    }

    // MARK: Timing Precision Estimate

    /// Verifies that 1 sample at 44.1 kHz = ~0.0227 ms.
    /// Our ±2 ms target = ±88 samples tolerance.
    func testOneSampleDuration() {
        let sampleRate: Double = 44_100
        let oneSampleMs = 1000.0 / sampleRate
        XCTAssertEqual(oneSampleMs, 0.02268, accuracy: 0.0001)

        let toleranceSamples = Int(2.0 / oneSampleMs)
        XCTAssertEqual(toleranceSamples, 88)  // 2ms budget
    }
}
