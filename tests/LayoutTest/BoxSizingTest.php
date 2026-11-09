<?php
namespace Dompdf\Tests\LayoutTest;

use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Tests\TestCase;

class BoxSizingTest extends TestCase
{
    public static function dimensionsProvider(): array
    {
        $box = "width:200pt;height:80pt;padding:10pt;border:2pt solid;";
        $borderBox = $box . "box-sizing:border-box;";
        $image = "data:image/svg+xml;base64," . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="50"><rect width="100" height="50" fill="red"/></svg>');

        return [
            "default content box" => ['<div id="box" style="' . $box . '"></div>', ["box" => [200, 80, 224, 104]]],
            "explicit content box" => ['<div id="box" style="' . $box . 'box-sizing:content-box"></div>', ["box" => [200, 80, 224, 104]]],
            "border box" => ['<div id="box" style="' . $borderBox . '"></div>', ["box" => [176, 56, 200, 80]]],
            "asymmetric padding and borders" => ['<div id="box" style="box-sizing:border-box;width:200pt;height:80pt;padding:5pt 10pt 15pt 20pt;border-style:solid;border-width:1pt 2pt 3pt 4pt"></div>', ["box" => [164, 56, 200, 80]]],
            "calculated dimensions" => ['<div id="box" style="' . $borderBox . 'width:calc(50% - 10pt)"></div>', ["box" => [166, 56, 190, 80]]],
            "inline block" => ['<div id="box" style="' . $borderBox . 'display:inline-block"></div>', ["box" => [176, 56, 200, 80]]],
            "float" => ['<div id="box" style="' . $borderBox . 'float:left"></div>', ["box" => [176, 56, 200, 80]]],
            "absolute" => ['<div id="box" style="' . $borderBox . 'position:absolute;left:20pt;top:30pt"></div>', ["box" => [176, 56, 200, 80]]],
            "absolute minimum" => ['<div id="box" style="' . $borderBox . 'position:absolute;bottom:0;height:40pt;min-height:80pt"></div>', ["box" => [176, 56, 200, 80]]],
            "absolute maximum" => ['<div id="box" style="' . $borderBox . 'position:absolute;bottom:0;height:150pt;max-height:80pt"></div>', ["box" => [176, 56, 200, 80]]],
            "absolute auto width" => ['<div id="box" style="box-sizing:border-box;position:absolute;left:0;right:0;height:80pt;padding:10pt;border:2pt solid"></div>', ["box" => [376, 56, 400, 80]]],
            "fixed" => ['<div id="box" style="' . $borderBox . 'position:fixed;left:0;top:0"></div>', ["box" => [176, 56, 200, 80]]],
            "percentage size and padding" => ['<div id="box" style="box-sizing:border-box;width:50%;height:80pt;padding:5%;border:2pt solid"></div>', ["box" => [156, 36, 200, 80]]],
            "minimum sizes" => ['<div id="box" style="' . $borderBox . 'width:100pt;height:40pt;min-width:200pt;min-height:80pt"></div>', ["box" => [176, 56, 200, 80]]],
            "maximum sizes" => ['<div id="box" style="' . $borderBox . 'width:300pt;height:150pt;max-width:200pt;max-height:80pt"></div>', ["box" => [176, 56, 200, 80]]],
            "minimum wins" => ['<div id="box" style="' . $borderBox . 'min-width:200pt;max-width:100pt;min-height:80pt;max-height:30pt"></div>', ["box" => [176, 56, 200, 80]]],
            "nonnegative content" => ['<div id="box" style="' . $borderBox . 'width:10pt;height:10pt"></div>', ["box" => [0, 0, 24, 24]]],
            "none border" => ['<div id="box" style="' . $borderBox . 'border-style:none"></div>', ["box" => [180, 60, 200, 80]]],
            "auto sizes" => ['<div id="box" style="' . $borderBox . 'width:auto;height:auto"><div style="height:20pt"></div></div>', ["box" => [376, 20, 400, 44]]],
            "percentage child" => ['<div style="' . $borderBox . '"><div id="box" style="width:100%;height:100%"></div></div>', ["box" => [176, 56, 176, 56]]],
            "percentage child with maximum" => ['<div style="' . $borderBox . 'height:150pt;max-height:80pt"><div id="box" style="width:100%;height:100%"></div></div>', ["box" => [176, 56, 176, 56]]],
            "image width and height" => ['<img id="box" src="' . $image . '" style="' . $borderBox . '">', ["box" => [176, 56, 200, 80]]],
            "image automatic height" => ['<img id="box" src="' . $image . '" style="' . $borderBox . 'height:auto">', ["box" => [176, 88, 200, 112]]],
            "image automatic width" => ['<img id="box" src="' . $image . '" style="' . $borderBox . 'width:auto">', ["box" => [112, 56, 136, 80]]],
            "image maximum" => ['<img id="box" src="' . $image . '" style="' . $borderBox . 'max-width:100pt;max-height:50pt">', ["box" => [76, 26, 100, 50]]],
            "table default" => ['<table id="box" style="' . $box . 'border-spacing:0"><tr><td></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "table content box" => ['<table id="box" style="' . $box . 'box-sizing:content-box;border-spacing:0"><tr><td></td></tr></table>', ["box" => [200, 80, 224, 104]]],
            "table spacing" => ['<table id="box" style="' . $box . 'border-spacing:6pt 8pt"><tr><td></td></tr></table>', ["box" => [170, 48, 200, 80]]],
            "table content box spacing" => ['<table id="box" style="' . $box . 'box-sizing:content-box;border-spacing:6pt 8pt"><tr><td></td></tr></table>', ["box" => [194, 72, 224, 104]]],
            "table minimum" => ['<table id="box" style="' . $box . 'width:100pt;height:40pt;min-width:200pt;min-height:80pt;border-spacing:0"><tr><td></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "table maximum" => ['<table id="box" style="' . $box . 'width:300pt;height:150pt;max-width:200pt;max-height:80pt;border-spacing:0"><tr><td></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "cell border box" => ['<table style="border-spacing:0"><tr><td id="box" style="' . $borderBox . '"></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "cell content box" => ['<table style="border-spacing:0"><tr><td id="box" style="' . $box . '"></td></tr></table>', ["box" => [200, 80, 224, 104]]],
            "collapsed cell" => ['<table style="border-collapse:collapse"><tr><td id="box" style="' . $borderBox . '"></td></tr></table>', ["box" => [178, 58, 200, 80]]],
            "fixed layout cell" => ['<table style="width:400pt;table-layout:fixed;border-spacing:0"><tr><td id="box" style="' . $borderBox . '"></td><td id="remaining"></td></tr></table>', ["box" => [176, 56, 200, 80], "remaining" => [200, 80, 200, 80]]],
            "unequal fixed layout cells" => ['<table style="width:400pt;table-layout:fixed;border-spacing:0"><tr><td id="box" style="' . $borderBox . 'width:120pt"></td><td id="remaining"></td></tr></table>', ["box" => [96, 56, 120, 80], "remaining" => [280, 80, 280, 80]]],
            "percentage border box cell" => ['<table style="width:400pt;border-spacing:0"><tr><td id="box" style="' . $borderBox . 'width:50%"></td><td id="remaining"></td></tr></table>', ["box" => [176, 56, 200, 80], "remaining" => [200, 80, 200, 80]]],
            "percentage content box cell" => ['<table style="width:400pt;border-spacing:0"><tr><td id="box" style="' . $box . 'width:50%"></td><td id="remaining"></td></tr></table>', ["box" => [200, 80, 224, 104], "remaining" => [176, 104, 176, 104]]],
            "fixed colspan" => ['<table style="width:400pt;table-layout:fixed;border-spacing:0"><tr><td id="box" colspan="2" style="' . $borderBox . 'width:120pt"></td><td id="remaining"></td></tr><tr><td></td><td></td><td></td></tr></table>', ["box" => [96, 56, 120, 80], "remaining" => [280, 80, 280, 80]]],
            "rowspan" => ['<table style="border-spacing:0"><tr><td id="box" rowspan="2" style="' . $borderBox . '"></td><td></td></tr><tr><td></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "header cell" => ['<table style="border-spacing:0"><thead><tr><th id="box" style="' . $borderBox . '"></th></tr></thead></table>', ["box" => [176, 56, 200, 80]]],
            "cell minimum" => ['<table style="border-spacing:0"><tr><td id="box" style="' . $borderBox . 'width:100pt;height:40pt;min-width:200pt;min-height:80pt"></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "cell maximum" => ['<table style="border-spacing:0"><tr><td id="box" style="' . $borderBox . 'width:300pt;height:150pt;max-width:200pt;max-height:80pt"></td></tr></table>', ["box" => [176, 56, 200, 80]]],
            "cell vertical percentage padding" => ['<table style="width:200pt;border-spacing:0"><tr><td id="box" style="box-sizing:border-box;width:200pt;height:80pt;padding:5%;border:2pt solid"></td></tr></table>', ["box" => [176, 56, 200, 80]]],
        ];
    }

    /**
     * @dataProvider dimensionsProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dimensionsProvider')]
    public function testDimensions(string $body, array $expected): void
    {
        [$frames] = $this->render($body);
        foreach ($expected as $id => $dimensions) {
            $this->assertArrayHasKey($id, $frames);
            $actual = array_merge(array_slice($frames[$id]["content"], 2, 2), array_slice($frames[$id]["border"], 2, 2));
            foreach ($dimensions as $i => $value) {
                $this->assertEqualsWithDelta($value, $actual[$i], 0.001, "$id dimension $i");
            }
        }
    }

    public function testHalfWidthBoxesStayOnTheSameLine(): void
    {
        [$frames] = $this->render('<div id="a" class="half"></div><div id="b" class="half"></div>',
            '.half {display:inline-block;width:50%;height:80pt;box-sizing:border-box;padding:10pt;border:2pt solid}');
        $this->assertEqualsWithDelta(200, $frames["b"]["border"][0] - $frames["a"]["border"][0], 0.001);
        $this->assertEqualsWithDelta($frames["a"]["border"][1], $frames["b"]["border"][1], 0.001);
    }

    public function testTableRowPositionsFollowExpandedHeight(): void
    {
        [$frames] = $this->render('<table id="table" style="height:100pt;width:200pt;border-spacing:0"><tr><td id="a"><div style="height:10pt"></div></td></tr><tr><td id="b"><div style="height:10pt"></div></td></tr></table>');
        $this->assertEqualsWithDelta(50, $frames["a"]["border"][3], 0.001);
        $this->assertEqualsWithDelta(50, $frames["b"]["border"][1] - $frames["a"]["border"][1], 0.001);
        $this->assertEqualsWithDelta(100, $frames["table"]["border"][3], 0.001);
    }

    public function testBoxSizingIsReappliedAfterPageBreak(): void
    {
        [$frames, $pages] = $this->render('<div style="height:260pt"></div><div id="box" style="box-sizing:border-box;width:200pt;height:80pt;padding:10pt;border:2pt solid;page-break-inside:avoid"></div>');
        $this->assertSame(2, $pages);
        $this->assertEqualsWithDelta(200, $frames["box"]["border"][2], 0.001);
        $this->assertEqualsWithDelta(80, $frames["box"]["border"][3], 0.001);
    }

    public function testAbsoluteMaximumKeepsBottomOffset(): void
    {
        [$frames] = $this->render('<div id="box" style="position:absolute;bottom:0;box-sizing:border-box;width:200pt;height:150pt;max-height:80pt;padding:10pt;border:2pt solid"></div>');
        $this->assertEqualsWithDelta(220, $frames["box"]["border"][1], 0.001);
    }

    public function testTableContinuationKeepsColumnWidths(): void
    {
        $body = '<table style="width:400pt;table-layout:fixed;border-spacing:0"><thead><tr><th style="width:50%">Header</th><th>Header</th></tr></thead><tbody>';
        for ($i = 0; $i < 8; $i++) {
            $body .= '<tr><td id="row' . $i . '" style="box-sizing:border-box;width:50%;height:80pt;padding:10pt;border:2pt solid">Row</td><td>Data</td></tr>';
        }
        $body .= '</tbody></table>';
        [$frames, $pages] = $this->render($body);
        $this->assertGreaterThan(1, $pages);
        for ($i = 0; $i < 8; $i++) {
            $this->assertArrayHasKey("row$i", $frames);
            $this->assertEqualsWithDelta(176, $frames["row$i"]["content"][2], 0.001);
            $this->assertEqualsWithDelta(200, $frames["row$i"]["border"][2], 0.001);
            $this->assertEqualsWithDelta(80, $frames["row$i"]["border"][3], 0.001);
        }
    }

    private function render(string $body, string $css = ""): array
    {
        $frames = [];
        $dompdf = new Dompdf();
        $dompdf->setCallbacks([[
            "event" => "begin_frame",
            "f" => function (AbstractFrameDecorator $frame) use (&$frames): void {
                $node = $frame->get_node();
                if ($node instanceof \DOMElement) {
                    $id = $node->getAttribute("id") ?: $node->getAttribute("data-dompdf-original-id");
                    if ($id === "") {
                        return;
                    }
                    $frames[$id] = [
                        "content" => array_values(array_filter($frame->get_content_box(), "is_int", ARRAY_FILTER_USE_KEY)),
                        "border" => array_values(array_filter($frame->get_border_box(), "is_int", ARRAY_FILTER_USE_KEY))
                    ];
                }
            }
        ]]);
        $dompdf->loadHtml('<!DOCTYPE html><html><head><style>@page{size:400pt 300pt;margin:0}body{margin:0}td,th{padding:0}' . $css . '</style></head><body>' . $body . '</body></html>');
        $dompdf->render();
        return [$frames, $dompdf->getCanvas()->get_page_count()];
    }
}
