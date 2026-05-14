import CoreGraphics

// MARK: - 8-Point Grid

/// All layout constants. Every value is a multiple of 4 or 8.
enum KMSpacing {

    // MARK: Grid atoms

    static let pt4:  CGFloat = 4
    static let pt8:  CGFloat = 8
    static let pt12: CGFloat = 12
    static let pt16: CGFloat = 16
    static let pt20: CGFloat = 20
    static let pt24: CGFloat = 24
    static let pt32: CGFloat = 32
    static let pt40: CGFloat = 40
    static let pt48: CGFloat = 48
    static let pt56: CGFloat = 56
    static let pt64: CGFloat = 64

    // MARK: Semantic names

    /// 20 pt — Safe distance from screen edge.
    static let screenEdge:   CGFloat = 20

    /// 16 pt — Internal card padding.
    static let cardPadding:  CGFloat = 16

    /// 12 pt — Gap between elements inside a card.
    static let elementGap:   CGFloat = 12

    /// 32 pt — Gap between top-level sections.
    static let sectionGap:   CGFloat = 32

    /// 44 pt — Minimum Apple HIG touch target.
    static let minTouchTarget: CGFloat = 44
}
