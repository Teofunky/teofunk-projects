"""Подложка v5: рыбы уплывают далеко, поэтому дыры под ними заполняются соседним звёздным небом
(перенос со сдвигом и подгонкой тона), девочка закрыта полностью (качается сильнее), рука и раковина
тоже переносом неба."""
import sys, cv2, numpy as np
sys.path.insert(0, '/home/claude/teofunk-projects/apps/atelier-kit'); import mark
P = 'painting.png'; im = cv2.imread(P)
names = ['body', 'legB', 'legF', 'hand', 'shell'] + [f'f{i:02d}' for i in range(1, 12)]
A = {k: np.load(f'alpha_{k}.npy') for k in names}
occupied = np.zeros(im.shape[:2], np.uint8)
for a in A.values():
    occupied |= ((a > 0.004) * 255).astype(np.uint8)
cv2.circle(occupied, (1121, 250), 196, 255, -1)            # солнце
occupied = cv2.dilate(occupied, np.ones((9, 9), np.uint8))

# 1. девочка и ноги: по строкам (небо, море, песок горизонтальными слоями)
Mg = np.zeros(im.shape[:2], np.uint8)
for k in ('body', 'legB', 'legF'):
    Mg |= cv2.dilate(((A[k] > 0.02) * 255).astype(np.uint8), np.ones((3, 3), np.uint8))
mark._fill(im, Mg, 'plate_tmp.png', 7, 8, method='rows')
pl = cv2.imread('plate_tmp.png').astype(np.float32)
src = im.astype(np.float32)

def shift_fill(region, cands):
    """Заполнить region переносом неба с первого чистого сдвига из cands, тон подогнать."""
    global pl
    ys, xs = np.nonzero(region)
    for dx, dy in cands:
        y2, x2 = ys + dy, xs + dx
        if y2.min() < 0 or x2.min() < 0 or y2.max() >= im.shape[0] or x2.max() >= im.shape[1]:
            continue
        if occupied[y2, x2].any():
            continue
        M = np.float32([[1, 0, -dx], [0, 1, -dy]])
        shifted = cv2.warpAffine(src, M, (im.shape[1], im.shape[0]), flags=cv2.INTER_NEAREST, borderMode=cv2.BORDER_REFLECT)
        ring = (cv2.dilate(region, np.ones((21, 21), np.uint8)) > 0) & (region == 0) & (occupied == 0)
        m = region > 0
        if ring.sum() > 30:
            mu_t, sd_t = pl[ring].mean(0), pl[ring].std(0)
            mu_s, sd_s = shifted[m].mean(0), shifted[m].std(0)
            shifted = (shifted - mu_s) * np.clip(sd_t / np.maximum(sd_s, 1), 0.6, 1.0) + mu_t
        w = cv2.GaussianBlur(region.astype(np.float32) / 255, (0, 0), 1.5)[..., None]
        pl = pl * (1 - w) + np.clip(shifted, 0, 255) * w
        return (dx, dy)
    return None

cands = [(dx, dy) for r in (1.2, 1.6, 2.2, 3.0) for dx, dy in ((1, 0), (-1, 0), (0, 1), (0, -1), (1, 1), (-1, 1))]
for k in [f'f{i:02d}' for i in range(1, 12)] + ['hand+shell']:
    if k == 'hand+shell':
        a = np.maximum(A['hand'], A['shell']); reg = cv2.dilate(((a > 0.004) * 255).astype(np.uint8), np.ones((7, 7), np.uint8))
        reg[A['body'] > 0.6] = 0
    else:
        reg = cv2.dilate(((A[k] > 0.004) * 255).astype(np.uint8), np.ones((5, 5), np.uint8))
    ys, xs = np.nonzero(reg); w, h = int(np.ptp(xs)) + 1, int(np.ptp(ys)) + 1
    got = shift_fill(reg, [(int(r * w * sx), int(r * h * sy)) for r in (1.2, 1.6, 2.2, 3.0) for sx, sy in ((1, 0), (-1, 0), (0, 1), (0, -1), (1, 1), (-1, 1))])
    if got is None:  # нет чистого соседа рядом: ищем любой чистый кусок неба того же размера
        y0, x0 = ys.min(), xs.min()
        far = [(x - x0, y - y0) for y in range(0, 440 - h, 15) for x in range(0, im.shape[1] - w, 15)]
        far.sort(key=lambda d: abs(d[1]) * 3 + abs(d[0]))      # ближе по высоте: тот же тон неба
        got = shift_fill(reg, far)
    print(k, 'сдвиг', got)
cv2.imwrite('plate.png', np.clip(pl, 0, 255).round().astype(np.uint8))
cv2.imwrite('plate_sky.jpg', np.clip(pl[0:330], 0, 255).astype(np.uint8))
