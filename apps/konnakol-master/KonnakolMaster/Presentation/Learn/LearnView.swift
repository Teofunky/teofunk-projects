import SwiftUI

struct LearnView: View {
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    Text("Your Journey")
                        .kmStyle(KMFont.title1)
                }
                .padding(KMSpacing.screenEdge)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
