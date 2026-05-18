import SwiftUI

struct IntroStepView: View {
    let text: String
    let videoURL: URL?
    let onComplete: () -> Void

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Image(systemName: "text.book.closed.fill")
                .font(.system(size: 52))
                .foregroundStyle(Color.kmPrimary)
                .kmGlow(radius: 16)

            Text(text)
                .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)
                .multilineTextAlignment(.center)
                .lineSpacing(6)
                .padding(.horizontal, KMSpacing.screenEdge)

            Spacer()

            KMButton("Got it") { onComplete() }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
    }
}
