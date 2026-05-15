import Foundation
import Observation
import SwiftUI

// MARK: - LessonPlayerViewModel
//
// State machine for the lesson flow.
// Drives LessonPlayerView — one observable shared across all step views.

@Observable
final class LessonPlayerViewModel {

    // MARK: State

    enum Phase {
        case inProgress, celebrating, failed
    }

    private(set) var lesson: Lesson
    private(set) var currentIndex: Int = 0
    private(set) var phase: Phase = .inProgress
    private(set) var stepResults: [StepResult] = []
    private(set) var finalAccuracy: Double = 0
    private(set) var xpEarned: Int = 0

    var progress: Double {
        guard lesson.steps.count > 0 else { return 0 }
        return Double(currentIndex) / Double(lesson.steps.count)
    }

    var currentStep: LessonStep { lesson.steps[currentIndex] }
    var isLastStep: Bool { currentIndex == lesson.steps.count - 1 }
    var totalSteps: Int { lesson.steps.count }

    // MARK: Init

    init(lesson: Lesson) {
        self.lesson = lesson
    }

    // MARK: Navigation

    /// Call when a step completes — records result and advances.
    func completeStep(accuracy: Double = Double.nan, avgDriftMs: Int = 0) {
        let result = StepResult(
            stepId: currentStep.id,
            accuracy: accuracy,
            avgDriftMs: avgDriftMs,
            completedAt: Date()
        )
        stepResults.append(result)

        if isLastStep {
            finishLesson()
        } else {
            withAnimation(.kmStandard) {
                currentIndex += 1
            }
        }
    }

    /// Skip optional steps (Play, Intro).
    func skipStep() { completeStep() }

    // MARK: Private

    private func finishLesson() {
        let measuredResults = stepResults.filter { !$0.accuracy.isNaN }
        if measuredResults.isEmpty {
            finalAccuracy = 1.0
        } else {
            finalAccuracy = measuredResults.map(\.accuracy).reduce(0, +) / Double(measuredResults.count)
        }

        let passed = finalAccuracy >= lesson.passCriteria.minAccuracy
        xpEarned = passed ? 50 + Int(finalAccuracy * 50) : 10
        phase = passed ? .celebrating : .failed
    }

    func retry() {
        currentIndex = 0
        stepResults = []
        phase = .inProgress
    }
}
