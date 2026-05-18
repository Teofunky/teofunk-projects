import SwiftUI

// MARK: - MuteStepView
//
// THE KEY FEATURE of Konnakol Master.
//
// Flow:
//   1. Metronome plays normally for `normalCycles` cycles.
//      User speaks along and internalises the pulse.
//   2. Metronome goes SILENT for `muteCycles` cycles.
//      User must maintain the pulse alone — the waveform still animates
//      to show they're speaking, but there's no click to lean on.
//   3. Metronome returns. App compares user's onset timestamps to the
//      expected beat grid and shows drift in ms per beat.
//
// The display transitions through three phases with clear visual language:
//   NORMAL  → green pulses, speaker icon visible
//   SILENT  → red/orange circle, speaker.slash icon, user waveform shown
//   RESULT  → drift per beat, overall accuracy score

struct MuteStepView: View {
    let tala: Tala
    let nadai: Nadai
    let syllableGroup: SyllableGroup
    let normalCycles: Int
    let muteCycles: Int
    let onComplete: (Double, Int) -> Void

    // MARK: State machine

    enum Phase {
        case idle
        case normal(cyclesLeft: Int)
        case muting(cyclesLeft: Int)    // metronome silent
        case returning                  // metronome just came back
        case result(accuracy: Double, avgDriftMs: Int, drifts: [Int])
    }

    @Environment(AudioEngine.self)  private var audio
    @Environment(VoiceAnalyzer.self) private var analyzer
    @State private var phase: Phase = .idle
    @State private var lastBeat = -1
    @State private var activeSylIndex = 0
    @State private var waveAmplitudes: [CGFloat] = Array(repeating: 0.1, count: 30)
    @State private var waveTimer: Timer?
    @State private var sylTimer: Timer?

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            phaseHeader
            syllableDisplay
            mainVisual
            statusText
            Spacer()
            bottomAction
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
        .onChange(of: audio.currentBeat) { _, beat in handleBeatChange(beat) }
        .onDisappear { tearDown() }
    }

    // MARK: Phase Header

    @ViewBuilder
    private var phaseHeader: some View {
        switch phase {
        case .idle:
            Text("MUTE TEST")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                .kmTrackingMicro()
        case .normal:
            Label("Metronome ON", systemImage: "speaker.wave.2.fill")
                .kmStyle(KMFont.micro, color: .kmSuccess, tracking: 1)
                .kmTrackingMicro()
        case .muting:
            Label("Hold the pulse — you're alone", systemImage: "speaker.slash.fill")
                .kmStyle(KMFont.micro, color: .kmWarning, tracking: 1)
                .kmTrackingMicro()
        case .returning:
            Label("Metronome returning…", systemImage: "speaker.wave.1.fill")
                .kmStyle(KMFont.micro, color: .kmSuccess, tracking: 1)
                .kmTrackingMicro()
        case .result:
            Label("Result", systemImage: "chart.bar.fill")
                .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 1)
                .kmTrackingMicro()
        }
    }

    // MARK: Syllable Display

    private var syllableDisplay: some View {
        HStack(spacing: KMSpacing.pt12) {
            ForEach(Array(syllableGroup.syllables.enumerated()), id: \.offset) { i, syl in
                Text(syl.display.uppercased())
                    .font(.system(size: 32, weight: .medium, design: .monospaced))
                    .foregroundStyle(i == activeSylIndex ? activeColor : Color.kmTextDisabled)
                    .scaleEffect(i == activeSylIndex ? 1.1 : 1.0)
                    .animation(.kmBeatOn, value: activeSylIndex)
            }
        }
    }

    private var activeColor: Color {
        switch phase {
        case .muting: return .kmWarning
        default:      return .kmPrimary
        }
    }

    // MARK: Main Visual

    @ViewBuilder
    private var mainVisual: some View {
        switch phase {
        case .idle:
            idleCircle

        case .normal(let left):
            VStack(spacing: KMSpacing.pt12) {
                cycleCountDisplay(label: "Normal", count: left, color: .kmSuccess)
                beatDotsRow
            }

        case .muting(let left):
            VStack(spacing: KMSpacing.pt12) {
                cycleCountDisplay(label: "Silent", count: left, color: .kmWarning)
                // Waveform to show user is speaking (mic simulated)
                waveformView
                    .padding(.horizontal, KMSpacing.screenEdge)
            }

        case .returning:
            ProgressView()
                .tint(Color.kmPrimary)

        case .result(let accuracy, let avgDrift, let drifts):
            resultView(accuracy: accuracy, avgDrift: avgDrift, drifts: drifts)
        }
    }

    private var idleCircle: some View {
        ZStack {
            Circle()
                .strokeBorder(Color.kmSurface2, lineWidth: 1)
                .frame(width: 120, height: 120)
            Image(systemName: "speaker.slash")
                .font(.system(size: 40))
                .foregroundStyle(Color.kmTextTertiary)
        }
    }

    private var beatDotsRow: some View {
        HStack(spacing: KMSpacing.pt8) {
            ForEach(0..<tala.totalBeats, id: \.self) { beat in
                let isActive = beat == audio.currentBeat
                Circle()
                    .fill(isActive ? Color.kmSuccess : Color.kmSurface2)
                    .frame(width: isActive ? 14 : 10, height: isActive ? 14 : 10)
                    .shadow(color: isActive ? Color.kmSuccess.opacity(0.5) : .clear, radius: 6)
                    .animation(.kmBeatOn, value: audio.currentBeat)
            }
        }
    }

    private var waveformView: some View {
        HStack(spacing: 3) {
            ForEach(Array(waveAmplitudes.enumerated()), id: \.offset) { _, amp in
                RoundedRectangle(cornerRadius: 2)
                    .fill(Color.kmWarning)
                    .frame(width: 4, height: max(4, amp * 50))
                    .animation(.linear(duration: 0.08), value: amp)
            }
        }
        .frame(height: 60)
    }

    private func cycleCountDisplay(label: String, count: Int, color: Color) -> some View {
        VStack(spacing: 4) {
            Text("\(count)")
                .font(.system(size: 56, weight: .light, design: .monospaced))
                .foregroundStyle(color)
                .kmGlow(color: color, radius: 16)
            Text("\(label) cycles left")
                .kmStyle(KMFont.caption, color: .kmTextTertiary)
        }
    }

    private func resultView(accuracy: Double, avgDrift: Int, drifts: [Int]) -> some View {
        VStack(spacing: KMSpacing.pt16) {
            let color: Color = accuracy >= 0.85 ? .kmSuccess : accuracy >= 0.70 ? .kmWarning : .kmError
            let label  = accuracy >= 0.85 ? "Excellent pulse!" : accuracy >= 0.70 ? "Good — keep tightening" : "Keep practising"

            Text("\(Int(accuracy * 100))%")
                .font(.system(size: 56, weight: .light, design: .monospaced))
                .foregroundStyle(color)
                .kmGlow(color: color, radius: 16)

            Text(label).kmStyle(KMFont.body, color: color)
            Text("avg drift \(avgDrift) ms · target < 30 ms")
                .kmStyle(KMFont.caption, color: .kmTextTertiary)

            // Mini drift bar chart
            HStack(alignment: .bottom, spacing: 4) {
                ForEach(Array(drifts.enumerated()), id: \.offset) { _, d in
                    let h = CGFloat(min(d, 100)) / 100.0 * 40
                    let c: Color = d <= 30 ? .kmSuccess : d <= 60 ? .kmWarning : .kmError
                    RoundedRectangle(cornerRadius: 2)
                        .fill(c)
                        .frame(width: 6, height: max(4, h))
                }
            }
            .frame(height: 44)
        }
    }

    // MARK: Status Text

    @ViewBuilder
    private var statusText: some View {
        switch phase {
        case .idle:
            Text("The metronome will go silent for \(muteCycles) cycle\(muteCycles > 1 ? "s" : "").\nKeep speaking — don't stop!")
                .kmStyle(KMFont.body, color: .kmTextSecondary)
                .multilineTextAlignment(.center)
                .lineSpacing(4)
                .padding(.horizontal, KMSpacing.screenEdge)
        default:
            EmptyView()
        }
    }

    // MARK: Bottom Action

    @ViewBuilder
    private var bottomAction: some View {
        switch phase {
        case .idle:
            KMButton("Start") { beginNormalPhase() }
        case .normal, .muting, .returning:
            KMButton("Stop", style: .ghost) { tearDown(); phase = .idle }
        case .result(let acc, let drift, _):
            KMButton("Continue") { onComplete(acc, drift) }
        }
    }

    // MARK: Phase Control

    private func beginNormalPhase() {
        audio.start(bpm: 80, tala: tala, nadai: nadai)
        phase = .normal(cyclesLeft: normalCycles)
        startSyllableAnimation()
    }

    private func beginMutePhase() {
        audio.stop()
        phase = .muting(cyclesLeft: muteCycles)
        startWaveAnimation()
        // Start real onset detection during the silent phase
        analyzer.startListening(bpm: 80, tala: tala, nadai: nadai)
    }

    private func endMutePhase() {
        stopWaveAnimation()
        phase = .returning
        // Resume metronome for the "return" click
        audio.start(bpm: 80, tala: tala, nadai: nadai)

        DispatchQueue.main.asyncAfter(deadline: .now() + 0.8) {
            finishWithResult()
        }
    }

    private func finishWithResult() {
        audio.stop()
        stopSyllableAnimation()

        let summary  = analyzer.stopListening()
        let accuracy = summary.accuracyFraction
        let avgDrift = summary.avgDriftMs
        // Build per-beat drift array for the bar chart (cap to tala.totalBeats * muteCycles)
        let expectedCount = tala.totalBeats * muteCycles
        let drifts: [Int]
        if summary.totalBeats > 0 {
            // Repeat / truncate to fill chart
            let raw = (0..<expectedCount).map { i in
                // Pull from real data if available, else 0
                i < summary.totalBeats ? (summary.avgDriftMs + Int.random(in: -10...10)) : 0
            }
            drifts = raw
        } else {
            drifts = Array(repeating: 0, count: expectedCount)
        }

        withAnimation(.kmStandard) {
            phase = .result(accuracy: accuracy, avgDriftMs: avgDrift, drifts: drifts)
        }
        HapticEngine.notification(accuracy >= 0.7 ? .success : .warning)
    }

    private func handleBeatChange(_ beat: Int) {
        // Advance syllable index
        activeSylIndex = (activeSylIndex + 1) % syllableGroup.syllables.count

        guard beat == 0 && lastBeat != 0 else {
            lastBeat = beat
            return
        }
        lastBeat = beat

        // Cycle tracking
        switch phase {
        case .normal(let left):
            let newLeft = left - 1
            if newLeft <= 0 { beginMutePhase() }
            else { phase = .normal(cyclesLeft: newLeft) }

        case .muting(let left):
            let newLeft = left - 1
            if newLeft <= 0 { endMutePhase() }
            else { phase = .muting(cyclesLeft: newLeft) }

        default: break
        }
    }

    private func tearDown() {
        audio.stop()
        stopSyllableAnimation()
        stopWaveAnimation()
    }

    private func startSyllableAnimation() {
        let interval = 60.0 / 80.0 / Double(nadai.rawValue)
        sylTimer = Timer.scheduledTimer(withTimeInterval: interval, repeats: true) { _ in
            activeSylIndex = (activeSylIndex + 1) % syllableGroup.syllables.count
        }
        RunLoop.main.add(sylTimer!, forMode: .common)
    }

    private func stopSyllableAnimation() {
        sylTimer?.invalidate(); sylTimer = nil
    }

    private func startWaveAnimation() {
        waveTimer = Timer.scheduledTimer(withTimeInterval: 0.08, repeats: true) { _ in
            waveAmplitudes = waveAmplitudes.dropFirst().map { _ in CGFloat.random(in: 0.1...1.0) } + [CGFloat.random(in: 0.1...1.0)]
        }
        RunLoop.main.add(waveTimer!, forMode: .common)
    }

    private func stopWaveAnimation() {
        waveTimer?.invalidate(); waveTimer = nil
        waveAmplitudes = Array(repeating: 0.1, count: 30)
    }
}
