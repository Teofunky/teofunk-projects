# Как запустить KonnakolMaster

## Требования

- Mac (macOS 14 Sonoma или новее)
- Xcode 15.2 или новее (бесплатно из Mac App Store)
- iPhone или iPad c iOS 17+ **или** встроенный симулятор (не нужен реальный девайс)

---

## Шаги

### 1. Клонировать репозиторий на Mac

```bash
git clone <url-репозитория> Teofunky
cd Teofunky
git checkout claude/new-session-Iskis
```

### 2. Открыть проект в Xcode

Два способа — любой работает:

**Способ A — через Finder:**
Дважды кликнуть на файл `Package.swift` в папке `Teofunky`

**Способ Б — через терминал:**
```bash
open Package.swift
```

Xcode откроется и автоматически загрузит пакет (может занять ~30 секунд).

### 3. Выбрать симулятор

В верхней панели Xcode (рядом с кнопкой ▶):
- Слева выбрать схему: **KonnakolMaster**
- Справа выбрать устройство: **iPhone 16 Pro** (или любой iPhone 17+)

### 4. Запустить

Нажать **⌘R** (или кнопку ▶)

Первый билд занимает ~1-2 минуты. Затем откроется симулятор с приложением.

---

## Запуск тестов

```
⌘U  — запустить все тесты
```

Или через меню: **Product → Test**

Тесты не требуют микрофона и работают без симулятора (на Mac напрямую).

---

## Если Xcode выдаёт ошибки

### "No such module 'KonnakolMaster'"
→ File → Packages → Reset Package Caches

### "Scheme not found"
→ Product → Scheme → Manage Schemes → поставить галку на KonnakolMaster

### Предупреждения о StrictConcurrency
→ Это не ошибки, можно игнорировать. Или убрать строку `enableExperimentalFeature` из Package.swift.

---

## Тест микрофона (голосовой анализ)

Микрофон работает **только на реальном устройстве**. В симуляторе:
- Все экраны открываются
- Метроном играет
- Анализ голоса возвращает нулевые значения (нет микрофона)

Для теста на устройстве:
1. Подключить iPhone через USB
2. Выбрать его в списке устройств в Xcode
3. Xcode спросит подписать сертификатом — использовать личный Apple ID (бесплатно, для разработки)

---

## Структура проекта

```
Teofunky/
├── Package.swift              ← точка входа для Xcode
└── KonnakolMaster/
    ├── App/                   ← KonnakolMasterApp.swift, DependencyContainer
    ├── Core/
    │   ├── Audio/             ← метроном (AVAudioEngine + DispatchSourceTimer)
    │   └── Voice/             ← FFT, OnsetDetector, VoiceAnalyzer
    ├── Domain/
    │   ├── Models/            ← Tala, Lesson, UserProgress
    │   ├── Library/           ← TalaLibrary, LessonBuilder (16 уровней)
    │   └── UseCases/          ← ProgressStore
    ├── Data/                  ← SwiftData модели и репозитории
    ├── Presentation/
    │   ├── DesignSystem/      ← цвета, типографика, компоненты
    │   ├── Onboarding/
    │   ├── Today/
    │   ├── Learn/             ← Skill Tree
    │   ├── Lesson/            ← Lesson Player + 8 типов шагов
    │   ├── Practice/          ← TalaWheelView, PracticeView
    │   └── Profile/
    └── Tests/UnitTests/       ← MetronomeScheduler, OnsetDetector, Pipeline тесты
```
