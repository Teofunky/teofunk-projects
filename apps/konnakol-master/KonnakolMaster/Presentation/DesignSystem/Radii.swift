import CoreGraphics

// MARK: - Corner Radii

/// Consistent radius tokens for the 8-pt grid. Never hard-code corner radii inline.
enum KMRadius {
    /// 8 pt — Chips, badges, small tags.
    static let chip:    CGFloat = 8

    /// 12 pt — Buttons, text inputs, small interactive surfaces.
    static let button:  CGFloat = 12

    /// 16 pt — Cards, list rows, medium containers.
    static let card:    CGFloat = 16

    /// 24 pt — Modals, full-width sheets, large surfaces.
    static let modal:   CGFloat = 24

    /// 50% (use .clipShape(Circle())) — Avatars, FABs, round buttons.
    static let full:    CGFloat = 9999
}
