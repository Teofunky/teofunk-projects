import Foundation
import Observation

@Observable
final class OnboardingViewModel {
    var page: Int = 0
    var selectedInstrument: Instrument = .rhythmOnly
    var selectedLevel: ExperienceLevel = .beginner

    let totalPages = 3

    func advance() { if page < totalPages - 1 { page += 1 } }
    func back()    { if page > 0 { page -= 1 } }

    func finish(store: ProgressStore) {
        store.updateInstrument(selectedInstrument)
        store.completeOnboarding()
    }
}
