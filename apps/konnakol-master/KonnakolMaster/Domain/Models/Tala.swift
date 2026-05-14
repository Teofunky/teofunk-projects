import Foundation

// MARK: - Tala

/// A rhythmic cycle in the Carnatic tala system.
struct Tala: Identifiable, Hashable, Codable {
    let id: String           // e.g. "adi", "rupaka", "misra-chapu"
    let name: String         // "Adi Tala"
    let displayName: String  // "आदि ताल" (optional localisation)
    let totalBeats: Int      // 8
    let angas: [Anga]        // [.laghu(4), .drutam, .drutam]
    let defaultNadai: Nadai
}

// MARK: - Anga

/// A single rhythmic unit within a tala, defined by its gesture and beat count.
enum Anga: Hashable, Codable {
    /// 1 beat — single clap (anudrutam).
    case anudrutam
    /// 2 beats — clap + palm wave down (drutam).
    case drutam
    /// n beats — clap + count (n−1) fingers (laghu).
    case laghu(beats: Int)

    var beatCount: Int {
        switch self {
        case .anudrutam:       return 1
        case .drutam:          return 2
        case .laghu(let n):    return n
        }
    }
}

// MARK: - Nadai

/// Subdivision density within a single akshara (beat) of the tala.
enum Nadai: Int, CaseIterable, Hashable, Codable {
    case tisra    = 3
    case chatusra = 4
    case khanda   = 5
    case misra    = 7
    case sankeerna = 9

    var displayName: String {
        switch self {
        case .tisra:     return "Tisra"
        case .chatusra:  return "Chatusra"
        case .khanda:    return "Khanda"
        case .misra:     return "Misra"
        case .sankeerna: return "Sankeerna"
        }
    }

    var westernEquivalent: String {
        switch self {
        case .tisra:     return "Triplets"
        case .chatusra:  return "16ths"
        case .khanda:    return "Quintuplets"
        case .misra:     return "Septuplets"
        case .sankeerna: return "Novemplets"
        }
    }

    /// Short label for segmented controls and picker chips (3 / 4 / 5 / 7 / 9).
    var shortLabel: String { "\(rawValue)" }
}
