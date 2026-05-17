import SwiftUI

// MARK: - ReferenceView

struct ReferenceView: View {

    enum Section: Int, CaseIterable {
        case syllables, talas, glossary

        var label: String {
            switch self {
            case .syllables: return "Слоги"
            case .talas:     return "Талы"
            case .glossary:  return "Глоссарий"
            }
        }
    }

    @State private var selectedSection: Section = .syllables

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                header
                    .padding(.horizontal, KMSpacing.screenEdge)
                    .padding(.top, KMSpacing.pt12)
                    .padding(.bottom, KMSpacing.pt16)

                KMSegmentedControl(
                    options: Section.allCases,
                    selection: $selectedSection,
                    labelBuilder: { $0.label }
                )
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.bottom, KMSpacing.pt16)

                ScrollView {
                    Group {
                        switch selectedSection {
                        case .syllables: SyllablesSection()
                        case .talas:     TalasSection()
                        case .glossary:  GlossarySection()
                        }
                    }
                    .padding(.horizontal, KMSpacing.screenEdge)
                    .padding(.bottom, KMSpacing.pt48)
                }
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
        }
    }

    private var header: some View {
        HStack(alignment: .bottom) {
            VStack(alignment: .leading, spacing: 4) {
                Text("СПРАВОЧНИК")
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                    .kmTrackingMicro()
                Text("Теория")
                    .kmStyle(KMFont.title1)
            }
            Spacer()
            Image(systemName: "book.closed.fill")
                .foregroundStyle(Color.kmPrimary)
                .font(.system(size: 22))
        }
    }
}

// MARK: - Syllables Section

private struct SyllablesSection: View {

    struct SyllableEntry: Identifiable {
        let id = UUID()
        let name: String
        let phonetic: String
        let role: String
        let jathis: [Int]
    }

    let syllables: [SyllableEntry] = [
        SyllableEntry(name: "Та",  phonetic: "та",     role: "акцентный",    jathis: [2, 3, 4, 5, 7, 9]),
        SyllableEntry(name: "Ка",  phonetic: "ка",     role: "неакцентный",  jathis: [2, 4, 5, 7, 9]),
        SyllableEntry(name: "Ди",  phonetic: "ди",     role: "неакцентный",  jathis: [4, 7, 9]),
        SyllableEntry(name: "Ми",  phonetic: "ми",     role: "неакцентный",  jathis: [4, 7, 9]),
        SyllableEntry(name: "Ки",  phonetic: "ки",     role: "неакцентный",  jathis: [3, 5, 9]),
        SyllableEntry(name: "Тха", phonetic: "тха",    role: "акцентный",    jathis: [3, 5, 7, 9]),
        SyllableEntry(name: "Тхам",phonetic: "тхам",   role: "акцентный",    jathis: [3, 5, 7, 9]),
        SyllableEntry(name: "Дхин",phonetic: "дхин",   role: "акцентный",    jathis: [7]),
        SyllableEntry(name: "Ги",  phonetic: "ги",     role: "неакцентный",  jathis: [7]),
        SyllableEntry(name: "На",  phonetic: "на",     role: "неакцентный",  jathis: [9]),
        SyllableEntry(name: "Том", phonetic: "том",    role: "акцентный",    jathis: [9]),
    ]

    struct JathiGroup: Identifiable {
        let id = UUID()
        let count: Int
        let sequence: String
        let name: String
    }

    let jathiGroups: [JathiGroup] = [
        JathiGroup(count: 2, sequence: "Та-Ка",              name: "Тисра (двойка)"),
        JathiGroup(count: 3, sequence: "Та-Ки-Та",           name: "Тисра (тройка)"),
        JathiGroup(count: 4, sequence: "Та-Ка-Ди-Ми",        name: "Чатусра"),
        JathiGroup(count: 5, sequence: "Та-Ка-Та-Ки-Та",     name: "Кханда"),
        JathiGroup(count: 7, sequence: "Та-Ки-Та-Та-Ка-Ди-Ми", name: "Мисра"),
        JathiGroup(count: 9, sequence: "Та-Ка-Та-Ки-Та-Та-Ка-Ди-Ми", name: "Санкирна"),
    ]

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            syllableTable
            jathiGroupsCard
        }
    }

    // MARK: Syllable Table

    private var syllableTable: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            sectionLabel("СЛОГИ КОННАКОЛ")

            KMCard(elevation: .standard, padding: 0) {
                VStack(spacing: 0) {
                    // Header row
                    HStack(spacing: 0) {
                        headerCell("Слог",    flex: 1)
                        headerCell("Произн.", flex: 1)
                        headerCell("Роль",    flex: 2)
                        headerCell("Джати",   flex: 3)
                    }
                    .background(Color.kmSurface2)

                    Divider().background(Color.kmSurface2)

                    ForEach(Array(syllables.enumerated()), id: \.element.id) { idx, entry in
                        HStack(spacing: 0) {
                            // Syllable name
                            Text(entry.name)
                                .font(.system(size: 16, weight: .semibold, design: .monospaced))
                                .foregroundStyle(Color.kmPrimary)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, KMSpacing.pt12)
                                .padding(.vertical, KMSpacing.pt8)
                                .frame(maxWidth: .infinity)

                            // Phonetic
                            Text(entry.phonetic)
                                .kmStyle(KMFont.caption, color: .kmTextSecondary)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, KMSpacing.pt12)
                                .padding(.vertical, KMSpacing.pt8)

                            // Role
                            Text(entry.role)
                                .kmStyle(KMFont.micro, color: roleColor(entry.role), tracking: 0.3)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, KMSpacing.pt12)
                                .padding(.vertical, KMSpacing.pt8)

                            // Jathis
                            Text(entry.jathis.map { String($0) }.joined(separator: " · "))
                                .kmStyle(KMFont.micro, color: .kmTextTertiary)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(.horizontal, KMSpacing.pt12)
                                .padding(.vertical, KMSpacing.pt8)
                        }
                        .background(idx % 2 == 0 ? Color.clear : Color.kmSurface2.opacity(0.4))

                        if idx < syllables.count - 1 {
                            Divider()
                                .background(Color.kmSurface2.opacity(0.5))
                        }
                    }
                }
            }
        }
    }

    private func headerCell(_ text: String, flex: Int) -> some View {
        Text(text)
            .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 1)
            .kmTrackingMicro()
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(.horizontal, KMSpacing.pt12)
            .padding(.vertical, KMSpacing.pt8)
    }

    private func roleColor(_ role: String) -> Color {
        role == "акцентный" ? Color.kmPrimary : Color.kmTextSecondary
    }

    // MARK: Jathi Groups

    private var jathiGroupsCard: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            sectionLabel("ПЯТЬ ДЖАТИ")

            VStack(spacing: KMSpacing.pt8) {
                ForEach(jathiGroups) { group in
                    KMCard(elevation: .standard) {
                        HStack(alignment: .center, spacing: KMSpacing.pt12) {
                            // Count badge
                            Text("\(group.count)")
                                .font(.system(size: 20, weight: .bold, design: .monospaced))
                                .foregroundStyle(Color.kmPrimary)
                                .frame(width: 36, height: 36)
                                .background(Color.kmSurface2)
                                .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip))

                            VStack(alignment: .leading, spacing: 4) {
                                Text(group.name)
                                    .kmStyle(KMFont.caption, color: .kmTextSecondary)
                                Text(group.sequence)
                                    .font(.system(size: 13, weight: .medium, design: .monospaced))
                                    .foregroundStyle(Color.kmTextPrimary)
                            }
                            Spacer()
                        }
                    }
                }
            }
        }
    }
}

// MARK: - Talas Section

private struct TalasSection: View {

    struct TalaEntry: Identifiable {
        let id = UUID()
        let name: String
        let beats: Int
        let structure: String
        let angaBreakdown: String
        let angas: [String] // symbols
    }

    let talas: [TalaEntry] = [
        TalaEntry(
            name: "Ади",
            beats: 8,
            structure: "4 + 2 + 2",
            angaBreakdown: "Лагху (4) + Дхрутам (2) + Дхрутам (2)",
            angas: ["|", "O", "O"]
        ),
        TalaEntry(
            name: "Рупака",
            beats: 6,
            structure: "2 + 4",
            angaBreakdown: "Дхрутам (2) + Лагху (4)",
            angas: ["O", "|"]
        ),
        TalaEntry(
            name: "Мисра Чапу",
            beats: 7,
            structure: "3 + 2 + 2",
            angaBreakdown: "Лагху (3) + Дхрутам (2) + Дхрутам (2)",
            angas: ["|₃", "O", "O"]
        ),
        TalaEntry(
            name: "Кханда Чапу",
            beats: 5,
            structure: "2 + 3",
            angaBreakdown: "Дхрутам (2) + Лагху (3)",
            angas: ["O", "|₃"]
        ),
        TalaEntry(
            name: "Тисра Трипута",
            beats: 7,
            structure: "3 + 2 + 2",
            angaBreakdown: "Лагху тисра (3) + Дхрутам (2) + Дхрутам (2)",
            angas: ["|₃", "O", "O"]
        ),
    ]

    struct AngaSymbol: Identifiable {
        let id = UUID()
        let symbol: String
        let name: String
        let description: String
    }

    let angaCatalog: [AngaSymbol] = [
        AngaSymbol(symbol: "|",  name: "Лагху",      description: "Хлопок + загнутые пальцы. Длительность варьируется: 3, 4, 5, 7 или 9 бит в зависимости от джати."),
        AngaSymbol(symbol: "O",  name: "Дхрутам",    description: "Хлопок + волна ладони. Всегда 2 бита."),
        AngaSymbol(symbol: "U",  name: "Анудхрутам", description: "Одиночный хлопок. Всегда 1 бит. Используется только в некоторых строгих талах."),
    ]

    var body: some View {
        VStack(spacing: KMSpacing.sectionGap) {
            talasTable
            angaCatalogCard
        }
    }

    private var talasTable: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            sectionLabel("РАСПРОСТРАНЁННЫЕ ТАЛЫ")

            VStack(spacing: KMSpacing.pt8) {
                ForEach(talas) { tala in
                    KMCard(elevation: .standard) {
                        VStack(alignment: .leading, spacing: KMSpacing.pt8) {
                            HStack(alignment: .firstTextBaseline) {
                                Text(tala.name)
                                    .kmStyle(KMFont.title2)
                                Spacer()
                                Text("\(tala.beats) бит")
                                    .kmStyle(KMFont.caption, color: .kmPrimary)
                                    .padding(.horizontal, KMSpacing.pt8)
                                    .padding(.vertical, 4)
                                    .background(Color.kmSurface2)
                                    .clipShape(Capsule())
                            }

                            // Structure chips
                            HStack(spacing: KMSpacing.pt8) {
                                Text(tala.structure)
                                    .font(.system(size: 14, weight: .medium, design: .monospaced))
                                    .foregroundStyle(Color.kmTextPrimary)

                                Spacer()

                                HStack(spacing: KMSpacing.pt4) {
                                    ForEach(Array(tala.angas.enumerated()), id: \.offset) { _, sym in
                                        Text(sym)
                                            .font(.system(size: 16, weight: .bold, design: .monospaced))
                                            .foregroundStyle(Color.kmPrimary)
                                            .frame(width: 32, height: 28)
                                            .background(Color.kmSurface2)
                                            .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip))
                                    }
                                }
                            }

                            Text(tala.angaBreakdown)
                                .kmStyle(KMFont.caption, color: .kmTextSecondary)
                        }
                    }
                }
            }
        }
    }

    private var angaCatalogCard: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            sectionLabel("КАТАЛОГ АНГОВ")

            VStack(spacing: KMSpacing.pt8) {
                ForEach(angaCatalog) { anga in
                    KMCard(elevation: .standard) {
                        HStack(alignment: .top, spacing: KMSpacing.pt12) {
                            Text(anga.symbol)
                                .font(.system(size: 22, weight: .bold, design: .monospaced))
                                .foregroundStyle(Color.kmPrimary)
                                .frame(width: 40, height: 40)
                                .background(Color.kmSurface2)
                                .clipShape(RoundedRectangle(cornerRadius: KMRadius.chip))

                            VStack(alignment: .leading, spacing: 4) {
                                Text(anga.name)
                                    .kmStyle(KMFont.body)
                                Text(anga.description)
                                    .kmStyle(KMFont.caption, color: .kmTextSecondary)
                                    .lineSpacing(3)
                            }
                        }
                    }
                }
            }
        }
    }
}

// MARK: - Glossary Section

private struct GlossarySection: View {

    struct GlossaryEntry: Identifiable {
        let id = UUID()
        let term: String
        let definition: String
    }

    let entries: [GlossaryEntry] = [
        GlossaryEntry(
            term: "Авартанам",
            definition: "Один полный цикл талы. Термин используется для обозначения завершённого ритмического круга. Композиции в карнатической музыке измеряются количеством авартанамов."
        ),
        GlossaryEntry(
            term: "Акшара",
            definition: "Наименьшая единица времени в карнатической музыке — одна доля талы. Каждый акшара делится на более мелкие единицы (матры) в зависимости от надаи."
        ),
        GlossaryEntry(
            term: "Анга",
            definition: "Структурный элемент талы. Три основных анги: лагху (|), дхрутам (O) и анудхрутам (U). Комбинации ангов определяют структуру и характер каждой талы."
        ),
        GlossaryEntry(
            term: "Анудхрутам",
            definition: "Анга из одного хлопка. Обозначается символом U. Является наименьшим ангом — занимает ровно одну долю и встречается только в отдельных сложных талах."
        ),
        GlossaryEntry(
            term: "Брига",
            definition: "Быстрый мелизматический пробег по нотам или слогам. В ритмической практике — скоростное произнесение последовательности коннаколных слогов в украшательной манере."
        ),
        GlossaryEntry(
            term: "Вилямбит",
            definition: "Медленный темп исполнения. Один из трёх основных калам (темпов). В виламбите каждая доля растянута, что позволяет чётко прочувствовать внутреннее деление на надаи."
        ),
        GlossaryEntry(
            term: "Дхрит",
            definition: "Технический термин, используемый в некоторых традициях вместо «дхрутам». Обозначает анг из двух долей: хлопок плюс волнообразное движение ладони."
        ),
        GlossaryEntry(
            term: "Дхрутам",
            definition: "Анга из двух долей: хлопок и волна ладони. Обозначается символом O. Всегда состоит ровно из двух акшар и присутствует в большинстве распространённых тал — Ади, Рупаке и других."
        ),
        GlossaryEntry(
            term: "Джати",
            definition: "Группа слогов коннакола, составляющая ритмическую ячейку. Пять основных джати: тисра (3), чатусра (4), кханда (5), мисра (7), санкирна (9). Джати определяет надаи — плотность пульсации внутри доли."
        ),
        GlossaryEntry(
            term: "Калам",
            definition: "Темп исполнения. Три основных калама: вилямбит (медленный), мадхьям (средний) и дхрит (быстрый). Трикалам — упражнение, в котором все три скорости исполняются последовательно."
        ),
        GlossaryEntry(
            term: "Карнатическая музыка",
            definition: "Классическая музыкальная традиция Южной Индии. Отличается развитой теорией ритма (тала), специализированной ритмической речью (коннакол) и системой 72 основных ладов (мелакарта). Возраст традиции — более двух тысяч лет."
        ),
        GlossaryEntry(
            term: "Коннакол",
            definition: "Устная ритмическая сольфеджио Южной Индии. Система слогов (та, ка, ди, ми и другие), используемая для произнесения и запоминания ритмических паттернов. Практикуется как самостоятельное искусство и как инструмент обучения игре на мриданге."
        ),
        GlossaryEntry(
            term: "Корваи",
            definition: "Ритмическая каденция: паттерн, повторённый трижды с промежутками, завершающийся точно на саме. Корваи = (паттерн × 3) + зазор. Искусство состоит в том, чтобы выбрать зазор так, чтобы финал совпал с первой долей цикла."
        ),
        GlossaryEntry(
            term: "Куруппу",
            definition: "Ритмическая игра, в которой такт и надаи вступают в намеренное противоречие. Термин используется в некоторых школах для описания полиритмических упражнений, аналогичных западному понятию «полиритмия»."
        ),
        GlossaryEntry(
            term: "Лагху",
            definition: "Основная анга талы: хлопок и отсчёт пальцами. Обозначается символом |. Длина лагху определяется джати: тисра-лагху = 3 доли, чатусра-лагху = 4 доли и так далее — до санкирна-лагху (9 долей)."
        ),
        GlossaryEntry(
            term: "Мадхьям",
            definition: "Средний темп исполнения. Второй из трёх калам. В большинстве концертных выступлений пьеса начинается в мадхьяме, переходя к дхриту в кульминации."
        ),
        GlossaryEntry(
            term: "Мора",
            definition: "Ритмическая фигура из трёх повторений паттерна, каждое из которых начинается в строго определённой позиции внутри цикла. Отличается от корваи тем, что промежутков между повторениями нет — паттерн следует непрерывно."
        ),
        GlossaryEntry(
            term: "Мриданга яти",
            definition: "Общий термин для ритмических упражнений и композиций в стиле игры на мридange (двусторонний барабан). Коннакол часто используется как вербальная запись партий мриданги."
        ),
        GlossaryEntry(
            term: "Надаи (гати)",
            definition: "Деление каждой доли талы на равные подразделения. Пять надаи соответствуют пяти джати: тисра-надаи (×3), чатусра-надаи (×4), кханда-надаи (×5), мисра-надаи (×7), санкирна-надаи (×9). Выбор надаи задаёт «зернистость» ритма."
        ),
        GlossaryEntry(
            term: "Сам",
            definition: "Первая доля ритмического цикла (авартанама). Священная точка отсчёта: вся ритмическая игра устремлена к тому, чтобы попасть на сам. Произносится с ударением на «а» — «са́м»."
        ),
        GlossaryEntry(
            term: "Санкирна",
            definition: "Девятичастный джати: Та-Ка-Та-Ки-Та-Та-Ка-Ди-Ми (9 слогов). Наиболее сложный из пяти джати. Обозначается цифрой 9 и используется в продвинутых ритмических упражнениях и импровизациях."
        ),
        GlossaryEntry(
            term: "Сулади Сапта",
            definition: "«Семь сулади» — система из семи базовых тал карнатической музыки. Каждая тала имеет пять вариантов, соответствующих пяти джати, что в итоге даёт 35 основных тал."
        ),
        GlossaryEntry(
            term: "Тала",
            definition: "Ритмический цикл карнатической музыки. Тала — это не просто размер, а полная система: набор ангов, хлопков и жестов, которые исполнитель воспроизводит рукой, удерживая ритмическую основу произведения."
        ),
        GlossaryEntry(
            term: "Тани авартанам",
            definition: "Сольная ритмическая каденция перкуссиониста в конце концертного произведения. Во время тани авартанама солист и остальные музыканты умолкают, давая барабанщику возможность продемонстрировать мастерство."
        ),
        GlossaryEntry(
            term: "Тирманам",
            definition: "Торжественное ритмическое заключение: три повторения финального паттерна, замыкающих музыкальную фразу или раздел. Синоним корваи в ряде традиций, но чаще обозначает особенно акцентированный каданс."
        ),
        GlossaryEntry(
            term: "Тисра",
            definition: "Трёхчастный джати: Та-Ки-Та (3 слога). Один из двух наиболее часто используемых джати наряду с чатусрой. Лежит в основе Тисра Трипута талы и всех упражнений на трёхдольность."
        ),
        GlossaryEntry(
            term: "Трикалам",
            definition: "Упражнение в трёх темпах: исходном (1×), вдвое быстрее (2×) и вчетверо быстрее (4×). Все три скорости исполняются без остановки подряд, при этом акцент всегда попадает на сам."
        ),
        GlossaryEntry(
            term: "Чатусра",
            definition: "Четырёхчастный джати: Та-Ка-Ди-Ми (4 слога). Самый распространённый джати — основа Ади талы. Чатусра-надаи соответствует западному понятию четырёхдольности."
        ),
        GlossaryEntry(
            term: "Кханда",
            definition: "Пятичастный джати: Та-Ка-Та-Ки-Та (5 слогов). Название происходит от санскритского слова, означающего «разделённый». Кханда-надаи создаёт характерное асимметричное ощущение — пять в четырёх."
        ),
        GlossaryEntry(
            term: "Яти",
            definition: "Принцип изменения длины паттернов внутри ритмической последовательности. Шесть типов яти: самаяти (равная), срото-вахаяти (нарастающая), гопуча-яти (убывающая), мридангаяти (нарастает-убывает), дамарояти (убывает-нарастает), пипиликаяти (узкая в середине)."
        ),
    ]

    var body: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            sectionLabel("ГЛОССАРИЙ")

            VStack(spacing: KMSpacing.pt8) {
                ForEach(entries) { entry in
                    GlossaryRow(entry: entry)
                }
            }
        }
    }
}

private struct GlossaryRow: View {
    let entry: GlossarySection.GlossaryEntry
    @State private var isExpanded = false

    var body: some View {
        KMCard(elevation: .standard) {
            VStack(alignment: .leading, spacing: 0) {
                Button {
                    withAnimation(.kmQuick) { isExpanded.toggle() }
                } label: {
                    HStack {
                        Text(entry.term)
                            .kmStyle(KMFont.body)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        Image(systemName: isExpanded ? "chevron.up" : "chevron.down")
                            .foregroundStyle(Color.kmTextTertiary)
                            .font(.system(size: 12, weight: .medium))
                    }
                }
                .buttonStyle(.plain)

                if isExpanded {
                    Divider()
                        .background(Color.kmSurface2)
                        .padding(.vertical, KMSpacing.pt8)

                    Text(entry.definition)
                        .kmStyle(KMFont.caption, color: .kmTextSecondary)
                        .lineSpacing(4)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }
}

// MARK: - Shared Helpers

private func sectionLabel(_ text: String) -> some View {
    Text(text)
        .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
        .kmTrackingMicro()
}

// MARK: - Preview

#Preview {
    ReferenceView()
}
