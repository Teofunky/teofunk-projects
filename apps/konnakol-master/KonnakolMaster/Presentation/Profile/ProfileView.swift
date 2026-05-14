import SwiftUI

struct ProfileView: View {
    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    Text("Profile")
                        .kmStyle(KMFont.title1)
                }
                .padding(KMSpacing.screenEdge)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
