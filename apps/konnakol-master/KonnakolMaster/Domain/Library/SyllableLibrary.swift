import Foundation

// MARK: - Syllable Model

/// A single phonetic unit in the konnakol system.
struct Syllable: Identifiable, Hashable, Codable {
    let id: String           // "ta", "ka", "di"
    let display: String      // "Ta", "Ka", "Di" — shown in UI
    let audioFile: String    // "ta.wav"
    let isAccent: Bool       // true for phrase-opening syllables
}

// MARK: - SyllableGroup

/// N syllables spoken as one rhythmic unit (jathi).
struct SyllableGroup: Identifiable, Hashable, Codable {
    let id: String
    let count: Int
    let syllables: [Syllable]
    let standardName: String   // "Ta-Ka-Di-Mi"
    let karnaticName: String   // "Chatusra"
}

// MARK: - SyllableLibrary

enum SyllableLibrary {

    // MARK: Individual Syllables

    static let ta   = Syllable(id: "ta",   display: "Ta",   audioFile: "ta.wav",   isAccent: true)
    static let ka   = Syllable(id: "ka",   display: "Ka",   audioFile: "ka.wav",   isAccent: false)
    static let di   = Syllable(id: "di",   display: "Di",   audioFile: "di.wav",   isAccent: false)
    static let mi   = Syllable(id: "mi",   display: "Mi",   audioFile: "mi.wav",   isAccent: false)
    static let ki   = Syllable(id: "ki",   display: "Ki",   audioFile: "ki.wav",   isAccent: false)
    static let tha  = Syllable(id: "tha",  display: "Tha",  audioFile: "tha.wav",  isAccent: true)
    static let tham = Syllable(id: "tham", display: "Tham", audioFile: "tham.wav", isAccent: true)
    static let dhin = Syllable(id: "dhin", display: "Dhin", audioFile: "dhin.wav", isAccent: false)
    static let gi   = Syllable(id: "gi",   display: "Gi",   audioFile: "gi.wav",   isAccent: false)
    static let na   = Syllable(id: "na",   display: "Na",   audioFile: "na.wav",   isAccent: false)
    static let tom  = Syllable(id: "tom",  display: "Tom",  audioFile: "tom.wav",  isAccent: true)

    static let all: [Syllable] = [ta, ka, di, mi, ki, tha, tham, dhin, gi, na, tom]

    // MARK: Standard Groups (Jathis 1–9)

    static let jathi1 = SyllableGroup(
        id: "jathi-1", count: 1,
        syllables: [tham],
        standardName: "Tham",
        karnaticName: "Anudrutam")

    static let jathi2 = SyllableGroup(
        id: "jathi-2", count: 2,
        syllables: [ta, ka],
        standardName: "Ta-Ka",
        karnaticName: "Drutam")

    static let jathi3 = SyllableGroup(
        id: "jathi-3", count: 3,
        syllables: [ta, ki, ta],
        standardName: "Ta-Ki-Ta",
        karnaticName: "Tisra")

    static let jathi4 = SyllableGroup(
        id: "jathi-4", count: 4,
        syllables: [ta, ka, di, mi],
        standardName: "Ta-Ka-Di-Mi",
        karnaticName: "Chatusra")

    static let jathi5 = SyllableGroup(
        id: "jathi-5", count: 5,
        syllables: [ta, ka, ta, ki, ta],
        standardName: "Ta-Ka-Ta-Ki-Ta",
        karnaticName: "Khanda")

    static let jathi6 = SyllableGroup(
        id: "jathi-6", count: 6,
        syllables: [ta, ki, ta, ta, ki, ta],
        standardName: "Ta-Ki-Ta Ta-Ki-Ta",
        karnaticName: "Tisra ×2")

    static let jathi7 = SyllableGroup(
        id: "jathi-7", count: 7,
        syllables: [ta, ki, ta, ta, ka, di, mi],
        standardName: "Ta-Ki-Ta Ta-Ka-Di-Mi",
        karnaticName: "Misra")

    static let jathi8 = SyllableGroup(
        id: "jathi-8", count: 8,
        syllables: [ta, ka, di, mi, ta, ka, di, mi],
        standardName: "Ta-Ka-Di-Mi Ta-Ka-Di-Mi",
        karnaticName: "Chatusra ×2")

    static let jathi9 = SyllableGroup(
        id: "jathi-9", count: 9,
        syllables: [ta, ka, di, mi, ta, ta, ka, di, mi],
        standardName: "Ta-Ka-Di-Mi-Ta Ta-Ka-Di-Mi",
        karnaticName: "Sankeerna")

    static let allJathis: [SyllableGroup] = [
        jathi1, jathi2, jathi3, jathi4, jathi5,
        jathi6, jathi7, jathi8, jathi9
    ]

    // MARK: Nadai → Default Group

    /// The standard syllable group that matches a given nadai.
    static func defaultGroup(for nadai: Nadai) -> SyllableGroup {
        switch nadai {
        case .tisra:     return jathi3
        case .chatusra:  return jathi4
        case .khanda:    return jathi5
        case .misra:     return jathi7
        case .sankeerna: return jathi9
        }
    }
}
