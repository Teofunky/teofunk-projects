import Foundation
import SwiftData

// MARK: - DependencyContainer

/// Root composition root. All services are constructed here and injected
/// via SwiftUI environment or explicit initialiser — never via singletons.
///
/// Construction order matters: lower-layer objects first, higher-layer last.
@MainActor
final class DependencyContainer {

    // MARK: Storage

    let modelContainer: ModelContainer = {
        let schema = Schema([
            ProgressRecord.self,
            PracticeSessionRecord.self,
        ])
        let config = ModelConfiguration(schema: schema, isStoredInMemoryOnly: false)
        do {
            return try ModelContainer(for: schema, configurations: [config])
        } catch {
            fatalError("SwiftData container failed to initialise: \(error)")
        }
    }()

    // MARK: Repositories

    lazy var progressRepository: ProgressRepository = {
        SwiftDataProgressRepository(modelContext: modelContainer.mainContext)
    }()

    // MARK: Stores / observable state shared across the app

    lazy var progressStore: ProgressStore = {
        ProgressStore(repository: progressRepository)
    }()

    // MARK: Audio (constructed lazily — microphone permission not yet requested)

    lazy var audioEngine: AudioEngine = AudioEngine()
}
