import SwiftUI

struct ListenStepView: View {
    let syllableGroup: SyllableGroup
    let repetitions: Int
    let onComplete: () -> Void

    @State private var isPlaying = false
    @State private var activeSyllableIndex: Int = -1
    @State private var playCount = 0
    @State private var timer: Timer?

    // Tempo: one syllable per 0.35s at ~170 BPM feel for demo
    private let syllableInterval: TimeInterval = 0.35

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            // Step label
            Text("LISTEN")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            // Syllable display — the centre mono view
            HStack(spacing: KMSpacing.pt16) {
                ForEach(Array(syllableGroup.syllables.enumerated()), id: \.offset) { i, syl in
                    Text(syl.display.uppercased())
                        .font(.system(size: 32, weight: .medium, design: .monospaced))
                        .foregroundStyle(i == activeSyllableIndex ? Color.kmPrimary : Color.kmTextDisabled)
                        .scaleEffect(i == activeSyllableIndex ? 1.15 : 1.0)
                        .shadow(color: i == activeSyllableIndex ? Color.kmPrimary.opacity(0.4) : .clear, radius: 10)
                        .animation(.kmBeatOn, value: activeSyllableIndex)
                }
            }
            .padding(.vertical, KMSpacing.pt24)

            Text(syllableGroup.standardName)
                .kmStyle(KMFont.caption, color: .kmTextTertiary)

            Text("Listen \(repetitions)×")
                .kmStyle(KMFont.body, color: .kmTextSecondary)

            // Play button
            Button { togglePlay() } label: {
                ZStack {
                    Circle()
                        .fill(Color.kmPrimary)
                        .frame(width: 72, height: 72)
                        .shadow(color: Color.kmPrimary.opacity(isPlaying ? 0.4 : 0.15), radius: isPlaying ? 20 : 8)
                    Image(systemName: isPlaying ? "stop.fill" : "play.fill")
                        .font(.system(size: 28))
                        .foregroundStyle(Color(hex: "#0B0D10"))
                }
            }
            .buttonStyle(.plain)
            .animation(.kmQuick, value: isPlaying)

            if playCount >= repetitions {
                Text("✓ Heard it \(playCount) times")
                    .kmStyle(KMFont.caption, color: .kmSuccess)
                    .transition(.opacity)
            }

            Spacer()

            KMButton("Continue") { onComplete() }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
    }

    private func togglePlay() {
        if isPlaying { stopPlayback() } else { startPlayback() }
    }

    private func startPlayback() {
        isPlaying = true
        var sylIdx = 0
        let total = syllableGroup.syllables.count

        timer = Timer.scheduledTimer(withTimeInterval: syllableInterval, repeats: true) { _ in
            activeSyllableIndex = sylIdx
            HapticEngine.impact(.light)
            sylIdx += 1

            if sylIdx >= total {
                sylIdx = 0
                playCount += 1
                if playCount >= repetitions {
                    stopPlayback()
                }
            }
        }
        RunLoop.main.add(timer!, forMode: .common)
    }

    private func stopPlayback() {
        timer?.invalidate()
        timer = nil
        isPlaying = false
        withAnimation(.kmQuick) { activeSyllableIndex = -1 }
    }
}
