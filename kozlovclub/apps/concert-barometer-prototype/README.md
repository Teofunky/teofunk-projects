# Концертный барометр — рабочий MVP 0.1

Локальное приложение для внешнего мониторинга спроса на концерты и городской офлайн-досуг Москвы.

## Запуск

macOS / Linux:

```bash
cd concert_barometer_app
./run.sh
```

Windows:

```bat
run.bat
```

Откройте: http://127.0.0.1:8765

Приложение использует только стандартную библиотеку Python 3 и SQLite, поэтому `pip install` не нужен.

## Что уже работает

- веб-интерфейс с галочками сегментов;
- сравнение до 5 кривых;
- SQLite с историей наблюдений;
- импорт CSV внешних рядов;
- отдельные live/synthetic данные;
- погодный live-adapter без ключа (Open-Meteo) как операционный источник;
- NOW / WoW / Momentum / seasonal deviation;
- прозрачный базовый forecast 7/14/30/90 дней с диапазоном;
- источники и свежесть;
- demo mode, который явно маркируется как synthetic;
- API health/dashboard/import/run.

## Важное ограничение MVP

`transparent_linear_v0.1` — контрольная прогнозная модель, а не финальный Concert Barometer ML. Она нужна, чтобы весь pipeline, UI и forecast-vintage logic можно было тестировать до подключения исторических рыночных данных. Производственный прогноз должен заменить её только после rolling backtest.

Прямые продажи конкурентов без их авторизованного data feed недоступны. Публичная билетная доступность может использоваться только как proxy.

## Импорт

Нажмите «Скачать шаблон CSV». Минимум:

- `date` YYYY-MM-DD
- `segment`: concerts, jazz, theatres, foodservice, restaurants, retail, search, macro, event_stress, weather
- `value`
- `source_name`

Для индексов желательно нормализовать ряд так, чтобы 100 = сезонная норма.

## Следующая production-фаза

1. Yandex Wordstat API.
2. Банк России / Росстат / Мосстат adapters.
3. лицензированный consumer/receipt feed.
4. registry московских площадок и законный snapshot engine.
5. immutable raw layer + provenance.
6. dynamic factor/state-space model.
7. CatBoost/LightGBM ensemble.
8. rolling-origin backtest и immutable forecast vintages.
9. agent orchestration + fact checker + skeptic.
