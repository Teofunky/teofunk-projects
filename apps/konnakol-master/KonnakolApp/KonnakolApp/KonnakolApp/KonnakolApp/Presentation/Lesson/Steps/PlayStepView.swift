import SwiftUI

struct PlayStepView: View {
    let instrument: Instrument
    let prompt: String
    let onComplete: () -> Void
    let onSkip: () -> Void

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Text("APPLY")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            Image(systemName: instrument.systemImage)
                .font(.system(size: 56))
                .foregroundStyle(Color.kmPrimary)
                .kmGlow(radius: 20)

            VStack(spacing: KMSpacing.pt12) {
                Text("On your \(instrument.displayName)")
                    .kmStyle(KMFont.title2)
                Text(prompt)
                    .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)
                    .multilineTextAlignment(.center)
                    .lineSpacing(4)
                    .padding(.horizontal, KMSpacing.screenEdge)
            }

            Spacer()

            VStack(spacing: KMSpacing.pt12) {
                KMButton("Done") { onComplete() }
                KMButton("Skip", style: .ghost) { onSkip() }
            }
            .padding(.horizontal, KMSpacing.screenEdge)
            .padding(.bottom, KMSpacing.pt32)
        }
    }
}
