# Fixtures

SYNTHETIC FIXTURE. Format faithful, values invented. Never treat as observed data.

Every file here reproduces the SHAPE of a real response: encoding, element
names, attribute names, decimal separators, wrapper structure. The NUMBERS are
invented and must never be read as observations of the world.

They exist so parsers can be tested without touching a live service, which also
means a parser passing these tests has not yet been proved against the real
service. First live run is the real test; a mismatch there is stored as raw
evidence with a parse error attached, not swallowed.

| file | reproduces |
|------|------------|
| `cbr_fx_business_day.xml` | XML_daily.asp on a publication day, windows-1251 |
| `cbr_fx_nominal_ten.xml` | a currency quoted per 10 units, to test conversion |
| `cbr_key_rate.xml` | the ASMX DataSet/diffgram wrapper around KR rows |
| `open_meteo_archive.json` | ERA5 archive, three complete days |
| `open_meteo_archive_partial.json` | same, with one evening hour missing |
| `open_meteo_forecast.json` | forecast endpoint, same shape |
| `production_calendar_2026.json` | xmlcalendar JSON with `*` and `+` markers |
