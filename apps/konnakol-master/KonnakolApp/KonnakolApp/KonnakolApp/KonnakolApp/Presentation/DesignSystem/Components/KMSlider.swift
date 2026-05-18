import SwiftUI

// MARK: - KMSlider

/// Horizontal slider with large ±1 stepper buttons flanking it.
/// Used for BPM control on the Practice screen.
/// Minimum touch target for +/- is 44×44 pt (Apple HIG).
struct KMSlider: View {
    @Binding var value: Double
    let range: ClosedRange<Double>
    let step: Double
    var label: String?

    init(
        value: Binding<Double>,
        in range: ClosedRange<Double>,
        step: Double = 1,
        label: String? = nil
    ) {
        self._value = value
        self.range = range
        self.step = step
        self.label = label
    }

    var body: some View {
        VStack(spacing: KMSpacing.pt8) {
            if let label {
                Text(label)
                    .kmStyle(KMFont.caption, color: .kmTextSecondary, tracking: 0)
            }

            HStack(spacing: KMSpacing.pt12) {
                stepButton(delta: -step, symbol: "minus")
                slider
                stepButton(delta: +step, symbol: "plus")
            }
        }
    }

    // MARK: Private

    private var slider: some View {
        Slider(value: $value, in: range, step: step)
            .tint(Color.kmPrimary)
    }

    private func stepButton(delta: Double, symbol: String) -> some View {
        Button {
            HapticEngine.impact(.light)
            value = (value + delta).clamped(to: range)
        } label: {
            Image(systemName: symbol)
                .font(.system(size: 15, weight: .semibold))
                .foregroundStyle(Color.kmPrimary)
                .frame(width: KMSpacing.minTouchTarget, height: KMSpacing.minTouchTarget)
                .background(Color.kmSurface2)
                .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip, style: .continuous))
        }
    }
}

// MARK: - Clamped Helper

private extension Comparable {
    func clamped(to range: ClosedRange<Self>) -> Self {
        max(range.lowerBound, min(self, range.upperBound))
    }
}

// MARK: - Preview

#Preview {
    @Previewable @State var bpm: Double = 90
    KMSlider(value: $bpm, in: 30...300, step: 1, label: "BPM")
        .padding(KMSpacing.screenEdge)
        .background(Color.kmBase)
}
