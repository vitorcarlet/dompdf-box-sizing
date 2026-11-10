<?php
namespace Dompdf\Tests\LayoutTest;

use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Tests\TestCase;

class FlexTest extends TestCase
{
    private function render(string $body, string $css = '', bool $enabled = true): array
    {
        $frames = [];
        $text = [];
        $dompdf = new Dompdf(['isFlexboxEnabled' => $enabled]);
        $dompdf->setCallbacks([['event' => 'begin_frame', 'f' => function (AbstractFrameDecorator $frame) use (&$frames, &$text, $dompdf) {
            if ($frame->is_text_node()) { $text[] = $frame->get_node()->nodeValue; }
            $node = $frame->get_node();
            if ($node instanceof \DOMElement && ($id = $node->getAttribute('id') ?: $node->getAttribute('data-dompdf-original-id'))) {
                $frames[$id][] = ['box' => $frame->get_border_box(), 'content' => $frame->get_content_box(), 'page' => $dompdf->getCanvas()->get_page_number()];
            }
        }]]);
        $dompdf->loadHtml('<html><head><style>@page{size:400pt 300pt;margin:0}body{margin:0}.flex{display:flex}' . $css . '</style></head><body>' . $body . '</body></html>');
        $dompdf->render();
        return [$frames, $dompdf->getCanvas()->get_page_count(), implode(' ', $text)];
    }

    private function box(array $frames, string $id): array { return $frames[$id][0]['box']; }

    public function testGrowAndGapWithBorderBox(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a">A</div><div id="b">B</div></div>',
            '.flex{gap:20pt} .flex>div{flex:1;min-width:0;height:50pt;box-sizing:border-box;padding:5pt;border:2pt solid}');
        $this->assertEqualsWithDelta(190, $this->box($frames, 'a')['w'], 0.001);
        $this->assertEqualsWithDelta(210, $this->box($frames, 'b')['x'], 0.001);
        $this->assertEqualsWithDelta(50, $this->box($frames, 'b')['h'], 0.001);
    }

    public function testWrappingAndIndependentRowColumnGaps(): void
    {
        list($frames) = $this->render('<div id="parent" class="flex"><div id="a"></div><div id="b"></div><div id="c"></div></div>',
            '.flex{width:210pt;flex-wrap:wrap;gap:20pt 10pt}.flex>div{width:100pt;height:30pt}');
        $this->assertEqualsWithDelta(110, $this->box($frames, 'b')['x'], 0.001);
        $this->assertEqualsWithDelta(50, $this->box($frames, 'c')['y'], 0.001);
        $this->assertEqualsWithDelta(80, $this->box($frames, 'parent')['h'], 0.001);
    }

    public function testColumnReverseAndOrder(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div><div id="b"></div></div>',
            '.flex{height:200pt;flex-direction:column-reverse;gap:10pt}.flex>div{height:40pt}#a{order:2}');
        $this->assertEqualsWithDelta(160, $this->box($frames, 'b')['y'], 0.001);
        $this->assertEqualsWithDelta(110, $this->box($frames, 'a')['y'], 0.001);
    }

    public function testNestedFlexTextAndInlineBlockification(): void
    {
        list($frames) = $this->render('<div class="flex"><span id="a">longer text content wraps at the final allocated width</span><div id="b" class="flex"><div id="c"></div><div id="d"></div></div></div>',
            '.flex{gap:10pt}.flex>span,.flex>div{flex:1;min-width:0}#c,#d{height:30pt}');
        $this->assertEqualsWithDelta(195, $this->box($frames, 'a')['w'], 0.001);
        $this->assertEqualsWithDelta(205, $this->box($frames, 'b')['x'], 0.001);
        $this->assertEqualsWithDelta(92.5, $this->box($frames, 'c')['w'], 0.001);
        $this->assertGreaterThan(10, $this->box($frames, 'a')['h']);
    }

    public function testAutoMarginsAndNoMarginCollapse(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div><div id="b"></div></div>',
            '.flex{height:100pt;align-items:flex-start}.flex>div{width:50pt;height:20pt;margin-top:10pt}#b{margin-left:auto}');
        $this->assertEqualsWithDelta(350, $this->box($frames, 'b')['x'], 0.001);
        $this->assertEqualsWithDelta(10, $this->box($frames, 'a')['y'], 0.001);
    }

    public function testWrappedRowsContinueWithoutResizingOrLeadingGap(): void
    {
        $body = '<div class="flex">';
        for ($i = 0; $i < 8; $i++) { $body .= '<div id="item' . $i . '">' . $i . '</div>'; }
        $body .= '</div>';
        list($frames, $pages) = $this->render($body, '.flex{flex-wrap:wrap;gap:20pt}.flex>div{flex:0 0 190pt;height:100pt}');
        $this->assertSame(2, $pages);
        $this->assertSame(2, $frames['item4'][0]['page']);
        $this->assertEqualsWithDelta(0, $this->box($frames, 'item4')['y'], 0.001);
        $this->assertEqualsWithDelta(210, $this->box($frames, 'item5')['x'], 0.001);
        foreach (range(0, 7) as $i) {
            $this->assertCount(1, $frames['item' . $i]);
            $this->assertEqualsWithDelta(190, $this->box($frames, 'item' . $i)['w'], 0.001);
        }
    }

    public function testParallelTextItemsContinueOnTheSamePages(): void
    {
        $text = str_repeat('one two three four five six seven eight nine ten ', 50);
        list($frames, $pages) = $this->render('<div class="flex"><div id="a">' . $text . '</div><div id="b">' . $text . '</div></div>',
            '.flex{gap:20pt;align-items:flex-start}.flex>div{flex:1;min-width:0;font-size:10pt;line-height:12pt}');
        $this->assertGreaterThan(1, $pages);
        $this->assertCount($pages, $frames['a']);
        $this->assertCount($pages, $frames['b']);
        foreach ($frames['b'] as $fragment) {
            $this->assertEqualsWithDelta(210, $fragment['box']['x'], 0.001);
            $this->assertEqualsWithDelta(190, $fragment['box']['w'], 0.001);
            $this->assertLessThanOrEqual(300.001, $fragment['box']['y'] + $fragment['box']['h']);
        }
    }

    public function testImageAspectRatioAndPercentageChildren(): void
    {
        $image = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"><rect width="100" height="50" fill="red"/></svg>');
        list($frames) = $this->render('<div class="flex"><img id="image" src="' . $image . '"><div id="a"><div id="b" style="height:100%"></div></div></div>',
            '.flex{gap:20pt;align-items:flex-start}.flex>img{width:100pt}.flex>div{height:80pt;flex:1}');
        $this->assertEqualsWithDelta(50, $this->box($frames, 'image')['h'], 0.001);
        $this->assertEqualsWithDelta(80, $this->box($frames, 'b')['h'], 0.001);
    }

    public function testForcedBreakBetweenColumnItems(): void
    {
        list($frames, $pages) = $this->render('<div class="flex"><div id="a"></div><div id="b" style="page-break-before:always"></div></div>',
            '.flex{flex-direction:column;gap:20pt}.flex>div{height:30pt}');
        $this->assertSame(2, $pages);
        $this->assertSame(2, $frames['b'][0]['page']);
        $this->assertEqualsWithDelta(0, $this->box($frames, 'b')['y'], 0.001);
    }

    public function testMinimumHeightParticipatesInAlignment(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div></div>', '.flex{min-height:100pt;align-items:center}.flex>div{width:50pt;height:20pt}');
        $this->assertEqualsWithDelta(40, $this->box($frames, 'a')['y'], 0.001);
    }

    public function testExplicitMinimumWinsOverMaximum(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div><div id="b"></div></div>',
            '.flex>div{flex:1;height:20pt}#a{min-width:250pt;max-width:100pt}');
        $this->assertEqualsWithDelta(250, $this->box($frames, 'a')['w'], 0.001);
        $this->assertEqualsWithDelta(150, $this->box($frames, 'b')['w'], 0.001);
    }

    public function testTableAsFlexItemGetsAllocatedWidth(): void
    {
        list($frames) = $this->render('<div class="flex"><table id="a"><tr><td>Table</td></tr></table><div id="b">Block</div></div>',
            '.flex{gap:20pt;align-items:flex-start}.flex>table,.flex>div{flex:1;min-width:0}table{border-spacing:0}td{padding:0}');
        $this->assertEqualsWithDelta(190, $this->box($frames, 'a')['w'], 0.001);
        $this->assertEqualsWithDelta(210, $this->box($frames, 'b')['x'], 0.001);
    }

    public function testInlineFlexUsesIntrinsicWidth(): void
    {
        list($frames) = $this->render('<div id="parent" style="display:inline-flex;gap:10pt"><div style="width:50pt;height:20pt"></div><div style="width:80pt;height:20pt"></div></div>');
        $this->assertEqualsWithDelta(140, $this->box($frames, 'parent')['w'], 0.001);
    }

    public function testUnequalParallelItemsDoNotRepeatFinishedSibling(): void
    {
        $short = str_repeat('short text ', 5);
        $long = str_repeat('long text to continue across pages ', 100);
        list($frames, $pages, $textOutput) = $this->render('<div class="flex"><div id="a">' . $short . '</div><div id="b">' . $long . '</div></div><div id="after">After</div>',
            '.flex{gap:20pt;align-items:flex-start}.flex>div{flex:1;min-width:0;font-size:10pt;line-height:12pt}');
        $this->assertGreaterThan(1, $pages);
        $this->assertCount(1, $frames['a']);
        $this->assertGreaterThan(1, count($frames['b']));
        $this->assertSame($pages, $frames['after'][0]['page']);
        $this->assertSame(100, substr_count($textOutput, 'long'));
        $this->assertSame(5, substr_count($textOutput, 'short'));
    }

    public function testGeometryMatchesIndependentBrowserReference(): void
    {
        $base = __DIR__ . '/../_files/flex/browser';
        $reference = json_decode(file_get_contents($base . '.json'), true);
        $actual = [];
        $dompdf = new Dompdf(['isFlexboxEnabled' => true]);
        $dompdf->setCallbacks([['event' => 'begin_frame', 'f' => function ($frame) use (&$actual) {
            $node = $frame->get_node();
            if ($node instanceof \DOMElement && ($id = $node->getAttribute('id'))) {
                $box = $frame->get_border_box();
                $actual[$id] = [$box['x'], $box['y'], $box['w'], $box['h']];
            }
        }]]);
        $dompdf->loadHtmlFile($base . '.html');
        $dompdf->render();
        foreach ($reference['boxes'] as $id => $expected) {
            $this->assertArrayHasKey($id, $actual);
            $origin = $actual[$reference['cases'][$id]];
            $box = $actual[$id];
            $box[0] -= $origin[0];
            $box[1] -= $origin[1];
            foreach ($expected as $index => $value) {
                $this->assertEqualsWithDelta($value, $box[$index], 0.03, "$id coordinate $index");
            }
        }
    }

    public function testPageFitAccountsForPaddingAndBordersOfItems(): void
    {
        list($frames, $pages) = $this->render('<div style="height:220pt"></div><div class="flex"><div id="a" style="display:flex;flex-direction:column"><div>Top</div><div>Bottom</div></div><div id="b">Sibling</div></div>',
            '.flex{height:100pt;gap:20pt}.flex>div{flex:1;box-sizing:border-box;padding:10pt;border:1pt solid}');
        $this->assertSame(2, $pages);
        $this->assertSame(2, $frames['a'][0]['page']);
        $this->assertSame(2, $frames['b'][0]['page']);
        $this->assertEqualsWithDelta(0, $this->box($frames, 'a')['y'], 0.001);
    }

    public function testCountersAndGeneratedContentAreNotDuplicatedByMeasurement(): void
    {
        list($frames, $pages, $text) = $this->render('<div class="flex"><div id="a">First</div><div id="b">Second</div></div>',
            '.flex{counter-reset:item}.flex>div{flex:1;counter-increment:item}.flex>div::before{content:counter(item) ":"}#a{order:2}');
        $this->assertSame(1, $pages);
        $this->assertSame(1, substr_count($text, '1:'));
        $this->assertSame(1, substr_count($text, '2:'));
        $this->assertEqualsWithDelta(200, $this->box($frames, 'a')['x'], 0.001);
    }

    public function testAvoidKeepsContainerTogetherWhenItFitsOnFreshPage(): void
    {
        list($frames, $pages) = $this->render('<div style="height:250pt"></div><div class="flex"><div id="a">A</div><div id="b">B</div></div>',
            '.flex{page-break-inside:avoid;gap:20pt}.flex>div{flex:1;height:80pt}');
        $this->assertSame(2, $pages);
        $this->assertSame(2, $frames['a'][0]['page']);
        $this->assertSame(2, $frames['b'][0]['page']);
    }

    public function testCollapsedItemRetainsCrossSizeStrut(): void
    {
        list($frames) = $this->render('<div id="parent" class="flex"><div style="visibility:collapse;width:100pt;height:80pt"></div><div id="a"></div><div id="b"></div></div>',
            '.flex{gap:10pt}.flex>div{width:100pt;height:20pt}');
        $this->assertEqualsWithDelta(0, $this->box($frames, 'a')['x'], 0.001);
        $this->assertEqualsWithDelta(110, $this->box($frames, 'b')['x'], 0.001);
        $this->assertEqualsWithDelta(80, $this->box($frames, 'parent')['h'], 0.001);
    }

    public function testTallEmptyBoxFragmentsWithoutRepeatingItsContent(): void
    {
        list($frames, $pages, $text) = $this->render('<div class="flex"><div id="a">Once</div></div>',
            '.flex>div{height:700pt;flex:1;padding:10pt;border:2pt solid}');
        $this->assertSame(3, $pages);
        $this->assertCount(3, $frames['a']);
        $this->assertSame(1, substr_count($text, 'Once'));
        foreach ($frames['a'] as $fragment) {
            $this->assertLessThanOrEqual(300.001, $fragment['box']['y'] + $fragment['box']['h']);
        }
    }

    public function testDefaultStretchDoesNotPaintBeyondPageBoundary(): void
    {
        list($frames, $pages, $text) = $this->render('<div class="flex"><div id="a">Once</div><div id="b">' . str_repeat('long text to continue across pages ', 100) . '</div></div>',
            '.flex{gap:20pt}.flex>div{flex:1;min-width:0;font-size:10pt;line-height:12pt}');
        $this->assertGreaterThan(1, $pages);
        $this->assertSame(1, substr_count($text, 'Once'));
        $this->assertSame(100, substr_count($text, 'long'));
        foreach ($frames['a'] as $fragment) {
            $this->assertLessThanOrEqual(300.001, $fragment['box']['y'] + $fragment['box']['h']);
        }
    }

    public function testAbsoluteChildrenUseStaticAlignmentWithoutJoiningFlexFlow(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div><div id="absolute"></div></div>',
            '.flex{position:relative;height:100pt;align-items:center;justify-content:center}#a{width:100pt;height:20pt}#absolute{position:absolute;width:50pt;height:20pt}');
        $this->assertEqualsWithDelta(150, $this->box($frames, 'a')['x'], 0.001);
        $this->assertEqualsWithDelta(175, $this->box($frames, 'absolute')['x'], 0.001);
        $this->assertEqualsWithDelta(40, $this->box($frames, 'absolute')['y'], 0.001);
    }

    public function testAvoidBetweenColumnItemsKeepsAdjacentGroupsTogether(): void
    {
        list($frames, $pages) = $this->render('<div style="height:200pt"></div><div class="flex"><div id="a" style="page-break-after:avoid"></div><div id="b"></div></div>',
            '.flex{flex-direction:column;gap:20pt}.flex>div{height:80pt}');
        $this->assertSame(2, $pages);
        $this->assertSame(2, $frames['a'][0]['page']);
        $this->assertSame(2, $frames['b'][0]['page']);
    }

    public function testForcedBreakInsideItemDoesNotDropSiblingOrRepeatText(): void
    {
        list($frames, $pages, $text) = $this->render('<div class="flex"><div id="a"><div>First</div><div style="page-break-before:always">Second</div><div style="page-break-before:always">Third</div></div><div id="b">Sibling</div></div>',
            '.flex{gap:20pt;align-items:flex-start}.flex>div{flex:1;min-width:0}');
        $this->assertSame(3, $pages);
        $this->assertCount(1, $frames['b']);
        foreach (['First', 'Second', 'Third', 'Sibling'] as $word) {
            $this->assertSame(1, substr_count($text, $word));
        }
    }

    public function testDisabledOptionKeepsBlockFallback(): void
    {
        list($frames) = $this->render('<div class="flex"><div id="a"></div><div id="b"></div></div>', '.flex>div{height:30pt}', false);
        $this->assertEqualsWithDelta(30, $this->box($frames, 'b')['y'], 0.001);
    }
}
