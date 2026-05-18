import Foundation
import Observation

@Observable
final class LearnViewModel {

    enum NodeState {
        case completed, current, available, locked
    }

    struct LevelNode: Identifiable {
        let id: String
        let meta: LessonLibrary.Meta
        let state: NodeState
        /// Horizontal offset fraction (−0.5 … +0.5) for gentle zigzag.
        let xOffset: Double
    }

    private(set) var nodes: [LevelNode] = []
    var selectedNode: LevelNode?

    func load(progress: UserProgress) {
        nodes = LessonLibrary.all.enumerated().map { i, meta in
            let state: NodeState
            if progress.completedLessons.contains(meta.id) {
                state = .completed
            } else if meta.level == progress.currentLevel {
                state = .current
            } else if meta.level == progress.currentLevel + 1 {
                state = .available
            } else if meta.level < progress.currentLevel {
                state = .completed // safety — shouldn't happen
            } else {
                state = .locked
            }
            // Alternating offsets: -0.15, 0, +0.15 to create mountain-path feel
            let offsets: [Double] = [0, -0.12, 0.12, 0, -0.12, 0.12, 0, -0.12]
            let xOffset = offsets[i % offsets.count]
            return LevelNode(id: meta.id, meta: meta, state: state, xOffset: xOffset)
        }
    }
}
