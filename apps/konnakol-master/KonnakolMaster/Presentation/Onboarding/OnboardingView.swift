import SwiftUI

// MARK: - OnboardingView

struct OnboardingView: View {
    @Environment(ProgressStore.self) private var progressStore
    @State private var vm = OnboardingViewModel()

    var body: some View {
        ZStack {
            Color.kmBase.ignoresSafeArea()
            YantraBackground()

            VStack(spacing: 0) {
                // Progress dots
                HStack(spacing: KMSpacing.pt8) {
                    ForEach(0..<vm.totalPages, id: \.self) { i in
                        Capsule()
                            .fill(i == vm.page ? Color.kmPrimary : Color.kmSurface2)
                            .frame(width: i == vm.page ? 24 : 8, height: 8)
                            .animation(.kmQuick, value: vm.page)
                    }
                }
                .padding(.top, KMSpacing.pt48)

                Spacer()

                // Page content
                Group {
                    switch vm.page {
                    case 0: WelcomePage()
                    case 1: InstrumentPickerPage(selected: $vm.selectedInstrument)
                    case 2: ExperiencePage(selected: $vm.selectedLevel)
                    default: EmptyView()
                    }
                }
                .transition(.asymmetric(
                    insertion: .move(edge: .trailing).combined(with: .opacity),
                    removal:   .move(edge: .leading).combined(with: .opacity)
                ))
                .id(vm.page) // forces re-render + transition

                Spacer()

                // Navigation
                VStack(spacing: KMSpacing.pt12) {
                    if vm.page < vm.totalPages - 1 {
                        KMButton("Continue") {
                            withAnimation(.kmStandard) { vm.advance() }
                        }
                    } else {
                        KMButton("Begin Your Journey") {
                            vm.finish(store: progressStore)
                        }
                    }

                    if vm.page > 0 {
                        Button("Back") {
                            withAnimation(.kmStandard) { vm.back() }
                        }
                        .kmStyle(KMFont.body, color: .kmTextSecondary)
                        .frame(height: KMSpacing.minTouchTarget)
                    }
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
            }
        }
    }
}

// MARK: - Page 1: Welcome

private struct WelcomePage: View {
    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            // Logo mark — concentric circles with saffron dot (matches HTML prototype)
            ZStack {
                ForEach([56, 40, 24] as [CGFloat], id: \.self) { r in
                    Circle()
                        .strokeBorder(Color.kmPrimary.opacity(0.4), lineWidth: 1)
                        .frame(width: r * 2, height: r * 2)
                }
                Rectangle()
                    .strokeBorder(Color.kmPrimary.opacity(0.25), lineWidth: 1)
                    .frame(width: 88, height: 88)
                    .rotationEffect(.degrees(45))
                Circle()
                    .fill(Color.kmPrimary)
                    .frame(width: 10, height: 10)
                    .offset(y: -56)
            }
            .frame(width: 140, height: 140)
            .kmGlow(radius: 24)

            VStack(spacing: KMSpacing.pt12) {
                Text("Konnakol Master")
                    .kmStyle(KMFont.title1)
                    .kmTrackingDisplay()

                Text("The ancient language of rhythm.\nMade for modern musicians.")
                    .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)
                    .multilineTextAlignment(.center)
                    .lineSpacing(4)

                Text("Konnakol is the South Indian art of vocalising rhythm — speaking syllables (ta, ka, di, mi) as a complete musical language. Musicians and composers use it to master complex patterns, polyrhythm, and composition without any instrument.")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
                    .multilineTextAlignment(.center)
                    .lineSpacing(4)
                    .padding(.top, KMSpacing.pt8)
            }

            KMCard(elevation: .standard) {
                VStack(alignment: .leading, spacing: KMSpacing.elementGap) {
                    Label("Voice-first learning", systemImage: "mic.fill")
                    Label("Sample-accurate metronome", systemImage: "metronome")
                    Label("16 levels from syllables to Korvai", systemImage: "map")
                }
                .kmStyle(KMFont.body, color: .kmTextSecondary)
                .labelStyle(.titleAndIcon)
            }
            .padding(.horizontal, KMSpacing.screenEdge)
        }
        .padding(.horizontal, KMSpacing.screenEdge)
    }
}

// MARK: - Page 2: Instrument

private struct InstrumentPickerPage: View {
    @Binding var selected: Instrument

    private let instruments = Instrument.allCases
    private let columns = [GridItem(.flexible()), GridItem(.flexible())]

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            VStack(spacing: KMSpacing.pt8) {
                Text("Your Instrument")
                    .kmStyle(KMFont.title1)
                Text("Konnakol adapts to how you play.")
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
                    .multilineTextAlignment(.center)
            }

            LazyVGrid(columns: columns, spacing: KMSpacing.pt12) {
                ForEach(instruments, id: \.self) { instrument in
                    InstrumentTile(instrument: instrument, isSelected: selected == instrument) {
                        withAnimation(.kmQuick) { selected = instrument }
                    }
                }
            }
            .padding(.horizontal, KMSpacing.screenEdge)
        }
        .padding(.horizontal, KMSpacing.screenEdge)
    }
}

private struct InstrumentTile: View {
    let instrument: Instrument
    let isSelected: Bool
    let onTap: () -> Void

    var body: some View {
        Button(action: onTap) {
            VStack(spacing: KMSpacing.pt8) {
                Image(systemName: instrument.systemImage)
                    .font(.system(size: 28))
                    .foregroundStyle(isSelected ? Color(hex: "#0B0D10") : .kmTextSecondary)
                Text(instrument.displayName)
                    .kmStyle(KMFont.caption, color: isSelected ? Color(hex: "#0B0D10") : .kmTextSecondary)
            }
            .frame(maxWidth: .infinity)
            .frame(height: 88)
            .background(isSelected ? Color.kmPrimary : Color.kmSurface1)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous)
                    .strokeBorder(isSelected ? Color.kmPrimary : Color.clear, lineWidth: 1.5)
            )
            .shadow(color: isSelected ? Color.kmPrimary.opacity(0.3) : .clear, radius: 12)
        }
        .buttonStyle(.plain)
        .animation(.kmQuick, value: isSelected)
    }
}

// MARK: - Page 3: Experience

private struct ExperiencePage: View {
    @Binding var selected: ExperienceLevel

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            VStack(spacing: KMSpacing.pt8) {
                Text("Your Experience")
                    .kmStyle(KMFont.title1)
                Text("We'll start you in the right place.")
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
            }

            VStack(spacing: KMSpacing.pt12) {
                ForEach(ExperienceLevel.allCases, id: \.self) { level in
                    ExperienceTile(level: level, isSelected: selected == level) {
                        withAnimation(.kmQuick) { selected = level }
                    }
                }
            }
            .padding(.horizontal, KMSpacing.screenEdge)
        }
        .padding(.horizontal, KMSpacing.screenEdge)
    }
}

private struct ExperienceTile: View {
    let level: ExperienceLevel
    let isSelected: Bool
    let onTap: () -> Void

    var subtitle: String {
        switch level {
        case .beginner:     return "Never counted rhythms out loud"
        case .someTraining: return "Familiar with time signatures, played with a metronome"
        case .advanced:     return "Reading rhythms, polyrhythm experience"
        }
    }

    var body: some View {
        Button(action: onTap) {
            HStack(spacing: KMSpacing.pt12) {
                ZStack {
                    Circle()
                        .strokeBorder(isSelected ? Color.kmPrimary : Color.kmSurface3, lineWidth: 1.5)
                        .frame(width: 24, height: 24)
                    if isSelected {
                        Circle().fill(Color.kmPrimary).frame(width: 12, height: 12)
                    }
                }

                VStack(alignment: .leading, spacing: 4) {
                    Text(level.displayName)
                        .kmStyle(KMFont.body, color: isSelected ? .kmTextPrimary : .kmTextSecondary)
                    Text(subtitle)
                        .kmStyle(KMFont.caption, color: .kmTextTertiary)
                }
                Spacer()
            }
            .padding(KMSpacing.cardPadding)
            .background(isSelected ? Color.kmSurface2 : Color.kmSurface1)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous)
                    .strokeBorder(isSelected ? Color.kmPrimary.opacity(0.5) : Color.clear, lineWidth: 1)
            )
        }
        .buttonStyle(.plain)
        .animation(.kmQuick, value: isSelected)
    }
}

// MARK: - Yantra Background

/// Barely-visible geometric grid — subtle nod to yantra geometry without kitsch.
private struct YantraBackground: View {
    var body: some View {
        Canvas { ctx, size in
            let cx = size.width / 2
            let cy = size.height / 2
            var path = Path()
            // Concentric circles
            for r in stride(from: 60.0, through: 320.0, by: 60.0) {
                path.addEllipse(in: CGRect(x: cx - r, y: cy - r, width: r * 2, height: r * 2))
            }
            // Cross-hairs
            path.move(to: CGPoint(x: cx, y: 0));   path.addLine(to: CGPoint(x: cx, y: size.height))
            path.move(to: CGPoint(x: 0, y: cy));   path.addLine(to: CGPoint(x: size.width, y: cy))
            // Diagonal
            path.move(to: CGPoint(x: cx - 300, y: cy - 300))
            path.addLine(to: CGPoint(x: cx + 300, y: cy + 300))
            path.move(to: CGPoint(x: cx + 300, y: cy - 300))
            path.addLine(to: CGPoint(x: cx - 300, y: cy + 300))

            ctx.stroke(path, with: .color(Color.kmPrimary.opacity(0.04)), lineWidth: 1)
        }
        .ignoresSafeArea()
    }
}
