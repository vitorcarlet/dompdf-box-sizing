<?php
namespace Dompdf\Layout\Flex;

/**
 * Frame-independent Flexbox calculations. Values are content-box lengths in pt.
 * https://www.w3.org/TR/css-flexbox-1/#layout-algorithm
 */
final class Calculator
{
    private const EPSILON = 0.0000001;

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($value, $max));
    }

    /**
     * @param Item[] $items Measurements in document order.
     * @param float|null $main null denotes an indefinite main size.
     */
    public function main(array $items, ?float $main, bool $wrap, float $gap): Result
    {
        $sorted = [];
        foreach ($items as $index => $item) {
            $sorted[] = [$index, clone $item];
        }
        usort($sorted, function ($a, $b) {
            return ($a[1]->order <=> $b[1]->order) ?: ($a[0] <=> $b[0]);
        });
        $result = new Result();
        $line = new Line();
        $occupied = 0.0;
        $activeCount = 0;
        foreach ($sorted as $entry) {
            $item = $entry[1];
            $hypothetical = $item->collapsed ? 0 : $item->outerMain(self::clamp($item->base, $item->min, $item->max));
            $extra = $activeCount && !$item->collapsed ? $gap : 0;
            if ($wrap && $main !== null && $line->items && $occupied + $extra + $hypothetical > $main + self::EPSILON) {
                $result->lines[] = $line;
                $line = new Line();
                $occupied = 0;
                $activeCount = 0;
                $extra = 0;
            }
            $item->line = count($result->lines);
            $line->items[] = $item;
            $occupied += $extra + $hypothetical;
            if (!$item->collapsed) { $activeCount++; }
        }
        if ($line->items) {
            $result->lines[] = $line;
        }
        foreach ($result->lines as $line) {
            $this->flex($line, $main, $gap);
            $outer = array_sum(array_map(function (Item $item) {
                return $item->collapsed ? 0 : $item->outerMain($item->size);
            }, $line->items));
            $count = count(array_filter($line->items, function (Item $item) { return !$item->collapsed;
            }));
            $result->main = max($result->main, $outer + max(0, $count - 1) * $gap);
        }
        $result->main = $main ?? $result->main;
        return $result;
    }

    private function flex(Line $line, ?float $available, float $gap): void
    {
        $items = array_values(array_filter($line->items, function (Item $item) { return !$item->collapsed;
        }));
        if ($available === null) {
            foreach ($items as $item) {
                $item->size = self::clamp($item->base, $item->min, $item->max);
            }
            return;
        }
        $available -= max(0, count($items) - 1) * $gap;
        $hypothetical = 0;
        foreach ($items as $item) {
            $hypothetical += $item->outerMain(self::clamp($item->base, $item->min, $item->max));
        }
        $grow = $hypothetical < $available;
        $frozen = [];
        foreach ($items as $i => $item) {
            $item->size = $item->base;
            $hyp = self::clamp($item->base, $item->min, $item->max);
            $frozen[$i] = ($grow ? $item->grow : $item->shrink) == 0
                || ($grow ? $item->base > $hyp : $item->base < $hyp);
            if ($frozen[$i]) {
                $item->size = $hyp;
            }
        }
        $remaining = function () use ($items, &$frozen, $available) {
            $space = $available;
            foreach ($items as $i => $item) {
                $space -= $item->outerMain($frozen[$i] ? $item->size : $item->base);
            }
            return $space;
        };
        $initial = $remaining();
        // Every pass freezes at least one item, including zero-violation passes.
        for ($pass = 0; $pass <= count($items) && in_array(false, $frozen, true); $pass++) {
            $space = $remaining();
            $factorSum = 0;
            $scaledSum = 0;
            foreach ($items as $i => $item) {
                if (!$frozen[$i]) {
                    $factorSum += $grow ? $item->grow : $item->shrink;
                    $scaledSum += $item->shrink * max(0, $item->base);
                }
            }
            if ($factorSum < 1 && abs($initial * $factorSum) < abs($space)) {
                $space = $initial * $factorSum;
            }
            $violations = [];
            foreach ($items as $i => $item) {
                if ($frozen[$i]) {
                    continue;
                }
                $weight = $grow ? $item->grow : $item->shrink * max(0, $item->base);
                $denominator = $grow ? $factorSum : $scaledSum;
                $target = $item->base + ($denominator > 0 ? $space * $weight / $denominator : 0);
                $item->size = self::clamp($target, max(0, $item->min), $item->max);
                $violations[$i] = $item->size - $target;
            }
            $total = array_sum($violations);
            foreach ($violations as $i => $violation) {
                if (abs($total) < self::EPSILON || ($total > 0 ? $violation > 0 : $violation < 0)) {
                    $frozen[$i] = true;
                }
            }
        }
    }

    /** Alignment offset and additional space BETWEEN subjects (never edge gaps). */
    private function distribution(string $mode, float $space, int $count): array
    {
        if ($mode === 'flex-end') { return [$space, 0]; }
        if ($mode === 'center') { return [$space / 2, 0]; }
        if ($mode === 'space-between' && $count > 1 && $space > 0) { return [0, $space / ($count - 1)]; }
        if ($mode === 'space-around') { return [$space / max(1, $count) / 2, max(0, $space) / max(1, $count)]; }
        if ($mode === 'space-evenly') { return [$space / ($count + 1), max(0, $space) / ($count + 1)]; }
        return [0, 0];
    }

    /**
     * Cross measurements are supplied after children are measured at final main sizes.
     * Mutates only the Result returned by main(), never the original measurements.
     */
    public function align(
        Result $result,
        ?float $cross,
        bool $wrap,
        float $mainGap,
        float $crossGap,
        string $justify,
        string $alignContent,
        bool $reverse,
        bool $wrapReverse
    ): void {
        foreach ($result->lines as $line) {
            $descent = 0;
            foreach ($line->items as $item) {
                $line->cross = max($line->cross, $item->outerCross());
                if ($item->align === 'baseline' && $item->crossStart !== null && $item->crossEnd !== null) {
                    $line->baseline = max($line->baseline, $item->crossStart + $item->baseline);
                    $descent = max($descent, $item->outerCross() - $item->crossStart - $item->baseline);
                }
            }
            $line->cross = max($line->cross, $line->baseline + $descent);
        }
        if (!$wrap && isset($result->lines[0]) && $cross !== null) {
            $result->lines[0]->cross = $cross;
        }
        $count = count($result->lines);
        $natural = array_sum(array_map(function (Line $line) { return $line->cross;
        }, $result->lines)) + max(0, $count - 1) * $crossGap;
        $result->cross = $cross ?? $natural;
        $space = $result->cross - $natural;
        if ($wrap && $alignContent === 'stretch' && $space > 0 && $count) {
            foreach ($result->lines as $line) { $line->cross += $space / $count; }
            $space = 0;
        }
        list($cursor, $between) = $this->distribution($wrap ? $alignContent : 'flex-start', $space, $count);
        foreach ($result->lines as $line) {
            $line->position = $wrapReverse ? $result->cross - $cursor - $line->cross : $cursor;
            $cursor += $line->cross + $crossGap + $between;
            $active = array_values(array_filter($line->items, function (Item $item) { return !$item->collapsed;
            }));
            $free = $result->main - max(0, count($active) - 1) * $mainGap;
            $auto = 0;
            foreach ($active as $item) {
                $free -= $item->outerMain($item->size);
                $auto += (int) ($item->mainStart === null) + (int) ($item->mainEnd === null);
            }
            $autoSize = $free > 0 && $auto ? $free / $auto : 0;
            list($position, $extra) = $this->distribution($justify, $autoSize ? 0 : $free, count($active));
            foreach ($line->items as $item) {
                if ($item->collapsed) { continue; }
                $start = $item->mainStart ?? $autoSize;
                $end = $item->mainEnd ?? $autoSize;
                // Positions refer to the margin-box origin; resolved margins are retained.
                $item->mainStart = $start;
                $item->mainEnd = $end;
                $outer = $item->outerMain($item->size);
                $item->mainPosition = $reverse ? $result->main - $position - $outer : $position;
                $position += $outer + $mainGap + $extra;
                if ($item->crossAuto && $item->align === 'stretch' && $item->crossStart !== null && $item->crossEnd !== null) {
                    $item->cross = self::clamp($line->cross - $item->crossDecoration - $item->crossStart - $item->crossEnd,
                        $item->crossMin, $item->crossMax);
                }
                $left = $line->cross - $item->outerCross();
                if ($item->crossStart === null || $item->crossEnd === null) {
                    $autos = (int) ($item->crossStart === null) + (int) ($item->crossEnd === null);
                    $item->crossStart = $item->crossStart ?? max(0, $left) / $autos;
                    $item->crossEnd = $item->crossEnd ?? ($left >= 0 ? $left / $autos : $left);
                    $offset = 0;
                } else {
                    $offset = $item->align === 'center' ? $left / 2 : ($item->align === 'flex-end' ? $left : 0);
                    if ($item->align === 'baseline') { $offset = $line->baseline - $item->crossStart - $item->baseline; }
                    if ($wrapReverse && $item->align !== 'center' && $item->align !== 'baseline') { $offset = $left - $offset; }
                }
                $item->crossPosition = $line->position + $offset;
            }
        }
    }
}
