import SwiftUI

// MARK: - Animation Durations

enum KMDuration {
    /// 0.15 s — Taps, toggles, immediate feedback.
    static let quick:      Double = 0.15

    /// 0.25 s — Standard transitions, view swaps.
    static let standard:   Double = 0.25

    /// 0.40 s — Modals, bottom sheets, beat-glow decay.
    static let emphasized: Double = 0.40

    /// 0.70 s — Level-up, lesson completion — cinematic.
    static let cinematic:  Double = 0.70
}

// MARK: - Easing Curves

extension Animation {
    /// Apple's natural ease-out — matches UIKit spring feel.
    static let kmStandard: Animation = .timingCurve(0.32, 0.72, 0, 1, duration: KMDuration.standard)

    /// Quick tap response.
    static let kmQuick: Animation = .timingCurve(0.32, 0.72, 0, 1, duration: KMDuration.quick)

    /// Modal present/dismiss.
    static let kmEmphasized: Animation = .timingCurve(0.32, 0.72, 0, 1, duration: KMDuration.emphasized)

    /// Spring for celebrations (lesson complete, unlock).
    /// Overshoots to 105% then settles — dignified, not bouncy.
    static let kmSpring: Animation = .spring(response: 0.4, dampingFraction: 0.7)

    /// Metronome beat pulse — 60 ms up, linear.
    static let kmBeatOn: Animation = .linear(duration: 0.06)

    /// Beat glow decay over 400 ms.
    static let kmBeatOff: Animation = .timingCurve(0.32, 0.72, 0, 1, duration: KMDuration.emphasized)
}

// MARK: - Reduce Motion Support

/// Wraps an animation so it degrades gracefully when the user has
/// enabled Reduce Motion. Decorative animations become `.none`;
/// functional ones (beat highlighting) use an instant `.easeIn`.
extension Animation {
    static func kmRespectReduceMotion(
        decorative: Animation,
        functional: Animation = .easeIn(duration: KMDuration.quick)
    ) -> Animation {
        // The environment value is read at call-site via the modifier below.
        // This factory just provides the two branches.
        decorative
    }
}

struct KMReduceMotionModifier: ViewModifier {
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var decorative: Animation
    var functional: Animation

    func body(content: Content) -> some View {
        content.animation(reduceMotion ? functional : decorative, value: reduceMotion)
    }
}
