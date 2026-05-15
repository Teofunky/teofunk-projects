import Foundation

// MARK: - Lesson

struct Lesson: Identifiable, Codable {
    let id: String
    let levelNumber: Int
    let title: String
    let subtitle: String
    let description: String
    let estimatedMinutes: Int
    let steps: [LessonStep]
    let passCriteria: PassCriteria
    let unlockCriteria: UnlockCriteria
}

// MARK: - LessonStep

enum LessonStep: Codable, Identifiable {
    case intro(text: String, videoURL: URL?)
    case listen(syllableGroupId: String, repetitions: Int)
    case speak(syllableGroupId: String, targetCount: Int, instruction: String)
    case clap(talaId: String, nadai: Nadai, cycles: Int)
    case loop(talaId: String, nadai: Nadai, syllableGroupId: String, cycles: Int)
    case mute(talaId: String, nadai: Nadai, syllableGroupId: String, normalCycles: Int, muteCycles: Int)
    case play(instrument: Instrument, prompt: String)
    case check(summary: String)

    var id: String {
        switch self {
        case .intro:   return "intro"
        case .listen:  return "listen"
        case .speak:   return "speak"
        case .clap:    return "clap"
        case .loop:    return "loop"
        case .mute:    return "mute"
        case .play:    return "play"
        case .check:   return "check"
        }
    }

    var displayName: String {
        switch self {
        case .intro:   return "Intro"
        case .listen:  return "Listen"
        case .speak:   return "Speak"
        case .clap:    return "Clap"
        case .loop:    return "Loop"
        case .mute:    return "Mute Test"
        case .play:    return "Apply"
        case .check:   return "Results"
        }
    }

    var systemImage: String {
        switch self {
        case .intro:   return "text.book.closed"
        case .listen:  return "ear"
        case .speak:   return "mic"
        case .clap:    return "hands.clap"
        case .loop:    return "repeat"
        case .mute:    return "speaker.slash"
        case .play:    return "guitars"
        case .check:   return "checkmark.seal"
        }
    }

    // Needed for Codable with associated values
    private enum CodingKeys: String, CodingKey {
        case type, text, videoURL, syllableGroupId, repetitions, targetCount
        case instruction, talaId, nadai, cycles, normalCycles, muteCycles
        case instrument, prompt, summary
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let type = try c.decode(String.self, forKey: .type)
        switch type {
        case "intro":
            self = .intro(
                text: try c.decode(String.self, forKey: .text),
                videoURL: try c.decodeIfPresent(URL.self, forKey: .videoURL)
            )
        case "listen":
            self = .listen(
                syllableGroupId: try c.decode(String.self, forKey: .syllableGroupId),
                repetitions: try c.decode(Int.self, forKey: .repetitions)
            )
        case "speak":
            self = .speak(
                syllableGroupId: try c.decode(String.self, forKey: .syllableGroupId),
                targetCount: try c.decode(Int.self, forKey: .targetCount),
                instruction: try c.decode(String.self, forKey: .instruction)
            )
        case "clap":
            self = .clap(
                talaId: try c.decode(String.self, forKey: .talaId),
                nadai: try c.decode(Nadai.self, forKey: .nadai),
                cycles: try c.decode(Int.self, forKey: .cycles)
            )
        case "loop":
            self = .loop(
                talaId: try c.decode(String.self, forKey: .talaId),
                nadai: try c.decode(Nadai.self, forKey: .nadai),
                syllableGroupId: try c.decode(String.self, forKey: .syllableGroupId),
                cycles: try c.decode(Int.self, forKey: .cycles)
            )
        case "mute":
            self = .mute(
                talaId: try c.decode(String.self, forKey: .talaId),
                nadai: try c.decode(Nadai.self, forKey: .nadai),
                syllableGroupId: try c.decode(String.self, forKey: .syllableGroupId),
                normalCycles: try c.decode(Int.self, forKey: .normalCycles),
                muteCycles: try c.decode(Int.self, forKey: .muteCycles)
            )
        case "play":
            self = .play(
                instrument: try c.decode(Instrument.self, forKey: .instrument),
                prompt: try c.decode(String.self, forKey: .prompt)
            )
        default:
            self = .check(summary: (try? c.decode(String.self, forKey: .summary)) ?? "")
        }
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        switch self {
        case .intro(let text, let url):
            try c.encode("intro", forKey: .type)
            try c.encode(text, forKey: .text)
            try c.encodeIfPresent(url, forKey: .videoURL)
        case .listen(let gid, let reps):
            try c.encode("listen", forKey: .type)
            try c.encode(gid, forKey: .syllableGroupId)
            try c.encode(reps, forKey: .repetitions)
        case .speak(let gid, let count, let instr):
            try c.encode("speak", forKey: .type)
            try c.encode(gid, forKey: .syllableGroupId)
            try c.encode(count, forKey: .targetCount)
            try c.encode(instr, forKey: .instruction)
        case .clap(let tid, let nadai, let cycles):
            try c.encode("clap", forKey: .type)
            try c.encode(tid, forKey: .talaId)
            try c.encode(nadai, forKey: .nadai)
            try c.encode(cycles, forKey: .cycles)
        case .loop(let tid, let nadai, let gid, let cycles):
            try c.encode("loop", forKey: .type)
            try c.encode(tid, forKey: .talaId)
            try c.encode(nadai, forKey: .nadai)
            try c.encode(gid, forKey: .syllableGroupId)
            try c.encode(cycles, forKey: .cycles)
        case .mute(let tid, let nadai, let gid, let nc, let mc):
            try c.encode("mute", forKey: .type)
            try c.encode(tid, forKey: .talaId)
            try c.encode(nadai, forKey: .nadai)
            try c.encode(gid, forKey: .syllableGroupId)
            try c.encode(nc, forKey: .normalCycles)
            try c.encode(mc, forKey: .muteCycles)
        case .play(let instr, let prompt):
            try c.encode("play", forKey: .type)
            try c.encode(instr, forKey: .instrument)
            try c.encode(prompt, forKey: .prompt)
        case .check(let summary):
            try c.encode("check", forKey: .type)
            try c.encode(summary, forKey: .summary)
        }
    }
}

// MARK: - Criteria

struct PassCriteria: Codable {
    let minAccuracy: Double   // 0.0–1.0
    let maxAvgDriftMs: Int
}

struct UnlockCriteria: Codable {
    enum Kind: String, Codable { case first, completePrevious }
    let kind: Kind
    let requiredLessonId: String?
}

// MARK: - Step Result (collected during playthrough)

struct StepResult {
    let stepId: String
    let accuracy: Double       // 0–1, NaN if not applicable
    let avgDriftMs: Int        // 0 if not applicable
    let completedAt: Date
}
