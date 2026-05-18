import Foundation
import SwiftData

// MARK: - ProgressRepository Protocol

protocol ProgressRepository: AnyObject {
    func load() async throws -> UserProgress
    func save(_ progress: UserProgress) async throws
}

// MARK: - SwiftData Implementation

final class SwiftDataProgressRepository: ProgressRepository {
    private let modelContext: ModelContext

    init(modelContext: ModelContext) {
        self.modelContext = modelContext
    }

    func load() async throws -> UserProgress {
        let records = try modelContext.fetch(FetchDescriptor<ProgressRecord>())
        guard let record = records.first else {
            let fresh = ProgressRecord()
            modelContext.insert(fresh)
            try modelContext.save()
            return UserProgress()
        }
        return map(record)
    }

    func save(_ progress: UserProgress) async throws {
        let records = try modelContext.fetch(FetchDescriptor<ProgressRecord>())
        let record = records.first ?? {
            let r = ProgressRecord()
            modelContext.insert(r)
            return r
        }()
        apply(progress, to: record)
        try modelContext.save()
    }

    // MARK: Mapping

    private func map(_ record: ProgressRecord) -> UserProgress {
        var p = UserProgress()
        p.currentLevel = record.currentLevel
        p.completedLessons = record.completedLessons
        p.currentStreak = record.currentStreak
        p.longestStreak = record.longestStreak
        if let t = record.lastPracticeDateSince1970 {
            p.lastPracticeDate = Date(timeIntervalSince1970: t)
        }
        p.totalPracticeMinutes = record.totalPracticeMinutes
        p.instrument = Instrument(rawValue: record.instrument) ?? .rhythmOnly
        p.experienceLevel = ExperienceLevel(rawValue: record.experienceLevel) ?? .beginner
        p.xp = record.xp
        p.hasCompletedOnboarding = record.hasCompletedOnboarding
        return p
    }

    private func apply(_ progress: UserProgress, to record: ProgressRecord) {
        record.currentLevel = progress.currentLevel
        record.completedLessons = progress.completedLessons
        record.currentStreak = progress.currentStreak
        record.longestStreak = progress.longestStreak
        record.lastPracticeDateSince1970 = progress.lastPracticeDate?.timeIntervalSince1970
        record.totalPracticeMinutes = progress.totalPracticeMinutes
        record.instrument = progress.instrument.rawValue
        record.experienceLevel = progress.experienceLevel.rawValue
        record.xp = progress.xp
        record.hasCompletedOnboarding = progress.hasCompletedOnboarding
    }
}
