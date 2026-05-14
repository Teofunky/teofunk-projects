import SwiftUI

// MARK: - Type Scale

/// All type styles. Use semantic names, never raw font sizes in views.
enum KMFont {

    // MARK: Variants

    /// 40pt Light — BPM display, hero numbers.
    static let display: Font = .system(size: 40, weight: .light, design: .default)

    /// 28pt Semibold — Screen titles.
    static let title1: Font = .system(size: 28, weight: .semibold, design: .default)

    /// 22pt Semibold — Section headings.
    static let title2: Font = .system(size: 22, weight: .semibold, design: .default)

    /// 17pt Regular — Long descriptions, lesson body text.
    static let bodyLarge: Font = .system(size: 17, weight: .regular, design: .default)

    /// 15pt Regular — Standard UI text.
    static let body: Font = .system(size: 15, weight: .regular, design: .default)

    /// 13pt Medium — Labels, status indicators.
    static let caption: Font = .system(size: 13, weight: .medium, design: .default)

    /// 11pt Medium, tracked — Badges, "PRACTICED 14 DAYS" micro labels.
    static let micro: Font = .system(size: 11, weight: .medium, design: .default)

    // MARK: Syllable (monospaced) — critical for the "code / formula" aesthetic

    /// 24pt monospaced Medium — Syllable chips in lesson cards.
    static let syllableSmall: Font = .system(size: 24, weight: .medium, design: .monospaced)

    /// 36pt monospaced Medium — Syllables in the centre of TalaWheelView.
    static let syllableMedium: Font = .system(size: 36, weight: .medium, design: .monospaced)

    /// 48pt monospaced Medium — Large syllable on Listen/Speak steps.
    static let syllableLarge: Font = .system(size: 48, weight: .medium, design: .monospaced)
}

// MARK: - Tracking Modifiers

extension View {
    /// −0.02 em tracking for display text (premium, condensed feel).
    func kmTrackingDisplay() -> some View {
        self.tracking(-0.8) // ≈ −0.02 em at 40 pt
    }

    /// +0.05 em tracking for micro / caps labels.
    func kmTrackingMicro() -> some View {
        self.tracking(0.55) // ≈ +0.05 em at 11 pt
    }
}

// MARK: - Text Style ViewModifier

struct KMTextStyle: ViewModifier {
    let font: Font
    let color: Color
    let tracking: CGFloat

    func body(content: Content) -> some View {
        content
            .font(font)
            .foregroundStyle(color)
            .tracking(tracking)
    }
}

extension View {
    func kmStyle(
        _ font: Font,
        color: Color = .kmTextPrimary,
        tracking: CGFloat = 0
    ) -> some View {
        modifier(KMTextStyle(font: font, color: color, tracking: tracking))
    }
}
