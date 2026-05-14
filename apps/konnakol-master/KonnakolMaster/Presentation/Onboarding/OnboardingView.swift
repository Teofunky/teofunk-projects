import SwiftUI

struct OnboardingView: View {
    @Environment(ProgressStore.self) private var progressStore
    @State private var page = 0

    var body: some View {
        VStack {
            Text("Welcome to Konnakol Master")
                .kmStyle(KMFont.title1)
                .multilineTextAlignment(.center)
                .padding(KMSpacing.screenEdge)

            Spacer()

            KMButton("Get Started") {
                progressStore.completeOnboarding()
            }
            .padding(KMSpacing.screenEdge)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color.kmBase.ignoresSafeArea())
    }
}
