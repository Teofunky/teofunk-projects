import Foundation
import Observation

@Observable
final class TodayViewModel {
    private(set) var greeting: String = ""
    private(set) var todayLesson: LessonSummary?
    private(set) var dailyChallenge: ChallengeSummary?
    private(set) var tipOfDay: String = ""

    struct LessonSummary {
        let id: String
        let level: Int
        let title: String
        let estimatedMinutes: Int
        let progressFraction: Double  // 0–1
        let isStarted: Bool
    }

    struct ChallengeSummary {
        let title: String
        let description: String
        let durationMinutes: Int
    }

    func load(progress: UserProgress) {
        greeting = makeGreeting()
        todayLesson = makeTodayLesson(progress: progress)
        dailyChallenge = makeChallenge(progress: progress)
        tipOfDay = tips.randomElement() ?? tips[0]
    }

    // MARK: Private

    private func makeGreeting() -> String {
        let hour = Calendar.current.component(.hour, from: Date())
        switch hour {
        case 5..<12:  return "Good morning"
        case 12..<17: return "Good afternoon"
        case 17..<21: return "Good evening"
        default:      return "Good night"
        }
    }

    private func makeTodayLesson(progress: UserProgress) -> LessonSummary {
        let level = progress.currentLevel
        let id    = "level-\(level)"
        let isStarted = progress.completedLessons.contains(where: { $0.hasPrefix(id) })
        return LessonSummary(
            id: id,
            level: level,
            title: LessonLibrary.title(forLevel: level),
            estimatedMinutes: 8,
            progressFraction: isStarted ? 0.5 : 0,
            isStarted: isStarted
        )
    }

    private func makeChallenge(progress: UserProgress) -> ChallengeSummary {
        ChallengeSummary(
            title: "5-Minute Warm-Up",
            description: "Ta-Ka-Di-Mi at 80 BPM · 5 cycles",
            durationMinutes: 5
        )
    }

    private let tips: [String] = [
        "\"The voice is the first instrument. Speak before you play.\" — B.C. Manjunath",
        "Trikalam: the same phrase at ×1, ×2, ×4 reveals every weakness in your timing.",
        "Sam is home. Every phrase you play is a journey away from home — and back.",
        "Chatusra is the ground. Tisra is the wave. Learn to surf between them.",
        "Count the silence as carefully as you count the sound.",
        "\"A wrong note played confidently is a jazz note. A right note played without pulse is nothing.\"",
        "Misra Chapu (7) = 3 + 2 + 2. Feel the groups, not the numbers.",
    ]
}
