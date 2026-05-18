import SwiftUI

// MARK: - KMButton

/// Primary and secondary button following the Konnakol Master design system.
/// - Primary: Saffron Glow (#E8A547) fill with glow on active/pressed state.
/// - Secondary: Surface-2 background, primary-color border and label.
/// - Ghost: text-only, primary-color label.
struct KMButton: View {
    enum Style {
        case primary, secondary, ghost
    }

    let title: String
    let style: Style
    let action: () -> Void

    init(_ title: String, style: Style = .primary, action: @escaping () -> Void) {
        self.title = title
        self.style = style
        self.action = action
    }

    var body: some View {
        Button(action: {
            HapticEngine.impact(.medium)
            action()
        }) {
            Text(title)
                .kmStyle(KMFont.body, color: labelColor, tracking: 0)
                .frame(maxWidth: .infinity)
                .frame(height: KMSpacing.minTouchTarget)
                .background(backgroundView)
                .clipShape(RoundedRectangle(cornerRadius: KMRadius.button, style: .continuous))
                .overlay(borderOverlay)
        }
        .buttonStyle(KMButtonPressStyle(glowColor: style == .primary ? .kmPrimary : .clear))
    }

    // MARK: Private helpers

    private var labelColor: Color {
        switch style {
        case .primary:   return Color(hex: "#0B0D10") // dark text on saffron
        case .secondary: return .kmPrimary
        case .ghost:     return .kmPrimary
        }
    }

    @ViewBuilder
    private var backgroundView: some View {
        switch style {
        case .primary:
            Color.kmPrimary
        case .secondary:
            Color.kmSurface2
        case .ghost:
            Color.clear
        }
    }

    @ViewBuilder
    private var borderOverlay: some View {
        if style == .secondary {
            RoundedRectangle(cornerRadius: KMRadius.button, style: .continuous)
                .strokeBorder(Color.kmPrimary, lineWidth: 1)
        } else {
            EmptyView()
        }
    }
}

// MARK: - Press Style (glow on active)

private struct KMButtonPressStyle: ButtonStyle {
    var glowColor: Color

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.97 : 1.0)
            .shadow(
                color: glowColor.opacity(configuration.isPressed ? 0.50 : 0.0),
                radius: configuration.isPressed ? 20 : 0
            )
            .animation(.kmQuick, value: configuration.isPressed)
    }
}

// MARK: - Haptic Helper

/// Centralised haptic calls so every button plays consistent feedback.
enum HapticEngine {
    static func impact(_ style: UIImpactFeedbackGenerator.FeedbackStyle) {
        UIImpactFeedbackGenerator(style: style).impactOccurred()
    }

    static func notification(_ type: UINotificationFeedbackGenerator.FeedbackType) {
        UINotificationFeedbackGenerator().notificationOccurred(type)
    }
}

// MARK: - Preview

#Preview {
    VStack(spacing: KMSpacing.elementGap) {
        KMButton("Begin Lesson", style: .primary) {}
        KMButton("View Details", style: .secondary) {}
        KMButton("Skip for now", style: .ghost) {}
    }
    .padding(KMSpacing.screenEdge)
    .background(Color.kmBase)
}
