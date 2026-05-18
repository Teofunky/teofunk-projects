import Foundation

// MARK: - User Progress Domain Model

struct UserProgress {
    var currentLevel: Int = 1
    var completedLessons: [String] = []
    var currentStreak: Int = 0
    var longestStreak: Int = 0
    var lastPracticeDate: Date? = nil
    var totalPracticeMinutes: Int = 0
    var instrument: Instrument = .rhythmOnly
    var experienceLevel: ExperienceLevel = .beginner
    var xp: Int = 0
    var achievements: [Achievement] = []
    var hasCompletedOnboarding: Bool = false
}

enum Instrument: String, CaseIterable, Codable {
    case guitar, bass, vocal, drums, keys, wind, rhythmOnly

    var displayName: String {
        switch self {
        case .guitar:      return "Guitar"
        case .bass:        return "Bass"
        case .vocal:       return "Vocal"
        case .drums:       return "Drums"
        case .keys:        return "Keys"
        case .wind:        return "Wind"
        case .rhythmOnly:  return "Rhythm Only"
        }
    }

    var systemImage: String {
        switch self {
        case .guitar, .bass:  return "guitars"
        case .vocal:           return "mic"
        case .drums:           return "music.quarternote.3"
        case .keys:            return "pianokeys"
        case .wind:            return "wind"
        case .rhythmOnly:      return "hand.tap"
        }
    }
}

enum ExperienceLevel: String, CaseIterable, Codable {
    case beginner, someTraining, advanced

    var displayName: String {
        switch self {
        case .beginner:      return "Beginner"
        case .someTraining:  return "Some Rhythm Training"
        case .advanced:      return "Advanced Musician"
        }
    }
}

struct Achievement: Identifiable, Codable {
    let id: String
    let title: String
    let description: String
    let earnedDate: Date?

    var isEarned: Bool { earnedDate != nil }
}
