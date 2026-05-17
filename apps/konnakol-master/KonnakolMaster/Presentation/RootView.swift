import SwiftUI

// MARK: - RootView

/// Entry-point view. Switches between onboarding and the main tab bar
/// based on whether the user has completed setup.
struct RootView: View {
    @Environment(ProgressStore.self) private var progressStore

    var body: some View {
        Group {
            if progressStore.isLoaded {
                if progressStore.hasCompletedOnboarding {
                    MainTabView()
                } else {
                    OnboardingView()
                }
            } else {
                Color.kmBase.ignoresSafeArea()
            }
        }
        .task {
            await progressStore.load()
        }
    }
}

// MARK: - MainTabView

struct MainTabView: View {
    @State private var selectedTab: Tab = .today

    enum Tab: Int {
        case today, learn, practice, theory, exercises
    }

    var body: some View {
        TabView(selection: $selectedTab) {
            TodayView()
                .tabItem { Label("Сегодня", systemImage: "sun.horizon.fill") }
                .tag(Tab.today)

            LearnView()
                .tabItem { Label("Школа", systemImage: "graduationcap.fill") }
                .tag(Tab.learn)

            PracticeView()
                .tabItem { Label("Практика", systemImage: "scope") }
                .tag(Tab.practice)

            TheoryView()
                .tabItem { Label("Теория", systemImage: "book.closed.fill") }
                .tag(Tab.theory)

            ExercisesView()
                .tabItem { Label("Упражнения", systemImage: "figure.run") }
                .tag(Tab.exercises)
        }
        .tint(Color.kmPrimary)
        // Apply glassmorphism background to the tab bar via UIKit appearance —
        // SwiftUI's TabView doesn't expose this surface directly.
        .onAppear(perform: styleTabBar)
    }

    private func styleTabBar() {
        let appearance = UITabBarAppearance()
        appearance.configureWithOpaqueBackground()
        appearance.backgroundColor = UIColor(Color.kmSurface1).withAlphaComponent(0.9)
        UITabBar.appearance().standardAppearance = appearance
        UITabBar.appearance().scrollEdgeAppearance = appearance
    }
}
