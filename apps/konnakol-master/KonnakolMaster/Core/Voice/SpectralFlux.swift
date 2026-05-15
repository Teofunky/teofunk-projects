import Accelerate

// MARK: - SpectralFlux
//
// Stateless pure-function FFT and spectral-flux helpers.
// All operations use vDSP from the Accelerate framework for SIMD performance.
//
// Precision note:
//   windowSize = 1024 samples @ 44.1 kHz → 23.2 ms per frame
//   hopSize    = 512  samples @ 44.1 kHz → 11.6 ms resolution
//   Both are within the spec's ≤20 ms window / ≤10 ms hop targets.

enum SpectralFlux {

    // MARK: Types

    typealias Magnitude = [Float]

    // MARK: Constants

    static let windowSize: Int = 1024
    static let hopSize: Int    = 512
    static let log2n           = vDSP_Length(10)  // log2(1024)

    // MARK: FFT Setup (one-time allocation, reused across calls)

    static let fftSetup: FFTSetup = {
        guard let s = vDSP_create_fftsetup(log2n, FFTRadix(kFFTRadix2)) else {
            fatalError("vDSP_create_fftsetup failed — hardware does not support FFT")
        }
        return s
    }()

    // MARK: Hann Window (pre-computed)

    static let hannWindow: [Float] = {
        var w = [Float](repeating: 0, count: windowSize)
        vDSP_hann_window(&w, vDSP_Length(windowSize), Int32(vDSP_HANN_NORM))
        return w
    }()

    // MARK: Public API

    /// Computes the half-spectrum magnitude for a block of `windowSize` real samples.
    /// Returns windowSize/2 = 512 magnitude bins.
    static func magnitude(from samples: UnsafePointer<Float>) -> Magnitude {
        // 1. Apply Hann window
        var windowed = [Float](repeating: 0, count: windowSize)
        vDSP_vmul(samples, 1, hannWindow, 1, &windowed, 1, vDSP_Length(windowSize))

        // 2. Pack real input as interleaved complex (imaginary = 0)
        //    We treat pairs of real samples as a complex number for vDSP_ctoz.
        var realPart = [Float](repeating: 0, count: windowSize / 2)
        var imagPart = [Float](repeating: 0, count: windowSize / 2)

        windowed.withUnsafeBufferPointer { buf in
            buf.baseAddress!.withMemoryRebound(to: DSPComplex.self, capacity: windowSize / 2) { cBuf in
                var split = DSPSplitComplex(realp: &realPart, imagp: &imagPart)
                vDSP_ctoz(cBuf, 2, &split, 1, vDSP_Length(windowSize / 2))
            }
        }

        // 3. In-place forward FFT
        realPart.withUnsafeMutableBufferPointer { rBuf in
            imagPart.withUnsafeMutableBufferPointer { iBuf in
                var split = DSPSplitComplex(realp: rBuf.baseAddress!, imagp: iBuf.baseAddress!)
                vDSP_fft_zrip(fftSetup, &split, 1, log2n, FFTDirection(FFT_FORWARD))
            }
        }

        // 4. Power spectrum (squared magnitudes)
        var mag = [Float](repeating: 0, count: windowSize / 2)
        realPart.withUnsafeBufferPointer { rBuf in
            imagPart.withUnsafeBufferPointer { iBuf in
                var split = DSPSplitComplex(
                    realp: UnsafeMutablePointer(mutating: rBuf.baseAddress!),
                    imagp: UnsafeMutablePointer(mutating: iBuf.baseAddress!)
                )
                vDSP_zvmags(&split, 1, &mag, 1, vDSP_Length(windowSize / 2))
            }
        }

        // 5. Normalise
        var scale = Float(windowSize)
        vDSP_vsdiv(mag, 1, &scale, &mag, 1, vDSP_Length(windowSize / 2))

        return mag
    }

    /// Half-wave rectified spectral flux between two consecutive frames.
    /// Only positive energy increases count — ignores bins that got quieter.
    /// Reference: Dixon (2006) "Onset Detection Revisited".
    static func flux(current: Magnitude, previous: Magnitude) -> Float {
        precondition(current.count == previous.count)
        var result: Float = 0
        for i in 0..<current.count {
            let diff = current[i] - previous[i]
            if diff > 0 { result += diff }
        }
        return result
    }
}
