import SwiftUI

struct ClapStepView: View {
    let tala: Tala
    let nadai: Nadai
    let cycles: Int
    let onComplete: () -> Void

    @Environment(AudioEngine.self) private var audio
    @State private var cyclesCompleted = 0
    @State private var lastBeat = -1

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Text("CLAP")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            Text("Follow the hand pattern")
                .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)

            // Mini tala indicator
            HStack(spacing: KMSpacing.pt8) {
                ForEach(0..<tala.totalBeats, id: \.self) { beat in
                    beatDot(beat: beat)
                }
            }
            .padding(.vertical, KMSpacing.pt24)

            // Gesture icon for current beat
            gestureIcon(for: audio.currentBeat)
                .font(.system(size: 64))
                .foregroundStyle(Color.kmPrimary)
                .kmGlow(radius: 20)
                .animation(.kmBeatOn, value: audio.currentBeat)

            Text("\(tala.name) · \(cyclesCompleted)/\(cycles) cycles")
                .kmStyle(KMFont.caption, color: .kmTextTertiary)

            Spacer()

            KMButton(audio.isPlaying ? "Stop" : "Start") {
                if audio.isPlaying { audio.stop() }
                else { audio.start(bpm: 70, tala: tala, nadai: nadai) }
            }
            .padding(.horizontal, KMSpacing.screenEdge)

            if cyclesCompleted >= cycles {
                KMButton("Continue") { onComplete() }
                    .padding(.horizontal, KMSpacing.screenEdge)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }

            Spacer(minLength: KMSpacing.pt32)
        }
        .onChange(of: audio.currentBeat) { _, beat in
            if beat == 0 && lastBeat != 0 { cyclesCompleted += 1 }
            lastBeat = beat
        }
        .onDisappear { audio.stop() }
    }

    private func beatDot(beat: Int) -> some View {
        let isActive = beat == audio.currentBeat
        return Circle()
            .fill(isActive ? Color.kmPrimary : Color.kmSurface2)
            .frame(width: isActive ? 14 : 10, height: isActive ? 14 : 10)
            .shadow(color: isActive ? Color.kmPrimary.opacity(0.5) : .clear, radius: 6)
            .animation(.kmBeatOn, value: audio.currentBeat)
    }

    private func gestureIcon(for beat: Int) -> Image {
        // Derive gesture from anga position
        var cursor = 0
        for anga in tala.angas {
            let end = cursor + anga.beatCount
            if beat >= cursor && beat < end {
                switch anga {
                case .anudrutam: return Image(systemName: "hands.clap.fill")
                case .drutam:
                    return beat == cursor
                        ? Image(systemName: "hands.clap.fill")
                        : Image(systemName: "hand.wave.fill")
                case .laghu:
                    if beat == cursor { return Image(systemName: "hands.clap.fill") }
                    let finger = beat - cursor // 1, 2, 3…
                    return Image(systemName: "hand.\(min(finger, 5)).raised.fill")
                }
            }
            cursor = end
        }
        return Image(systemName: "hands.clap.fill")
    }
}
