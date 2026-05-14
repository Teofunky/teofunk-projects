import SwiftUI

// MARK: - RootView

/// Entry-point view. Switches between onboarding and the main tab bar
/// based on whether the user has completed setup.
struct RootView: View {
    @Environment(ProgressStore.self) private var progressStore

    var body: some View {
        if progressStore.hasCompletedOnboarding {
            MainTabView()
        } else {
            OnboardingView()
        }
    }
}

// MARK: - MainTabView

struct MainTabView: View {
    @State private var selectedTab: Tab = .today

    enum Tab: Int {
        case today, learn, practice, compose, profile
    }

    var body: some View {
        TabView(selection: $selectedTab) {
            TodayView()
                .tabItem { Label("Today", systemImage: "sun.horizon") }
                .tag(Tab.today)

            LearnView()
                .tabItem { Label("Learn", systemImage: "map") }
                .tag(Tab.learn)

            PracticeView()
                .tabItem { Label("Practice", systemImage: "scope") }
                .tag(Tab.practice)

            ComposeView()
                .tabItem { Label("Compose", systemImage: "sparkles") }
                .tag(Tab.compose)

            ProfileView()
                .tabItem { Label("Profile", systemImage: "person") }
                .tag(Tab.profile)
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
