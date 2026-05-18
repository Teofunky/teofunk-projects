import Foundation
import Observation

// MARK: - ProgressStore
//
// Observable state shared across the entire app via SwiftUI environment.
// All mutations funnel through here; the repository handles persistence.

@Observable
final class ProgressStore {
    private(set) var progress: UserProgress = UserProgress()
    private(set) var isLoaded: Bool = false

    var hasCompletedOnboarding: Bool { progress.hasCompletedOnboarding }

    private let repository: ProgressRepository

    init(repository: ProgressRepository) {
        self.repository = repository
    }

    // MARK: Lifecycle

    func load() async {
        do {
            progress = try await repository.load()
        } catch {
            // If load fails, proceed with a default in-memory progress.
            // The error is non-fatal; the user can always redo onboarding.
        }
        isLoaded = true
    }

    // MARK: Mutations

    func completeOnboarding() {
        progress.hasCompletedOnboarding = true
        persist()
    }

    func recordLessonComplete(lessonId: String, xpGained: Int) {
        guard !progress.completedLessons.contains(lessonId) else { return }
        progress.completedLessons.append(lessonId)
        progress.xp += xpGained

        // Advance to next level if this lesson matches the current one
        let completedLevel = Int(lessonId.replacingOccurrences(of: "level-", with: "")) ?? 0
        if completedLevel == progress.currentLevel {
            progress.currentLevel = min(completedLevel + 1, 16)
        }

        updateStreak()
        persist()
    }

    func updateInstrument(_ instrument: Instrument) {
        progress.instrument = instrument
        persist()
    }

    func addPracticeMinutes(_ minutes: Int) {
        progress.totalPracticeMinutes += minutes
        updateStreak()
        persist()
    }

    // MARK: Private

    private func updateStreak() {
        let today = Calendar.current.startOfDay(for: Date())
        let lastDay = progress.lastPracticeDate.map { Calendar.current.startOfDay(for: $0) }

        if let last = lastDay {
            let days = Calendar.current.dateComponents([.day], from: last, to: today).day ?? 0
            if days == 0 {
                // Same day — streak unchanged
            } else if days == 1 {
                progress.currentStreak += 1
            } else {
                progress.currentStreak = 1
            }
        } else {
            progress.currentStreak = 1
        }
        progress.longestStreak = max(progress.longestStreak, progress.currentStreak)
        progress.lastPracticeDate = Date()
    }

    private func persist() {
        let snapshot = progress
        Task {
            try? await repository.save(snapshot)
        }
    }
}
