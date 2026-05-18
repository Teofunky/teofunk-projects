import SwiftUI

// MARK: - LessonDetailView
//
// Entry screen shown after tapping a node in the skill tree.
// Previews the lesson content before the user commits to starting.

struct LessonDetailView: View {
    let meta: LessonLibrary.Meta
    @State private var showPlayer = false
    @Environment(\.dismiss) private var dismiss

    // Build a Lesson from metadata (in real app this comes from LessonRepository)
    private var lesson: Lesson { LessonBuilder.build(forLevel: meta.level) }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: KMSpacing.sectionGap) {
                    heroIllustration
                    infoSection
                    stepsPreview
                    Spacer(minLength: KMSpacing.pt48)
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt16)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .safeAreaInset(edge: .bottom) { ctaBar }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button { dismiss() } label: {
                        Image(systemName: "xmark")
                            .foregroundStyle(Color.kmTextSecondary)
                    }
                }
            }
        }
        .fullScreenCover(isPresented: $showPlayer) {
            LessonPlayerView(lesson: lesson)
        }
    }

    // MARK: Hero

    private var heroIllustration: some View {
        ZStack {
            RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous)
                .fill(Color.kmSurface1)
                .frame(height: 180)
            VStack(spacing: KMSpacing.pt8) {
                Text("\(meta.level)")
                    .font(.system(size: 64, weight: .light, design: .monospaced))
                    .foregroundStyle(Color.kmPrimary)
                    .kmGlow(radius: 20)
                Text(meta.tier.rawValue.uppercased())
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                    .kmTrackingMicro()
            }
        }
    }

    // MARK: Info

    private var infoSection: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt8) {
            Text("Level \(meta.level) · \(meta.tier.rawValue.capitalized)")
                .kmStyle(KMFont.caption, color: .kmPrimary, tracking: 0.5)
            Text(meta.title)
                .kmStyle(KMFont.title1)
            Text(meta.subtitle)
                .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)
                .lineSpacing(4)

            HStack(spacing: KMSpacing.pt12) {
                statPill("\(meta.estimatedMinutes) min", icon: "clock")
                if let talaId = meta.talaId {
                    statPill(talaId.capitalized, icon: "circle.hexagongrid")
                }
            }
            .padding(.top, KMSpacing.pt4)
        }
    }

    // MARK: Steps Preview

    private var stepsPreview: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            Text("WHAT YOU'LL DO")
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()

            VStack(spacing: 0) {
                ForEach(Array(lesson.steps.enumerated()), id: \.offset) { i, step in
                    HStack(spacing: KMSpacing.pt12) {
                        ZStack {
                            Circle()
                                .fill(Color.kmSurface2)
                                .frame(width: 32, height: 32)
                            Image(systemName: step.systemImage)
                                .font(.system(size: 13))
                                .foregroundStyle(Color.kmTextSecondary)
                        }
                        Text(step.displayName)
                            .kmStyle(KMFont.body, color: .kmTextSecondary)
                        Spacer()
                        // KEY FEATURE badge on mute step
                        if case .mute = step {
                            Text("KEY")
                                .kmStyle(KMFont.micro, color: Color(hex: "#0B0D10"), tracking: 1)
                                .padding(.horizontal, 6)
                                .padding(.vertical, 3)
                                .background(Color.kmPrimary)
                                .clipShape(Capsule())
                        }
                    }
                    .padding(.vertical, KMSpacing.pt8)

                    if i < lesson.steps.count - 1 {
                        Rectangle()
                            .fill(Color.kmSurface2)
                            .frame(height: 1)
                    }
                }
            }
            .padding(KMSpacing.cardPadding)
            .background(Color.kmSurface1)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous))
        }
    }

    // MARK: CTA

    private var ctaBar: some View {
        VStack(spacing: 0) {
            Divider().background(Color.kmSurface2)
            KMButton("Begin Lesson") { showPlayer = true }
                .padding(KMSpacing.screenEdge)
                .background(Color.kmBase)
        }
    }

    private func statPill(_ label: String, icon: String) -> some View {
        Label(label, systemImage: icon)
            .kmStyle(KMFont.caption, color: .kmTextSecondary)
            .padding(.horizontal, KMSpacing.pt12)
            .padding(.vertical, KMSpacing.pt8)
            .background(Color.kmSurface1)
            .clipShape(Capsule())
    }
}
