<?php

namespace Kozlov\Gazeta\Domain;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a crossword grid from a list of answers (0.5.2).
 *
 * Classic free-form construction: long words first, every next word must cross the grid, no
 * letter may touch a neighbour that would form an unintended word, nothing grows beyond the
 * size limit. Several shuffled attempts are made from one seed and the best is kept: most
 * words placed, then most crossings, then the smallest and squarest box. The same words and
 * the same seed always give the same grid, so «Другая сетка» simply means another seed.
 */
final class CrosswordBuilder
{
    public const MAX_SIZE = 25;
    public const ATTEMPTS = 24;

    /** @var array<string,string> "r:c" => letter */
    private $cells = [];
    /** @var array<string,array<string,bool>> "r:c" => ['a' => true, 'd' => true] */
    private $dirs = [];
    /** @var array<string,array<int,array{int,int}>> letter => list of [r, c] */
    private $byLetter = [];
    /** @var int[] */
    private $box = [0, 0, -1, -1];
    /** @var int */
    private $state;

    /**
     * @param string[] $words normalised answers (upper case, one alphabet, no spaces)
     * @return array{rows:int,cols:int,placements:array<int,array{w:int,r:int,c:int,d:string,n:int}>,unplaced:int[],crossings:int}
     */
    public static function build(array $words, int $seed, int $maxSize = self::MAX_SIZE): array
    {
        // Start from a compact square and widen only while some words do not fit.
        $letters = 0;
        $longest = 0;
        foreach ($words as $word) {
            $length = mb_strlen($word);
            $letters += $length;
            $longest = max($longest, $length);
        }
        $size = min($maxSize, max($longest, (int) ceil(sqrt($letters * 1.9))));
        $best = null;
        $bestScore = null;
        for (; $size <= $maxSize; $size++) {
            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $builder = new self(($seed * 7919 + $attempt * 104729 + $size * 15485863) & 0x7fffffff ?: 1);
                $result = $builder->attempt($words, $size, $attempt);
                $score = [
                    count($result['placements']),
                    $result['crossings'],
                    -($result['rows'] * $result['cols']),
                    -abs($result['rows'] - $result['cols']),
                ];
                if ($bestScore === null || $score > $bestScore) {
                    $best = $result;
                    $bestScore = $score;
                }
            }
            if ($best !== null && !$best['unplaced']) {
                break;
            }
        }
        return self::number($best);
    }

    private function __construct(int $state)
    {
        $this->state = $state;
    }

    /** xorshift32: deterministic, no global state. */
    private function random(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xffffffff;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xffffffff;
        $this->state = $x & 0xffffffff ?: 1;
        return $this->state;
    }

    private function attempt(array $words, int $maxSize, int $attempt): array
    {
        $order = array_keys($words);
        // Longest first; equal lengths in a seeded order. Attempt 0 keeps the file order for ties.
        $keys = [];
        foreach ($order as $index) {
            $keys[$index] = [-mb_strlen($words[$index]), $attempt === 0 ? $index : $this->random()];
        }
        usort($order, static function ($a, $b) use ($keys) {
            return $keys[$a] <=> $keys[$b];
        });
        $placements = [];
        $crossings = 0;
        $pending = $order;
        $first = true;
        do {
            $progress = false;
            $left = [];
            foreach ($pending as $index) {
                $letters = self::letters($words[$index]);
                if ($first) {
                    if (count($letters) > $maxSize) {
                        $left[] = $index;
                        continue;
                    }
                    $this->place($letters, 0, 0, 'a');
                    $placements[$index] = ['w' => $index, 'r' => 0, 'c' => 0, 'd' => 'a'];
                    $first = false;
                    $progress = true;
                    continue;
                }
                $spot = $this->bestSpot($letters, $maxSize);
                if ($spot === null) {
                    $left[] = $index;
                    continue;
                }
                [$r, $c, $d, $cross] = $spot;
                $this->place($letters, $r, $c, $d);
                $placements[$index] = ['w' => $index, 'r' => $r, 'c' => $c, 'd' => $d];
                $crossings += $cross;
                $progress = true;
            }
            $pending = $left;
        } while ($pending && $progress);

        // Shift to a 0-based box.
        [$top, $leftEdge, $bottom, $right] = $this->box;
        foreach ($placements as &$p) {
            $p['r'] -= $top;
            $p['c'] -= $leftEdge;
        }
        unset($p);
        ksort($placements);
        sort($pending);
        return [
            'rows' => $placements ? $bottom - $top + 1 : 0,
            'cols' => $placements ? $right - $leftEdge + 1 : 0,
            'placements' => array_values($placements),
            'unplaced' => array_values($pending),
            'crossings' => $crossings,
        ];
    }

    /** @return string[] */
    private static function letters(string $word): array
    {
        return preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return array{int,int,string,int}|null [row, col, direction, crossings] */
    private function bestSpot(array $letters, int $maxSize): ?array
    {
        $best = null;
        $bestScore = null;
        $seen = [];
        foreach ($letters as $i => $letter) {
            foreach ($this->byLetter[$letter] ?? [] as [$r, $c]) {
                $used = $this->dirs[$r . ':' . $c] ?? [];
                foreach (['a', 'd'] as $d) {
                    if (isset($used[$d])) {
                        continue;
                    }
                    $sr = $d === 'd' ? $r - $i : $r;
                    $sc = $d === 'a' ? $c - $i : $c;
                    $key = $sr . ':' . $sc . ':' . $d;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $cross = $this->fits($letters, $sr, $sc, $d, $maxSize);
                    if ($cross < 1) {
                        continue;
                    }
                    $score = $this->score($letters, $sr, $sc, $d, $cross);
                    if ($bestScore === null || $score > $bestScore) {
                        $best = [$sr, $sc, $d, $cross];
                        $bestScore = $score;
                    }
                }
            }
        }
        return $best;
    }

    /** Number of crossings, or -1 when the word cannot go there. */
    private function fits(array $letters, int $r, int $c, string $d, int $maxSize): int
    {
        $dr = $d === 'd' ? 1 : 0;
        $dc = $d === 'a' ? 1 : 0;
        $n = count($letters);
        // Cells right before and after the word stay empty.
        if (isset($this->cells[($r - $dr) . ':' . ($c - $dc)]) || isset($this->cells[($r + $dr * $n) . ':' . ($c + $dc * $n)])) {
            return -1;
        }
        [$top, $left, $bottom, $right] = $this->box;
        $top = min($top, $r);
        $left = min($left, $c);
        $bottom = max($bottom, $r + $dr * ($n - 1));
        $right = max($right, $c + $dc * ($n - 1));
        if ($bottom - $top + 1 > $maxSize || $right - $left + 1 > $maxSize) {
            return -1;
        }
        $cross = 0;
        for ($i = 0; $i < $n; $i++) {
            $rr = $r + $dr * $i;
            $cc = $c + $dc * $i;
            $key = $rr . ':' . $cc;
            if (isset($this->cells[$key])) {
                if ($this->cells[$key] !== $letters[$i] || isset($this->dirs[$key][$d])) {
                    return -1;
                }
                $cross++;
                continue;
            }
            // An empty cell must not touch letters on the sides (they would form a new word).
            if (isset($this->cells[($rr + $dc) . ':' . ($cc + $dr)]) || isset($this->cells[($rr - $dc) . ':' . ($cc - $dr)])) {
                return -1;
            }
        }
        return $cross === $n ? -1 : $cross;
    }

    private function score(array $letters, int $r, int $c, string $d, int $cross): array
    {
        [$top, $left, $bottom, $right] = $this->box;
        $n = count($letters);
        $nTop = min($top, $r);
        $nLeft = min($left, $c);
        $nBottom = max($bottom, $r + ($d === 'd' ? $n - 1 : 0));
        $nRight = max($right, $c + ($d === 'a' ? $n - 1 : 0));
        $height = $nBottom - $nTop + 1;
        $width = $nRight - $nLeft + 1;
        $growth = $height * $width - max(1, ($bottom - $top + 1) * ($right - $left + 1));
        return [$cross, -$growth, -abs($height - $width), $this->random() & 0xffff];
    }

    private function place(array $letters, int $r, int $c, string $d): void
    {
        $dr = $d === 'd' ? 1 : 0;
        $dc = $d === 'a' ? 1 : 0;
        foreach ($letters as $i => $letter) {
            $rr = $r + $dr * $i;
            $cc = $c + $dc * $i;
            $key = $rr . ':' . $cc;
            if (!isset($this->cells[$key])) {
                $this->cells[$key] = $letter;
                $this->byLetter[$letter][] = [$rr, $cc];
            }
            $this->dirs[$key][$d] = true;
        }
        $n = count($letters);
        if ($this->box[2] < $this->box[0]) {
            $this->box = [$r, $c, $r + $dr * ($n - 1), $c + $dc * ($n - 1)];
            return;
        }
        $this->box = [
            min($this->box[0], $r),
            min($this->box[1], $c),
            max($this->box[2], $r + $dr * ($n - 1)),
            max($this->box[3], $c + $dc * ($n - 1)),
        ];
    }

    /** Standard numbering: cells that start a word, in reading order. */
    private static function number(array $result): array
    {
        $starts = [];
        foreach ($result['placements'] as $p) {
            $starts[$p['r'] * 1000 + $p['c']] = true;
        }
        ksort($starts);
        $numbers = [];
        $n = 0;
        foreach (array_keys($starts) as $key) {
            $numbers[$key] = ++$n;
        }
        foreach ($result['placements'] as &$p) {
            $p['n'] = $numbers[$p['r'] * 1000 + $p['c']];
        }
        unset($p);
        return $result;
    }
}
