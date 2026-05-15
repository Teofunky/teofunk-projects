import SwiftUI

// MARK: - SkillNodeView

/// Single node on the skill tree. Four visual states matching the design brief.
struct SkillNodeView: View {
    let node: LearnViewModel.LevelNode
    @State private var isPulsing = false

    var diameter: CGFloat {
        switch node.state {
        case .completed: return 56
        case .current:   return 64
        case .available: return 56
        case .locked:    return 48
        }
    }

    var body: some View {
        Button {
            HapticEngine.impact(.light)
        } label: {
            ZStack {
                // Glow ring for current node
                if node.state == .current {
                    Circle()
                        .fill(Color.kmPrimary.opacity(0.15))
                        .frame(width: diameter + 20, height: diameter + 20)
                        .scaleEffect(isPulsing ? 1.12 : 1.0)
                        .animation(
                            .easeInOut(duration: 1.8).repeatForever(autoreverses: true),
                            value: isPulsing
                        )
                }

                // Main circle
                Circle()
                    .fill(fillColor)
                    .frame(width: diameter, height: diameter)
                    .overlay(
                        Circle().strokeBorder(strokeColor, lineWidth: strokeWidth)
                    )
                    .shadow(color: shadowColor, radius: 12)

                // Icon / content
                nodeIcon
            }
        }
        .buttonStyle(.plain)
        .onAppear { if node.state == .current { isPulsing = true } }
    }

    // MARK: Visual Properties

    private var fillColor: Color {
        switch node.state {
        case .completed: return Color.kmPrimary
        case .current:   return Color.kmSurface2
        case .available: return Color.kmSurface1
        case .locked:    return Color(hex: "#0F1116")
        }
    }

    private var strokeColor: Color {
        switch node.state {
        case .completed: return Color.clear
        case .current:   return Color.kmPrimary
        case .available: return Color.kmSurface3
        case .locked:    return Color.kmSurface2
        }
    }

    private var strokeWidth: CGFloat {
        node.state == .current ? 2 : 1
    }

    private var shadowColor: Color {
        switch node.state {
        case .completed: return Color.kmPrimary.opacity(0.35)
        case .current:   return Color.kmPrimary.opacity(0.25)
        default:         return .clear
        }
    }

    @ViewBuilder
    private var nodeIcon: some View {
        switch node.state {
        case .completed:
            Image(systemName: "checkmark")
                .font(.system(size: 20, weight: .semibold))
                .foregroundStyle(Color(hex: "#0B0D10"))

        case .current:
            VStack(spacing: 2) {
                Text("\(node.meta.level)")
                    .font(.system(size: 16, weight: .semibold, design: .default))
                    .foregroundStyle(Color.kmPrimary)
            }

        case .available:
            Text("\(node.meta.level)")
                .font(.system(size: 15, weight: .medium))
                .foregroundStyle(Color.kmTextSecondary)

        case .locked:
            Image(systemName: "lock.fill")
                .font(.system(size: 14))
                .foregroundStyle(Color.kmTextDisabled)
        }
    }
}
