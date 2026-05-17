import Foundation

// MARK: - LessonBuilder
//
// Constructs Lesson domain objects for all 16 levels.
// Each level is written as a genuine pedagogical unit — not a stub.
// Russian titles and intros reflect the app's primary language.

enum LessonBuilder {

    static func build(forLevel level: Int) -> Lesson {
        switch level {
        case 1:  return level1()
        case 2:  return level2()
        case 3:  return level3()
        case 4:  return level4()
        case 5:  return level5()
        case 6:  return level6()
        case 7:  return level7()
        case 8:  return level8()
        case 9:  return level9()
        case 10: return level10()
        case 11: return level11()
        case 12: return level12()
        case 13: return level13()
        case 14: return level14()
        case 15: return level15()
        case 16: return level16()
        default: return stub(level: level)
        }
    }

    // MARK: - Level 1: Первый голос

    private static func level1() -> Lesson {
        Lesson(
            id: "level-1",
            levelNumber: 1,
            title: "Первый голос",
            subtitle: "Базовые слоги без метронома",
            description: "Коннакол — это язык ритма. Прежде чем считать, нужно говорить. Изучите три фундаментальные слоговые группы и почувствуйте их звучание.",
            estimatedMinutes: 8,
            steps: [
                .intro(
                    text: "Коннакол — это система устной передачи ритма, существующая более двух тысяч лет. В западной музыке ритм записывают нотами. В карнатической традиции его произносят вслух — слогами, которые сами по себе несут в себе тайминг, ударение и форму.\n\nПочему слоги важнее нот? Потому что рот и ухо учатся вместе. Когда вы произносите «Та-Ки-Та», ваше тело запоминает ровно три шага. Когда вы читаете «три», — это только число. Слог живёт в мышцах.\n\nСегодня мы изучим три группы: Та-Ка (2 слога), Та-Ки-Та (3 слога), Та-Ка-Ди-Ми (4 слога). Метронома пока нет. Ваша задача — добиться ровности и ясности каждого слога.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-2", repetitions: 4),
                .speak(
                    syllableGroupId: "jathi-2",
                    targetCount: 16,
                    instruction: "Та-Ка — два ровных слога, как шаги. Произнесите 8 раз подряд, не торопясь. Оба слога должны быть одинаковой длины."
                ),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .speak(
                    syllableGroupId: "jathi-3",
                    targetCount: 24,
                    instruction: "Та-Ки-Та — триоль. Средний слог «Ки» легко сжать. Следите, чтобы все три были равномерными. Повторите 8 раз."
                ),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .speak(
                    syllableGroupId: "jathi-4",
                    targetCount: 32,
                    instruction: "Та-Ка-Ди-Ми — самая важная группа в коннаколе. Четыре слога, ровно, уверенно. «Ди» и «Ми» в конце — не глотайте их. Повторите 8 раз."
                ),
                .check(
                    summary: "Вы познакомились с тремя основными джати: двойкой, тройкой и четвёркой. Помните: ровность важнее скорости. На следующем уроке появятся все пять групп."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.75, maxAvgDriftMs: 80),
            unlockCriteria: UnlockCriteria(kind: .first, requiredLessonId: nil)
        )
    }

    // MARK: - Level 2: Пять джати

    private static func level2() -> Lesson {
        Lesson(
            id: "level-2",
            levelNumber: 2,
            title: "Пять джати",
            subtitle: "Освоение всех пяти групп",
            description: "Джати — это слоговые группы от 3 до 9. Каждая группа имеет собственный характер и звучание. Сегодня вы освоите все пять.",
            estimatedMinutes: 10,
            steps: [
                .intro(
                    text: "В коннаколе существует пять фундаментальных слоговых групп — джати. Они образуют основу всего ритмического языка:\n\n• Тисра (3): Та-Ки-Та\n• Чатусра (4): Та-Ка-Ди-Ми\n• Кханда (5): Та-Ка-Та-Ки-На\n• Мисра (7): Та-Ки-Та-Та-Ка-Ди-Ми\n• Санкирна (9): Та-Ка-Та-Ки-На-Та-Ки-Та\n\nОбратите особое внимание на кханду (5) и мисру (7) — они нестандартные для европейского уха. Пять слогов имеют особое «плавающее» ощущение. Семь — ощущение волны с подъёмом в конце.\n\nСегодня слушайте каждую группу перед тем, как произносить.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-3", repetitions: 3),
                .speak(
                    syllableGroupId: "jathi-3",
                    targetCount: 18,
                    instruction: "Та-Ки-Та, 6 раз. Ровно, как капли метронома."
                ),
                .listen(syllableGroupId: "jathi-4", repetitions: 3),
                .speak(
                    syllableGroupId: "jathi-4",
                    targetCount: 24,
                    instruction: "Та-Ка-Ди-Ми, 6 раз. Помните прошлый урок — «Ди-Ми» не проглатывать."
                ),
                .listen(syllableGroupId: "jathi-5", repetitions: 4),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 30,
                    instruction: "Та-Ка-Та-Ки-На — кханда. Пять слогов. Не торопитесь на «Ки-На» в конце. Произнесите 6 раз."
                ),
                .listen(syllableGroupId: "jathi-7", repetitions: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 42,
                    instruction: "Та-Ки-Та-Та-Ка-Ди-Ми — мисра. Семь слогов. Это как «тройка + четвёрка» слитые вместе. Почувствуйте акцент в начале. Произнесите 6 раз."
                ),
                .listen(syllableGroupId: "jathi-9", repetitions: 3),
                .speak(
                    syllableGroupId: "jathi-9",
                    targetCount: 27,
                    instruction: "Та-Ка-Та-Ки-На-Та-Ки-Та — санкирна. Девять слогов. Это «пятёрка + тройка». Медленно. Произнесите 3 раза."
                ),
                .check(
                    summary: "Теперь вы знаете все пять джати. Запомните: каждая группа — отдельная «единица мысли». Вы не считаете слоги — вы произносите имя. Следующий урок: ади тала."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.75, maxAvgDriftMs: 75),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-1")
        )
    }

    // MARK: - Level 3: Ади тала

    private static func level3() -> Lesson {
        Lesson(
            id: "level-3",
            levelNumber: 3,
            title: "Ади тала",
            subtitle: "Первый ритмический цикл — 8 долей",
            description: "Тала — это повторяющийся цикл. Ади тала — 8 долей, и это основа карнатической музыки. Сегодня вы научитесь хлопать её и говорить внутри.",
            estimatedMinutes: 12,
            steps: [
                .intro(
                    text: "Тала — это структура времени. Представьте тактовую черту, которая возвращается снова и снова. Каждый возврат к началу цикла называется авартанам, а сама точка возврата — сам.\n\nАди тала — восемь долей. Структура:\n• Доли 1–4: лагху (один хлопок + три касания пальцами)\n• Доли 5–6: друтам (хлопок + волна)\n• Доли 7–8: друтам (хлопок + волна)\n\nСам — это доля 1. Это момент «возвращения домой». В карнатической музыке всё — мелодия, ритм, импровизация — рассчитывается так, чтобы разрешиться на самé.\n\nСначала просто хлопайте. Потом добавим слоги.",
                    videoURL: nil
                ),
                .clap(talaId: "adi", nadai: .chatusra, cycles: 4),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-4",
                    targetCount: 32,
                    instruction: "Произносите Та-Ка-Ди-Ми, чувствуя границу такта. Первый слог каждых восьми — это сам. Попробуйте выделить его голосом."
                ),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .check(
                    summary: "Вы прошли первый ритмический цикл. Ади тала — основа половины всей карнатической музыки. Чувствуете, как цикл «замыкается»? Это сам. Это будет вашей точкой опоры на все следующие уроки."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-2")
        )
    }

    // MARK: - Level 4: Трикалам

    private static func level4() -> Lesson {
        Lesson(
            id: "level-4",
            levelNumber: 4,
            title: "Трикалам",
            subtitle: "Три скорости — один пульс",
            description: "Трикалам — это одна и та же фраза в трёх плотностях: ×1, ×2, ×4. Метроном не ускоряется. Меняется только количество слогов на долю.",
            estimatedMinutes: 13,
            steps: [
                .intro(
                    text: "Трикалам означает «три скорости». Это не ускорение темпа — это увеличение плотности слогов внутри того же пульса.\n\n• Калам 1 (пратхама): один слог на долю — Та\n• Калам 2 (двития): два слога на долю — Та-Ка\n• Калам 4 (чатурта): четыре слога на долю — Та-Ка-Ди-Ми\n\nВ живом исполнении музыкант переходит от одной скорости к другой без остановки. Пульс — как якорь: он не двигается. Меняется то, что внутри.\n\nЭто первый настоящий тест вашего внутреннего метронома. Если вы «поплыли» при смене скорости — значит, держитесь за плотность, а не за пульс. Слушайте долю, а не слоги.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-2", repetitions: 4),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-2", cycles: 2),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-4",
                    targetCount: 32,
                    instruction: "Произнесите трикалам вслух: сначала Та-Ка (8 раз), затем без паузы Та-Ка-Ди-Ми (8 раз). Доли не меняются — только плотность."
                ),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Трикалам — это разговор между плотностью и пульсом. Если метроном пропал и вы удержали темп — ваш внутренний часовой работает. Следующий шаг: другая тала."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 65),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-3")
        )
    }

    // MARK: - Level 5: Рупака тала

    private static func level5() -> Lesson {
        Lesson(
            id: "level-5",
            levelNumber: 5,
            title: "Рупака тала",
            subtitle: "Шестидольный цикл",
            description: "Рупака тала — 6 долей: 2 + 4. Первая доля — не хлопок, а волна. Это меняет всё ощущение от цикла.",
            estimatedMinutes: 12,
            steps: [
                .intro(
                    text: "Рупака тала состоит из шести долей. Структура:\n• Доли 1–2: друтам (хлопок + волна)\n• Доли 3–6: лагху (хлопок + три пальца)\n\nКлючевое отличие от ади талы: сам здесь — это первая доля друтама, которая начинается с хлопка-и-волны. Ощущение «возвращения» другое — оно мягче, как вдох перед новым шагом.\n\nЧтобы считать: 1-2-3-4-5-6 становится «хлоп-волна-хлоп-2-3-4». Отрабатывайте хлопки до того, как добавите слоги — руки должны быть уверенными.",
                    videoURL: nil
                ),
                .clap(talaId: "rupaka", nadai: .chatusra, cycles: 4),
                .listen(syllableGroupId: "jathi-4", repetitions: 4),
                .loop(talaId: "rupaka", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-4",
                    targetCount: 24,
                    instruction: "Говорите Та-Ка-Ди-Ми внутри рупаки, продолжая хлопать. Шесть долей — это три раза по Та-Ка плюс начало следующей группы. Почувствуйте, где начинается новый цикл."
                ),
                .mute(talaId: "rupaka", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Рупака — ваша вторая тала. Обратите внимание: цикл из 6 долей воспринимается иначе, чем 8. Он короче и «острее» — сам появляется быстрее. Следующий урок: нечётные метры."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-4")
        )
    }

    // MARK: - Level 6: Чапу талы

    private static func level6() -> Lesson {
        Lesson(
            id: "level-6",
            levelNumber: 6,
            title: "Чапу талы",
            subtitle: "Мисра (7) и Кханда (5) Чапу",
            description: "Чапу талы — это асимметричные циклы. Мисра чапу — 7 долей (3+4), кханда чапу — 5 долей (2+3). Они звучат как «хромающий» шаг — и в этом их красота.",
            estimatedMinutes: 14,
            steps: [
                .intro(
                    text: "Чапу — особый класс тала. В отличие от «классических» тала, чапу нечётны и не разбиты на равные части. Это делает их самыми «народными» и «земными» из всех тала.\n\nМисра чапу: 7 долей = 3 + 4. Структура: короткая единица (3) + длинная единица (4). Счёт: «раз-два-три / раз-два-три-четыре».\n\nКханда чапу: 5 долей = 2 + 3. Структура: короткая (2) + длинная (3). Счёт: «раз-два / раз-два-три».\n\nОщущение у обеих — качание, «лимп» (limp). Не пытайтесь выровнять их — асимметрия и есть характер чапу.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-7", repetitions: 4),
                .clap(talaId: "misra-chapu", nadai: .chatusra, cycles: 4),
                .loop(talaId: "misra-chapu", nadai: .chatusra, syllableGroupId: "jathi-7", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 28,
                    instruction: "Та-Ки-Та-Та-Ка-Ди-Ми в мисра чапу. Четыре раза. Чувствуете, как семь долей делятся 3+4? Акцент на первом слоге каждой группы."
                ),
                .listen(syllableGroupId: "jathi-5", repetitions: 4),
                .clap(talaId: "khanda-chapu", nadai: .chatusra, cycles: 4),
                .loop(talaId: "khanda-chapu", nadai: .chatusra, syllableGroupId: "jathi-5", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 20,
                    instruction: "Та-Ка-Та-Ки-На в кханда чапу. Четыре раза. Пять долей — 2+3. Короткая-длинная. Почувствуйте «спотыкание» на стыке."
                ),
                .check(
                    summary: "Чапу талы — сердце народной и кинематографической музыки Южной Индии. Их асимметрия — не дефект, а суть. Если вы можете качаться в 7 и 5, ваше ритмическое ухо расширилось."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.77, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-5")
        )
    }

    // MARK: - Level 7: Надаи — Тисра

    private static func level7() -> Lesson {
        Lesson(
            id: "level-7",
            levelNumber: 7,
            title: "Надаи — Тисра",
            subtitle: "Трёхдольное подразделение внутри талы",
            description: "Надаи — это сетка внутри каждой доли талы. Тисра надаи означает три подразделения на каждую долю. Это другое ощущение от той же ади талы.",
            estimatedMinutes: 12,
            steps: [
                .intro(
                    text: "До сих пор вы работали с чатусра надаи — четыре подразделения на долю (Та-Ка-Ди-Ми). Это «стандартная сетка».\n\nНадаи меняет эту сетку, не меняя тала. В тисра надаи каждая доля делится на три: Та-Ки-Та. Тала остаётся той же — например, ади тала с её восемью долями. Но внутри каждой доли теперь три пульса вместо четырёх.\n\nРезультат: в одном авартанаме ади талы (8 долей × 3) = 24 слога вместо 32.\n\nОщущение меняется. Цикл становится «мягче», «тягучее». Слушайте внимательно перед тем, как говорить.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-3", repetitions: 6),
                .loop(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-3",
                    targetCount: 24,
                    instruction: "Та-Ки-Та в тисра надаи внутри ади талы. Восемь хлопков — и на каждый приходится ровно три слога. Почувствуйте разницу с четвёркой."
                ),
                .mute(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Тисра надаи — ваш первый шаг в мир подразделений. Обратите внимание: восемь долей ади талы остались теми же. Изменилось то, что «внутри». Следующий урок: пять внутри каждой доли."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 65),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-6")
        )
    }

    // MARK: - Level 8: Надаи — Кханда

    private static func level8() -> Lesson {
        Lesson(
            id: "level-8",
            levelNumber: 8,
            title: "Надаи — Кханда",
            subtitle: "Пятидольное подразделение",
            description: "Кханда надаи: пять слогов на каждую долю талы. Это «плавающее» ощущение — пять не делится на два, поэтому акценты всё время смещаются.",
            estimatedMinutes: 13,
            steps: [
                .intro(
                    text: "Кханда надаи — пять подразделений на долю: Та-Ка-Та-Ки-На. В ади тале (8 долей × 5 = 40 слогов на авартанам) это создаёт особое «плавающее» ощущение.\n\nПочему оно плавающее? Потому что 5 не делится на 2. В чатусра (4) и тисра (3) акценты предсказуемы. В кханда (5) каждый раз кажется, что ритм «скользит» — а на самом деле просто нет привычных кратных двойке опорных точек.\n\nСовет: не пытайтесь «посчитать» пятёрку. Произносите «Та-Ка-Та-Ки-На» как одно слово. Пусть рот знает его, а не разум.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-5", repetitions: 6),
                .loop(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 40,
                    instruction: "Та-Ка-Та-Ки-На, 8 раз подряд. Все пять слогов — одинаковой длины. Не торопитесь на «Ки-На» в конце."
                ),
                .mute(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Кханда надаи освоена. Пять — самое «странное» подразделение для европейского уха. Если вы дошли до конца без потери пульса — поздравляем. Следующий урок: первая корваи."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.77, maxAvgDriftMs: 65),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-7")
        )
    }

    // MARK: - Level 9: Первая корваи

    private static func level9() -> Lesson {
        Lesson(
            id: "level-9",
            levelNumber: 9,
            title: "Первая корваи",
            subtitle: "Ритмическая каденция",
            description: "Корваи — это ритмическая фраза, повторяющаяся три раза, которая точно попадает на сам. Это «предложение» в языке ритма.",
            estimatedMinutes: 14,
            steps: [
                .intro(
                    text: "Корваи (korvai) — один из важнейших понятий карнатического ритма. Это каденция: фраза повторяется ровно три раза, и конец третьего повтора попадает точно на сам — начало нового авартанама.\n\nФормула: (длина фразы + промежуток) × 3 = кратно количеству долей в цикле.\n\nПростой пример. Возьмём Та-Ка-Та-Ки-На (5 слогов) в ади тале (8 долей × чатусра = 32 пульса на авартанам). Пять слогов три раза = 15 слогов. Добавим промежуток из 1 слога: (5+1) × 3 = 18 слогов. Не делится ровно. Пробуем без промежутка, или берём другое соотношение.\n\nСегодня — самый простой вариант: просто произносите Та-Ка-Та-Ки-На три раза подряд в такт. Слушайте, попадаете ли вы на сам после третьего.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-5", repetitions: 6),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-5", cycles: 3),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 15,
                    instruction: "Произнесите Та-Ка-Та-Ки-На ровно три раза подряд — без остановки. После третьего «На» должен быть сам. Повторите всю конструкцию несколько раз подряд."
                ),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-5", cycles: 6),
                .check(
                    summary: "Вы сделали первую корваи. Это ещё простая форма, но механизм тот же: фраза × 3 → сам. В следующих уроках мы усложним структуру. Но суть не изменится: всё разрешается на единице."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 60),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-8")
        )
    }

    // MARK: - Level 10: Мора

    private static func level10() -> Lesson {
        Lesson(
            id: "level-10",
            levelNumber: 10,
            title: "Мора",
            subtitle: "Тройное разрешение без промежутков",
            description: "Мора — это фраза без пауз между повторениями, которая попадает на сам. В отличие от корваи, здесь нет «зазоров» — чистый поток, три раза.",
            estimatedMinutes: 14,
            steps: [
                .intro(
                    text: "Мора (mora) — это конкретный тип ритмической каденции. Фраза повторяется три раза подряд без пауз, и конец третьего повтора точно совпадает с самом.\n\nОтличие от корваи: в корваи могут быть промежутки (gaps) между повторениями. В море — только непрерывный поток.\n\nКлассический пример моры в ади тале:\nТа-Ки-Та-Та-Ка-Ди-Ми (7 слогов) × 3 = 21 слог. Ади тала в чатусра надаи = 32 пульса. 21 не делится на 32. Значит, мора занимает больше одного авартанама.\n\nНо сегодня мы работаем с семью слогами как потоком. Ваша задача: произнести мору три раза — и почувствовать, куда она ведёт.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-7", repetitions: 6),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 21,
                    instruction: "Та-Ки-Та-Та-Ка-Ди-Ми три раза подряд — без паузы, без остановки. Это ваша мора. Почувствуйте её как единое высказывание из 21 слога."
                ),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 6),
                .mute(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Мора — это «предложение из трёх слов», где каждое слово одинаково. Чувствуете нарастание к третьему? Это напряжение — и его разрешение на самé — электрическое ощущение коннакола."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.78, maxAvgDriftMs: 60),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-9")
        )
    }

    // MARK: - Level 11: Мисра надаи

    private static func level11() -> Lesson {
        Lesson(
            id: "level-11",
            levelNumber: 11,
            title: "Мисра надаи",
            subtitle: "Семидольное подразделение",
            description: "Мисра надаи — семь подразделений на каждую долю. Это продвинутый уровень: семёрка нечётна и не делится ни на 2, ни на 3. Ади тала × 7 = 56 слогов на авартанам.",
            estimatedMinutes: 15,
            steps: [
                .intro(
                    text: "Мисра надаи — семь подразделений на долю: Та-Ки-Та-Та-Ка-Ди-Ми. В ади тале (8 долей) это 8 × 7 = 56 слогов на авартанам.\n\n56 — это большое число. Но обращайте внимание не на количество, а на ощущение: семёрка внутри каждой доли делает всю структуру «тягучей», почти как slowmo-версия мисра чапу.\n\nТехника: не считайте «один-два-три-четыре-пять-шесть-семь». Произносите «Та-Ки-Та-Та-Ка-Ди-Ми» как одно слово. Рот знает его форму. Доверьтесь форме.\n\nЭто один из самых сложных надаи. Не спешите — точность важнее скорости.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-7", repetitions: 6),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 56,
                    instruction: "Та-Ки-Та-Та-Ка-Ди-Ми, 8 раз подряд без остановки. Каждая группа — ровно одна доля ади талы. Медленно и уверенно."
                ),
                .mute(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Мисра надаи освоена. Вы теперь можете говорить в 3, 4, 5 и 7 подразделений. Остался последний — санкирна (9). До него — освоим сложные корваи с промежутками."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.77, maxAvgDriftMs: 60),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-10")
        )
    }

    // MARK: - Level 12: Корваи с промежутками

    private static func level12() -> Lesson {
        Lesson(
            id: "level-12",
            levelNumber: 12,
            title: "Корваи с промежутками",
            subtitle: "Расчёт гэпов и сложные каденции",
            description: "Промежуток (gap) между повторами фразы в корваи — это не пустота, а точно рассчитанная пауза или фраза-заполнитель. Сегодня мы считаем гэпы.",
            estimatedMinutes: 16,
            steps: [
                .intro(
                    text: "Корваи с промежутками — следующий уровень мастерства. Формула:\n\n(длина фразы + длина промежутка) × 3 = полное число слогов корваи\n\nПолное число слогов должно быть кратно длине авартанама (или нескольких авартанамов).\n\nПример: фраза из 7 слогов (мисра) в ади тале с чатусра надаи (32 пульса).\n(7 + 1) × 3 = 24 слога — не кратно 32.\n(7 + 3) × 3 = 30 слогов — не кратно 32.\n(7 + 4) × 3 = 33 слога — не кратно 32.\nНо (7 + 0) × 3 = 21 слог — это три авартанама минус 11 слогов. Не совпадает.\n\nНа самом деле кратность ищется на нескольких авартанамах. Это математика коннакола — она сложная, но вознаграждающая. Сегодня мы практикуем на готовых примерах.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-7", repetitions: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-7", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 21,
                    instruction: "Мора из мисра-джати: произнесите Та-Ки-Та-Та-Ка-Ди-Ми три раза — без промежутков. Это базовая форма. Запомните её ощущение."
                ),
                .loop(talaId: "adi", nadai: .misra, syllableGroupId: "jathi-7", cycles: 6),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 15,
                    instruction: "Теперь корваи с промежутком: Та-Ка-Та-Ки-На [пауза 2] × 3. Произнесите пять слогов, выдержите два пульса тишины, повторите три раза. Конец третьего попадает на сам."
                ),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-5", normalCycles: 3, muteCycles: 2),
                .check(
                    summary: "Промежуток — не пустота, а пространство, рассчитанное математически. Мастера коннакола строят каденции в голове, как шахматисты строят партии. Вы сделали первый шаг в эту сторону."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.77, maxAvgDriftMs: 55),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-11")
        )
    }

    // MARK: - Level 13: Санкирна надаи

    private static func level13() -> Lesson {
        Lesson(
            id: "level-13",
            levelNumber: 13,
            title: "Санкирна надаи",
            subtitle: "Девятидольное подразделение",
            description: "Санкирна надаи — девять подразделений на долю. Это самое сложное и редкое подразделение. Ади тала × 9 = 72 слога на авартанам.",
            estimatedMinutes: 16,
            steps: [
                .intro(
                    text: "Санкирна надаи — девять подразделений на каждую долю: Та-Ка-Та-Ки-На-Та-Ки-Та. Это «пятёрка + тройка» слитые вместе.\n\nВ ади тале (8 долей) получается 8 × 9 = 72 слога за один авартанам. Это очень плотная ткань — почти непрерывный поток слогов.\n\nСанкирна редко используется в чистом виде в классической музыке, но она венчает пятёрку надаи. Освоив её, вы завершаете «пять скоростей» — панча надаи.\n\nПодход: санкирна — это «Та-Ка-Та-Ки-На» плюс «Та-Ки-Та». Объедините их как одно девятисложное слово. Медленно. Очень медленно. Не спешите.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-9", repetitions: 6),
                .speak(
                    syllableGroupId: "jathi-9",
                    targetCount: 18,
                    instruction: "Та-Ка-Та-Ки-На-Та-Ки-Та — произнесите дважды медленно, потом ещё раз. Пусть рот запомнит всё слово целиком, а не девять отдельных слогов."
                ),
                .loop(talaId: "adi", nadai: .sankeerna, syllableGroupId: "jathi-9", cycles: 4),
                .mute(talaId: "adi", nadai: .sankeerna, syllableGroupId: "jathi-9", normalCycles: 2, muteCycles: 2),
                .check(
                    summary: "Санкирна надаи завершена. Вы теперь владеете всеми пятью надаи — тисра, чатусра, кханда, мисра и санкирна. Панча надаи — ваш. Следующий урок: архитектура ритмических фраз."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.76, maxAvgDriftMs: 55),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-12")
        )
    }

    // MARK: - Level 14: Яти паттерны

    private static func level14() -> Lesson {
        Lesson(
            id: "level-14",
            levelNumber: 14,
            title: "Яти паттерны",
            subtitle: "Архитектура ритмических фраз",
            description: "Яти — это паттерны с геометрической формой: возрастающие, убывающие, симметричные. Они придают ритму форму в пространстве времени.",
            estimatedMinutes: 16,
            steps: [
                .intro(
                    text: "Яти — это способ организации серий ритмических фраз по форме. Существует пять видов яти:\n\n• Сама яти: все фразы одинаковой длины (3-3-3-3)\n• Сротовахá яти: возрастающие фразы (3-4-5-6-7)\n• Гопуча яти: убывающие фразы (7-6-5-4-3)\n• Мридангам яти: расширение затем сужение (3-5-7-5-3)\n• Вишхам яти: асимметричные длины\n\nЯти-паттерны — это архитектура. Они определяют не только тайминг, но и эмоциональный «контур» фразы: нарастание, спад, пульсация, волна.\n\nСегодня мы практикуем сама, сротоваха и гопуча яти.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-3", repetitions: 3),
                .listen(syllableGroupId: "jathi-5", repetitions: 3),
                .listen(syllableGroupId: "jathi-7", repetitions: 3),
                .speak(
                    syllableGroupId: "jathi-3",
                    targetCount: 9,
                    instruction: "Сама яти: Та-Ки-Та три раза. Все одинаковые. Это основа."
                ),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-3", cycles: 2),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 15,
                    instruction: "Сротоваха яти — возрастание: произнесите тройку, пятёрку, затем семёрку. Три разных группы подряд. Ощущение нарастания."
                ),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-7", cycles: 3),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 21,
                    instruction: "Гопуча яти — убывание: произнесите семёрку, пятёрку, тройку. Ощущение схождения, сжатия. Третья фраза должна попасть на сам."
                ),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-5", normalCycles: 3, muteCycles: 2),
                .check(
                    summary: "Яти-паттерны дают ритму форму. Не просто «звуки во времени», а архитектура — как у здания: фундамент, подъём, завершение. Следующий урок: полиритмия."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.77, maxAvgDriftMs: 55),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-13")
        )
    }

    // MARK: - Level 15: Полиритмия

    private static func level15() -> Lesson {
        Lesson(
            id: "level-15",
            levelNumber: 15,
            title: "Полиритмия",
            subtitle: "Скрещивание ритмов: джати против надаи",
            description: "Полиритмия — это когда два ритма звучат одновременно. В коннаколе это достигается сочетанием джати и надаи, которые не кратны друг другу: 3 против 4, 5 против 4.",
            estimatedMinutes: 18,
            steps: [
                .intro(
                    text: "Полиритмия — вершина ритмического мышления. В западной музыке это редкость. В карнатической — повседневность.\n\nКак это работает в коннаколе: вы произносите джати (слоговую группу) внутри надаи, которая не кратна этой группе. Результат — два ритма «скрещиваются» и встречаются только в определённых точках.\n\nПример 1: Та-Ки-Та (3) внутри чатусра надаи (4). Три слога против четырёх пульсов. Они встретятся через 12 позиций — три цикла надаи.\n\nПример 2: Та-Ка-Та-Ки-На (5) внутри чатусра надаи (4). Они встретятся через 20 позиций — пять циклов надаи.\n\nЭто не «ошибка ритма» — это намеренное перекрещивание двух сеток. Слушайте оба ритма одновременно.",
                    videoURL: nil
                ),
                .listen(syllableGroupId: "jathi-3", repetitions: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-3", cycles: 6),
                .speak(
                    syllableGroupId: "jathi-3",
                    targetCount: 18,
                    instruction: "3 против 4: произнесите Та-Ки-Та внутри чатусра надаи. Слоги не совпадают с пульсами. Не пытайтесь их выровнять — доверьтесь «3». Повторите 6 раз."
                ),
                .listen(syllableGroupId: "jathi-5", repetitions: 4),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-5", cycles: 6),
                .speak(
                    syllableGroupId: "jathi-5",
                    targetCount: 30,
                    instruction: "5 против 4: произнесите Та-Ка-Та-Ки-На внутри чатусра надаи. Пять слогов на четыре пульса — пляж и волна. Повторите 6 раз."
                ),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-3", normalCycles: 3, muteCycles: 3),
                .check(
                    summary: "Полиритмия — это разговор двух голосов. Один держит пульс, другой плетёт узор поверх. Вы научились слышать оба одновременно. Это мышление, которое меняет восприятие музыки навсегда."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.76, maxAvgDriftMs: 50),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-14")
        )
    }

    // MARK: - Level 16: Тани Авартанам

    private static func level16() -> Lesson {
        Lesson(
            id: "level-16",
            levelNumber: 16,
            title: "Тани Авартанам",
            subtitle: "Сольная импровизация",
            description: "Тани авартанам — это сольная ритмическая каденция. Финальный урок объединяет всё: талу, надаи, корваи, яти и паузу. Это ваш первый шаг к живому исполнению.",
            estimatedMinutes: 20,
            steps: [
                .intro(
                    text: "Тани авартанам (tani avartanam) — это сольная импровизация в карнатическом концерте. Обычно исполняется перцуссионистом (мридангистом) в конце произведения. Но в коннаколе это может быть голос.\n\nСтруктура тани:\n1. Pallavi — вступительный раздел: установить тала и темп\n2. Развитие — серии яти, корваи, гати бхедам\n3. Тирманам (tirmanam) — финальная серия корваи, разрешающаяся точно на сам\n\nЭто не исполнение по нотам. Это импровизация в рамках структуры. Вы знаете все инструменты — теперь используйте их свободно.\n\nСегодняшний урок — это ваш тани. Начните с ади талы. Говорите. Слушайте. Разрешайтесь на сам.",
                    videoURL: nil
                ),
                .clap(talaId: "adi", nadai: .chatusra, cycles: 2),
                .loop(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", cycles: 4),
                .loop(talaId: "adi", nadai: .tisra, syllableGroupId: "jathi-3", cycles: 4),
                .loop(talaId: "adi", nadai: .khanda, syllableGroupId: "jathi-5", cycles: 4),
                .speak(
                    syllableGroupId: "jathi-7",
                    targetCount: 21,
                    instruction: "Тирманам — финальная корваи: Та-Ки-Та-Та-Ка-Ди-Ми три раза подряд. Это ваша финальная каденция. Произнесите её уверенно — конец должен совпасть с самом."
                ),
                .mute(talaId: "adi", nadai: .chatusra, syllableGroupId: "jathi-4", normalCycles: 2, muteCycles: 4),
                .check(
                    summary: "Вы прошли все 16 уровней Konnakol Master.\n\nВы освоили пять джати, пять надаи, четыре талы, корваи, мору, яти и полиритмию. Вы стали способны удерживать пульс в тишине.\n\nНо путь в коннаколе не заканчивается — он только разворачивается. Практикуйте каждый день. Пойте с другими. Слушайте мастеров. И помните: главное не скорость — главное попасть на сам.\n\nНамаскарам."
                ),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.80, maxAvgDriftMs: 40),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-15")
        )
    }

    // MARK: - Stub for unknown levels

    private static func stub(level: Int) -> Lesson {
        Lesson(
            id: "level-\(level)",
            levelNumber: level,
            title: "Уровень \(level)",
            subtitle: "",
            description: "Этот уровень ещё в разработке.",
            estimatedMinutes: 15,
            steps: [
                .intro(text: "Контент этого уровня появится в следующей версии. Продолжайте практиковаться на предыдущих уровнях.", videoURL: nil),
                .check(summary: ""),
            ],
            passCriteria: PassCriteria(minAccuracy: 0.75, maxAvgDriftMs: 70),
            unlockCriteria: UnlockCriteria(kind: .completePrevious, requiredLessonId: "level-\(level - 1)")
        )
    }
}
