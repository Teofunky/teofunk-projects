import SwiftUI

struct SpeakStepView: View {
    let syllableGroup: SyllableGroup
    let targetCount: Int
    let instruction: String
    let onComplete: (Double, Int) -> Void  // (accuracy, avgDriftMs)

    @Environment(VoiceAnalyzer.self) private var analyzer

    enum RecState { case idle, countdown(Int), recording, done(Double, Int) }

    @State private var state: RecState = .idle
    @State private var waveAmplitudes: [CGFloat] = Array(repeating: 0.1, count: 40)
    @State private var waveTimer: Timer?
    @State private var countdownTimer: Timer?

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            Text("SPEAK")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()

            // Syllable display
            HStack(spacing: KMSpacing.pt12) {
                ForEach(syllableGroup.syllables, id: \.id) { syl in
                    Text(syl.display.uppercased())
                        .font(.system(size: 36, weight: .medium, design: .monospaced))
                        .foregroundStyle(Color.kmTextPrimary)
                }
            }
            .kmGlow(radius: 12)

            Text(instruction)
                .kmStyle(KMFont.body, color: .kmTextSecondary)
                .multilineTextAlignment(.center)
                .padding(.horizontal, KMSpacing.screenEdge)

            // Waveform / countdown display
            stateView

            Spacer()

            // Bottom CTA
            bottomButton
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
    }

    // MARK: State Views

    @ViewBuilder
    private var stateView: some View {
        switch state {
        case .idle:
            VStack(spacing: KMSpacing.pt16) {
                waveformPlaceholder
                Text("Tap the mic to start")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }

        case .countdown(let n):
            Text("\(n)")
                .font(.system(size: 72, weight: .light, design: .monospaced))
                .foregroundStyle(Color.kmPrimary)
                .kmGlow(radius: 24)
                .transition(.scale.combined(with: .opacity))
                .id(n)
                .animation(.kmSpring, value: n)

        case .recording:
            VStack(spacing: KMSpacing.pt12) {
                waveformLive
                Text("Listening…")
                    .kmStyle(KMFont.caption, color: .kmPrimary)
            }

        case .done(let accuracy, let drift):
            resultView(accuracy: accuracy, drift: drift)
        }
    }

    private var waveformPlaceholder: some View {
        HStack(spacing: 3) {
            ForEach(0..<40, id: \.self) { _ in
                RoundedRectangle(cornerRadius: 2)
                    .fill(Color.kmSurface2)
                    .frame(width: 3, height: 20)
            }
        }
        .frame(height: 60)
    }

    private var waveformLive: some View {
        HStack(spacing: 3) {
            ForEach(Array(waveAmplitudes.enumerated()), id: \.offset) { _, amp in
                RoundedRectangle(cornerRadius: 2)
                    .fill(Color.kmPrimary)
                    .frame(width: 3, height: max(4, amp * 60))
                    .animation(.linear(duration: 0.08), value: amp)
            }
        }
        .frame(height: 60)
    }

    private func resultView(accuracy: Double, drift: Int) -> some View {
        VStack(spacing: KMSpacing.pt12) {
            let label: String = accuracy >= 0.9 ? "Great rhythm!"
                             : accuracy >= 0.7 ? "Good — keep going"
                             : "Keep practising"
            let color: Color  = accuracy >= 0.9 ? .kmSuccess
                             : accuracy >= 0.7 ? .kmWarning
                             : .kmError

            Text("\(Int(accuracy * 100))% accuracy")
                .kmStyle(KMFont.title2, color: color)
            Text("avg drift \(drift) ms")
                .kmStyle(KMFont.caption, color: .kmTextTertiary)
            Text(label)
                .kmStyle(KMFont.body, color: color)
        }
        .transition(.scale.combined(with: .opacity))
    }

    // MARK: Bottom Button

    @ViewBuilder
    private var bottomButton: some View {
        switch state {
        case .idle:
            micButton("Start Recording") { beginCountdown() }

        case .countdown, .recording:
            KMButton("Cancel", style: .ghost) { cancelRecording() }

        case .done(let acc, let drift):
            KMButton("Continue") { onComplete(acc, drift) }
        }
    }

    private func micButton(_ label: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: KMSpacing.pt8) {
                Image(systemName: "mic.fill")
                Text(label)
            }
            .kmStyle(KMFont.body, color: Color(hex: "#0B0D10"))
            .frame(maxWidth: .infinity)
            .frame(height: KMSpacing.minTouchTarget)
            .background(Color.kmPrimary)
            .clipShape(RoundedRectangle(cornerRadius: KMRadius.button))
        }
        .buttonStyle(.plain)
    }

    // MARK: Recording Flow

    private func beginCountdown() {
        var count = 3
        state = .countdown(count)
        HapticEngine.impact(.medium)

        countdownTimer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { t in
            count -= 1
            if count > 0 {
                state = .countdown(count)
                HapticEngine.impact(.light)
            } else {
                t.invalidate()
                startRecording()
            }
        }
        RunLoop.main.add(countdownTimer!, forMode: .common)
    }

    private func startRecording() {
        state = .recording
        HapticEngine.impact(.medium)
        startWaveAnimation()

        // Estimate syllable tempo from targetCount and a nominal 80 BPM feel
        let bpmEstimate = 80
        analyzer.startListening(bpm: bpmEstimate, tala: TalaLibrary.adi, nadai: .chatusra)

        let duration = TimeInterval(targetCount) * 0.35 + 1.0
        DispatchQueue.main.asyncAfter(deadline: .now() + duration) {
            finishRecording()
        }
    }

    private func finishRecording() {
        stopWaveAnimation()
        HapticEngine.notification(.success)
        let summary = analyzer.stopListening()
        let accuracy = summary.accuracyFraction
        let drift    = summary.avgDriftMs
        withAnimation(.kmStandard) { state = .done(accuracy, drift) }
    }

    private func cancelRecording() {
        countdownTimer?.invalidate()
        stopWaveAnimation()
        state = .idle
    }

    private func startWaveAnimation() {
        waveTimer = Timer.scheduledTimer(withTimeInterval: 0.08, repeats: true) { _ in
            waveAmplitudes = waveAmplitudes.dropFirst().map { _ in CGFloat.random(in: 0.1...1.0) } + [CGFloat.random(in: 0.1...1.0)]
        }
        RunLoop.main.add(waveTimer!, forMode: .common)
    }

    private func stopWaveAnimation() {
        waveTimer?.invalidate()
        waveTimer = nil
        waveAmplitudes = Array(repeating: 0.1, count: 40)
    }
}
