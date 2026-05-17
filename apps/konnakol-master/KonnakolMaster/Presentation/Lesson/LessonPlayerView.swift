import SwiftUI

// MARK: - LessonPlayerView
//
// Fullscreen step-by-step lesson experience.
// Wraps each step view in a shared chrome (progress bar, close button).
// On completion shows CelebrationView or RetryView.

struct LessonPlayerView: View {
    @State private var vm: LessonPlayerViewModel
    @Environment(ProgressStore.self) private var progressStore
    @Environment(VoiceAnalyzer.self) private var voiceAnalyzer
    @Environment(\.dismiss) private var dismiss

    init(lesson: Lesson) {
        _vm = State(initialValue: LessonPlayerViewModel(lesson: lesson))
    }

    private var lessonNeedsMic: Bool {
        vm.lesson.steps.contains { step in
            switch step {
            case .speak, .loop, .mute: return true
            default: return false
            }
        }
    }

    var body: some View {
        ZStack {
            Color.kmBase.ignoresSafeArea()

            switch vm.phase {
            case .inProgress:
                lessonContent
            case .celebrating:
                CelebrationView(
                    accuracy: vm.finalAccuracy,
                    xpEarned: vm.xpEarned,
                    lessonTitle: vm.lesson.title
                ) {
                    progressStore.recordLessonComplete(
                        lessonId: vm.lesson.id,
                        xpGained: vm.xpEarned
                    )
                    dismiss()
                }
                .transition(.opacity)
            case .failed:
                RetryView(accuracy: vm.finalAccuracy) {
                    withAnimation(.kmStandard) { vm.retry() }
                } onExit: {
                    dismiss()
                }
                .transition(.opacity)
            }
        }
        .animation(.kmStandard, value: vm.phase)
        .task {
            guard lessonNeedsMic else { return }
            if !voiceAnalyzer.permissionGranted {
                await voiceAnalyzer.requestPermission()
            }
            if voiceAnalyzer.permissionGranted {
                await voiceAnalyzer.calibrate()
            }
        }
    }

    // MARK: Lesson Content

    private var lessonContent: some View {
        VStack(spacing: 0) {
            topBar
            stepContent
                .transition(.asymmetric(
                    insertion: .move(edge: .trailing).combined(with: .opacity),
                    removal:   .move(edge: .leading).combined(with: .opacity)
                ))
                .id(vm.currentIndex) // triggers transition on index change
        }
    }

    // MARK: Top Bar

    private var topBar: some View {
        VStack(spacing: KMSpacing.pt8) {
            HStack {
                Button { dismiss() } label: {
                    Image(systemName: "xmark")
                        .foregroundStyle(Color.kmTextSecondary)
                        .frame(width: KMSpacing.minTouchTarget, height: KMSpacing.minTouchTarget)
                }

                Spacer()

                Text("\(vm.currentIndex + 1) / \(vm.totalSteps)")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }
            .padding(.horizontal, KMSpacing.screenEdge)

            // Progress bar
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Capsule().fill(Color.kmSurface2).frame(height: 4)
                    Capsule()
                        .fill(Color.kmPrimary)
                        .frame(width: geo.size.width * vm.progress, height: 4)
                        .animation(.kmStandard, value: vm.progress)
                }
            }
            .frame(height: 4)
            .padding(.horizontal, KMSpacing.screenEdge)
        }
        .padding(.top, KMSpacing.pt16)
        .padding(.bottom, KMSpacing.pt12)
    }

    // MARK: Step Dispatch

    @ViewBuilder
    private var stepContent: some View {
        switch vm.currentStep {
        case .intro(let text, let videoURL):
            IntroStepView(text: text, videoURL: videoURL) { vm.completeStep() }

        case .listen(let groupId, let reps):
            ListenStepView(
                syllableGroup: syllableGroup(id: groupId),
                repetitions: reps
            ) { vm.completeStep() }

        case .speak(let groupId, let count, let instruction):
            SpeakStepView(
                syllableGroup: syllableGroup(id: groupId),
                targetCount: count,
                instruction: instruction
            ) { accuracy, drift in vm.completeStep(accuracy: accuracy, avgDriftMs: drift) }

        case .clap(let talaId, let nadai, let cycles):
            ClapStepView(
                tala: tala(id: talaId),
                nadai: nadai,
                cycles: cycles
            ) { vm.completeStep() }

        case .loop(let talaId, let nadai, let groupId, let cycles):
            LoopStepView(
                tala: tala(id: talaId),
                nadai: nadai,
                syllableGroup: syllableGroup(id: groupId),
                cycles: cycles
            ) { accuracy, drift in vm.completeStep(accuracy: accuracy, avgDriftMs: drift) }

        case .mute(let talaId, let nadai, let groupId, let normalCycles, let muteCycles):
            MuteStepView(
                tala: tala(id: talaId),
                nadai: nadai,
                syllableGroup: syllableGroup(id: groupId),
                normalCycles: normalCycles,
                muteCycles: muteCycles
            ) { accuracy, drift in vm.completeStep(accuracy: accuracy, avgDriftMs: drift) }

        case .play(let instrument, let prompt):
            PlayStepView(instrument: instrument, prompt: prompt) {
                vm.completeStep()
            } onSkip: {
                vm.skipStep()
            }

        case .check(let summary):
            CheckStepView(
                summary: summary,
                stepResults: vm.stepResults
            ) { vm.completeStep() }
        }
    }

    // MARK: Helpers

    private func syllableGroup(id: String) -> SyllableGroup {
        SyllableLibrary.allJathis.first { $0.id == id } ?? SyllableLibrary.jathi4
    }

    private func tala(id: String) -> Tala {
        TalaLibrary.tala(forId: id) ?? TalaLibrary.adi
    }
}

// MARK: - CelebrationView

struct CelebrationView: View {
    let accuracy: Double
    let xpEarned: Int
    let lessonTitle: String
    let onContinue: () -> Void

    @State private var checkVisible = false
    @State private var glowVisible  = false

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()

            ZStack {
                Circle()
                    .fill(Color.kmSuccess.opacity(glowVisible ? 0 : 0.15))
                    .frame(width: 140, height: 140)
                    .scaleEffect(glowVisible ? 1.5 : 1.0)
                    .animation(.easeOut(duration: 0.5), value: glowVisible)

                Image(systemName: "checkmark")
                    .font(.system(size: 52, weight: .semibold))
                    .foregroundStyle(Color.kmSuccess)
                    .scaleEffect(checkVisible ? 1.0 : 0.3)
                    .opacity(checkVisible ? 1 : 0)
                    .animation(.kmSpring, value: checkVisible)
            }

            VStack(spacing: KMSpacing.pt8) {
                Text("Lesson Complete")
                    .kmStyle(KMFont.title1)
                Text("+\(xpEarned) XP")
                    .kmStyle(KMFont.title2, color: .kmPrimary)
                    .kmGlow(radius: 8)
                Text("\(Int(accuracy * 100))% accuracy")
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
            }

            Spacer()

            KMButton("Continue") { onContinue() }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt32)
        }
        .background(Color.kmBase.ignoresSafeArea())
        .onAppear {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
                checkVisible = true
                HapticEngine.notification(.success)
            }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                glowVisible = true
            }
        }
    }
}

// MARK: - RetryView

struct RetryView: View {
    let accuracy: Double
    let onRetry: () -> Void
    let onExit: () -> Void

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            Spacer()
            Image(systemName: "arrow.circlepath")
                .font(.system(size: 52))
                .foregroundStyle(Color.kmWarning)
            VStack(spacing: KMSpacing.pt8) {
                Text("Not quite")
                    .kmStyle(KMFont.title1)
                Text("\(Int(accuracy * 100))% — need 70% to unlock next level")
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
                    .multilineTextAlignment(.center)
            }
            Spacer()
            VStack(spacing: KMSpacing.pt12) {
                KMButton("Try Again", style: .primary)  { onRetry() }
                KMButton("Exit Lesson", style: .ghost)  { onExit()  }
            }
            .padding(.horizontal, KMSpacing.screenEdge)
            .padding(.bottom, KMSpacing.pt32)
        }
        .background(Color.kmBase.ignoresSafeArea())
    }
}
