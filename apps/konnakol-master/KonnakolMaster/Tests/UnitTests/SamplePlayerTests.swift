import XCTest
import AVFoundation
@testable import KonnakolMaster

// MARK: - SamplePlayerTests
//
// Verifies that synthesised fallback buffers are valid even without WAV assets.

final class SamplePlayerTests: XCTestCase {

    private var format: AVAudioFormat!

    override func setUp() {
        super.setUp()
        format = AVAudioFormat(standardFormatWithSampleRate: 44_100, channels: 1)!
    }

    func testClickBufferIsNonEmpty() {
        let buf = SamplePlayer.buffer(named: "Click.wav", format: format)
        XCTAssertGreaterThan(buf.frameLength, 0)
    }

    func testSamBufferIsNonEmpty() {
        let buf = SamplePlayer.buffer(named: "Sam.wav", format: format)
        XCTAssertGreaterThan(buf.frameLength, 0)
    }

    func testAllSyllableBuffersAreNonEmpty() {
        let files = ["Ta.wav", "Ka.wav", "Di.wav", "Mi.wav", "Ki.wav",
                     "Tha.wav", "Tham.wav", "Dhin.wav"]
        for file in files {
            let buf = SamplePlayer.buffer(named: file, format: format)
            XCTAssertGreaterThan(buf.frameLength, 0, "\(file) produced an empty buffer")
        }
    }

    func testClickBufferPeakAmplitudeIsReasonable() {
        let buf = SamplePlayer.buffer(named: "Click.wav", format: format)
        guard let data = buf.floatChannelData?[0] else {
            return XCTFail("No float channel data")
        }
        let frames = Int(buf.frameLength)
        let peak = (0..<frames).map { abs(data[$0]) }.max() ?? 0
        XCTAssertGreaterThan(peak, 0.01, "Click is too quiet")
        XCTAssertLessThanOrEqual(peak, 1.0, "Click is clipping")
    }

    func testSineClickDoesNotClip() {
        let buf = SamplePlayer.sineClick(
            hz: 800, durationMs: 25, decayRate: 150, gain: 0.9, format: format
        )
        guard let data = buf.floatChannelData?[0] else { return XCTFail() }
        for i in 0..<Int(buf.frameLength) {
            XCTAssertLessThanOrEqual(abs(data[i]), 1.0, "Sample \(i) is clipping")
        }
    }
}
