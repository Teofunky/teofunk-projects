import Foundation

// MARK: - TheoryArticle

struct TheoryArticle: Identifiable {
    let id: String
    let title: String
    let subtitle: String
    let icon: String          // SF Symbol
    let readingMinutes: Int
    let sections: [ArticleSection]
}

// MARK: - ArticleSection

struct ArticleSection: Identifiable {
    let id = UUID()
    let heading: String?      // nil = no heading, just body paragraph
    let body: String
    let example: ArticleExample?

    init(heading: String? = nil, body: String, example: ArticleExample? = nil) {
        self.heading = heading
        self.body = body
        self.example = example
    }
}

// MARK: - ArticleExample

struct ArticleExample {
    let label: String         // e.g. "Пример:"
    let syllables: String     // e.g. "ТА-КА-ДИ-МИ  ТА-КА-ДИ-МИ"
    let note: String?

    init(label: String, syllables: String, note: String? = nil) {
        self.label = label
        self.syllables = syllables
        self.note = note
    }
}
