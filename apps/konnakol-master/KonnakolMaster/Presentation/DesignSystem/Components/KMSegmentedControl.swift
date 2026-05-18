import SwiftUI

// MARK: - KMSegmentedControl

/// Custom segmented control that matches the dark design system.
/// SwiftUI's native Picker with .segmented style cannot be themed to dark
/// without appearance hacks, so we build our own.
struct KMSegmentedControl<T: Hashable>: View {
    let options: [T]
    @Binding var selection: T
    var labelBuilder: (T) -> String

    init(
        options: [T],
        selection: Binding<T>,
        labelBuilder: @escaping (T) -> String
    ) {
        self.options = options
        self._selection = selection
        self.labelBuilder = labelBuilder
    }

    var body: some View {
        HStack(spacing: 2) {
            ForEach(options, id: \.self) { option in
                segmentButton(option)
            }
        }
        .padding(3)
        .background(Color.kmSurface2)
        .clipShape(RoundedRectangle(cornerRadius: KMRadius.button, style: .continuous))
    }

    private func segmentButton(_ option: T) -> some View {
        let isSelected = option == selection
        return Button {
            HapticEngine.impact(.light)
            withAnimation(.kmQuick) { selection = option }
        } label: {
            Text(labelBuilder(option))
                .kmStyle(KMFont.caption, color: isSelected ? Color(hex: "#0B0D10") : .kmTextSecondary)
                .frame(maxWidth: .infinity)
                .frame(height: 36)
                .background(isSelected ? Color.kmPrimary : Color.clear)
                .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Preview

#Preview {
    @Previewable @State var selected = "Adi"
    KMSegmentedControl(
        options: ["Adi", "Rupaka", "Misra", "Khanda"],
        selection: $selected,
        labelBuilder: { $0 }
    )
    .padding(KMSpacing.screenEdge)
    .background(Color.kmBase)
}
