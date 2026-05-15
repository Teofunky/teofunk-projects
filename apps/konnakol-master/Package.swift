// swift-tools-version: 5.9
import PackageDescription

let package = Package(
    name: "KonnakolMaster",
    platforms: [
        .iOS(.v17),
    ],
    targets: [
        .target(
            name: "KonnakolMaster",
            path: "KonnakolMaster",
            exclude: [
                "Tests",
                "AppStore",
                "Resources/Sounds",
                "Resources/Videos",
                "Resources/Lessons",
                "Resources/Fonts",
            ],
            swiftSettings: []
        ),
        .testTarget(
            name: "KonnakolMasterTests",
            dependencies: ["KonnakolMaster"],
            path: "KonnakolMaster/Tests/UnitTests"
        ),
    ]
)
