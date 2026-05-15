import Foundation

// MARK: - LessonLibrary
//
// Metadata for all 16 levels. Full lesson content (steps, audio refs)
// lives in Resources/Lessons/lessons.json. This file provides the
// in-memory index used by the skill tree and Today screen.

enum LessonLibrary {

    struct Meta: Identifiable {
        let id: String
        let level: Int
        let title: String
        let subtitle: String
        let tier: Tier
        let estimatedMinutes: Int
        let talaId: String?
    }

    enum Tier: String {
        case beginner, intermediate, advanced
    }

    static let all: [Meta] = [
        .init(id: "level-1",  level: 1,  title: "First Voice",       subtitle: "Your first syllables",            tier: .beginner,     estimatedMinutes: 8,  talaId: nil),
        .init(id: "level-2",  level: 2,  title: "The Pulse",         subtitle: "Meet the metronome",              tier: .beginner,     estimatedMinutes: 10, talaId: nil),
        .init(id: "level-3",  level: 3,  title: "Adi Tala",          subtitle: "The king of rhythmic cycles",     tier: .beginner,     estimatedMinutes: 12, talaId: "adi"),
        .init(id: "level-4",  level: 4,  title: "Trikalam",          subtitle: "Three speeds, one pulse",         tier: .beginner,     estimatedMinutes: 12, talaId: "adi"),
        .init(id: "level-5",  level: 5,  title: "Tisra",             subtitle: "The art of triplets",             tier: .beginner,     estimatedMinutes: 10, talaId: "adi"),
        .init(id: "level-6",  level: 6,  title: "Pancha Nadai",      subtitle: "All five subdivisions",           tier: .intermediate, estimatedMinutes: 15, talaId: "adi"),
        .init(id: "level-7",  level: 7,  title: "Rupaka Tala",       subtitle: "The six-beat cycle",              tier: .intermediate, estimatedMinutes: 12, talaId: "rupaka"),
        .init(id: "level-8",  level: 8,  title: "Chapu Talas",       subtitle: "Misra 7 and Khanda 5",            tier: .intermediate, estimatedMinutes: 15, talaId: "misra-chapu"),
        .init(id: "level-9",  level: 9,  title: "Gati Bhedam",       subtitle: "Nadai switching within tala",     tier: .intermediate, estimatedMinutes: 15, talaId: "adi"),
        .init(id: "level-10", level: 10, title: "Yati Phrases",      subtitle: "Geometry of rhythm",              tier: .intermediate, estimatedMinutes: 15, talaId: "adi"),
        .init(id: "level-11", level: 11, title: "Mora Builder",       subtitle: "Statement · Gap · Resolution",   tier: .advanced,     estimatedMinutes: 20, talaId: "adi"),
        .init(id: "level-12", level: 12, title: "Mukthay",           subtitle: "Triple repetition + resolution",  tier: .advanced,     estimatedMinutes: 20, talaId: "adi"),
        .init(id: "level-13", level: 13, title: "Korvai Construction",subtitle: "Purvangam + Uttarangam",         tier: .advanced,     estimatedMinutes: 25, talaId: "adi"),
        .init(id: "level-14", level: 14, title: "Jathi Bhedam",      subtitle: "Accent displacement",             tier: .advanced,     estimatedMinutes: 20, talaId: "adi"),
        .init(id: "level-15", level: 15, title: "Anuloma-Pratiloma", subtitle: "Phrase in five speeds",           tier: .advanced,     estimatedMinutes: 25, talaId: "adi"),
        .init(id: "level-16", level: 16, title: "Compose Your Korvai",subtitle: "Your own composition",          tier: .advanced,     estimatedMinutes: 30, talaId: "adi"),
    ]

    static func title(forLevel level: Int) -> String {
        all.first { $0.level == level }?.title ?? "Level \(level)"
    }

    static func meta(forLevel level: Int) -> Meta? {
        all.first { $0.level == level }
    }
}
