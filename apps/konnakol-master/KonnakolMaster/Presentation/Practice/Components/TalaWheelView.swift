import SwiftUI

// MARK: - TalaWheelView
//
// THE hero visual of Konnakol Master.
// Design spec: 280-320pt diameter circle, beat dots around perimeter,
// active beat glows saffron with 120% scale + trail decay, Sam larger,
// gesture icons below each dot, syllables in centre.
//
// Animation model:
//   - Active beat: scale 1.0→1.2 in 60ms (kmBeatOn), then decay over 400ms (kmBeatOff)
//   - Trail: beat-1 at 45% opacity, beat-2 at 20%, beat-3 at 0%
//   - Sam (beat 0): always rendered 30% larger than other dots
//   - Whole-circle "breath": subtle scale 1.0↔1.015 synced to Sam

struct TalaWheelView: View {

    let tala: Tala
    let currentBeat: Int
    let isPlaying: Bool
    let syllableGroup: SyllableGroup
    let activeSyllableIndex: Int
    let trikalamMode: Bool

    // MARK: Layout constants

    private let wheelDiameter: CGFloat = 300
    private var dotOrbitRadius: CGFloat { wheelDiameter / 2 - 22 }
    private var gestureOrbitRadius: CGFloat { dotOrbitRadius + 22 }

    // MARK: Animation state

    @State private var breathe: Bool = false
    @State private var beatScale: [Int: CGFloat]   = [:]   // beat → current scale
    @State private var beatOpacity: [Int: Double]  = [:]   // beat → glow opacity

    var body: some View {
        ZStack {
            trikalamRings
            outerBreathRing
            baseCircle
            beatDots
            centerContent
        }
        .frame(width: wheelDiameter + 48, height: wheelDiameter + 48)
        .onChange(of: isPlaying) { _, playing in
            withAnimation(playing
                ? .easeInOut(duration: 2.4).repeatForever(autoreverses: true)
                : .kmQuick
            ) { breathe = playing }
        }
        .onChange(of: currentBeat) { _, beat in
            pulseBeat(beat)
        }
    }

    // MARK: Trikalam rings (x1, x2, x4)

    @ViewBuilder
    private var trikalamRings: some View {
        if trikalamMode {
            ForEach([0.75, 0.88] as [CGFloat], id: \.self) { fraction in
                Circle()
                    .strokeBorder(Color.kmPrimary.opacity(0.10), lineWidth: 1)
                    .frame(width: wheelDiameter * fraction, height: wheelDiameter * fraction)
            }
        }
    }

    // MARK: Outer breath ring (Sam pulse)

    private var outerBreathRing: some View {
        Circle()
            .strokeBorder(Color.kmPrimary.opacity(breathe ? 0.12 : 0.04), lineWidth: 1)
            .frame(width: wheelDiameter + 24, height: wheelDiameter + 24)
            .scaleEffect(breathe ? 1.015 : 1.0)
    }

    // MARK: Base circle

    private var baseCircle: some View {
        Circle()
            .strokeBorder(Color.kmSurface2, lineWidth: 1)
            .frame(width: wheelDiameter, height: wheelDiameter)
    }

    // MARK: Beat dots + gesture icons

    private var beatDots: some View {
        ZStack {
            ForEach(0..<tala.totalBeats, id: \.self) { beat in
                let angle  = beatAngle(beat)
                let isSam  = beat == 0
                let isActive = beat == currentBeat && isPlaying

                let dotSize: CGFloat  = isSam ? 18 : 12
                let scale             = beatScale[beat] ?? 1.0
                let glowOpacity       = beatOpacity[beat] ?? 0
                let trailOpacity      = trailOpacity(for: beat)
                let fillColor: Color  = isActive ? .kmPrimary : trailOpacity > 0 ? Color.kmPrimary : Color.kmSurface3

                // Glow shadow layer
                Circle()
                    .fill(Color.kmPrimary)
                    .frame(width: dotSize * scale + 16, height: dotSize * scale + 16)
                    .opacity(glowOpacity)
                    .offset(dotOffset(angle: angle, radius: dotOrbitRadius))
                    .animation(nil, value: currentBeat)

                // Dot
                Circle()
                    .fill(fillColor)
                    .frame(width: dotSize, height: dotSize)
                    .scaleEffect(scale)
                    .opacity(isActive ? 1.0 : max(trailOpacity, isSam ? 0.5 : 0.25))
                    .overlay(
                        // Sam marker ring
                        isSam ? Circle().strokeBorder(Color.kmPrimary.opacity(0.6), lineWidth: 1.5) : nil
                    )
                    .offset(dotOffset(angle: angle, radius: dotOrbitRadius))
                    .animation(.kmBeatOff, value: scale)

                // Gesture icon (outside the circle)
                gestureIcon(beat: beat, isSam: isSam)
                    .offset(dotOffset(angle: angle, radius: gestureOrbitRadius))
                    .opacity(isPlaying ? 1 : 0.4)
            }
        }
    }

    // MARK: Center content

    private var centerContent: some View {
        SyllableCenterView(
            syllableGroup: syllableGroup,
            activeIndex: activeSyllableIndex,
            isPlaying: isPlaying
        )
        .frame(width: wheelDiameter * 0.55)
    }

    // MARK: Gesture icons

    @ViewBuilder
    private func gestureIcon(beat: Int, isSam: Bool) -> some View {
        let gesture = angaGesture(beat: beat)
        let isActive = beat == currentBeat && isPlaying

        Group {
            switch gesture {
            case .clap:
                Image(systemName: "hands.clap\(isActive ? ".fill" : "")")
            case .wave:
                Image(systemName: "hand.wave\(isActive ? ".fill" : "")")
            case .finger(let n):
                Image(systemName: "hand.\(min(n, 5)).raised\(isActive ? ".fill" : "")")
            }
        }
        .font(.system(size: 11, weight: isActive ? .semibold : .regular))
        .foregroundStyle(isActive ? Color.kmPrimary : Color.kmTextDisabled)
        .animation(.kmBeatOn, value: currentBeat)
    }

    // MARK: Beat pulse animation

    private func pulseBeat(_ beat: Int) {
        // Immediately scale up
        beatScale[beat]   = 1.35
        beatOpacity[beat] = 0.45
        HapticEngine.impact(beat == 0 ? .medium : .light)

        // Decay over 400ms
        withAnimation(.kmBeatOff) {
            beatScale[beat]   = 1.0
            beatOpacity[beat] = 0
        }
    }

    // MARK: Trail opacity

    private func trailOpacity(for beat: Int) -> Double {
        guard isPlaying else { return 0 }
        let total = tala.totalBeats
        let distance = (currentBeat - beat + total) % total
        switch distance {
        case 0: return 1.0
        case 1: return 0.45
        case 2: return 0.20
        default: return 0.0
        }
    }

    // MARK: Geometry helpers

    private func beatAngle(_ beat: Int) -> Double {
        // Beat 0 (Sam) at 12 o'clock = -π/2
        -Double.pi / 2 + 2 * Double.pi * Double(beat) / Double(tala.totalBeats)
    }

    private func dotOffset(angle: Double, radius: CGFloat) -> CGSize {
        CGSize(
            width:  radius * CGFloat(cos(angle)),
            height: radius * CGFloat(sin(angle))
        )
    }

    // MARK: Anga → gesture type

    private enum Gesture {
        case clap, wave, finger(Int)
    }

    private func angaGesture(beat: Int) -> Gesture {
        var cursor = 0
        for anga in tala.angas {
            let end = cursor + anga.beatCount
            if beat >= cursor && beat < end {
                switch anga {
                case .anudrutam:
                    return .clap
                case .drutam:
                    return beat == cursor ? .clap : .wave
                case .laghu:
                    return beat == cursor ? .clap : .finger(beat - cursor)
                }
            }
            cursor = end
        }
        return .clap
    }
}

// MARK: - Preview

#Preview {
    @Previewable @State var beat = 0
    TalaWheelView(
        tala: TalaLibrary.adi,
        currentBeat: beat,
        isPlaying: true,
        syllableGroup: SyllableLibrary.jathi4,
        activeSyllableIndex: 0,
        trikalamMode: false
    )
    .padding()
    .background(Color.kmBase)
    .onAppear {
        Timer.scheduledTimer(withTimeInterval: 0.5, repeats: true) { _ in
            beat = (beat + 1) % 8
        }
    }
}
