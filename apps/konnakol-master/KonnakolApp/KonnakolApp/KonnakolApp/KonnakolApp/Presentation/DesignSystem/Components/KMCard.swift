import SwiftUI

// MARK: - KMCard

/// Elevated card container. Wraps arbitrary content with standard padding,
/// radius, and surface background. Elevation is conveyed via color step,
/// not shadow (dark mode convention).
struct KMCard<Content: View>: View {
    enum Elevation {
        /// Surface 1 (#15181D) — Standard cards.
        case standard
        /// Surface 2 (#1E2229) — Nested / highlighted cards.
        case elevated
        /// Surface 3 (#272C35) — Modals, popovers.
        case modal
    }

    var elevation: Elevation
    var padding: CGFloat
    @ViewBuilder var content: () -> Content

    init(
        elevation: Elevation = .standard,
        padding: CGFloat = KMSpacing.cardPadding,
        @ViewBuilder content: @escaping () -> Content
    ) {
        self.elevation = elevation
        self.padding = padding
        self.content = content
    }

    var body: some View {
        content()
            .padding(padding)
            .background(backgroundColor)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.card, style: .continuous))
    }

    private var backgroundColor: Color {
        switch elevation {
        case .standard: return .kmSurface1
        case .elevated: return .kmSurface2
        case .modal:    return .kmSurface3
        }
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: KMSpacing.elementGap) {
        KMCard {
            Text("Standard card")
                .kmStyle(KMFont.body)
        }
        KMCard(elevation: .elevated) {
            Text("Elevated card")
                .kmStyle(KMFont.body)
        }
        KMCard(elevation: .modal) {
            Text("Modal surface")
                .kmStyle(KMFont.body)
        }
    }
    .padding(KMSpacing.screenEdge)
    .background(Color.kmBase)
}
