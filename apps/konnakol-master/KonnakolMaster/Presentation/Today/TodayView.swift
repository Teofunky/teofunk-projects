import SwiftUI

struct TodayView: View {
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    Text("Today")
                        .kmStyle(KMFont.title1)
                }
                .padding(KMSpacing.screenEdge)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
