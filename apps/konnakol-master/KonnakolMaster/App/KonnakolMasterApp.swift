import SwiftUI
import SwiftData

@main
struct KonnakolMasterApp: App {
    private let container = DependencyContainer()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(container.progressStore)
                .modelContainer(container.modelContainer)
                .preferredColorScheme(.dark) // Dark-first, light mode deferred to v1.1
        }
    }
}
