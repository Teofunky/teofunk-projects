from datetime import date

from bs4 import BeautifulSoup

from app.analyzer import build_report, dedupe_items
from app.scraper import CompetitorScraper


def test_jsonld_event_parser():
    html = '''
    <html><script type="application/ld+json">
    {"@context":"https://schema.org","@type":"MusicEvent","name":"International Jazz Festival",
     "startDate":"2026-07-21T20:00:00+03:00","description":"Гости из Италии, премьера программы",
     "url":"/event/1","offers":{"lowPrice":"1500"}}
    </script></html>
    '''
    scraper = CompetitorScraper()
    soup = BeautifulSoup(html, "lxml")
    source = {"name":"Тест","competitor":"Эссе","source_type":"official","url":"https://example.com"}
    items = scraper._parse_jsonld(soup, source, date(2026, 7, 1), date(2026, 7, 31))
    assert len(items) == 1
    assert items[0].price_min == 1500
    assert items[0].is_import is True
    assert "фестиваль" in items[0].categories


def test_dom_event_parser():
    html = '''
    <article class="event-card">
      <h3>Jazz Jam: Bossa Nova Edition</h3>
      <div>16 июля 2026, 20:00</div><div>Билеты от 1200 руб.</div>
      <a href="/tickets/123">Купить билет</a>
    </article>
    '''
    scraper = CompetitorScraper()
    soup = BeautifulSoup(html, "lxml")
    source = {"name":"Тест","competitor":"Louis","source_type":"official","url":"https://example.com"}
    items = scraper._parse_dom_candidates(soup, source, date(2026, 7, 1), date(2026, 7, 31))
    assert items
    assert items[0].title == "Jazz Jam: Bossa Nova Edition"
    assert items[0].price_min == 1200
    assert "джем" in items[0].categories


def test_cross_source_deduplication():
    rows = [
        {"competitor":"Эссе","source_name":"Официальный","source_type":"official","source_url":"x","item_url":"x1","title":"Квартет Ивана Иванова","description":"","starts_at":"2026-07-20T20:00:00","published_at":None,"price_min":1500,"venue":"","item_type":"event","categories":[],"is_import":False},
        {"competitor":"Эссе","source_name":"Агрегатор","source_type":"aggregator","source_url":"y","item_url":"y1","title":"Квартет Ивана Иванова","description":"","starts_at":"2026-07-20T20:00:00","published_at":None,"price_min":1700,"venue":"","item_type":"event","categories":[],"is_import":False},
    ]
    result = dedupe_items(rows)
    assert len(result) == 1
    assert len(result[0]["sources"]) == 2
    assert result[0]["price_min"] == 1500
