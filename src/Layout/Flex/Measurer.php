<?php
namespace Dompdf\Layout\Flex;

use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Block;

/** Detached, per-layout measurement cache. No live frame or counter is reflowed. */
final class Measurer
{
    private $cache = [];

    public function measure(AbstractFrameDecorator $frame, float $cbw, ?float $cbh, ?float $width = null, ?float $height = null): array
    {
        $key = $frame->get_id() . ':' . serialize([$cbw, $cbh, $width, $height]);
        if (isset($this->cache[$key])) { return $this->cache[$key]; }
        $dompdf = $frame->get_dompdf();
        $originalRoot = $frame->get_root();
        $rootFrame = new \Dompdf\Frame($originalRoot->get_node()->cloneNode());
        $rootFrame->set_style(clone $originalRoot->get_style());
        $root = new MeasurementPage($rootFrame, $dompdf);
        $root->set_root($root);
        $root->copy_counter_state($originalRoot);
        $root->set_containing_block(0, 0, $cbw, $cbh);
        $ancestors = [];
        for ($parent = $frame->get_parent(); $parent && $parent !== $originalRoot; $parent = $parent->get_parent()) {
            $ancestors[] = $parent;
        }
        $parent = $root;
        foreach (array_reverse($ancestors) as $ancestor) {
            $copy = $ancestor->copy($ancestor->get_node()->cloneNode());
            $copy->set_root($root);
            $copy->copy_counter_state($ancestor);
            $copy->set_containing_block(0, 0, $cbw, $cbh);
            $copy->set_position(0, 0);
            $parent->append_child($copy, false);
            $parent = $copy;
        }
        $copy = $frame->deep_copy();
        $copy->reset();
        foreach ($copy->get_subtree() as $descendant) { $descendant->set_root($root); }
        $parent->append_child($copy, false);
        $copy->set_containing_block(0, 0, $cbw, $cbh);
        $style = $copy->get_style();
        // Flex bases and automatic minimums need intrinsic content, independent
        // of the item's preferred width and its own min/max constraints.
        $declared = clone $style;
        $style->set_prop('width', 'auto');
        $style->set_prop('min_width', '0');
        $style->set_prop('max_width', 'none');
        $intrinsic = $copy->get_min_max_width();
        $copy->reset();
        $copy->set_style($declared);
        $style = $declared;
        $copy->set_containing_block(0, 0, $cbw, $cbh);
        $assignment = ['x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $height];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $assignment['margin_' . $side] = (float) $style->length_in_pt($style->{'margin_' . $side}, $cbw);
        }
        $copy->set_flex_layout($assignment);
        $copy->reflow();
        $box = $copy->get_content_box();
        // Synthesized baseline for empty/replaced items; text uses the first line.
        $baseline = $copy->get_margin_height() - $assignment['margin_bottom'];
        if ($copy instanceof Block) {
            foreach ($copy->get_line_boxes() as $line) {
                foreach ($line->get_frames() as $lineFrame) {
                    if ($lineFrame->is_text_node()) {
                        $textStyle = $lineFrame->get_style();
                        $baseline = $lineFrame->get_position('y')
                            + $dompdf->getFontMetrics()->getFontBaseline($textStyle->font_family, $textStyle->font_size)
                            - $copy->get_position('y') - $assignment['margin_top'];
                        break 2;
                    }
                }
            }
        }
        return $this->cache[$key] = [
            'min' => $intrinsic[0], 'max' => $intrinsic[1],
            'width' => $box['w'], 'height' => $box['h'], 'baseline' => $baseline
        ];
    }
}
