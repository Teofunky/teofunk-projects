import SwiftUI

struct LoopStepView: View {
    let tala: Tala
    let nadai: Nadai
    let syllableGroup: SyllableGroup
    let cycles: Int
    let onComplete: (Double, Int) -> Void

    @Environment(AudioEngine.self)    private var audio
    @Environment(VoiceAnalyzer.self)  private var analyzer
    @State private var cyclesCompleted = 0
    @State private var lastBeat = -1
    @State private var activeSylIndex = 0
    @State private var sylTimer: Timer?
    @State private var isListening = false

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Text("LOOP")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            Text("Speak along with the metronome")
                .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)

            // Syllable centre display
            HStack(spacing: KMSpacing.pt12) {
                ForEach(Array(syllableGroup.syllables.enumerated()), id: \.offset) { i, syl in
                    Text(syl.display.uppercased())
                        .font(.system(size: 34, weight: .medium, design: .monospaced))
                        .foregroundStyle(i == activeSylIndex ? Color.kmPrimary : Color.kmTextDisabled)
                        .scaleEffect(i == activeSylIndex ? 1.1 : 1.0)
                        .shadow(color: i == activeSylIndex ? Color.kmPrimary.opacity(0.5) : .clear, radius: 8)
                        .animation(.kmBeatOn, value: activeSylIndex)
                }
            }

            // Progress
            VStack(spacing: 4) {
                Text("\(cyclesCompleted) / \(cycles)")
                    .font(.system(size: 40, weight: .light, design: .monospaced))
                    .foregroundStyle(Color.kmTextPrimary)
                Text("cycles")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }

            Spacer()

            KMButton(audio.isPlaying ? "Stop" : "Start") {
                if audio.isPlaying { stopMetronome() }
                else { startMetronome() }
            }
            .padding(.horizontal, KMSpacing.screenEdge)

            if cyclesCompleted >= cycles {
                KMButton("Continue") {
                    stopMetronome()
                    let summary = analyzer.stopListening()
                    isListening = false
                    let accuracy = summary.totalBeats > 0 ? summary.accuracyFraction : 1.0
                    let drift    = summary.avgDriftMs
                    onComplete(accuracy, drift)
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }

            Spacer(minLength: KMSpacing.pt32)
        }
        .onChange(of: audio.currentBeat) { _, beat in
            if beat == 0 && lastBeat != 0 { cyclesCompleted += 1 }
            lastBeat = beat
        }
        .onDisappear { stopMetronome() }
    }

    private func startMetronome() {
        audio.start(bpm: 80, tala: tala, nadai: nadai)
        startSyllableAnimation()
        if analyzer.permissionGranted && !isListening {
            analyzer.startListening(bpm: 80, tala: tala, nadai: nadai)
            isListening = true
        }
    }

    private func stopMetronome() {
        audio.stop()
        sylTimer?.invalidate()
        sylTimer = nil
        if isListening {
            _ = analyzer.stopListening()
            isListening = false
        }
    }

    private func startSyllableAnimation() {
        let interval = 60.0 / 80.0 / Double(nadai.rawValue)
        sylTimer = Timer.scheduledTimer(withTimeInterval: interval, repeats: true) { _ in
            activeSylIndex = (activeSylIndex + 1) % syllableGroup.syllables.count
        }
        RunLoop.main.add(sylTimer!, forMode: .common)
    }
}
