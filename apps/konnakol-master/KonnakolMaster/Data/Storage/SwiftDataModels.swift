import Foundation
import SwiftData

// MARK: - SwiftData Persistent Records
//
// These are thin persistence containers. Domain models (UserProgress,
// PracticeSession) are separate value types — repositories translate between them.

@Model
final class ProgressRecord {
    var currentLevel: Int = 1
    var completedLessons: [String] = []
    var currentStreak: Int = 0
    var longestStreak: Int = 0
    var lastPracticeDateSince1970: Double? = nil
    var totalPracticeMinutes: Int = 0
    var instrument: String = Instrument.rhythmOnly.rawValue
    var experienceLevel: String = ExperienceLevel.beginner.rawValue
    var xp: Int = 0
    var hasCompletedOnboarding: Bool = false

    init() {}
}

@Model
final class PracticeSessionRecord {
    var id: UUID = UUID()
    var date: Date = Date()
    var durationSeconds: Int = 0
    var talaId: String = "adi"
    var nadaiRawValue: Int = 4
    var bpm: Int = 60
    var totalBeats: Int = 0
    var hits: Int = 0
    var avgDriftMs: Int = 0
    var accuracyPercent: Double = 0

    init() {}
}
