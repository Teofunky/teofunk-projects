# Девочка с рыбами · проба набора (23.11.2026)

10 с, 1080p. Моргания на 1,4 / 4,9 / 8,1 с, взгляд на красную рыбу с 5,5 с, красная рыба справа плывёт на ярусе.
Картина `devochka-s-rybami.png` лежит на Mac: `ATELIER-Studio-Mac (2)/Картины/`.

Ярус и подложка из контура `fish-rtr.poly.json`:

```python
import json, mark
q = json.load(open("fish-rtr.poly.json"))
mark.cutout("devochka-s-rybami.png", q, "L_rtr.png")
mark.plate("devochka-s-rybami.png", [q], "plate.png")
```

Результат проверки: тождество 0,027 (макс 5), вне масок 0 пикс., моргание закрыто на 92 %, застывших участков нет.
