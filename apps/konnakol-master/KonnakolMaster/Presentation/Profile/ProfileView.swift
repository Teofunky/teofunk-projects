import SwiftUI

struct ProfileView: View {
    @Environment(ProgressStore.self) private var progressStore

    var progress: UserProgress { progressStore.progress }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    header
                    statsGrid
                    achievementsSection
                    settingsRows
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt12)
                .padding(.bottom, KMSpacing.pt48)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }

    // MARK: Header

    private var header: some View {
        VStack(spacing: KMSpacing.pt12) {
            ZStack {
                Circle()
                    .fill(Color.kmSurface1)
                    .frame(width: 80, height: 80)
                    .kmGlow(radius: 12)
                Text("T")
                    .font(.system(size: 32, weight: .semibold))
                    .foregroundStyle(Color.kmPrimary)
            }

            VStack(spacing: 4) {
                Text("Teofunk")
                    .kmStyle(KMFont.title2)
                Text(progress.experienceLevel.displayName + " · " + progress.instrument.displayName)
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }

            // Level badge
            HStack(spacing: 6) {
                Image(systemName: "star.fill")
                    .foregroundStyle(Color.kmPrimary)
                Text("Level \(progress.currentLevel)")
                    .kmStyle(KMFont.body, color: .kmPrimary)
            }
            .padding(.horizontal, KMSpacing.pt16)
            .padding(.vertical, KMSpacing.pt8)
            .background(Color.kmSurface1)
            .clipShape(Capsule())
        }
    }

    // MARK: Stats Grid (2×3)

    private var statsGrid: some View {
        let items: [(String, String, String)] = [
            ("clock",        formatTime(progress.totalPracticeMinutes), "Practice Time"),
            ("checkmark.circle", "\(progress.completedLessons.count)", "Lessons Done"),
            ("flame.fill",   "\(progress.currentStreak)",  "Streak"),
            ("trophy",       "\(progress.longestStreak)",  "Best Streak"),
            ("waveform",     "—",                          "Avg Accuracy"),
            ("circle.hexagongrid", "Adi Tala",             "Fav Tala"),
        ]

        let columns = [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())]

        return LazyVGrid(columns: columns, spacing: KMSpacing.pt12) {
            ForEach(items, id: \.1) { (icon, value, label) in
                statTile(icon: icon, value: value, label: label)
            }
        }
    }

    private func statTile(icon: String, value: String, label: String) -> some View {
        KMCard(elevation: .standard) {
            VStack(spacing: KMSpacing.pt8) {
                Image(systemName: icon)
                    .font(.system(size: 18))
                    .foregroundStyle(Color.kmPrimary)
                Text(value)
                    .kmStyle(KMFont.body)
                Text(label)
                    .kmStyle(KMFont.micro, color: .kmTextTertiary)
                    .kmTrackingMicro()
                    .multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
        }
    }

    // MARK: Achievements

    private var achievementsSection: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            Text("ACHIEVEMENTS")
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: KMSpacing.pt12) {
                    ForEach(Achievement.allDefined, id: \.id) { ach in
                        let earned = progress.achievements.contains { $0.id == ach.id }
                        achievementBadge(ach, earned: earned)
                    }
                }
                .padding(.horizontal, 1)
            }
        }
    }

    private func achievementBadge(_ ach: Achievement, earned: Bool) -> some View {
        VStack(spacing: KMSpacing.pt8) {
            ZStack {
                Circle()
                    .fill(earned ? Color.kmPrimary.opacity(0.15) : Color.kmSurface1)
                    .frame(width: 56, height: 56)
                Image(systemName: ach.id == "first-korvai" ? "star.fill" :
                                  ach.id == "speed-demon"  ? "bolt.fill"  : "checkmark.seal.fill")
                    .font(.system(size: 22))
                    .foregroundStyle(earned ? Color.kmPrimary : Color.kmTextDisabled)
            }
            .shadow(color: earned ? Color.kmPrimary.opacity(0.3) : .clear, radius: 8)

            Text(ach.title)
                .kmStyle(KMFont.micro, color: earned ? .kmTextSecondary : .kmTextDisabled)
                .multilineTextAlignment(.center)
                .frame(width: 64)
        }
    }

    // MARK: Settings Rows

    private var settingsRows: some View {
        KMCard(elevation: .standard, padding: 0) {
            VStack(spacing: 0) {
                ForEach(["Subscription", "Notifications", "About", "Sign Out"], id: \.self) { label in
                    HStack {
                        Text(label)
                            .kmStyle(KMFont.body, color: label == "Sign Out" ? .kmError : .kmTextPrimary)
                        Spacer()
                        Image(systemName: "chevron.right")
                            .foregroundStyle(Color.kmTextDisabled)
                            .font(.system(size: 12))
                    }
                    .padding(KMSpacing.cardPadding)
                    .contentShape(Rectangle())
                    .onTapGesture { HapticEngine.impact(.light) }

                    if label != "Sign Out" {
                        Divider().background(Color.kmSurface2)
                    }
                }
            }
        }
    }

    // MARK: Helpers

    private func formatTime(_ minutes: Int) -> String {
        if minutes < 60 { return "\(minutes)m" }
        return "\(minutes / 60)h \(minutes % 60)m"
    }
}

// MARK: - Achievement stubs

extension Achievement {
    static let allDefined: [Achievement] = [
        Achievement(id: "first-lesson",  title: "First Step",    description: "Complete Level 1", earnedDate: nil),
        Achievement(id: "mute-master",   title: "Mute Master",   description: "Hold 4 silent cycles with <30ms drift", earnedDate: nil),
        Achievement(id: "first-korvai",  title: "First Korvai",  description: "Complete Level 13", earnedDate: nil),
        Achievement(id: "speed-demon",   title: "Speed Demon",   description: "Practice at 200+ BPM", earnedDate: nil),
        Achievement(id: "khanda-master", title: "Khanda Master", description: "Complete Khanda Chapu level", earnedDate: nil),
        Achievement(id: "streak-7",      title: "Week Streak",   description: "7-day practice streak", earnedDate: nil),
    ]
}
