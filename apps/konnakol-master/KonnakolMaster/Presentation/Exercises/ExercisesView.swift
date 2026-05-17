import SwiftUI

struct ExercisesView: View {
    var body: some View {
        NavigationStack {
            VStack {
                Text("Упражнения")
                    .kmStyle(KMFont.title1)
                Text("Тренажёр · v1.1")
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }
}
