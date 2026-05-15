import SwiftUI

// MARK: - TodayView

struct TodayView: View {
    @Environment(ProgressStore.self) private var progressStore
    @State private var vm = TodayViewModel()
    @State private var navigateToLesson = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    header
                    if let lesson = vm.todayLesson { heroCard(lesson) }
                    if let challenge = vm.dailyChallenge { challengeCard(challenge) }
                    tipCard
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt12)
                .padding(.bottom, KMSpacing.pt48)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
            .onAppear { vm.load(progress: progressStore.progress) }
        }
    }

    // MARK: Header

    private var header: some View {
        HStack(alignment: .center) {
            VStack(alignment: .leading, spacing: 4) {
                Text(vm.greeting)
                    .kmStyle(KMFont.caption, color: .kmTextSecondary, tracking: 0.5)
                Text("Your Practice")
                    .kmStyle(KMFont.title2)
            }
            Spacer()
            streakBadge
        }
    }

    private var streakBadge: some View {
        HStack(spacing: 6) {
            Image(systemName: "flame.fill")
                .foregroundStyle(Color.kmPrimary)
            Text("\(progressStore.progress.currentStreak)")
                .kmStyle(KMFont.body)
            Text("days")
                .kmStyle(KMFont.caption, color: .kmTextSecondary)
        }
        .padding(.horizontal, KMSpacing.pt12)
        .padding(.vertical, KMSpacing.pt8)
        .background(Color.kmSurface1)
        .clipShape(Capsule())
    }

    // MARK: Hero Card (Today's Lesson)

    private func heroCard(_ lesson: TodayViewModel.LessonSummary) -> some View {
        KMCard(elevation: .standard, padding: 0) {
            VStack(alignment: .leading, spacing: 0) {
                // Decorative top strip with level indicator
                HStack {
                    Text("LEVEL \(lesson.level)")
                        .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 2)
                        .kmTrackingMicro()
                    Spacer()
                    Text("\(lesson.estimatedMinutes) min")
                        .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 1)
                }
                .padding(KMSpacing.cardPadding)
                .background(Color.kmSurface2)

                VStack(alignment: .leading, spacing: KMSpacing.pt12) {
                    Text(lesson.title)
                        .kmStyle(KMFont.title2)
                    Text(LessonLibrary.meta(forLevel: lesson.level)?.subtitle ?? "")
                        .kmStyle(KMFont.body, color: .kmTextSecondary)

                    // Progress bar (only if lesson is in progress)
                    if lesson.isStarted {
                        GeometryReader { geo in
                            ZStack(alignment: .leading) {
                                Capsule().fill(Color.kmSurface3).frame(height: 4)
                                Capsule().fill(Color.kmPrimary)
                                    .frame(width: geo.size.width * lesson.progressFraction, height: 4)
                            }
                        }
                        .frame(height: 4)
                    }

                    KMButton(lesson.isStarted ? "Continue" : "Start Lesson") {
                        navigateToLesson = true
                    }
                }
                .padding(KMSpacing.cardPadding)
            }
        }
    }

    // MARK: Daily Challenge Card

    private func challengeCard(_ challenge: TodayViewModel.ChallengeSummary) -> some View {
        KMCard(elevation: .standard) {
            HStack(spacing: KMSpacing.pt12) {
                Image(systemName: "bolt.fill")
                    .foregroundStyle(Color.kmPrimary)
                    .frame(width: 36, height: 36)
                    .background(Color.kmSurface2)
                    .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip))

                VStack(alignment: .leading, spacing: 4) {
                    Text(challenge.title)
                        .kmStyle(KMFont.body)
                    Text(challenge.description)
                        .kmStyle(KMFont.caption, color: .kmTextSecondary)
                }
                Spacer()
                Image(systemName: "chevron.right")
                    .foregroundStyle(Color.kmTextTertiary)
            }
        }
    }

    // MARK: Tip of the Day

    private var tipCard: some View {
        KMCard(elevation: .standard) {
            VStack(alignment: .leading, spacing: KMSpacing.pt8) {
                Label("Tip of the Day", systemImage: "quote.opening")
                    .kmStyle(KMFont.micro, color: .kmPrimary, tracking: 1)
                    .kmTrackingMicro()
                Text(vm.tipOfDay)
                    .kmStyle(KMFont.body, color: .kmTextSecondary)
                    .italic()
                    .lineSpacing(4)
            }
        }
    }
}
