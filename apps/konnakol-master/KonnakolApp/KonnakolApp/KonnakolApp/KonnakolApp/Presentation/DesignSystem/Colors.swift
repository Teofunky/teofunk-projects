import SwiftUI

// MARK: - Color Constants

extension Color {

    // MARK: Surfaces (4-level elevation system)

    /// #0B0D10 — Base background. Never use pure black.
    static let kmBase = Color(hex: "#0B0D10")

    /// #15181D — Cards, panels, bottom navigation.
    static let kmSurface1 = Color(hex: "#15181D")

    /// #1E2229 — Nested cards, hover states, active elements.
    static let kmSurface2 = Color(hex: "#1E2229")

    /// #272C35 — Modals, dropdowns, tooltips.
    static let kmSurface3 = Color(hex: "#272C35")

    // MARK: Accent

    /// #E8A547 — Saffron Glow. Sam beat, active CTA, current skill node.
    /// Use sparingly and meaningfully — this is the app's signature color.
    static let kmPrimary = Color(hex: "#E8A547")

    /// #8A6328 — Dimmed primary for past/inactive beat markers.
    static let kmPrimaryDim = Color(hex: "#8A6328")

    // MARK: Semantic

    /// #5BC97E — Correct syllable, completed lesson.
    static let kmSuccess = Color(hex: "#5BC97E")

    /// #E87753 — Near-miss (±60–100 ms drift).
    static let kmWarning = Color(hex: "#E87753")

    /// #D14D4D — Miss or error. Noble red, not aggressive.
    static let kmError = Color(hex: "#D14D4D")

    /// #7C8BD9 — Informational hints and tooltips.
    static let kmInfo = Color(hex: "#7C8BD9")

    // MARK: Text

    /// #F1F2F4 — Body copy, headlines.
    static let kmTextPrimary = Color(hex: "#F1F2F4")

    /// #9BA0A8 — Descriptions, secondary labels.
    static let kmTextSecondary = Color(hex: "#9BA0A8")

    /// #5E646E — Captions, hints, placeholders.
    static let kmTextTertiary = Color(hex: "#5E646E")

    /// #3A3F46 — Locked / disabled states.
    static let kmTextDisabled = Color(hex: "#3A3F46")
}

// MARK: - Hex Initialiser

extension Color {
    /// Creates a Color from a 6-digit CSS hex string (e.g. "#E8A547" or "E8A547").
    init(hex: String) {
        let hex = hex.trimmingCharacters(in: CharacterSet(charactersIn: "#"))
        let scanner = Scanner(string: hex)
        var rgb: UInt64 = 0
        scanner.scanHexInt64(&rgb)
        let r = Double((rgb >> 16) & 0xFF) / 255.0
        let g = Double((rgb >> 8)  & 0xFF) / 255.0
        let b = Double( rgb        & 0xFF) / 255.0
        self.init(red: r, green: g, blue: b)
    }
}

// MARK: - UIColor Bridge (for AVFoundation / UIKit interop layers)

extension UIColor {
    static let kmPrimary      = UIColor(Color.kmPrimary)
    static let kmBase         = UIColor(Color.kmBase)
    static let kmSurface1     = UIColor(Color.kmSurface1)
    static let kmTextPrimary  = UIColor(Color.kmTextPrimary)
    static let kmError        = UIColor(Color.kmError)
    static let kmSuccess      = UIColor(Color.kmSuccess)
}

// MARK: - Glow Modifier

struct KMGlow: ViewModifier {
    var color: Color = .kmPrimary
    var radius: CGFloat = 16

    func body(content: Content) -> some View {
        content
            .shadow(color: color.opacity(0.30), radius: radius)
    }
}

extension View {
    /// Applies the signature Saffron Glow (or custom color) drop shadow.
    func kmGlow(color: Color = .kmPrimary, radius: CGFloat = 16) -> some View {
        modifier(KMGlow(color: color, radius: radius))
    }
}
