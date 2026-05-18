import SwiftUI

// MARK: - SyllableCenterView
//
// Displays the current syllable group in the centre of TalaWheelView.
// Each syllable cross-fades as activeIndex advances.
// Uses monospaced font — the "code/formula" aesthetic from the design brief.

struct SyllableCenterView: View {
    let syllableGroup: SyllableGroup
    let activeIndex: Int
    let isPlaying: Bool

    var body: some View {
        VStack(spacing: 8) {
            // Syllables in a row, active one highlighted
            HStack(spacing: syllableSpacing) {
                ForEach(Array(syllableGroup.syllables.enumerated()), id: \.offset) { i, syl in
                    syllableText(syl.display, isActive: i == activeIndex && isPlaying)
                }
            }
            .lineLimit(1)
            .minimumScaleFactor(0.6)
            .fixedSize(horizontal: false, vertical: true)

            // Nadai name beneath (tiny)
            if isPlaying {
                Text(syllableGroup.karnaticName)
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 1)
                    .kmTrackingMicro()
                    .transition(.opacity)
            }
        }
    }

    private func syllableText(_ text: String, isActive: Bool) -> some View {
        Text(text.uppercased())
            .font(fontSize)
            .fontDesign(.monospaced)
            .fontWeight(.medium)
            .foregroundStyle(isActive ? Color.kmPrimary : Color.kmTextDisabled)
            .scaleEffect(isActive ? 1.12 : 1.0)
            .shadow(color: isActive ? Color.kmPrimary.opacity(0.55) : .clear, radius: 8)
            .animation(.kmBeatOn, value: activeIndex)
    }

    // Tighter spacing for longer groups (7, 9 syllables)
    private var syllableSpacing: CGFloat {
        switch syllableGroup.count {
        case 1...4: return 10
        case 5...7: return 7
        default:    return 5
        }
    }

    private var fontSize: Font {
        switch syllableGroup.count {
        case 1...4: return .system(size: 28, weight: .medium, design: .monospaced)
        case 5...7: return .system(size: 22, weight: .medium, design: .monospaced)
        default:    return .system(size: 18, weight: .medium, design: .monospaced)
        }
    }
}
