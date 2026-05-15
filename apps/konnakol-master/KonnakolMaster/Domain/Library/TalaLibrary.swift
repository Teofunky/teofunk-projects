import Foundation

// MARK: - TalaLibrary
//
// Authoritative list of talas shipped with the app.
// MVP includes the 5 most common. The full Sapta-Tala system
// (35 base talas × 5 nadai = 175 combinations) is a v1.1 preset library.

enum TalaLibrary {

    // MARK: Ordered list for the segmented picker

    static let all: [Tala] = [adi, rupaka, misraChapu, khandaChapu, tisraTriputa]

    // MARK: - Tala Definitions

    /// Adi Tala (Chatusra-jati Triputa)
    /// Structure: Laghu(4) + Drutam + Drutam = 8 beats
    /// Used in ~50% of all Carnatic compositions.
    static let adi = Tala(
        id: "adi",
        name: "Adi Tala",
        displayName: "आदि ताल",
        totalBeats: 8,
        angas: [.laghu(beats: 4), .drutam, .drutam],
        defaultNadai: .chatusra
    )

    /// Rupaka Tala
    /// Structure: Drutam + Laghu(4) = 6 beats
    /// Common in kritis and slow compositions.
    static let rupaka = Tala(
        id: "rupaka",
        name: "Rupaka Tala",
        displayName: "रूपक ताल",
        totalBeats: 6,
        angas: [.drutam, .laghu(beats: 4)],
        defaultNadai: .chatusra
    )

    /// Misra Chapu
    /// Structure: 3+2+2 = 7 beats
    /// Came from folk tradition. No laghu finger-counting — special hand pattern.
    /// Western: 7/8
    static let misraChapu = Tala(
        id: "misra-chapu",
        name: "Misra Chapu",
        displayName: "मिश्र चापु",
        totalBeats: 7,
        angas: [.laghu(beats: 3), .drutam, .drutam],
        defaultNadai: .misra
    )

    /// Khanda Chapu
    /// Structure: 2+3 = 5 beats
    /// Folk-origin, energetic feel.
    /// Western: 5/8
    static let khandaChapu = Tala(
        id: "khanda-chapu",
        name: "Khanda Chapu",
        displayName: "खण्ड चापु",
        totalBeats: 5,
        angas: [.drutam, .laghu(beats: 3)],
        defaultNadai: .khanda
    )

    /// Tisra Triputa
    /// Structure: Laghu(3) + Drutam + Drutam = 7 beats
    /// Used for fast compositions.
    static let tisraTriputa = Tala(
        id: "tisra-triputa",
        name: "Tisra Triputa",
        displayName: "तिश्र त्रिपुट",
        totalBeats: 7,
        angas: [.laghu(beats: 3), .drutam, .drutam],
        defaultNadai: .tisra
    )

    // MARK: Lookup

    static func tala(forId id: String) -> Tala? {
        all.first { $0.id == id }
    }
}
