import AVFoundation

// MARK: - AudioSessionManager
//
// Configures AVAudioSession for low-latency playback + recording.
// Must be called before the engine starts.

enum AudioSessionManager {

    /// The preferred I/O buffer duration. Targets ~5ms latency on modern iPhones.
    private static let preferredBufferDuration: TimeInterval = 0.005

    /// Activate the audio session for simultaneous playback and recording.
    /// Throws if the system denies the configuration.
    static func activate() throws {
        let session = AVAudioSession.sharedInstance()
        // Bluetooth headset support deferred — `.allowBluetooth` is deprecated and its
        // replacement `.allowBluetoothHFP` requires iOS 18. Built-in mic + speaker work
        // for the MVP.
        try session.setCategory(
            .playAndRecord,
            mode: .measurement,          // low-latency, no DSP processing
            options: [.defaultToSpeaker]
        )
        try session.setPreferredIOBufferDuration(preferredBufferDuration)
        try session.setPreferredSampleRate(44_100)
        try session.setActive(true)
    }

    /// Deactivate when the app moves to background or the engine stops.
    static func deactivate() {
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }
}
