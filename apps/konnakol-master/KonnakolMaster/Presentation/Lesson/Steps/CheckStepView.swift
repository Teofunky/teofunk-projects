import SwiftUI

struct CheckStepView: View {
    let summary: String
    let stepResults: [StepResult]
    let onComplete: () -> Void

    private var measuredResults: [StepResult] {
        stepResults.filter { !$0.accuracy.isNaN }
    }

    private var overallAccuracy: Double {
        guard !measuredResults.isEmpty else { return 1.0 }
        return measuredResults.map(\.accuracy).reduce(0, +) / Double(measuredResults.count)
    }

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Text("CHECK")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            // Overall score ring
            ZStack {
                Circle()
                    .strokeBorder(Color.kmSurface2, lineWidth: 6)
                    .frame(width: 120, height: 120)
                Circle()
                    .trim(from: 0, to: overallAccuracy)
                    .stroke(accuracyColor, style: StrokeStyle(lineWidth: 6, lineCap: .round))
                    .frame(width: 120, height: 120)
                    .rotationEffect(.degrees(-90))
                    .animation(.kmEmphasized, value: overallAccuracy)
                VStack(spacing: 2) {
                    Text("\(Int(overallAccuracy * 100))%")
                        .kmStyle(KMFont.title2, color: accuracyColor)
                    Text("accuracy")
                        .kmStyle(KMFont.micro, color: .kmTextTertiary)
                }
            }

            if !summary.isEmpty {
                Text(summary)
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
                    .multilineTextAlignment(.center)
                    .lineSpacing(4)
                    .padding(.horizontal, KMSpacing.screenEdge)
            }

            // Per-step breakdown
            if !measuredResults.isEmpty {
                VStack(spacing: KMSpacing.pt8) {
                    ForEach(measuredResults, id: \.stepId) { result in
                        HStack {
                            Text(result.stepId.capitalized)
                                .kmStyle(KMFont.body, color: .kmTextSecondary)
                            Spacer()
                            Text("\(Int(result.accuracy * 100))%")
                                .kmStyle(KMFont.body, color: resultColor(result.accuracy))
                            if result.avgDriftMs > 0 {
                                Text("(\(result.avgDriftMs) ms)")
                                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
                            }
                        }
                        .padding(.horizontal, KMSpacing.cardPadding)
                    }
                }
                .padding(.vertical, KMSpacing.cardPadding)
                .background(Color.kmSurface1)
                .clipShape(RoundedRectangle(cornerRadius: KMRadius.card))
                .padding(.horizontal, KMSpacing.screenEdge)
            }

            Spacer()

            KMButton("Finish Lesson") { onComplete() }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
    }

    private var accuracyColor: Color {
        overallAccuracy >= 0.85 ? .kmSuccess : overallAccuracy >= 0.70 ? .kmWarning : .kmError
    }

    private func resultColor(_ acc: Double) -> Color {
        acc >= 0.85 ? .kmSuccess : acc >= 0.70 ? .kmWarning : .kmError
    }
}
