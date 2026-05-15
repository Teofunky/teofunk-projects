import Foundation

// MARK: - BeatOnset

struct BeatOnset: Identifiable, Codable {
    let id: UUID
    let expectedTime: TimeInterval   // seconds
    let actualTime: TimeInterval?    // nil = miss (no onset near this beat)
    let driftMs: Int?                // positive = late, negative = early
    let accuracy: BeatAccuracy

    init(expectedTime: TimeInterval, actualTime: TimeInterval?, driftMs: Int?, accuracy: BeatAccuracy) {
        self.id           = UUID()
        self.expectedTime = expectedTime
        self.actualTime   = actualTime
        self.driftMs      = driftMs
        self.accuracy     = accuracy
    }
}

enum BeatAccuracy: String, Codable {
    case perfect  // |drift| ≤ 30 ms
    case good     // |drift| ≤ 60 ms
    case ok       // |drift| ≤ 100 ms
    case miss     // > 100 ms or no onset detected

    var color: String {  // hex string — used by SwiftUI layer via Color(hex:)
        switch self {
        case .perfect: return "#5BC97E"
        case .good:    return "#A8D86B"
        case .ok:      return "#E87753"
        case .miss:    return "#D14D4D"
        }
    }

    static func from(driftMs: Int) -> BeatAccuracy {
        let abs = Swift.abs(driftMs)
        if abs <= 30  { return .perfect }
        if abs <= 60  { return .good    }
        if abs <= 100 { return .ok      }
        return .miss
    }
}

// MARK: - SessionSummary

struct VoiceSessionSummary {
    let totalBeats: Int
    let hits: Int
    let misses: Int
    let avgDriftMs: Int
    let maxDriftMs: Int
    let accuracyFraction: Double   // 0–1

    init(beats: [BeatOnset]) {
        totalBeats = beats.count
        let hit    = beats.filter { $0.accuracy != .miss }
        hits       = hit.count
        misses     = totalBeats - hits
        let drifts = hit.compactMap(\.driftMs).map(abs)
        avgDriftMs = drifts.isEmpty ? 0 : drifts.reduce(0, +) / drifts.count
        maxDriftMs = drifts.max() ?? 0
        accuracyFraction = totalBeats > 0 ? Double(hits) / Double(totalBeats) : 0
    }
}

// MARK: - BeatGridMatcher

/// Pure value-type. No state — call methods with whatever data you have.
struct BeatGridMatcher {

    // MARK: Grid Generation

    /// Generates expected beat timestamps for `durationSeconds` at the given BPM.
    /// `startTime` is an absolute TimeInterval (e.g. from AVAudioSession hostTime → seconds).
    func generateGrid(
        bpm: Int,
        tala: Tala,
        startTime: TimeInterval,
        durationSeconds: TimeInterval
    ) -> [TimeInterval] {
        let secondsPerBeat = 60.0 / Double(bpm)
        let totalBeats     = Int(durationSeconds / secondsPerBeat) + tala.totalBeats

        return (0..<totalBeats).map { i in
            startTime + Double(i) * secondsPerBeat
        }
    }

    // MARK: Matching

    /// Maps detected onset timestamps to the nearest expected beat.
    /// Each expected beat appears exactly once in the result — missed beats produce .miss entries.
    func match(
        onsets: [TimeInterval],
        expectedBeats: [TimeInterval],
        searchWindowMs: Double = 120
    ) -> [BeatOnset] {
        let searchSeconds = searchWindowMs / 1000.0
        var remainingOnsets = onsets

        return expectedBeats.map { expected in
            // Find the onset closest to this expected beat within the search window
            let candidates = remainingOnsets.filter { abs($0 - expected) <= searchSeconds }
            guard let best = candidates.min(by: { abs($0 - expected) < abs($1 - expected) }) else {
                return BeatOnset(expectedTime: expected, actualTime: nil, driftMs: nil, accuracy: .miss)
            }

            // Consume the matched onset so it doesn't match another beat
            remainingOnsets.removeAll { $0 == best }

            let driftMs = Int((best - expected) * 1000)
            return BeatOnset(
                expectedTime: expected,
                actualTime: best,
                driftMs: driftMs,
                accuracy: .from(driftMs: driftMs)
            )
        }
    }

    // MARK: Convenience

    func summary(beats: [BeatOnset]) -> VoiceSessionSummary {
        VoiceSessionSummary(beats: beats)
    }
}
