import json, re, sys, urllib.request, os
ids = json.load(open(os.path.join(os.path.dirname(__file__), 'ids.json')))
base = 'http://127.0.0.1:8089/event/case-%s/'
def fetch(key):
    try:
        req = urllib.request.Request(base % key.lower())
        with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req, timeout=30) as r:
            return r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')
def blocks(html):
    return re.findall(r'<script type="application/ld\+json" class="kozlov-event-schema">(.*?)</script>', html, re.S)
mode = sys.argv[1]
fails = 0
def ok(cond, msg):
    global fails
    if not cond:
        fails += 1
        print('  FAIL:', msg)
results = {}
for key in sorted(ids):
    status, html = fetch(key)
    b = blocks(html)
    results[key] = (status, b)
if mode == 'off':
    for k, (st, b) in results.items():
        ok(len(b) == 0, f'{k}: block present while disabled')
    print('OFF mode: pages', {k: v[0] for k, v in results.items()})
else:
    exp_none = {'G', 'H', 'I', 'N', 'O', 'RX'}
    for k, (st, b) in sorted(results.items()):
        if k in exp_none:
            ok(len(b) == 0, f'{k}: expected no block, got {len(b)} (status {st})')
            continue
        ok(st == 200, f'{k}: status {st}')
        ok(len(b) == 1, f'{k}: expected exactly 1 block, got {len(b)}')
        if not b: continue
        raw = b[0]
        ok('</' not in raw and '<script' not in raw.lower(), f'{k}: raw contains tag text')
        d = json.loads(raw)
        results[k] = (st, d)
        exp_type = 'EducationEvent' if k == 'W' else 'MusicEvent'
        ok(d['@context'] == 'https://schema.org' and d['@type'] == exp_type, f'{k}: type {d["@type"]}')
        ok(d['location']['@type'] == ['Place', 'MusicVenue'] and d['location']['@id'].startswith('http://127.0.0.1:8089/#'), f'{k}: venue {d["location"].get("@id")}')
        if 'offers' in d:
            ok('availability' not in d['offers'], f'{k}: availability must be absent')
            vf = d['offers'].get('validFrom')
            if k in ('PP', 'CC'):
                ok(vf is None, f'{k}: validFrom must be absent when published after start, got {vf}')
            else:
                ok(vf is not None and re.match(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+03:00$', vf) and vf[:10] <= d['startDate'][:10], f'{k}: validFrom {vf}')
        if k not in ('LP', 'LV', 'LM', 'LJ'):
            ok('performer' not in d, f'{k}: unexpected performer {d.get("performer")}')
        org = d['organizer']
        ok(org['name'] == 'Клуб Алексея Козлова' and org['url'] == 'http://127.0.0.1:8089/', f'{k}: organizer')
        ok((org.get('@id') == 'http://127.0.0.1:8089/#organization') == (os.environ.get('YOAST') == '1'), f'{k}: organizer @id {org.get("@id")}')
        for req in ('name', 'startDate', 'location'):
            ok(req in d, f'{k}: missing {req}')
        ok(d['location']['address']['streetAddress'] in ('ул. Маросейка, 9/2', 'ул. Мясницкая, 15'), f'{k}: address')
    g = lambda k: results[k][1]
    A = g('A'); ok(A['startDate'] == '2026-11-30T20:00:00+03:00' and A['endDate'] == '2026-11-30T22:30:00+03:00', 'A dates %s %s' % (A['startDate'], A.get('endDate')))
    ok(A['offers']['price'] == 1500 and A['offers']['priceCurrency'] == 'RUB' and A['offers']['url'] == A['url'], 'A offers %s' % A.get('offers'))
    ok(A.get('keywords') == 'Джаз, Соул' and A['location']['@id'].endswith('#stage-main') and A['eventStatus'].endswith('EventScheduled'), 'A keywords/venue/status %s %s' % (A.get('keywords'), A['location']['@id']))
    ok(A['location']['name'] == 'Клуб Алексея Козлова, Главная сцена', 'A loc')
    ok(A['description'] == 'Народный артист России Даниил Крамер и «Диалог»', 'A desc %r' % A.get('description'))
    ok(A['name'] == 'ДАНИИЛ КРАМЕР И РОМАН МИРОШНИЧЕНКО: «ДИАЛОГ ВИРТУОЗОВ»', 'A name')
    ok(re.match(r'^https?://[\x21-\x7e]+$', A['image'][0]) is not None and '%D0%A1%D0%B0%D1%88%D0%B0' in A['image'][0], 'A image encoded %s' % A['image'])
    ok(A['url'].endswith('/event/case-a/') and A['@id'] == A['url'] + '#event', 'A url')
    B = g('B'); ok('offers' not in B and B['endDate'] == '2026-12-10T00:30:00+03:00' and B['location']['name'].endswith('The Beat'), 'B deposit/end %s' % B)
    C = g('C'); ok(C['offers']['price'] == 0 and C.get('isAccessibleForFree') is True and C.get('keywords') == 'Джаз', 'C free/keywords %s' % C.get('keywords'))
    D = g('D'); ok(D['location']['name'] == 'Клуб Алексея Козлова' and D['location']['address']['streetAddress'] == 'ул. Маросейка, 9/2' and D['location']['@id'].endswith('#venue-marosejka') and 'keywords' not in D, 'D no-stage fallback')
    E = g('E'); ok('offers' not in E and E['location']['address']['streetAddress'] == 'ул. Мясницкая, 15', 'E myasnitskaya no tickets')
    F = g('F'); ok(F['offers']['price'] == 1000, 'F exact price')
    J = g('J'); ok(J['startDate'] == '2026-11-20T19:30:00+03:00', 'J tz Moscow %s' % J['startDate'])
    K = g('K'); ok('</script><b>' in K['description'] and 'alert' not in K['description'] and '"кавычки"' in K['description'], 'K desc roundtrip %r' % K['description'])
    L = g('L'); ok(L['startDate'] == '2026-12-31' and 'endDate' not in L, 'L allday %s %s' % (L['startDate'], L.get('endDate')))
    M = g('M'); ok('endDate' not in M, 'M instant')
    P = g('P'); ok(P['location']['name'].endswith('Главная сцена'), 'P multi')
    Q = g('Q'); ok('offers' not in Q, 'Q ambiguous price')
    R = g('R'); ok(R['startDate'] == '2026-11-26T20:00:00+03:00', 'R empty tz %s' % R['startDate'])
    S_ = g('S'); ok('image' not in S_, 'S small image')
    T = g('T'); ok(T['offers']['price'] == 2500 and T['description'] == 'Русский текст', 'T curly %s %s' % (T.get('offers'), T.get('description')))
    U = g('U'); ok(U['name'] == 'ЖАННА & ДРУЗЬЯ «ДЖАЗ»' and 'description' not in U and 'image' not in U, 'U entities %r' % U['name'])
    V = g('V'); ok('endDate' not in V, 'V end before start')
    W_ = g('W'); ok(W_['@type'] == 'EducationEvent' and W_.get('keywords') == 'Лекция, Джаз', 'W lecture %s' % W_.get('keywords'))
    X = g('X'); ok(X['eventStatus'] == 'https://schema.org/EventCancelled' and X['offers']['price'] == 1500 and X['startDate'] == '2026-11-12T20:00:00+03:00', 'X cancelled keeps props')
    Y = g('Y'); ok(Y['eventStatus'] == 'https://schema.org/EventPostponed' and Y['offers']['price'] == 0 and Y.get('isAccessibleForFree') is True, 'Y postponed keeps props')
    PP = g('PP'); ok(PP['eventStatus'].endswith('EventPostponed') and PP['startDate'] == '2026-10-01T20:00:00+03:00' and PP['offers']['price'] == 1200, 'PP postponed after original date still output')
    CC = g('CC'); ok(CC['eventStatus'].endswith('EventCancelled') and CC['startDate'] == '2026-10-02T20:00:00+03:00' and CC['location']['@id'].endswith('#stage-the-beat'), 'CC cancelled after original date still output')
    Z = g('Z'); ok(Z.get('keywords') == 'Фанк, Соул', 'Z multi-row genres %s' % Z.get('keywords'))
    E2 = g('E'); ok(E2['location']['@id'].endswith('#stage-myasnitskaya'), 'E venue id')
    RS = g('RS'); ok(RS['eventStatus'].endswith('EventRescheduled') and RS.get('previousStartDate') == '2026-11-10T20:00:00+03:00' and RS['startDate'] == '2026-11-17T20:00:00+03:00' and RS['offers']['price'] == 2000, 'RS rescheduled %s %s' % (RS.get('previousStartDate'), RS.get('offers')))
    RT = g('RT'); ok(RT['eventStatus'].endswith('EventRescheduled') and 'previousStartDate' not in RT and 'offers' in RT, 'RT rescheduled no prev')
    for k2 in ('A','B','C','D','E','F'):
        ok('previousStartDate' not in g(k2) and g(k2)['eventStatus'].endswith('EventScheduled'), k2 + ' scheduled')
    P_ = lambda k: [(x['@type'], x['name']) for x in g(k).get('performer', [])]
    ok(P_('LP') == [('Person', 'Дмитрий Илугдин'), ('Person', 'Михаил Дмитриев'), ('Person', 'Пётр Ившин')], 'LP performer %s' % P_('LP'))
    ok(g('LP')['offers'].get('validFrom') == '2026-09-01T12:00:00+03:00', 'LP validFrom %s' % g('LP')['offers'].get('validFrom'))
    ok(P_('LV') == [('Person', 'Николай Сарабьянов'), ('Person', 'Антон Ревнюк'), ('Person', 'Андрей Красильников'), ('Person', 'Roberto Porta'), ('Person', 'Петр Дольский')], 'LV performer %s' % P_('LV'))
    ok(P_('LM') == [('PerformingGroup', '«Арсенал» Алексея Козлова'), ('Person', 'Анна Королёва'), ('PerformingGroup', 'Трио Павла Ершова')], 'LM manual %s' % P_('LM'))
    ok('performer' not in g('LO'), 'LO performer off')
    ok(P_('LJ') == [('Person', 'Егор Балабанов')] and 'offers' not in g('LJ'), 'LJ jam host %s' % P_('LJ'))
    print('ON mode checked', len(results), 'pages')
print('FAILS:', fails)
