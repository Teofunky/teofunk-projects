import SwiftUI

struct PracticeView: View {
    var body: some View {
        NavigationStack {
            VStack {
                Text("Practice")
                    .kmStyle(KMFont.title1)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
