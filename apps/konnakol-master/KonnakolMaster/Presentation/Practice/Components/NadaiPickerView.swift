import SwiftUI

// MARK: - NadaiPickerView
//
// Compact pill row for selecting nadai subdivisions (3 · 4 · 5 · 7 · 9).
// Pills highlight in saffron when selected; inactive pills are Surface 2.

struct NadaiPickerView: View {
    @Binding var selection: Nadai
    let onChange: (Nadai) -> Void

    var body: some View {
        HStack(spacing: KMSpacing.pt8) {
            ForEach(Nadai.allCases, id: \.self) { nadai in
                pill(nadai)
            }
        }
    }

    private func pill(_ nadai: Nadai) -> some View {
        let isSelected = nadai == selection
        return Button {
            HapticEngine.impact(.light)
            withAnimation(.kmQuick) { selection = nadai }
            onChange(nadai)
        } label: {
            VStack(spacing: 2) {
                Text(nadai.shortLabel)
                    .font(.system(size: 17, weight: .semibold, design: .monospaced))
                    .foregroundStyle(isSelected ? Color(hex: "#0B0D10") : Color.kmTextSecondary)
                Text(nadai.displayName)
                    .font(.system(size: 9, weight: .medium))
                    .foregroundStyle(isSelected ? Color(hex: "#0B0D10").opacity(0.7) : Color.kmTextDisabled)
            }
            .frame(width: 52, height: 44)
            .background(isSelected ? Color.kmPrimary : Color.kmSurface2)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip, style: .continuous))
            .shadow(color: isSelected ? Color.kmPrimary.opacity(0.35) : .clear, radius: 8)
        }
        .buttonStyle(.plain)
        .animation(.kmQuick, value: isSelected)
    }
}
