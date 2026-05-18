import SwiftUI

// MARK: - LearnView (Skill Tree)

struct LearnView: View {
    @Environment(ProgressStore.self) private var progressStore
    @State private var vm = LearnViewModel()
    @State private var selectedNode: LearnViewModel.LevelNode?
    @State private var lessonToOpen: Lesson?

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
                LessonPreviewSheet(node: node) {
                    // Begin Lesson — dismiss sheet and open player
                    selectedNode = nil
                    DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                        lessonToOpen = LessonBuilder.build(forLevel: node.meta.level)
                    }
                }
                .presentationDetents([.medium])
                .presentationDragIndicator(.visible)
            }
            .fullScreenCover(item: $lessonToOpen) { lesson in
                LessonPlayerView(lesson: lesson)
                    .onDisappear { vm.load(progress: progressStore.progress) }
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
    // Uses VStack + offset() instead of GeometryReader + position() to avoid
    // the SwiftUI hit-testing bug where position() in ScrollView→GeometryReader
    // produces stale tap regions that don't match the rendered node positions.

    private static let nodeSpacing: CGFloat = 100
    private static let zigzagAmplitude: CGFloat = 110

    private var skillTree: some View {
        VStack(spacing: 0) {
            tierDividerRow("BEGINNER")

            ForEach(Array(vm.nodes.enumerated()), id: \.element.id) { i, node in
                if i == 5  { tierDividerRow("INTERMEDIATE") }
                if i == 10 { tierDividerRow("ADVANCED") }
                nodeRow(node, index: i)
            }
        }
        .background(connectionLinesBackground)
    }

    private func nodeRow(_ node: LearnViewModel.LevelNode, index: Int) -> some View {
        ZStack {
            VStack(spacing: 4) {
                SkillNodeView(node: node)
                Text(node.meta.title)
                    .kmStyle(
                        KMFont.caption,
                        color: node.state == .locked ? .kmTextDisabled : .kmTextSecondary
                    )
                    .lineLimit(1)
                    .frame(width: 110)
            }
            .offset(x: CGFloat(node.xOffset) * Self.zigzagAmplitude)
        }
        .frame(maxWidth: .infinity, minHeight: Self.nodeSpacing)
        .contentShape(Rectangle())
        .onTapGesture {
            HapticEngine.impact(.light)
            selectedNode = node
        }
    }

    private func tierDividerRow(_ label: String) -> some View {
        HStack(spacing: KMSpacing.pt8) {
            Rectangle().fill(Color.kmSurface2).frame(height: 1)
            Text(label)
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()
                .fixedSize()
            Rectangle().fill(Color.kmSurface2).frame(height: 1)
        }
        .padding(.horizontal, KMSpacing.pt32)
        .padding(.vertical, KMSpacing.pt8)
    }

    // Canvas drawn as a non-interactive background so it never intercepts taps.
    private var connectionLinesBackground: some View {
        Canvas { ctx, size in
            let nodes = vm.nodes
            guard nodes.count > 1 else { return }
            let spacing = Self.nodeSpacing
            let amp = Self.zigzagAmplitude
            let cx = size.width / 2

            // Approximate node y-positions within the VStack.
            // Each tier divider row adds ~36pt (8+8 vertical padding + ~20 text).
            var yOffsets: [CGFloat] = []
            var runningY: CGFloat = 36   // BEGINNER divider height
            for i in 0..<nodes.count {
                if i == 5  { runningY += 36 }
                if i == 10 { runningY += 36 }
                yOffsets.append(runningY + 32)  // 32 = half node spacing for center of node
                runningY += spacing
            }

            for i in 0..<(nodes.count - 1) {
                let curr = nodes[i]
                let next = nodes[i + 1]
                let x1 = cx + CGFloat(curr.xOffset) * amp
                let x2 = cx + CGFloat(next.xOffset) * amp
                let y1 = yOffsets[i]
                let y2 = yOffsets[i + 1]

                var path = Path()
                path.move(to: CGPoint(x: x1, y: y1))
                path.addCurve(
                    to: CGPoint(x: x2, y: y2),
                    control1: CGPoint(x: x1, y: y1 + spacing * 0.5),
                    control2: CGPoint(x: x2, y: y2 - spacing * 0.5)
                )

                let done = curr.state == .completed
                let color = done ? Color.kmPrimary.opacity(0.5) : Color.kmSurface2.opacity(0.7)
                let style = done
                    ? StrokeStyle(lineWidth: 2)
                    : StrokeStyle(lineWidth: 1.5, dash: [6, 5])
                ctx.stroke(path, with: .color(color), style: style)
            }
        }
        .allowsHitTesting(false)
    }
}

// MARK: - Lesson Preview Sheet

private struct LessonPreviewSheet: View {
    let node: LearnViewModel.LevelNode
    let onBegin: () -> Void
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
                if node.state == .locked {
                    dismiss()
                } else {
                    onBegin()
                }
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

