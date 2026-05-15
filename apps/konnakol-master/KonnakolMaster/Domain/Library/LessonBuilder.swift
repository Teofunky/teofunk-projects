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
        case 6:  return level6()
        case 7:  return level7()
        case 8:  return level8()
        case 9:  return level9()
        case 10: return level10()
        case 11: return level11()
        case 12: return level12()
        case 13: return level13()
        case 14: return level14()
        case 15: return level15()
        case 16: return level16()
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

    // MARK: Level 6 — Pancha Nadai

    private static func level6() -> Lesson {
        Lesson(
            id: "level-6",
            levelNumber: 6,
            title: "Pancha Nadai",
            subtitle: "All five subdivisions",
            description: "Cycle through all five nadais — Tisra, Chatusra, Khanda, Misra, Sankeerna — within the same Adi Tala frame. This is the gateway to full rhythmic fluency.",
            estimatedMinutes: 15,
            steps: [
                .intro(text: "Pancha means five. Today you'll speak in all five nadais — 3, 4, 5, 7, and 9 — each inside the same 8-beat tala. The pulse stays fixed; the density shifts.", videoURL: nil),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .listen(syllableGroupId: "jathi-5", repetitions: 4),
                .listen(syllableGroupId: "jathi-7", repetitions: 4),
                .speak(syllableGroupId: "jathi-5", targetCount: 40, instruction: "Khanda: Ta-Ka-Tha-Ki-Na. Five syllables per beat. Say it 8 times evenly."),
                .loop(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", cycles: 4),
                .speak(syllableGroupId: "jathi-7", targetCount: 56, instruction: "Misra: seven syllables per beat. Say it 8 times without rushing the end."),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 4),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 2, muteCycles: 2),
                .check(summary: "You now speak all five nadais. Each is a different shade of the same pulse."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.68, maxAvgDriftMs: 75),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-5")
        )
    }

    // MARK: Level 7 — Rupaka Tala

    private static func level7() -> Lesson {
        Lesson(
            id: "level-7",
            levelNumber: 7,
            title: "Rupaka Tala",
            subtitle: "The six-beat cycle",
            description: "Rupaka Tala is 6 beats: Drutam(2) + Laghu(4). The first beat is a rest — you wave, not clap. This asymmetry is its defining character.",
            estimatedMinutes: 12,
            steps: [
                .intro(text: "Rupaka Tala is 6 beats: a wave on beat 1, then a clap on beat 3. If Adi is the heartbeat, Rupaka is the breath — two unequal halves that balance each other.", videoURL: nil),
                .clap(talaId: "rupaka", nadai: .chatusra, cycles: 6),
                .loop(talaId: "rupaka", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 6),
                .mute(talaId: "rupaka", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 2),
                .play(instrument: .guitar, prompt: "In Rupaka Tala, play a chord on beat 1 (wave) and strum on beats 3 and 5. Try at 80 BPM."),
                .check(summary: "You can navigate the 6-beat wave. Notice how Sam (beat 1) has a different quality here."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.68, maxAvgDriftMs: 75),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-6")
        )
    }

    // MARK: Level 8 — Chapu Talas

    private static func level8() -> Lesson {
        Lesson(
            id: "level-8",
            levelNumber: 8,
            title: "Chapu Talas",
            subtitle: "Misra 7 and Khanda 5",
            description: "Chapu talas are asymmetric and folk-flavoured — 3+4 for Misra (7 beats) and 2+3 for Khanda (5 beats). They are the rhythmic lifeblood of folk and film music.",
            estimatedMinutes: 15,
            steps: [
                .intro(text: "Misra Chapu is 3+4: a short unit and a long one. Khanda Chapu is 2+3. The asymmetry is intentional — it creates a limping, forward-leaning feel that is deeply characteristic.", videoURL: nil),
                .clap(talaId: "misra-chapu", nadai: .chatusra, cycles: 4),
                .loop(talaId: "misra-chapu", nadai: .chatusra, syllableGroupId: "jathi-7", cycles: 4),
                .mute(talaId: "misra-chapu", nadai: .chatusra, syllableGroupId: "jathi-7", normalCycles: 4, muteCycles: 2),
                .clap(talaId: "khanda-chapu", nadai: .chatusra, cycles: 4),
                .loop(talaId: "khanda-chapu", nadai: .chatusra, syllableGroupId: "jathi-5", cycles: 4),
                .mute(talaId: "khanda-chapu", nadai: .chatusra, syllableGroupId: "jathi-5", normalCycles: 4, muteCycles: 2),
                .check(summary: "Chapu talas add asymmetry and groove to your rhythmic vocabulary."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.65, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-7")
        )
    }

    // MARK: Level 9 — Gati Bhedam

    private static func level9() -> Lesson {
        Lesson(
            id: "level-9",
            levelNumber: 9,
            title: "Gati Bhedam",
            subtitle: "Nadai switching within tala",
            description: "Gati Bhedam is the art of seamlessly switching nadai mid-tala cycle — from Chatusra to Tisra and back — while the pulse stays locked. This is advanced polyrhythm.",
            estimatedMinutes: 15,
            steps: [
                .intro(text: "Gati means gait, Bhedam means difference. You will shift from 4-per-beat to 3-per-beat mid-cycle — without pausing, without rushing the transition. The tala is the anchor.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .speak(syllableGroupId: "jathi-4", targetCount: 32, instruction: "Say Ta-Ka-Di-Mi 4 times, then immediately switch to Ta-Ki-Ta 4 times. The beat doesn't change."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .loop(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", cycles: 4),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 2),
                .check(summary: "Your ear now hears the pulse independently from the density. This is the beginning of real rhythmic independence."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.65, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-8")
        )
    }

    // MARK: Level 10 — Yati Phrases

    private static func level10() -> Lesson {
        Lesson(
            id: "level-10",
            levelNumber: 10,
            title: "Yati Phrases",
            subtitle: "Geometry of rhythm",
            description: "Yati phrases grow or shrink in specific geometric patterns. Srotovaha yati expands (3-4-5-6), Gopucha contracts (6-5-4-3). The shape of the phrase is the composition.",
            estimatedMinutes: 15,
            steps: [
                .intro(text: "Yati is pattern with geometric form. Srotovaha expands like a river widening. Gopucha contracts like a cow's tail tapering. Mridanga yati forms a diamond — expand then contract.", videoURL: nil),
                .listen(syllableGroupId: "jathi-3", repetitions: 3),
                .listen(syllableGroupId: "jathi-4", repetitions: 3),
                .listen(syllableGroupId: "jathi-5", repetitions: 3),
                .speak(syllableGroupId: "jathi-3", targetCount: 12, instruction: "3-syllable group × 3 reps, then 4-syllable × 3 reps, then 5-syllable × 3 reps. This is Srotovaha yati."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 6),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 3),
                .check(summary: "Yati phrases are the architecture of konnakol composition. Each shape has a name and a character."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.65, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-9")
        )
    }

    // MARK: Level 11 — Mora Builder

    private static func level11() -> Lesson {
        Lesson(
            id: "level-11",
            levelNumber: 11,
            title: "Mora Builder",
            subtitle: "Statement · Gap · Resolution",
            description: "A Mora is a 3× repetition of a phrase with gaps calculated to resolve exactly on Sam. The gap calculation is the mathematical heart of konnakol.",
            estimatedMinutes: 20,
            steps: [
                .intro(text: "Mora formula: (phrase × gapBeats) × 3 = Sam. A 3-beat phrase with 1 gap: (3+1) × 3 = 12 beats. In Adi Tala (8 beats), 12 beats = 1 cycle + 4 beats — Sam lands mid-cycle. Adjust until it resolves on beat 1.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 6),
                .speak(syllableGroupId: "jathi-4", targetCount: 48, instruction: "Say Ta-Ka-Di-Mi, pause 1 beat, repeat 3 times total. The third Ta-Ka-Di-Mi should land on Sam."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 6),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 3),
                .check(summary: "You've built your first Mora. Resolution on Sam is the electric feeling every konnakol student chases."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.65, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-10")
        )
    }

    // MARK: Level 12 — Mukthay

    private static func level12() -> Lesson {
        Lesson(
            id: "level-12",
            levelNumber: 12,
            title: "Mukthay",
            subtitle: "Triple repetition + resolution",
            description: "A Mukthay is a phrase repeated exactly 3 times so that the end of the third repetition lands precisely on Sam. Unlike a Mora, there are no gaps — the calculation is in the phrase length itself.",
            estimatedMinutes: 20,
            steps: [
                .intro(text: "Mukthay means liberation. The phrase must be a length that divides evenly into the tala (or a full cycle multiple) when tripled. In Adi Tala × 3 cycles = 24 beats. A 8-beat phrase × 3 = 24. Perfect.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 6),
                .listen(syllableGroupId: "jathi-5", repetitions: 6),
                .speak(syllableGroupId: "jathi-5", targetCount: 120, instruction: "Khanda (5-syllable) phrase × 3 reps. Count to make sure the third ends on beat 1 of the next cycle."),
                .loop(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", cycles: 6),
                .mute(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", normalCycles: 4, muteCycles: 3),
                .check(summary: "You can now construct a Mukthay. This is a complete compositional statement in itself."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.65, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-11")
        )
    }

    // MARK: Level 13 — Korvai Construction

    private static func level13() -> Lesson {
        Lesson(
            id: "level-13",
            levelNumber: 13,
            title: "Korvai Construction",
            subtitle: "Purvangam + Uttarangam",
            description: "A Korvai is a complete rhythmic composition with two parts: the Purvangam (setup, typically 3+ cycles) and the Uttarangam (conclusion, typically a Mukthay). Both must resolve on the same Sam.",
            estimatedMinutes: 25,
            steps: [
                .intro(text: "Korvai is the crown of konnakol. Purvangam establishes the character — a repeating pattern or yati phrase. Uttarangam is the Mukthay that closes it. Both parts are calculated to land on the same Sam.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 6),
                .listen(syllableGroupId: "jathi-5", repetitions: 6),
                .speak(syllableGroupId: "jathi-4", targetCount: 64, instruction: "Purvangam: Chatusra × 2 cycles (16 beats). Speak 4 times through, evenly."),
                .speak(syllableGroupId: "jathi-5", targetCount: 120, instruction: "Uttarangam: Khanda Mukthay × 3 repetitions. End must land on Sam."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 8),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 4),
                .check(summary: "You have performed your first Korvai. Welcome to the art of rhythmic composition."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.60, maxAvgDriftMs: 90),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-12")
        )
    }

    // MARK: Level 14 — Jathi Bhedam

    private static func level14() -> Lesson {
        Lesson(
            id: "level-14",
            levelNumber: 14,
            title: "Jathi Bhedam",
            subtitle: "Accent displacement",
            description: "Jathi Bhedam takes a fixed syllable pattern and displaces the accent across different groupings — 3, 4, 5, 7 — while the total number of syllables stays constant. The pulse reorganises around the accent.",
            estimatedMinutes: 20,
            steps: [
                .intro(text: "Jathi Bhedam: the same string of syllables, but accented differently. Ta-Ka-Di-Mi | Ta-Ka-Di-Mi sounds different from Ta-Ka-Di | Mi-Ta-Ka | Di-Mi-Ta. Your mouth says the same thing. Your ear hears different rhythms.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 6),
                .listen(syllableGroupId: "jathi-3", repetitions: 6),
                .speak(syllableGroupId: "jathi-4", targetCount: 48, instruction: "Accent every 3rd syllable in a stream of 4-syllable groups. Feel the 3-beat accent against the 4-beat pattern."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 6),
                .loop(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", cycles: 6),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 4),
                .check(summary: "Jathi Bhedam is where rhythm becomes language — the same words in different cadences."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.60, maxAvgDriftMs: 90),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-13")
        )
    }

    // MARK: Level 15 — Anuloma-Pratiloma

    private static func level15() -> Lesson {
        Lesson(
            id: "level-15",
            levelNumber: 15,
            title: "Anuloma-Pratiloma",
            subtitle: "Phrase in five speeds",
            description: "Anuloma is with the pulse; Pratiloma is against it. The same phrase is performed at ×1, ×1.5, ×2, ×3, and ×4 against a fixed tala. This is the ultimate test of internal rhythmic independence.",
            estimatedMinutes: 25,
            steps: [
                .intro(text: "Anuloma: phrase at its natural speed. Pratiloma: phrase squeezed against a different pulse. To speak 3 syllables where 4 are expected, or 5 where 3 are expected, your internal pulse must be absolute.", videoURL: nil),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .listen(syllableGroupId: "jathi-5", repetitions: 4),
                .speak(syllableGroupId: "jathi-4", targetCount: 32, instruction: "Speak Ta-Ka-Di-Mi at ×1 speed, then at ×2 (twice as dense), staying in Adi Tala."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 4),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 4, muteCycles: 4),
                .check(summary: "Anuloma-Pratiloma separates the practitioner from the master. If you can hold the pulse here, you hold it everywhere."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.58, maxAvgDriftMs: 100),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-14")
        )
    }

    // MARK: Level 16 — Compose Your Korvai

    private static func level16() -> Lesson {
        Lesson(
            id: "level-16",
            levelNumber: 16,
            title: "Compose Your Korvai",
            subtitle: "Your own composition",
            description: "Bring everything together. Choose your tala, choose your nadai, design your Purvangam, calculate your Uttarangam Mukthay, and perform it from start to Sam without the metronome.",
            estimatedMinutes: 30,
            steps: [
                .intro(text: "This is the graduation lesson. You have learned every tool — tala, nadai, yati, mora, mukthay, korvai, jathi bhedam, anuloma-pratiloma. Now compose something that is yours. It can be simple. Make it resolve on Sam.", videoURL: nil),
                .speak(syllableGroupId: "jathi-4", targetCount: 64, instruction: "Perform your Purvangam. Any nadai, any pattern. Maintain it for 2–4 cycles."),
                .speak(syllableGroupId: "jathi-5", targetCount: 120, instruction: "Perform your Uttarangam — 3× repetition of your chosen phrase. Resolve on Sam."),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 8),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 2, muteCycles: 6),
                .check(summary: "You are a konnakol practitioner. The art is now yours to carry, develop, and teach."),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.55, maxAvgDriftMs: 100),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-15")
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
