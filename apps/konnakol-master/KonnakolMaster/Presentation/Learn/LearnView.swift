import SwiftUI

// MARK: - LearnView (Skill Tree)

struct LearnView: View {
    @Environment(ProgressStore.self) private var progressStore
    @State private var vm = LearnViewModel()
    @State private var selectedNode: LearnViewModel.LevelNode?

    var body: some View {
        NavigationStack {
            ZStack {
                Color.kmBase.ignoresSafeArea()
                TopographicBackground()

                ScrollView {
                    VStack(spacing: 0) {
                        header
                            .padding(.horizontal, KMSpacing.screenEdge)
                            .padding(.top, KMSpacing.pt12)
                            .padding(.bottom, KMSpacing.pt32)

                        skillTree
                            .padding(.bottom, KMSpacing.pt64)
                    }
                }
            }
            .navigationBarHidden(true)
            .onAppear { vm.load(progress: progressStore.progress) }
            .sheet(item: $selectedNode) { node in
                LessonPreviewSheet(node: node)
                    .presentationDetents([.medium])
                    .presentationDragIndicator(.visible)
            }
        }
    }

    // MARK: Header

    private var header: some View {
        HStack(alignment: .bottom) {
            VStack(alignment: .leading, spacing: 4) {
                Text("YOUR JOURNEY")
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                    .kmTrackingMicro()
                Text("Skill Tree")
                    .kmStyle(KMFont.title1)
            }
            Spacer()
            levelBadge
        }
    }

    private var levelBadge: some View {
        VStack(spacing: 2) {
            Text("\(progressStore.progress.currentLevel)")
                .kmStyle(KMFont.title2, color: .kmPrimary)
            Text("of 16")
                .kmStyle(KMFont.micro, color: .kmTextTertiary)
                .kmTrackingMicro()
        }
        .padding(.horizontal, KMSpacing.pt12)
        .padding(.vertical, KMSpacing.pt8)
        .background(Color.kmSurface1)
        .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip))
        .kmGlow(radius: 8)
    }

    // MARK: Skill Tree

    private var skillTree: some View {
        GeometryReader { geo in
            let centerX = geo.size.width / 2
            let nodeSpacing: CGFloat = 100

            ZStack(alignment: .top) {
                // Connection lines drawn first (under nodes)
                connectionLines(centerX: centerX, nodeSpacing: nodeSpacing)

                // Nodes
                ForEach(Array(vm.nodes.enumerated()), id: \.element.id) { i, node in
                    let yPos = CGFloat(i) * nodeSpacing + 32
                    let xPos = centerX + CGFloat(node.xOffset) * geo.size.width

                    SkillNodeView(node: node)
                        .position(x: xPos, y: yPos)
                        .onTapGesture { selectedNode = node }

                    // Level label below node
                    nodeLabelView(node)
                        .position(x: xPos, y: yPos + 42)
                }
            }
            .frame(height: CGFloat(vm.nodes.count) * nodeSpacing + 80)
        }
        .frame(height: CGFloat(LessonLibrary.all.count) * 100 + 80)
    }

    @ViewBuilder
    private func nodeLabelView(_ node: LearnViewModel.LevelNode) -> some View {
        Text(node.meta.title)
            .kmStyle(
                KMFont.caption,
                color: node.state == .locked ? .kmTextDisabled : .kmTextSecondary
            )
            .lineLimit(1)
            .frame(width: 100)
    }

    private func connectionLines(centerX: CGFloat, nodeSpacing: CGFloat) -> some View {
        Canvas { ctx, size in
            for i in 0..<(vm.nodes.count - 1) {
                let curr = vm.nodes[i]
                let next = vm.nodes[i + 1]
                let y1 = CGFloat(i) * nodeSpacing + 32
                let y2 = CGFloat(i + 1) * nodeSpacing + 32
                let x1 = centerX + CGFloat(curr.xOffset) * size.width
                let x2 = centerX + CGFloat(next.xOffset) * size.width

                var path = Path()
                path.move(to: CGPoint(x: x1, y: y1))
                // Bezier curve for organic feel
                path.addCurve(
                    to: CGPoint(x: x2, y: y2),
                    control1: CGPoint(x: x1, y: y1 + nodeSpacing * 0.5),
                    control2: CGPoint(x: x2, y: y2 - nodeSpacing * 0.5)
                )

                let isCompleted = curr.state == .completed
                let color = isCompleted
                    ? Color.kmPrimary.opacity(0.6)
                    : Color.kmSurface2.opacity(0.8)

                if isCompleted {
                    ctx.stroke(path, with: .color(color), style: StrokeStyle(lineWidth: 2))
                } else {
                    ctx.stroke(path, with: .color(color), style: StrokeStyle(lineWidth: 1.5, dash: [6, 5]))
                }
            }
        }
    }
}

// MARK: - Lesson Preview Sheet

private struct LessonPreviewSheet: View {
    let node: LearnViewModel.LevelNode
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(alignment: .leading, spacing: KMSpacing.sectionGap) {
            // Drag indicator space handled by presentationDragIndicator

            VStack(alignment: .leading, spacing: KMSpacing.pt8) {
                Text("LEVEL \(node.meta.level) · \(node.meta.tier.rawValue.uppercased())")
                    .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                    .kmTrackingMicro()
                Text(node.meta.title)
                    .kmStyle(KMFont.title2)
                Text(node.meta.subtitle)
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
            }

            HStack(spacing: KMSpacing.pt16) {
                statPill(label: "\(node.meta.estimatedMinutes) min", icon: "clock")
                if let tala = node.meta.talaId {
                    statPill(label: tala.capitalized, icon: "circle.hexagongrid")
                }
            }

            Spacer()

            KMButton(node.state == .locked ? "Locked" : "Begin Lesson") {
                dismiss()
            }
            .disabled(node.state == .locked)
        }
        .padding(KMSpacing.screenEdge)
        .background(Color.kmSurface1.ignoresSafeArea())
    }

    private func statPill(label: String, icon: String) -> some View {
        Label(label, systemImage: icon)
            .kmStyle(KMFont.caption, color: .kmTextSecondary)
            .padding(.horizontal, KMSpacing.pt12)
            .padding(.vertical, KMSpacing.pt8)
            .background(Color.kmSurface2)
            .clipShape(Capsule())
    }
}

// MARK: - Topographic Background

/// Subtle horizontal lines that create a "mountain map" atmosphere.
private struct TopographicBackground: View {
    var body: some View {
        Canvas { ctx, size in
            for i in stride(from: 0.0, through: size.height, by: 40) {
                var path = Path()
                path.move(to: CGPoint(x: 0, y: i))
                path.addLine(to: CGPoint(x: size.width, y: i))
                ctx.stroke(path, with: .color(Color.kmSurface1.opacity(0.5)), lineWidth: 0.5)
            }
        }
        .ignoresSafeArea()
    }
}

