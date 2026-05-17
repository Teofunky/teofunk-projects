import SwiftUI

// MARK: - Data Model

struct Exercise: Identifiable {
    let id = UUID()
    let name: String
    let description: String
    let durationMinutes: Int
    let difficulty: Int       // 1–5
    let syllableSequence: String?
    let steps: [String]
    let category: Category

    enum Category: String, CaseIterable {
        case warmup      = "Разогрев"
        case jathi       = "Джати"
        case korvai      = "Корваи"
        case mora        = "Мора"
        case polyrhythm  = "Полиритмия"

        var icon: String {
            switch self {
            case .warmup:     return "sun.min.fill"
            case .jathi:      return "repeat"
            case .korvai:     return "arrow.triangle.2.circlepath"
            case .mora:       return "waveform"
            case .polyrhythm: return "square.grid.2x2"
            }
        }
    }
}

// MARK: - Exercise Library

private enum ExerciseLibrary {

    static let all: [Exercise] = [
        // WARMUP
        Exercise(
            name: "Базовые слоги",
            description: "Медленное произношение всех 5 джати по 8 раз каждый. Фокус: чёткость артикуляции, не скорость.",
            durationMinutes: 5,
            difficulty: 1,
            syllableSequence: "Та-Ки-Та · Та-Ка-Ди-Ми · Та-Ка-Та-Ки-Та",
            steps: [
                "Сядьте удобно, выпрямите спину. Расслабьте плечи и язык.",
                "Произнесите Та-Ки-Та (тисра) 8 раз подряд в медленном темпе.",
                "Перейдите к Та-Ка-Ди-Ми (чатусра) — 8 повторений.",
                "Произнесите Та-Ка-Та-Ки-Та (кханда) 8 раз. Следите за согласными.",
                "Та-Ки-Та Та-Ка-Ди-Ми (мисра) — 8 повторений.",
                "Завершите Та-Ка-Та-Ки-Та Та-Ка-Ди-Ми (санкирна) 8 раз.",
                "Обратите внимание: каждый слог должен звучать отчётливо, без слияния.",
            ],
            category: .warmup
        ),
        Exercise(
            name: "Счёт авартанам",
            description: "Считать Ади талу вслух (1-2-3-4-1-2-1-2) хлопая лагху и волнами на дхрутам.",
            durationMinutes: 3,
            difficulty: 1,
            syllableSequence: nil,
            steps: [
                "Ади тала состоит из 8 долей: лагху (4) + дхрутам (2) + дхрутам (2).",
                "Хлопните ладонью на долю 1. Загибайте пальцы на 2, 3, 4 — это лагху.",
                "На долю 5 хлопните снова, на 6 сделайте волну ладони — это первый дхрутам.",
                "На долю 7 хлопните, на 8 — волна. Это второй дхрутам.",
                "Считайте вслух: 1-2-3-4 · 1-2 · 1-2. Это один авартанам.",
                "Повторите 8 авартанамов не останавливаясь, поддерживая стабильный темп.",
            ],
            category: .warmup
        ),

        // JATHI
        Exercise(
            name: "Тисра трикалам",
            description: "Та-Ки-Та в трёх скоростях (1×, 2×, 4×). 4 авартанама каждая скорость.",
            durationMinutes: 8,
            difficulty: 2,
            syllableSequence: "Та-Ки-Та",
            steps: [
                "Установите метроном на 60 уд/мин. Каждый удар = одна доля Ади талы.",
                "1× скорость: произносите Та-Ки-Та, три слога на каждые три доли. 4 авартанама.",
                "2× скорость: шесть слогов за те же три доли. Дыхание остаётся ровным.",
                "4× скорость: двенадцать слогов. Язык и губы должны быть расслаблены.",
                "Переход между скоростями происходит точно на саме — без паузы.",
                "Следите: акцент на «Та» в начале каждой группы не пропадает даже на 4×.",
            ],
            category: .jathi
        ),
        Exercise(
            name: "Чатусра трикалам",
            description: "Та-Ка-Ди-Ми в трёх скоростях.",
            durationMinutes: 8,
            difficulty: 2,
            syllableSequence: "Та-Ка-Ди-Ми",
            steps: [
                "Установите метроном на 60 уд/мин.",
                "1× скорость: Та-Ка-Ди-Ми — четыре слога заполняют четыре доли. 4 авартанама.",
                "2× скорость: восемь слогов на четыре доли. Удвоение темпа, не акцента.",
                "4× скорость: шестнадцать слогов. Следите за финальным «Ми» — он не должен теряться.",
                "Трикалам: выполните все три скорости без остановки, завершая на саме.",
            ],
            category: .jathi
        ),
        Exercise(
            name: "Кханда трикалам",
            description: "Та-Ка-Та-Ки-Та в трёх скоростях — сложнее, требует концентрации.",
            durationMinutes: 10,
            difficulty: 3,
            syllableSequence: "Та-Ка-Та-Ки-Та",
            steps: [
                "Кханда (5) сложнее всего вписывается в чатусра-ритм — начните медленно.",
                "1× скорость: пять слогов на пять долей. 4 авартанама в Ади тале.",
                "2× скорость: десять слогов. Обратите внимание на «Ки» — он часто теряется.",
                "4× скорость: двадцать слогов. Вдыхайте между авартанамами, не внутри.",
                "Трикалам без остановки: 4+4+4 = 12 авартанамов. Финал точно на саме.",
                "Частая ошибка: «Та-Ки-Та» в конце звучит как тисра — следите за акцентами.",
            ],
            category: .jathi
        ),
        Exercise(
            name: "Все пять джати",
            description: "Пройти джати 3→4→5→7→9 подряд в Ади тале, 2 авартанама каждый.",
            durationMinutes: 15,
            difficulty: 4,
            syllableSequence: "3 → 4 → 5 → 7 → 9",
            steps: [
                "Цель: 10 авартанамов подряд, каждый джати — 2 авартанама.",
                "Тисра (Та-Ки-Та): 2 авартанама. Входите в Ади талу спокойно.",
                "Чатусра (Та-Ка-Ди-Ми): 2 авартанама. Переход на саме без паузы.",
                "Кханда (Та-Ка-Та-Ки-Та): 2 авартанама. Следите за стабильностью долей.",
                "Мисра (Та-Ки-Та-Та-Ка-Ди-Ми): 2 авартанама. Самый протяжённый джати.",
                "Санкирна (9): 2 авартанама. Финальная фраза заканчивается точно на саме.",
                "После завершения: оцените, где темп «плыл» — там и нужна дополнительная работа.",
            ],
            category: .jathi
        ),

        // KORVAI
        Exercise(
            name: "Корваи 5×3",
            description: "Та-Ка-Та-Ки-Та (5) × 3 = 15. В Ади тале (16): 15 + 1 = 16. Гэп = Та. Повторить 4 раза до самого сама.",
            durationMinutes: 10,
            difficulty: 3,
            syllableSequence: "[ Та-Ка-Та-Ки-Та ] × 3 + Та",
            steps: [
                "Паттерн: Та-Ка-Та-Ки-Та = 5 слогов. Три повторения = 15 слогов.",
                "Ади тала = 16 долей. Зазор = 16 − 15 = 1 доля. Зазор заполняется «Та».",
                "Полная корваи: Та-Ка-Та-Ки-Та · Та-Ка-Та-Ки-Та · Та-Ка-Та-Ки-Та · Та.",
                "Повторите корваи 4 раза подряд, каждый раз начиная точно после зазора.",
                "Финальный «Та» четвёртой корваи должен совпасть с самом.",
                "Практика без метронома: хлопайте Ади талу и произносите корваи одновременно.",
            ],
            category: .korvai
        ),
        Exercise(
            name: "Корваи 7×3",
            description: "Та-Ки-Та Та-Ка-Ди-Ми (7) × 3 = 21. В 2 Ади авартанамах (32): 32−21=11, промежуток 11 долей.",
            durationMinutes: 12,
            difficulty: 4,
            syllableSequence: "[ Та-Ки-Та-Та-Ка-Ди-Ми ] × 3",
            steps: [
                "Паттерн: Та-Ки-Та-Та-Ка-Ди-Ми = 7 слогов (мисра джати).",
                "Два авартанама = 32 доли. 7 × 3 = 21. Зазор = 32 − 21 = 11 долей.",
                "Проговорите зазор (11 долей), считая внутренне или хлопая тихо.",
                "Структура: [11 долей зазора] + [7+7+7 слогов] = 32 доли.",
                "После подготовки: начинайте произносить паттерн в нужный момент.",
                "Финальная слог 'Ми' точно попадает на сам следующего авартанама.",
            ],
            category: .korvai
        ),
        Exercise(
            name: "Корваи 9×3",
            description: "Та-Ка-Ди-Ми-Та Та-Ка-Ди-Ми (9) × 3 = 27. В 2 Ади авартанамах: 32−27=5.",
            durationMinutes: 12,
            difficulty: 5,
            syllableSequence: "[ Та-Ка-Та-Ки-Та-Та-Ка-Ди-Ми ] × 3",
            steps: [
                "Паттерн: санкирна-джати = 9 слогов. Три повторения = 27 слогов.",
                "Два авартанама = 32 доли. Зазор = 32 − 27 = 5 долей (кханда-гэп).",
                "Запомните зазор как один кханда-паттерн (Та-Ка-Та-Ки-Та) — это помогает.",
                "Общая схема: [5 долей тишины] → [9+9+9 слогов] → сам.",
                "Сложность: 9 слогов сложно удерживать стабильно на высокой скорости.",
                "Начинайте в виламбите. Только когда уверены — переходите к мадхьяму.",
            ],
            category: .korvai
        ),

        // MORA
        Exercise(
            name: "Простая мора",
            description: "Та-Ка-Та-Ки-Та-Дхин-Гина (7) × 3 = 21. Начать на доле 12 Ади (2 авартанама = 32 доли).",
            durationMinutes: 8,
            difficulty: 3,
            syllableSequence: "Та-Ка-Та-Ки-Та-Дхин-Ги-На · ×3",
            steps: [
                "Мора (в отличие от корваи) исполняется без зазоров: три повторения подряд.",
                "Паттерн: 7 слогов × 3 = 21. В двух авартанамах (32 доли) мора начинается на доле 12.",
                "Расчёт: 32 − 21 = 11. Значит, первые 11 долей — музыкальный материал до моры.",
                "Считайте первые 11 долей. На 12-й начинайте: Та-Ка-Та-Ки-Та-Дхин-Ги-На.",
                "Без паузы: Та-Ка-Та-Ки-Та-Дхин-Ги-На · Та-Ка-Та-Ки-Та-Дхин-Ги-На · ...",
                "Финальная «На» приходится точно на сам третьего авартанама.",
            ],
            category: .mora
        ),
        Exercise(
            name: "Мора в тисра надаи",
            description: "Та-Ки-Та (3) × 3 = 9. Вписать в Ади (8 долей): пересечение тала и моры.",
            durationMinutes: 10,
            difficulty: 4,
            syllableSequence: "Та-Ки-Та · Та-Ки-Та · Та-Ки-Та",
            steps: [
                "В тисра-надаи каждая доля делится на 3 подразделения. 8 долей × 3 = 24 подразделения.",
                "Паттерн моры: Та-Ки-Та = 3 слога. Три повторения = 9 слогов = 9 подразделений.",
                "Мора не укладывается ровно в 24: нужны 8 долей для начала + 1 авартанам моры.",
                "Цель: почувствовать, как тисра-мора «перетекает» через тактовые черты.",
                "Начните медленно: один хлопок на каждое подразделение, пока не ощутите позицию.",
                "Продвинутый вариант: одновременно хлопайте Ади талу и произносите мору.",
            ],
            category: .mora
        ),

        // POLYRHYTHM
        Exercise(
            name: "3 против 4",
            description: "Произносить Та-Ки-Та (3) в чатусра надаи (4). Мысленно держать оба ритма.",
            durationMinutes: 15,
            difficulty: 4,
            syllableSequence: "Та-Ки-Та  (на фоне × 4)",
            steps: [
                "Шаг 1: хлопайте чатусра пульс (4) равномерно, пока он не станет автоматическим.",
                "Шаг 2: произносите Та-Ки-Та (3) сначала отдельно от хлопков — запомните ощущение.",
                "Шаг 3: соедините. Хлопок остаётся на 4, речь — на 3. Они совпадут каждые 12 долей.",
                "Не пытайтесь синхронизировать — задача в том, чтобы слышать оба ритма одновременно.",
                "Точка соединения (сам): в 12 долях хлопков = 3 группы × 4 = 4 группы × 3.",
                "Практикуйте сначала 2 минуты, затем 5 минут без остановки.",
            ],
            category: .polyrhythm
        ),
        Exercise(
            name: "5 против 4",
            description: "Та-Ка-Та-Ки-Та (5) в чатусра надаи. Попадание на сам каждые 20 долей.",
            durationMinutes: 15,
            difficulty: 5,
            syllableSequence: "Та-Ка-Та-Ки-Та  (на фоне × 4)",
            steps: [
                "Шаг 1: установите метроном на 60 уд/мин. Каждый удар = одна доля пульса (4).",
                "Шаг 2: произносите Та-Ка-Та-Ки-Та в равномерном темпе — 5 слогов за 5 долей.",
                "Шаг 3: сохраняйте хлопки на 4, а произношение — на 5. НКО (наим. кратное) = 20.",
                "Совпадение (сам): каждые 20 долей хлопков и 4 повторения Та-Ка-Та-Ки-Та.",
                "Мысленная карта: на доле 1, 5, 9, 13, 17 начинается новый кханда-паттерн.",
                "Цель: почувствовать «скольжение» кханда по чатусра без потери ни одного ритма.",
            ],
            category: .polyrhythm
        ),
    ]
}

// MARK: - ExercisesView

struct ExercisesView: View {

    @State private var selectedExercise: Exercise?

    private var warmupExercises: [Exercise] {
        ExerciseLibrary.all.filter { $0.category == .warmup }
    }

    private var groupedExercises: [(Exercise.Category, [Exercise])] {
        let categories: [Exercise.Category] = [.jathi, .korvai, .mora, .polyrhythm]
        return categories.compactMap { cat in
            let items = ExerciseLibrary.all.filter { $0.category == cat }
            return items.isEmpty ? nil : (cat, items)
        }
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: KMSpacing.sectionGap) {
                    header
                    warmupSection
                    ForEach(groupedExercises, id: \.0) { category, exercises in
                        exerciseSection(category: category, exercises: exercises)
                    }
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt12)
                .padding(.bottom, KMSpacing.pt48)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
            .sheet(item: $selectedExercise) { exercise in
                ExerciseDetailView(exercise: exercise)
                    .presentationDragIndicator(.visible)
                    .presentationDetents([.large])
            }
        }
    }

    // MARK: Header

    private var header: some View {
        HStack(alignment: .bottom) {
            VStack(alignment: .leading, spacing: 4) {
                Text(dateSubtitle)
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 1.5)
                    .kmTrackingMicro()
                Text("Упражнения")
                    .kmStyle(KMFont.title1)
                Text("Ежедневная практика")
                    .kmStyle(KMFont.caption, color: .kmTextSecondary)
            }
            Spacer()
            Image(systemName: "figure.run")
                .foregroundStyle(Color.kmPrimary)
                .font(.system(size: 22))
        }
    }

    private var dateSubtitle: String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "ru_RU")
        formatter.dateFormat = "d MMMM · EEEE"
        return formatter.string(from: Date()).uppercased()
    }

    // MARK: Warmup Section

    private var warmupSection: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            categoryHeader(category: .warmup)
            VStack(spacing: KMSpacing.pt8) {
                ForEach(warmupExercises) { exercise in
                    ExerciseCard(exercise: exercise) {
                        selectedExercise = exercise
                    }
                }
            }
        }
    }

    // MARK: Generic Section

    private func exerciseSection(category: Exercise.Category, exercises: [Exercise]) -> some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            categoryHeader(category: category)
            VStack(spacing: KMSpacing.pt8) {
                ForEach(exercises) { exercise in
                    ExerciseCard(exercise: exercise) {
                        selectedExercise = exercise
                    }
                }
            }
        }
    }

    // MARK: Category Header

    private func categoryHeader(category: Exercise.Category) -> some View {
        HStack(spacing: KMSpacing.pt8) {
            Image(systemName: category.icon)
                .foregroundStyle(Color.kmPrimary)
                .font(.system(size: 12, weight: .semibold))

            Text(category.rawValue.uppercased())
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()
        }
    }
}

// MARK: - Exercise Card

private struct ExerciseCard: View {
    let exercise: Exercise
    let onTap: () -> Void

    var body: some View {
        Button(action: {
            HapticEngine.impact(.light)
            onTap()
        }) {
            KMCard(elevation: .standard) {
                HStack(alignment: .top, spacing: KMSpacing.pt12) {
                    VStack(alignment: .leading, spacing: KMSpacing.pt8) {
                        // Name + duration badge
                        HStack(alignment: .center, spacing: KMSpacing.pt8) {
                            Text(exercise.name)
                                .kmStyle(KMFont.body)
                                .frame(maxWidth: .infinity, alignment: .leading)

                            durationBadge
                        }

                        // Description
                        Text(exercise.description)
                            .kmStyle(KMFont.caption, color: .kmTextSecondary)
                            .lineSpacing(3)
                            .fixedSize(horizontal: false, vertical: true)

                        // Difficulty dots
                        HStack(spacing: 4) {
                            ForEach(1...5, id: \.self) { dot in
                                Circle()
                                    .fill(dot <= exercise.difficulty ? Color.kmPrimary : Color.kmSurface2)
                                    .frame(width: 6, height: 6)
                            }
                        }
                    }

                    Image(systemName: "chevron.right")
                        .foregroundStyle(Color.kmTextTertiary)
                        .font(.system(size: 12, weight: .medium))
                        .padding(.top, 2)
                }
            }
        }
        .buttonStyle(.plain)
    }

    private var durationBadge: some View {
        HStack(spacing: 4) {
            Image(systemName: "clock")
                .font(.system(size: 10, weight: .medium))
                .foregroundStyle(Color.kmTextTertiary)
            Text("\(exercise.durationMinutes) мин")
                .kmStyle(KMFont.micro, color: .kmTextTertiary)
        }
        .padding(.horizontal, KMSpacing.pt8)
        .padding(.vertical, 4)
        .background(Color.kmSurface2)
        .clipShape(Capsule())
    }
}

// MARK: - Exercise Detail View

struct ExerciseDetailView: View {
    let exercise: Exercise
    @Environment(\.dismiss) private var dismiss
    @State private var didTapStart = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: KMSpacing.sectionGap) {
                    // Meta header
                    metaHeader

                    // Syllable sequence (if available)
                    if let sequence = exercise.syllableSequence {
                        syllableSequenceCard(sequence)
                    }

                    // Step-by-step instructions
                    stepsSection

                    // Practice note
                    if didTapStart {
                        practiceNoteCard
                    }

                    // CTA
                    KMButton(didTapStart ? "Закрыть" : "Начать практику") {
                        if didTapStart {
                            dismiss()
                        } else {
                            withAnimation(.kmQuick) { didTapStart = true }
                            HapticEngine.notification(.success)
                        }
                    }
                }
                .padding(.horizontal, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt24)
                .padding(.bottom, KMSpacing.pt48)
            }
            .background(Color.kmBase.ignoresSafeArea())
            .navigationBarHidden(true)
            .overlay(alignment: .topTrailing) {
                Button {
                    dismiss()
                } label: {
                    Image(systemName: "xmark")
                        .foregroundStyle(Color.kmTextSecondary)
                        .font(.system(size: 14, weight: .semibold))
                        .frame(width: 30, height: 30)
                        .background(Color.kmSurface2)
                        .clipShape(Circle())
                }
                .padding(.trailing, KMSpacing.screenEdge)
                .padding(.top, KMSpacing.pt16)
            }
        }
    }

    // MARK: Meta Header

    private var metaHeader: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            HStack(spacing: KMSpacing.pt8) {
                Image(systemName: exercise.category.icon)
                    .foregroundStyle(Color.kmPrimary)
                    .font(.system(size: 12, weight: .semibold))
                Text(exercise.category.rawValue.uppercased())
                    .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                    .kmTrackingMicro()
            }

            Text(exercise.name)
                .kmStyle(KMFont.title1)

            Text(exercise.description)
                .kmStyle(KMFont.bodyLarge, color: .kmTextSecondary)
                .lineSpacing(4)

            HStack(spacing: KMSpacing.pt12) {
                // Duration
                HStack(spacing: 6) {
                    Image(systemName: "clock.fill")
                        .foregroundStyle(Color.kmPrimary)
                        .font(.system(size: 13))
                    Text("\(exercise.durationMinutes) минут")
                        .kmStyle(KMFont.caption)
                }
                .padding(.horizontal, KMSpacing.pt12)
                .padding(.vertical, KMSpacing.pt8)
                .background(Color.kmSurface1)
                .clipShape(Capsule())

                // Difficulty
                HStack(spacing: 6) {
                    ForEach(1...5, id: \.self) { dot in
                        Circle()
                            .fill(dot <= exercise.difficulty ? Color.kmPrimary : Color.kmSurface2)
                            .frame(width: 7, height: 7)
                    }
                    Text(difficultyLabel)
                        .kmStyle(KMFont.caption, color: .kmTextSecondary)
                }
                .padding(.horizontal, KMSpacing.pt12)
                .padding(.vertical, KMSpacing.pt8)
                .background(Color.kmSurface1)
                .clipShape(Capsule())
            }
        }
    }

    private var difficultyLabel: String {
        switch exercise.difficulty {
        case 1: return "Начало"
        case 2: return "Лёгкое"
        case 3: return "Среднее"
        case 4: return "Сложное"
        default: return "Эксперт"
        }
    }

    // MARK: Syllable Sequence

    private func syllableSequenceCard(_ sequence: String) -> some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt8) {
            Text("СЛОГОВАЯ ФОРМУЛА")
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()

            KMCard(elevation: .elevated) {
                Text(sequence)
                    .font(.system(size: 15, weight: .medium, design: .monospaced))
                    .foregroundStyle(Color.kmPrimary)
                    .lineSpacing(6)
                    .fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }

    // MARK: Steps

    private var stepsSection: some View {
        VStack(alignment: .leading, spacing: KMSpacing.pt12) {
            Text("ИНСТРУКЦИЯ")
                .kmStyle(KMFont.micro, color: .kmTextTertiary, tracking: 2)
                .kmTrackingMicro()

            VStack(spacing: KMSpacing.pt8) {
                ForEach(Array(exercise.steps.enumerated()), id: \.offset) { idx, step in
                    KMCard(elevation: .standard) {
                        HStack(alignment: .top, spacing: KMSpacing.pt12) {
                            // Step number
                            Text("\(idx + 1)")
                                .font(.system(size: 13, weight: .bold, design: .monospaced))
                                .foregroundStyle(Color.kmPrimary)
                                .frame(width: 26, height: 26)
                                .background(Color.kmSurface2)
                                .clipShape(Circle())

                            Text(step)
                                .kmStyle(KMFont.caption, color: .kmTextSecondary)
                                .lineSpacing(4)
                                .fixedSize(horizontal: false, vertical: true)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    }
                }
            }
        }
    }

    // MARK: Practice Note

    private var practiceNoteCard: some View {
        KMCard(elevation: .elevated) {
            HStack(alignment: .top, spacing: KMSpacing.pt12) {
                Image(systemName: "lightbulb.fill")
                    .foregroundStyle(Color.kmPrimary)
                    .font(.system(size: 16))

                VStack(alignment: .leading, spacing: 4) {
                    Text("Переключитесь на вкладку «Практика»")
                        .kmStyle(KMFont.body)
                    Text("Используйте метроном и тала-колесо в разделе Практика, чтобы выполнять упражнения с точной ритмической опорой.")
                        .kmStyle(KMFont.caption, color: .kmTextSecondary)
                        .lineSpacing(3)
                }
            }
        }
    }
}

// MARK: - Preview

#Preview("Exercises") {
    ExercisesView()
}

#Preview("Detail") {
    ExerciseDetailView(exercise: ExerciseLibrary.all[0])
}
