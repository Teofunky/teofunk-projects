import SwiftUI

struct ComposeView: View {
    var body: some View {
        NavigationStack {
            VStack {
                Text("Compose")
                    .kmStyle(KMFont.title1)
                Text("Korvai Builder · v1.1")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
