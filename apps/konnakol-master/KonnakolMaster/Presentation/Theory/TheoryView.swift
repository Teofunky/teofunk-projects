import SwiftUI

struct TheoryView: View {
    var body: some View {
        NavigationStack {
            VStack {
                Text("Теория")
                    .kmStyle(KMFont.title1)
                Text("Справочник · v1.1")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
