<?php
namespace Dompdf\FrameDecorator;

/** Flex containers retain the block interface used by descendant text layout. */
class Flex extends Block
{
    /** @var array|null Geometry retained across a page boundary. */
    public $continuationLayout;
    /** @var array|null Active assignments while committing a fragment. */
    public $fragmentContext;
    public $pendingFragments = [];

    public function copy(\DOMNode $node)
    {
        $copy = parent::copy($node);
        $copy->continuationLayout = $this->continuationLayout;
        return $copy;
    }

    /** Collect one item's continuation without pushing its unfinished siblings. */
    public function split(?\Dompdf\Frame $child = null, bool $page_break = false, bool $forced = false): void
    {
        if ($child !== null && $this->fragmentContext !== null) {
            // An existing continuation may itself be pushed before any content
            // fits. Only a newly created split-off lacks an active assignment.
            $record = $this->fragmentContext[$child->get_id()] ?? null;
            $newContinuation = $record === null && $child->is_split_off;
            if ($newContinuation && ($original = $child->get_prev_sibling())) {
                $record = $this->fragmentContext[$original->get_id()] ?? null;
            }
            if ($record !== null) {
                $record['frame'] = $child;
                if ($newContinuation) {
                    $record['geometry']['height'] = null;
                    $record['geometry']['margin_top'] = 0.0;
                    $cs = $child->get_style();
                    $record['decoration'] = (float) $cs->length_in_pt([$cs->padding_top, $cs->padding_bottom, $cs->border_top_width, $cs->border_bottom_width], (float) $this->get_style()->width);
                    $record['offset'] = 0.0;
                }
                $this->pendingFragments[] = $record;
                $this->remove_child($child, false);
                return;
            }
        }
        parent::split($child, $page_break, $forced);
    }

    /** Normalize only text runs. Element children were blockified by Factory. */
    public function normalize(): void
    {
        $run = [];
        $flush = function () use (&$run) {
            if (!$run) { return; }
            $text = '';
            foreach ($run as $child) { $text .= $child->get_node()->nodeValue; }
            if (trim($text, " \t\r\n\f") === '') {
                foreach ($run as $child) { $this->remove_child($child, false); }
            } else {
                $wrapper = $this->create_anonymous_child('flex-text', 'block');
                $this->insert_child_before($wrapper, $run[0], false);
                foreach ($run as $child) { $wrapper->append_child($child, false); }
            }
            $run = [];
        };
        foreach (iterator_to_array($this->get_children()) as $child) {
            if ($child->is_text_node()) { $run[] = $child; }
            else { $flush(); }
        }
        $flush();
    }
}
