import SwiftUI

// MARK: - TheoryView

struct TheoryView: View {

    enum Tab: Int, CaseIterable {
        case articles, reference

        var label: String {
            switch self {
            case .articles:  return "Теория"
            case .reference: return "Справочник"
            }
        }
    }

    @State private var selectedTab: Tab = .articles
    @State private var selectedArticle: TheoryArticle?

    var body: some View {
        VStack(spacing: 0) {
            // Header
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(selectedTab == .articles ? "Теория" : "Справочник")
                        .kmStyle(KMFont.title1)
                    Text(selectedTab == .articles
                         ? "Методика и принципы коннакола"
                         : "Слоги, талы, глоссарий")
                        .kmStyle(KMFont.caption, color: .kmTextTertiary)
                }
                Spacer()
            }
            .padding(.horizontal, KMSpacing.screenEdge)
            .padding(.top, KMSpacing.pt16)
            .padding(.bottom, KMSpacing.pt12)

            // Tab picker
            KMSegmentedControl(
                options: Tab.allCases,
                selection: $selectedTab,
                labelBuilder: { $0.label }
            )
            .padding(.horizontal, KMSpacing.screenEdge)
            .padding(.bottom, KMSpacing.pt16)

            // Content
            switch selectedTab {
            case .articles:
                articlesList
            case .reference:
                ReferenceView(standalone: false)
            }
        }
        .background(Color.kmBase.ignoresSafeArea())
        .sheet(item: $selectedArticle) { article in
            ArticleDetailView(article: article)
                .presentationDetents([.large])
                .presentationDragIndicator(.hidden)
                .interactiveDismissDisabled(true)
        }
    }

    // MARK: Articles List

    private var articlesList: some View {
        ScrollView {
            LazyVStack(spacing: KMSpacing.pt12) {
                ForEach(TheoryContent.allArticles) { article in
                    ArticleCard(article: article)
                        .onTapGesture {
                            HapticEngine.impact(.light)
                            selectedArticle = article
                        }
                }
            }
            .padding(.horizontal, KMSpacing.screenEdge)
            .padding(.bottom, KMSpacing.pt32)
        }
    }
}

// MARK: - ArticleCard

private struct ArticleCard: View {
    let article: TheoryArticle

    var body: some View {
        HStack(spacing: KMSpacing.pt16) {
            ZStack {
                RoundedRectangle(cornerRadius: 10)
                    .fill(Color.kmPrimary.opacity(0.15))
                    .frame(width: 48, height: 48)
                Image(systemName: article.icon)
                    .font(.system(size: 22))
                    .foregroundStyle(Color.kmPrimary)
            }

            VStack(alignment: .leading, spacing: 4) {
                Text(article.title)
                    .kmStyle(KMFont.body)
                Text(article.subtitle)
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
                    .lineLimit(2)
            }

            Spacer()

            VStack(alignment: .trailing, spacing: 2) {
                Image(systemName: "chevron.right")
                    .font(.system(size: 12))
                    .foregroundStyle(Color.kmTextTertiary)
                Text("\(article.readingMinutes) мин")
                    .kmStyle(KMFont.micro, color: .kmTextTertiary)
            }
        }
        .padding(KMSpacing.pt16)
        .background(Color.kmSurface1)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - ArticleDetailView

struct ArticleDetailView: View {
    let article: TheoryArticle
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        VStack(spacing: 0) {
            // Top bar
            HStack {
                Spacer()
                Button("Готово") { dismiss() }
                    .foregroundStyle(Color.kmPrimary)
                    .padding(.trailing, KMSpacing.screenEdge)
            }
            .frame(height: 44)
            .background(Color.kmBase)

            Divider().background(Color.kmSurface2)

            ScrollView(.vertical, showsIndicators: true) {
                VStack(alignment: .leading, spacing: KMSpacing.sectionGap) {
                    // Hero header
                    VStack(alignment: .leading, spacing: KMSpacing.pt8) {
                        HStack(spacing: KMSpacing.pt12) {
                            ZStack {
                                RoundedRectangle(cornerRadius: 12)
                                    .fill(Color.kmPrimary.opacity(0.15))
                                    .frame(width: 56, height: 56)
                                Image(systemName: article.icon)
                                    .font(.system(size: 26))
                                    .foregroundStyle(Color.kmPrimary)
                            }
                            VStack(alignment: .leading, spacing: 4) {
                                Text(article.title)
                                    .kmStyle(KMFont.title1)
                                HStack(spacing: 6) {
                                    Image(systemName: "clock")
                                        .font(.system(size: 11))
                                    Text("\(article.readingMinutes) минут чтения")
                                }
                                .kmStyle(KMFont.caption, color: .kmTextTertiary)
                            }
                        }
                        Text(article.subtitle)
                            .kmStyle(KMFont.body, color: .kmTextSecondary)
                    }
                    .padding(.horizontal, KMSpacing.screenEdge)

                    Divider().background(Color.kmSurface2)

                    ForEach(article.sections) { section in
                        ArticleSectionView(section: section)
                    }
                }
                .padding(.top, KMSpacing.pt16)
                .padding(.bottom, KMSpacing.pt48)
            }
        }
        .background(Color.kmBase.ignoresSafeArea())
    }
}

// MARK: - ArticleSectionView

private struct ArticleSectionView: View {
    let section: ArticleSection

    var body: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            if let heading = section.heading {
                Text(heading)
                    .kmStyle(KMFont.title2)
                    .padding(.horizontal, KMSpacing.screenEdge)
            }

            Text(section.body)
                .kmStyle(KMFont.body, color: .kmTextSecondary)
                .lineSpacing(5)
                .padding(.horizontal, KMSpacing.screenEdge)

            if let example = section.example {
                ExampleBox(example: example)
                    .padding(.horizontal, KMSpacing.screenEdge)
            }
        }
    }
}

// MARK: - ExampleBox

private struct ExampleBox: View {
    let example: ArticleExample

    var body: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt8) {
            Text(example.label)
                .kmStyle(KMFont.caption, color: .kmTextTertiary)
            Text(example.syllables)
                .font(.system(size: 18, weight: .medium, design: .monospaced))
                .foregroundStyle(Color.kmPrimary)
                .lineSpacing(6)
            if let note = example.note {
                Text(note)
                    .kmStyle(KMFont.caption, color: .kmTextTertiary)
            }
        }
        .padding(KMSpacing.pt16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.kmSurface1)
        .overlay(
            RoundedRectangle(cornerRadius: 8)
                .stroke(Color.kmPrimary.opacity(0.3), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }
}
