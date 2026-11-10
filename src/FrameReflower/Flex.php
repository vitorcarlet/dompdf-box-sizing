<?php
namespace Dompdf\FrameReflower;

use Dompdf\FrameDecorator\Block as BlockFrame;
use Dompdf\FrameDecorator\Image;
use Dompdf\Helpers;
use Dompdf\Layout\Flex\Calculator;
use Dompdf\Layout\Flex\Item;
use Dompdf\Layout\Flex\Measurer;

/** Measure, solve, then commit flex geometry. The solver never accesses frames. */
class Flex extends Block
{
    private function length($style, $value, ?float $reference, float $width, bool $vertical)
    {
        if ($value === 'auto' || $value === 'content' || $value === 'none'
            || ($reference === null && Helpers::is_percent($value))) {
            return null;
        }
        return $style->resolve_content_box_size($value, $reference ?? 0, $width, $vertical);
    }

    private function gap($value, ?float $reference): float
    {
        if ($value === 'normal' || ($reference === null && Helpers::is_percent($value))) { return 0; }
        return max(0, (float) $this->_frame->get_style()->length_in_pt($value, $reference ?? 0));
    }

    /** Intrinsic sizing for inline-flex, nested flex, floats and table cells. */
    public function get_min_max_content_width(): array
    {
        $this->_frame->normalize();
        $style = $this->_frame->get_style();
        $row = strpos($style->flex_direction, 'row') === 0;
        $mins = [];
        $maxs = [];
        foreach ($this->_frame->get_children() as $child) {
            if ($child->is_absolute()) { continue; }
            $child->get_reflower()->_set_content();
            list($min, $max) = $child->get_min_max_width();
            $mins[] = $min;
            $maxs[] = $max;
        }
        $gap = $row ? max(0, count($maxs) - 1) * $this->gap($style->column_gap, null) : 0;
        return $row
            ? [$style->flex_wrap === 'nowrap' ? array_sum($mins) + $gap : max(array_merge([0], $mins)), array_sum($maxs) + $gap]
            : [max(array_merge([0], $mins)), max(array_merge([0], $maxs))];
    }

    public function get_min_max_child_width(): array
    {
        return $this->get_min_max_content_width();
    }

    public function reflow(?BlockFrame $block = null)
    {
        $frame = $this->_frame;
        $page = $frame->get_root();
        $page->check_forced_page_break($frame);
        if ($page->is_full()) { return; }
        $this->determine_absolute_containing_block();
        $this->_set_content();
        // Counters follow source order even when flex items have visual order.
        foreach ($frame->get_subtree() as $descendant) {
            if ($descendant !== $frame) { $descendant->get_reflower()->_set_content(); }
        }
        $frame->normalize();
        $this->_collapse_margins();
        $style = $frame->get_style();
        $cb = $frame->get_containing_block();
        list($width, $leftMargin, $rightMargin, $left, $right) = $this->_calculate_restricted_width();
        $style->set_used('width', $width);
        $style->set_used('margin_left', $leftMargin);
        $style->set_used('margin_right', $rightMargin);
        $style->set_used('left', $left);
        $style->set_used('right', $right);
        $assignment = $frame->get_flex_layout();
        foreach (['top', 'bottom'] as $side) {
            $style->set_used('margin_' . $side, $assignment['margin_' . $side]
                ?? (float) $style->length_in_pt($style->{'margin_' . $side}, $cb['w']));
        }
        $height = $assignment['height'] ?? $this->length($style, $style->height, $cb['h'], $cb['w'], true);
        if ($height !== null) { $height = Helpers::clamp($height, $this->resolve_min_height($cb['h']), $this->resolve_max_height($cb['h'])); }
        $frame->position();
        $style->set_used('height', $height ?? 0.0);
        $content = $frame->get_content_box();
        $row = strpos($style->flex_direction, 'row') === 0;
        $reverse = strpos($style->flex_direction, 'reverse') !== false;
        $wrap = $style->flex_wrap !== 'nowrap';
        $main = $row ? $width : $height;
        $cross = $row ? $height : $width;
        $mainGap = $this->gap($row ? $style->column_gap : $style->row_gap, $main);
        $crossGap = $this->gap($row ? $style->row_gap : $style->column_gap, $cross);
        $measurer = new Measurer();
        $items = [];
        $children = [];
        $absolute = [];
        foreach ($frame->get_children() as $child) {
            if ($child->is_absolute()) { $absolute[] = $child;
                continue; }
            $cs = $child->get_style();
            $cs->set_used('float', 'none');
            $natural = $measurer->measure($child, $width, $height);
            $item = new Item();
            $item->key = $child->get_id();
            $item->order = $cs->order;
            $item->grow = $cs->flex_grow;
            $item->shrink = $cs->flex_shrink;
            $item->align = $cs->align_self === 'auto' ? $style->align_items : $cs->align_self;
            $item->collapsed = $cs->visibility === 'collapse';
            $margins = [];
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $margins[$side] = $cs->{'margin_' . $side} === 'auto' ? null : (float) $cs->length_in_pt($cs->{'margin_' . $side}, $width);
            }
            $horizontal = (float) $cs->length_in_pt([$cs->padding_left, $cs->padding_right, $cs->border_left_width, $cs->border_right_width], $width);
            $vertical = (float) $cs->length_in_pt([$cs->padding_top, $cs->padding_bottom, $cs->border_top_width, $cs->border_bottom_width], $width);
            $item->mainDecoration = $row ? $horizontal : $vertical;
            $item->crossDecoration = $row ? $vertical : $horizontal;
            $item->mainStart = $margins[$row ? ($reverse ? 'right' : 'left') : ($reverse ? 'bottom' : 'top')];
            $item->mainEnd = $margins[$row ? ($reverse ? 'left' : 'right') : ($reverse ? 'top' : 'bottom')];
            $item->crossStart = $margins[$row ? 'top' : 'left'];
            $item->crossEnd = $margins[$row ? 'bottom' : 'right'];
            $dimension = $row ? 'width' : 'height';
            $crossDimension = $row ? 'height' : 'width';
            $preferred = $this->length($cs, $cs->$dimension, $main, $width, !$row);
            $basis = $cs->flex_basis === 'auto' ? $preferred : $this->length($cs, $cs->flex_basis, $main, $width, !$row);
            $intrinsic = max(0, $natural['max'] - $horizontal - ($margins['left'] ?? 0) - ($margins['right'] ?? 0));
            if (!$row && $cs->width === 'auto'
                && ($item->align !== 'stretch' || $item->crossStart === null || $item->crossEnd === null)
            ) {
                $shrinkWidth = max(0, min($intrinsic, $width - $horizontal - ($margins['left'] ?? 0) - ($margins['right'] ?? 0)));
                $natural = $measurer->measure($child, $width, $height, $shrinkWidth);
            }
            $item->base = $basis ?? ($row ? ($child instanceof Image ? $natural['width'] : $intrinsic) : $natural['height']);
            $minContent = $row ? max(0, $natural['min'] - $horizontal - ($margins['left'] ?? 0) - ($margins['right'] ?? 0)) : $natural['height'];
            $minProperty = $cs->{'min_' . $dimension};
            $item->min = $this->length($cs, $minProperty, $main, $width, !$row)
                ?? ($cs->overflow === 'visible' ? min($minContent, $preferred ?? INF) : 0);
            $item->max = $this->length($cs, $cs->{'max_' . $dimension}, $main, $width, !$row) ?? INF;
            if ($minProperty === 'auto') { $item->min = min($item->min, $item->max); }
            $item->crossMin = $this->length($cs, $cs->{'min_' . $crossDimension}, $cross, $width, $row) ?? 0;
            $item->crossMax = $this->length($cs, $cs->{'max_' . $crossDimension}, $cross, $width, $row) ?? INF;
            $item->crossAuto = $this->length($cs, $cs->$crossDimension, $cross, $width, $row) === null;
            $items[] = $item;
            $children[$item->key] = $child;
        }
        $calculator = new Calculator();
        if (!$row && $main === null) {
            $naturalMain = max(0, count($items) - 1) * $mainGap;
            foreach ($items as $item) { $naturalMain += $item->outerMain(Helpers::clamp($item->base, $item->min, $item->max)); }
            $limited = Helpers::clamp($naturalMain, $this->resolve_min_height($cb['h']), $this->resolve_max_height($cb['h']));
            if ($limited !== $naturalMain) { $main = $limited; }
        }
        $struts = [];
        if (array_filter($items, function (Item $item) { return $item->collapsed; })) {
            $visible = array_map(function (Item $item) { $copy = clone $item;
                $copy->collapsed = false;
                return $copy;
            }, $items);
            $firstPass = $calculator->main($visible, $main, $wrap, $mainGap);
            foreach ($firstPass->lines as $line) {
                foreach ($line->items as $item) {
                    $measured = $measurer->measure($children[$item->key], $width, $height, $row ? $item->size : null, $row ? null : $item->size);
                    $item->cross = $measured[$row ? 'height' : 'width'];
                    $item->baseline = $measured['baseline'];
                }
            }
            $calculator->align($firstPass, $cross, $wrap, $mainGap, $crossGap, $style->justify_content, $style->align_content, $reverse, $style->flex_wrap === 'wrap-reverse');
            foreach ($firstPass->lines as $line) {
                foreach ($line->items as $item) { $struts[$item->key] = $line->cross; }
            }
        }
        $result = $calculator->main($items, $main, $wrap, $mainGap);
        foreach ($result->lines as $line) {
            foreach ($line->items as $item) {
                $child = $children[$item->key];
                if ($item->collapsed) {
                    $item->cross = max(0, ($struts[$item->key] ?? 0) - $item->crossDecoration - ($item->crossStart ?? 0) - ($item->crossEnd ?? 0));
                    continue;
                }
                $crossWidth = null;
                if (!$row && $child->get_style()->width === 'auto'
                    && ($item->align !== 'stretch' || $item->crossStart === null || $item->crossEnd === null)
                ) {
                    $intrinsic = $measurer->measure($child, $width, $height);
                    $crossWidth = max(0, min($intrinsic['max'] - $item->crossDecoration,
                        $width - $item->crossDecoration - ($item->crossStart ?? 0) - ($item->crossEnd ?? 0)));
                }
                $measured = $measurer->measure($child, $width, $height, $row ? $item->size : $crossWidth, $row ? null : $item->size);
                $item->cross = Helpers::clamp($measured[$row ? 'height' : 'width'], $item->crossMin, $item->crossMax);
                $item->baseline = $measured['baseline'];
            }
        }
        if ($row && $cross === null) {
            $naturalCross = 0;
            foreach ($result->lines as $line) {
                $lineCross = 0;
                foreach ($line->items as $item) { $lineCross = max($lineCross, $item->outerCross()); }
                $naturalCross += $lineCross;
            }
            $naturalCross += max(0, count($result->lines) - 1) * $crossGap;
            $limited = Helpers::clamp($naturalCross, $this->resolve_min_height($cb['h']), $this->resolve_max_height($cb['h']));
            if ($limited !== $naturalCross) { $cross = $limited; }
        }
        $calculator->align($result, $cross, $wrap, $mainGap, $crossGap, $style->justify_content, $style->align_content, $reverse, $style->flex_wrap === 'wrap-reverse');
        $usedHeight = $row ? $result->cross : $result->main;
        $usedHeight = Helpers::clamp($usedHeight, $this->resolve_min_height($cb['h']), $this->resolve_max_height($cb['h']));
        $style->set_used('height', $usedHeight);
        $records = [];
        foreach ($result->lines as $lineIndex => $line) {
            foreach ($line->items as $item) {
                $child = $children[$item->key];
                if ($item->collapsed) { continue; }
                $top = $row ? $line->position : $item->mainPosition;
                $records[] = [
                    'frame' => $child,
                    'group' => $row ? $lineIndex : $item->key,
                    'top' => $top,
                    'offset' => $row ? $item->crossPosition - $top : 0.0,
                    'decoration' => $row ? $item->crossDecoration : $item->mainDecoration,
                    'stretch' => $row && $item->crossAuto && $item->align === 'stretch',
                    'geometry' => [
                        'x' => $row ? $item->mainPosition : $item->crossPosition,
                        'y' => $row ? $item->crossPosition : $item->mainPosition,
                        'width' => $row ? $item->size : $item->cross,
                        'height' => $row ? $item->cross : $item->size,
                        'margin_left' => $row ? ($reverse ? $item->mainEnd : $item->mainStart) : $item->crossStart,
                        'margin_right' => $row ? ($reverse ? $item->mainStart : $item->mainEnd) : $item->crossEnd,
                        'margin_top' => $row ? $item->crossStart : ($reverse ? $item->mainEnd : $item->mainStart),
                        'margin_bottom' => $row ? $item->crossEnd : ($reverse ? $item->mainStart : $item->mainEnd)
                    ]
                ];
            }
        }
        if ($frame->continuationLayout !== null) {
            $records = $this->restore_continuation($frame->continuationLayout, $content, $width, $height, $row ? $crossGap : $mainGap);
            $usedHeight = $this->records_height($records);
            $style->set_used('height', $usedHeight);
        }
        if ($style->page_break_inside === 'avoid'
            && $usedHeight <= $page->get_flex_page_bottom($frame) - (float) $page->get_style()->length_in_pt($page->get_style()->margin_top, $cb['h'])
        ) {
            $page->check_page_break($frame);
            if ($page->is_full()) { return; }
        }
        if (!$this->commit($records, $content, $width, $height, $usedHeight)) {
            // The physical fragment still contributes its occupied height to normal flow.
            if ($block && $frame->get_position('x') !== null) {
                $block->add_frame_to_line($frame);
                if ($frame->is_block_level()) { $block->add_line(); }
            }
            return;
        }
        foreach ($absolute as $child) {
            $frame->append_child($child, false);
            $child->set_containing_block($content['x'], $content['y'], $width, $usedHeight);
            $child->get_reflower()->determine_absolute_containing_block();
            $this->absolute_static_position($child, $measurer, $content, $width, $usedHeight, $row, $reverse);
            $child->reflow();
        }
        if ($block && $frame->is_in_flow()) {
            $block->add_frame_to_line($frame);
            if ($frame->is_block_level()) { $block->add_line(); }
        }
    }
    private function alignment_offset(string $alignment, float $space): float
    {
        if ($alignment === 'flex-end') { return $space; }
        return in_array($alignment, ['center', 'space-around', 'space-evenly'], true) ? $space / 2 : 0.0;
    }

    /** Absolutely positioned children use alignment only for their static position. */
    private function absolute_static_position(
        $child,
        Measurer $measurer,
        array $content,
        float $width,
        float $height,
        bool $row,
        bool $reverse
    ): void {
        $style = $this->_frame->get_style();
        $cs = $child->get_style();
        $measured = $measurer->measure($child, $width, $height);
        $horizontal = (float) $cs->length_in_pt([$cs->margin_left, $cs->margin_right,
            $cs->padding_left, $cs->padding_right, $cs->border_left_width, $cs->border_right_width], $width);
        $vertical = (float) $cs->length_in_pt([$cs->margin_top, $cs->margin_bottom,
            $cs->padding_top, $cs->padding_bottom, $cs->border_top_width, $cs->border_bottom_width], $width);
        $freeX = $width - $measured['width'] - $horizontal;
        $freeY = $height - $measured['height'] - $vertical;
        $align = $cs->align_self === 'auto' ? $style->align_items : $cs->align_self;
        $main = $this->alignment_offset($style->justify_content, $row ? $freeX : $freeY);
        $cross = $this->alignment_offset($align, $row ? $freeY : $freeX);
        if ($reverse) { $main = ($row ? $freeX : $freeY) - $main; }
        $cb = $child->get_containing_block();
        if ($cs->left === 'auto' && $cs->right === 'auto') {
            $cs->set_used('left', $content['x'] + ($row ? $main : $cross) - $cb['x']);
        }
        if ($cs->top === 'auto' && $cs->bottom === 'auto') {
            $cs->set_used('top', $content['y'] + ($row ? $cross : $main) - $cb['y']);
        }
    }

    private function records_height(array $records): float
    {
        $height = 0;
        foreach ($records as $record) {
            $g = $record['geometry'];
            $height = max($height, $g['y'] + $g['height'] + $g['margin_top'] + $g['margin_bottom'] + $record['decoration']);
        }
        return $height;
    }

    /** Retain solved widths and visual groups; remeasure only unfinished content. */
    private function restore_continuation(array $records, array $content, float $width, ?float $height, float $gap): array
    {
        $measurer = new Measurer();
        $groups = [];
        foreach ($records as $record) { $groups[$record['group']][] = $record; }
        uasort($groups, function ($a, $b) { return $a[0]['top'] <=> $b[0]['top'];
        });
        $cursor = 0;
        $restored = [];
        foreach ($groups as $group) {
            $groupHeight = 0;
            foreach ($group as $record) {
                $g = $record['geometry'];
                if ($g['height'] === null) {
                    $measurement = $measurer->measure($record['frame'], $width, $height, $g['width']);
                    $g['height'] = $measurement['height'];
                }
                $g['y'] = $cursor + $record['offset'];
                $record['top'] = $cursor;
                $record['geometry'] = $g;
                $groupHeight = max($groupHeight, $record['offset'] + $g['height'] + $g['margin_top'] + $g['margin_bottom'] + $record['decoration']);
                $restored[] = $record;
            }
            $cursor += $groupHeight + $gap;
        }
        return $restored;
    }

    /** Fragment unused fixed/stretched box space even after its text has ended. */
    private function fragment_box_tail(array $record, float $pageBottom, float $width): void
    {
        $child = $record['frame'];
        if ($child->is_split || $child instanceof Image || $child instanceof \Dompdf\FrameDecorator\Table
            || !is_finite($pageBottom)
            || $child->get_position('y') + $child->get_margin_height() <= $pageBottom + 0.001
        ) { return; }
        $style = $child->get_style();
        $top = (float) $style->length_in_pt([$style->padding_top, $style->border_top_width], $width);
        $available = max(0, $pageBottom - $child->get_position('y') - (float) $style->margin_top - $top);
        $remaining = (float) $style->height - $available;
        if ($available <= 0 || $remaining <= 0) { return; }
        $continuation = $child->copy($child->get_node()->cloneNode());
        $continuation->is_split_off = true;
        $child->is_split = true;
        foreach (['margin_top', 'padding_top', 'border_top_width', 'border_top_left_radius', 'border_top_right_radius', 'counter_reset', 'counter_increment'] as $prop) {
            $continuation->get_style()->set_prop($prop, in_array($prop, ['counter_reset', 'counter_increment'], true) ? 'none' : '0');
        }
        foreach (['margin_bottom', 'padding_bottom', 'border_bottom_width', 'border_bottom_left_radius', 'border_bottom_right_radius'] as $prop) {
            $style->set_prop($prop, '0');
        }
        $style->set_used('height', $available);
        $record['frame'] = $continuation;
        $record['geometry']['height'] = $remaining;
        $record['geometry']['margin_top'] = 0.0;
        $record['offset'] = 0.0;
        $record['decoration'] -= $top;
        $this->_frame->pendingFragments[] = $record;
    }

    /** Commit siblings independently so a text split does not push the whole row. */
    private function commit(array $records, array $content, float $width, ?float $height, float $usedHeight): bool
    {
        $frame = $this->_frame;
        $page = $frame->get_root();
        $style = $frame->get_style();
        $frame->fragmentContext = [];
        $frame->pendingFragments = [];
        $groups = [];
        foreach ($records as $record) {
            $frame->fragmentContext[$record['frame']->get_id()] = $record;
            $groups[$record['group']][] = $record;
        }
        uasort($groups, function ($a, $b) { return $a[0]['top'] <=> $b[0]['top'];
        });
        // Make frame order deterministic before any split; DOM order remains intact.
        foreach ($groups as $group) {
            foreach ($group as $record) { $frame->append_child($record['frame'], false); }
        }
        $pageTop = (float) $page->get_style()->length_in_pt($page->get_style()->margin_top, $page->get_containing_block('h'));
        $pageBottom = $page->get_flex_page_bottom($frame);
        $merged = [];
        foreach ($groups as $group) {
            $last = count($merged) - 1;
            if ($last >= 0) {
                $previous = $merged[$last];
                $lastFrame = $previous[count($previous) - 1]['frame'];
                $nextFrame = $group[0]['frame'];
                $avoid = $lastFrame->get_style()->page_break_after === 'avoid'
                    || $nextFrame->get_style()->page_break_before === 'avoid';
                $forced = $lastFrame->get_style()->page_break_after === 'always'
                    || $nextFrame->get_style()->page_break_before === 'always';
                $combined = array_merge($previous, $group);
                if ($avoid && !$forced && $this->records_height($combined) - $previous[0]['top'] <= $pageBottom - $pageTop) {
                    $merged[$last] = $combined;
                    continue;
                }
            }
            $merged[] = $group;
        }
        $groups = $merged;
        $defer = false;
        $committedHeight = 0;
        $previousBreak = false;
        foreach ($groups as $group) {
            $groupBottom = $content['y'];
            $forced = $previousBreak;
            foreach ($group as $record) {
                $g = $record['geometry'];
                $groupBottom = max($groupBottom, $content['y'] + $g['y'] + $g['height'] + $g['margin_top'] + $g['margin_bottom'] + $record['decoration']);
                $forced = $forced || $record['frame']->get_style()->page_break_before === 'always';
            }
            $groupTop = $content['y'] + $group[0]['top'];
            $fitsFresh = $groupBottom - $groupTop <= $pageBottom - $pageTop + 0.001;
            if (($forced || ($fitsFresh && $groupBottom > $pageBottom + 0.001))
                && $groupTop > $pageTop + 0.001
            ) { $defer = true; }
            if ($defer) {
                foreach ($group as $record) {
                    $frame->pendingFragments[] = $record;
                    $record['frame']->get_style()->set_used('page_break_before', 'auto');
                }
                continue;
            }
            foreach ($group as $record) {
                $child = $record['frame'];
                $g = $record['geometry'];
                $g['x'] += $content['x'];
                $g['y'] += $content['y'];
                $child->set_containing_block($content['x'], $content['y'], $width, $height);
                $child->set_flex_layout($g);
                foreach (['left', 'right', 'top', 'bottom'] as $side) {
                    $child->get_style()->set_used('margin_' . $side, $g['margin_' . $side]);
                }
                // Forced breaks between flex items are handled at group boundaries.
                $child->get_style()->set_used('page_break_before', 'auto');
                $pendingBefore = count($frame->pendingFragments);
                $child->reflow();
                if ($page->is_full()) {
                    if (count($frame->pendingFragments) === $pendingBefore) {
                        $frame->fragmentContext = null;
                        return false;
                    }
                    $page->resume_flex_layout();
                }
                if ($child->get_parent() === $frame) {
                    $this->fragment_box_tail($record, $pageBottom, $width);
                    $committedHeight = max($committedHeight, $child->get_position('y') + $child->get_margin_height() - $content['y']);
                    $this->position_relative($child);
                }
            }
            $previousBreak = false;
            foreach ($group as $record) {
                $previousBreak = $previousBreak || $record['frame']->get_style()->page_break_after === 'always';
                $record['frame']->get_style()->set_used('page_break_after', 'auto');
            }
        }
        $frame->fragmentContext = null;
        if ($frame->pendingFragments) {
            $frame->continuationLayout = $frame->pendingFragments;
            foreach ($frame->pendingFragments as $record) { $frame->append_child($record['frame'], false); }
            $first = $frame->pendingFragments[0]['frame'];
            $frame->pendingFragments = [];
            $frame->split($first, true, true);
            $frame->continuationLayout = null;
            $page->finish_flex_page();
            $style->set_used('height', $committedHeight);
        }
        $frame->get_current_line_box()->y = $content['y'];
        $frame->get_current_line_box()->h = $frame->is_split ? $committedHeight : $usedHeight;
        return !$page->is_full();
    }

}
