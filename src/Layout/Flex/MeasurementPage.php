<?php
namespace Dompdf\Layout\Flex;

use Dompdf\Frame;
use Dompdf\FrameDecorator\Page;

/** A detached page: measuring never fragments or emits a page. */
final class MeasurementPage extends Page
{
    public function check_forced_page_break(Frame $frame) { return false; }
    public function check_page_break(Frame $frame) { return false; }
    public function get_bottom_page_edge(): float { return INF; }
    public function is_full() { return false; }
}
