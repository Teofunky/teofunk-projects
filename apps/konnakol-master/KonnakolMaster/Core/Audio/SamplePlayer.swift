import AVFoundation
import Accelerate

// MARK: - SamplePlayer
//
// Loads click/sam/syllable audio buffers from the app bundle.
// Falls back to synthesised tones when WAV files are absent so the
// metronome is functional from day 1 even without recorded assets.

enum SamplePlayer {

    // MARK: Public API

    /// Load a named WAV from Resources/Sounds/, or synthesise a fallback.
    static func buffer(named filename: String, format: AVAudioFormat) -> AVAudioPCMBuffer {
        if let loaded = loadFromBundle(filename: filename, format: format) {
            return loaded
        }
        return synthesise(for: filename, format: format)
    }

    // MARK: Bundle Loading

    private static func loadFromBundle(filename: String, format: AVAudioFormat) -> AVAudioPCMBuffer? {
        let name = (filename as NSString).deletingPathExtension
        let ext  = (filename as NSString).pathExtension.isEmpty ? "wav" : (filename as NSString).pathExtension

        guard let url = Bundle.main.url(forResource: name, withExtension: ext) else { return nil }
        guard let file = try? AVAudioFile(forReading: url) else { return nil }

        let frameCount = AVAudioFrameCount(file.length)
        guard frameCount > 0,
              let buffer = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameCount)
        else { return nil }

        try? file.read(into: buffer)
        buffer.frameLength = frameCount
        return buffer
    }

    // MARK: Synthesised Fallbacks
    //
    // Generates short sine-wave bursts with exponential decay.
    // Quality is acceptable for development; replace with recorded assets for shipping.

    private static func synthesise(for filename: String, format: AVAudioFormat) -> AVAudioPCMBuffer {
        switch filename.lowercased() {
        case "click.wav": return sineClick(hz: 800,  durationMs: 22, decayRate: 150, gain: 0.6, format: format)
        case "sam.wav":   return sineClick(hz: 1100, durationMs: 30, decayRate: 100, gain: 0.9, format: format)
        case "wave.wav":  return noiseClick(durationMs: 18, gain: 0.35, format: format)
        case "ta.wav":    return sineClick(hz: 440,  durationMs: 40, decayRate: 80,  gain: 0.7, format: format)
        case "ka.wav":    return sineClick(hz: 550,  durationMs: 30, decayRate: 100, gain: 0.6, format: format)
        case "di.wav":    return sineClick(hz: 370,  durationMs: 35, decayRate: 90,  gain: 0.6, format: format)
        case "mi.wav":    return sineClick(hz: 490,  durationMs: 30, decayRate: 110, gain: 0.6, format: format)
        case "ki.wav":    return sineClick(hz: 660,  durationMs: 25, decayRate: 120, gain: 0.55, format: format)
        case "tha.wav":   return sineClick(hz: 330,  durationMs: 45, decayRate: 70,  gain: 0.7, format: format)
        case "tham.wav":  return sineClick(hz: 220,  durationMs: 80, decayRate: 40,  gain: 0.75, format: format)
        case "dhin.wav":  return sineClick(hz: 185,  durationMs: 70, decayRate: 45,  gain: 0.75, format: format)
        default:          return sineClick(hz: 440,  durationMs: 30, decayRate: 100, gain: 0.5,  format: format)
        }
    }

    /// Generates a sine burst with exponential amplitude decay.
    /// - Parameters:
    ///   - hz: Fundamental frequency.
    ///   - durationMs: Total buffer length in milliseconds.
    ///   - decayRate: Higher = faster decay (envelope steepness).
    ///   - gain: Peak amplitude (0–1).
    static func sineClick(
        hz: Double,
        durationMs: Double,
        decayRate: Double,
        gain: Double,
        format: AVAudioFormat
    ) -> AVAudioPCMBuffer {
        let sampleRate = format.sampleRate
        let frameCount = AVAudioFrameCount(sampleRate * durationMs / 1000.0)
        let buffer = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameCount)!
        buffer.frameLength = frameCount

        let channelCount = Int(format.channelCount)
        for ch in 0..<channelCount {
            let channel = buffer.floatChannelData![ch]
            for i in 0..<Int(frameCount) {
                let t = Double(i) / sampleRate
                let envelope = exp(-decayRate * t)
                let sample = Float(gain * envelope * sin(2.0 * .pi * hz * t))
                channel[i] = sample
            }
        }
        return buffer
    }

    /// Generates a short white-noise burst (used for the drutam wave sound).
    private static func noiseClick(
        durationMs: Double,
        gain: Double,
        format: AVAudioFormat
    ) -> AVAudioPCMBuffer {
        let sampleRate = format.sampleRate
        let frameCount = AVAudioFrameCount(sampleRate * durationMs / 1000.0)
        let buffer = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameCount)!
        buffer.frameLength = frameCount

        let channelCount = Int(format.channelCount)
        for ch in 0..<channelCount {
            let channel = buffer.floatChannelData![ch]
            for i in 0..<Int(frameCount) {
                let t = Double(i) / sampleRate
                let envelope = exp(-80.0 * t)
                channel[i] = Float(gain * envelope * Double.random(in: -1...1))
            }
        }
        return buffer
    }
}
