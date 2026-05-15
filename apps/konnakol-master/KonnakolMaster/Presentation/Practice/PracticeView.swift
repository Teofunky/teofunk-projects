import SwiftUI

// MARK: - PracticeView
//
// The hero screen. Users spend more time here than anywhere else.
// Layout (top → bottom):
//   1. Compact top bar: tala name · nadai badge · settings
//   2. TalaWheelView (60% of available height)
//   3. BPM display (huge number)
//   4. BPM slider + tala picker + nadai picker
//   5. Bottom action bar: mic · play/pause · record

struct PracticeView: View {
    @Environment(AudioEngine.self) private var audio
    @State private var vm = PracticeViewModel()
    @State private var showSettings = false

    var body: some View {
        NavigationStack {
            GeometryReader { geo in
                ZStack(alignment: .bottom) {
                    Color.kmBase.ignoresSafeArea()

                    VStack(spacing: 0) {
                        topBar
                            .padding(.horizontal, KMSpacing.screenEdge)
                            .padding(.top, KMSpacing.pt12)

                        Spacer(minLength: 0)

                        // TalaWheelView — 60% of usable height
                        TalaWheelView(
                            tala: vm.selectedTala,
                            currentBeat: audio.currentBeat,
                            isPlaying: vm.isPlaying,
                            syllableGroup: vm.syllableGroup,
                            activeSyllableIndex: vm.activeSyllableIndex,
                            trikalamMode: vm.trikaramMode
                        )
                        .frame(height: geo.size.height * 0.42)

                        Spacer(minLength: 0)

                        bpmDisplay
                            .padding(.bottom, KMSpacing.pt8)

                        controlsSection
                            .padding(.horizontal, KMSpacing.screenEdge)

                        Spacer(minLength: KMSpacing.pt16)
                    }

                    // Floating bottom action bar
                    bottomActionBar
                }
            }
            .navigationBarHidden(true)
            .onChange(of: audio.currentBeat) { _, beat in
                vm.onBeatChange(beat)
            }
            .sheet(isPresented: $showSettings) { settingsSheet }
        }
    }

    // MARK: Top Bar

    private var topBar: some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(vm.selectedTala.name)
                    .kmStyle(KMFont.body)
                Text(vm.selectedNadai.displayName + " · " + vm.selectedNadai.westernEquivalent)
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }

            Spacer()

            // Trikalam toggle
            Button {
                HapticEngine.impact(.light)
                withAnimation(.kmQuick) { vm.trikaramMode.toggle() }
            } label: {
                Image(systemName: "circle.grid.3x3")
                    .foregroundStyle(vm.trikaramMode ? Color.kmPrimary : Color.kmTextTertiary)
                    .frame(width: KMSpacing.minTouchTarget, height: KMSpacing.minTouchTarget)
            }

            Button { showSettings = true } label: {
                Image(systemName: "slider.horizontal.3")
                    .foregroundStyle(Color.kmTextTertiary)
                    .frame(width: KMSpacing.minTouchTarget, height: KMSpacing.minTouchTarget)
            }
        }
    }

    // MARK: BPM Display

    private var bpmDisplay: some View {
        HStack(alignment: .lastTextBaseline, spacing: 4) {
            Text("\(vm.bpm)")
                .font(.system(size: 72, weight: .ultraLight, design: .default))
                .foregroundStyle(Color.kmTextPrimary)
                .contentTransition(.numericText(countsDown: false))
                .animation(.kmQuick, value: vm.bpm)
                .kmGlow(color: vm.isPlaying ? .kmPrimary : .clear, radius: 8)
            Text("BPM")
                .kmStyle(KMFont.caption, color: .kmTextTertiary)
                .padding(.bottom, 10)
        }
    }

    // MARK: Controls

    private var controlsSection: some View {
        VStack(spacing: KMSpacing.pt16) {
            // BPM Slider
            KMSlider(
                value: Binding(
                    get: { Double(vm.bpm) },
                    set: { vm.applyBPM(Int($0), audio: audio) }
                ),
                in: 30...300,
                step: 1
            )

            // Tala Segmented Control
            KMSegmentedControl(
                options: TalaLibrary.all,
                selection: Binding(
                    get: { vm.selectedTala },
                    set: { vm.applyTala($0, audio: audio) }
                ),
                labelBuilder: { tala in
                    // Short names for the control
                    switch tala.id {
                    case "adi":           return "Adi"
                    case "rupaka":        return "Rupaka"
                    case "misra-chapu":   return "Misra"
                    case "khanda-chapu":  return "Khanda"
                    case "tisra-triputa": return "Tisra"
                    default:             return tala.name
                    }
                }
            )

            // Nadai Pills
            NadaiPickerView(
                selection: Binding(
                    get: { vm.selectedNadai },
                    set: { vm.selectedNadai = $0 }
                ),
                onChange: { vm.applyNadai($0, audio: audio) }
            )
        }
    }

    // MARK: Bottom Action Bar

    private var bottomActionBar: some View {
        HStack(spacing: KMSpacing.pt32) {
            // Mic toggle
            iconButton(
                systemImage: vm.isMicActive ? "mic.fill" : "mic",
                tint: vm.isMicActive ? .kmPrimary : .kmTextSecondary,
                label: "Mic"
            ) {
                HapticEngine.impact(.light)
                vm.isMicActive.toggle()
            }

            // Play / Pause (central 72pt FAB)
            playPauseButton

            // Record session
            iconButton(
                systemImage: vm.isRecording ? "record.circle.fill" : "record.circle",
                tint: vm.isRecording ? .kmError : .kmTextSecondary,
                label: "Rec"
            ) {
                HapticEngine.impact(.light)
                vm.isRecording.toggle()
            }
        }
        .padding(.horizontal, KMSpacing.pt40)
        .padding(.vertical, KMSpacing.pt20)
        .background(
            // Glassmorphism bottom bar
            Rectangle()
                .fill(.ultraThinMaterial)
                .overlay(Rectangle().fill(Color.kmBase.opacity(0.6)))
                .ignoresSafeArea(edges: .bottom)
        )
    }

    private var playPauseButton: some View {
        Button {
            vm.togglePlay(audio: audio)
        } label: {
            ZStack {
                Circle()
                    .fill(Color.kmPrimary)
                    .frame(width: 72, height: 72)
                    .shadow(
                        color: Color.kmPrimary.opacity(vm.isPlaying ? 0.5 : 0.2),
                        radius: vm.isPlaying ? 20 : 8
                    )
                    .scaleEffect(vm.isPlaying ? 1.04 : 1.0)
                    .animation(.easeInOut(duration: 1.2).repeatForever(autoreverses: true), value: vm.isPlaying)

                Image(systemName: vm.isPlaying ? "pause.fill" : "play.fill")
                    .font(.system(size: 28))
                    .foregroundStyle(Color(hex: "#0B0D10"))
            }
        }
        .buttonStyle(.plain)
        .animation(.kmQuick, value: vm.isPlaying)
    }

    private func iconButton(
        systemImage: String,
        tint: Color,
        label: String,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: systemImage)
                    .font(.system(size: 22))
                    .foregroundStyle(tint)
                    .frame(width: KMSpacing.minTouchTarget, height: KMSpacing.minTouchTarget)
                Text(label)
                    .kmStyle(KMFont.micro, color: .kmTextTertiary)
                    .kmTrackingMicro()
            }
        }
        .buttonStyle(.plain)
        .animation(.kmQuick, value: tint)
    }

    // MARK: Settings Sheet

    private var settingsSheet: some View {
        NavigationStack {
            List {
                Section("TEMPO") {
                    HStack {
                        Text("BPM")
                        Spacer()
                        Text("\(vm.bpm)")
                            .kmStyle(KMFont.body, color: .kmPrimary)
                    }
                }
                Section("MODE") {
                    Toggle("Trikalam (×1 ×2 ×4)", isOn: $vm.trikaramMode)
                        .tint(Color.kmPrimary)
                }
                Section("ABOUT") {
                    HStack {
                        Text("Precision target")
                        Spacer()
                        Text("±2 ms")
                            .kmStyle(KMFont.caption, color: .kmTextTertiary)
                    }
                    HStack {
                        Text("Sample rate")
                        Spacer()
                        Text("44.1 kHz")
                            .kmStyle(KMFont.caption, color: .kmTextTertiary)
                    }
                }
            }
            .scrollContentBackground(.hidden)
            .background(Color.kmBase)
            .navigationTitle("Practice Settings")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button("Done") { showSettings = false }
                        .foregroundStyle(Color.kmPrimary)
                }
            }
        }
        .presentationDetents([.medium])
    }
}
