import Foundation
import Observation

@Observable
final class PracticeViewModel {

    // MARK: Controls (bound to UI)

    var bpm: Int = 90
    var selectedTala: Tala = TalaLibrary.adi
    var selectedNadai: Nadai = .chatusra
    var isPlaying: Bool = false
    var isMicActive: Bool = false
    var isRecording: Bool = false
    var trikaramMode: Bool = false      // shows 3 concentric rings

    // MARK: Beat state (updated from AudioEngine.currentBeat)

    private(set) var activeSyllableIndex: Int = 0
    private(set) var beatsHit: [Int] = []         // recent 3 beats for trail

    var syllableGroup: SyllableGroup {
        SyllableLibrary.defaultGroup(for: selectedNadai)
    }

    // MARK: Private

    private var sylTimer: Timer?
    private var trailTimer: Timer?

    // MARK: Public API

    func togglePlay(audio: AudioEngine) {
        if isPlaying {
            audio.stop()
            stopTimers()
            isPlaying = false
        } else {
            audio.start(bpm: bpm, tala: selectedTala, nadai: selectedNadai)
            startSyllableTimer()
            isPlaying = true
        }
    }

    func applyBPM(_ newBPM: Int, audio: AudioEngine) {
        bpm = newBPM
        guard isPlaying else { return }
        audio.changeBPM(newBPM)
        restartSyllableTimer()
    }

    func applyTala(_ tala: Tala, audio: AudioEngine) {
        selectedTala = tala
        guard isPlaying else { return }
        audio.changeTala(tala)
    }

    func applyNadai(_ nadai: Nadai, audio: AudioEngine) {
        selectedNadai = nadai
        guard isPlaying else { return }
        audio.changeNadai(nadai)
        restartSyllableTimer()
    }

    /// Called by PracticeView.onChange(of: audio.currentBeat)
    func onBeatChange(_ beat: Int) {
        beatsHit.append(beat)
        if beatsHit.count > 4 { beatsHit.removeFirst() }
    }

    // MARK: Private timers

    private func startSyllableTimer() {
        let interval = syllableInterval
        sylTimer = Timer.scheduledTimer(withTimeInterval: interval, repeats: true) { [weak self] _ in
            guard let self else { return }
            self.activeSyllableIndex = (self.activeSyllableIndex + 1) % self.syllableGroup.syllables.count
        }
        RunLoop.main.add(sylTimer!, forMode: .common)
    }

    private func restartSyllableTimer() {
        sylTimer?.invalidate()
        activeSyllableIndex = 0
        startSyllableTimer()
    }

    private func stopTimers() {
        sylTimer?.invalidate();  sylTimer = nil
        trailTimer?.invalidate(); trailTimer = nil
        activeSyllableIndex = 0
        beatsHit = []
    }

    private var syllableInterval: TimeInterval {
        60.0 / Double(bpm) / Double(selectedNadai.rawValue)
    }
}
