import Foundation

// MARK: - LessonBuilder
//
// Constructs Lesson domain objects for MVP levels 1–5.
// Levels 6–16 exist in the skill tree (locked) but their Lesson
// objects are stubs — full content arrives in v1.1.

enum LessonBuilder {

    static func build(forLevel level: Int) -> Lesson {
        switch level {
        case 1:  return level1()
        case 2:  return level2()
        case 3:  return level3()
        case 4:  return level4()
        case 5:  return level5()
        default: return stub(level: level)
        }
    }

    // MARK: Level 1 — First Voice

    private static func level1() -> Lesson {
        Lesson(
            id: "level-1",
            levelNumber: 1,
            title: "First Voice",
            subtitle: "Your first syllables",
            description: "Learn the four foundational syllable groups without a metronome. Focus on even, clear articulation.",
            estimatedMinutes: 8,
            steps: [
                .intro(text: "Konnakol is the language of rhythm. Every syllable is a sound, every group a building block. Let's begin with the simplest unit: Ta.", videoURL: nil),
                .listen(syllableGroupId: "jathi-2", repetitions: 4),
                .speak(syllableGroupId: "jathi-2", targetCount: 16, instruction: "Say 'Ta-Ka' as one unit, 8 times. Keep it even."),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .speak(syllableGroupId: "jathi-3", targetCount: 24, instruction: "Ta-Ki-Ta is the triplet. Say it 8 times."),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .speak(syllableGroupId: "jathi-4", targetCount: 32, instruction: "The most important phrase in konnakol. Say it 8 times, evenly."),
                .check(summary: "You now know the four foundational groups. Tomorrow we add the metronome."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.80, maxAvgDriftMs: 60),
            unlockCriteria: UnlockCriteria(kind: .first, requiredLessonId: nil)
        )
    }

    // MARK: Level 2 — The Pulse

    private static func level2() -> Lesson {
        Lesson(
            id: "level-2",
            levelNumber: 2,
            title: "The Pulse",
            subtitle: "Meet the metronome",
            description: "Add the metronome at 60 BPM and practise the Mute step — where the click disappears and you hold the pulse alone.",
            estimatedMinutes: 10,
            steps: [
                .intro(text: "The metronome is your heartbeat. Every syllable lands on — or between — its pulses. Today you'll also face the Mute test: the click disappears. Don't stop.", videoURL: nil),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 2),
                .check(summary: "If your drift was under 60 ms, your internal pulse is already strong. Keep it up."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.70, maxAvgDriftMs: 60),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-1")
        )
    }

    // MARK: Level 3 — Adi Tala

    private static func level3() -> Lesson {
        Lesson(
            id: "level-3",
            levelNumber: 3,
            title: "Adi Tala",
            subtitle: "The king of rhythmic cycles",
            description: "Adi Tala is 8 beats with a specific hand pattern. You'll learn to clap, count fingers, and wave — all while speaking.",
            estimatedMinutes: 12,
            steps: [
                .intro(text: "Adi Tala: 8 beats structured as Laghu(4) + Drutam + Drutam. Your hands map the structure: clap → 3 fingers → clap+wave → clap+wave.", videoURL: nil),
                .clap(talaId: "adi", nadai: .chatusra, cycles: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 8),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 2),
                .play(instrument: .guitar, prompt: "Play Ta-Ka-Di-Mi as down-up-down-up strokes at 80 BPM in Adi Tala."),
                .check(summary: "You can now navigate the 8-beat cycle. This is the foundation of 50% of all Carnatic music."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.70, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-2")
        )
    }

    // MARK: Level 4 — Trikalam

    private static func level4() -> Lesson {
        Lesson(
            id: "level-4",
            levelNumber: 4,
            title: "Trikalam",
            subtitle: "Three speeds, one pulse",
            description: "The same phrase at ×1, ×2, ×4 density — without changing the BPM. This reveals every weakness in your timing.",
            estimatedMinutes: 12,
            steps: [
                .intro(text: "Trikalam = three speeds. The BPM doesn't change. What changes is how many syllables you fit per beat: 1, 2, then 4. The pulse is the anchor.", videoURL: nil),
                .loop(talaId: "adi", nadai: .tisra,    syllableGroupId: "jathi-3", cycles: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 2),
                .check(summary: "If you can hold the pulse through the density change, your internal clock is strong."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.70, maxAvgDriftMs: 65),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-3")
        )
    }

    // MARK: Level 5 — Tisra

    private static func level5() -> Lesson {
        Lesson(
            id: "level-5",
            levelNumber: 5,
            title: "Tisra",
            subtitle: "The art of triplets",
            description: "Tisra = 3 subdivisions per beat. Ta-Ki-Ta. This is the first nadai shift — the rhythm feels like it leans.",
            estimatedMinutes: 10,
            steps: [
                .intro(text: "Tisra is triplets. Three syllables per beat instead of four. Ta-Ki-Ta. The 'Ki' is the middle child — it's easy to rush it.", videoURL: nil),
                .listen(syllableGroupId: "jathi-3", repetitions: 6),
                .speak(syllableGroupId: "jathi-3", targetCount: 24, instruction: "Ta-Ki-Ta, 8 times at your own pace. Keep the middle syllable even."),
                .loop(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", cycles: 6),
                .mute(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", normalCycles: 4, muteCycles: 2),
                .check(summary: "You've crossed into Tisra. The triplet feel is now in your body."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.70, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-4")
        )
    }

    // MARK: Stub for locked levels

    private static func stub(level: Int) -> Lesson {
        let meta = LessonLibrary.meta(forLevel: level)
        return Lesson(
            id: "level-\(level)",
            levelNumber: level,
            title: meta?.title ?? "Level \(level)",
            subtitle: meta?.subtitle ?? "",
            description: "Content coming in v1.1.",
            estimatedMinutes: meta?.estimatedMinutes ?? 15,
            steps: [
                .intro(text: "This level is coming soon. Keep practising the earlier levels to prepare.", videoURL: nil),
                .check(summary: ""),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.70, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-\(level - 1)")
        )
    }
}
