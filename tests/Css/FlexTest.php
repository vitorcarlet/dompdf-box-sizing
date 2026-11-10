<?php
namespace Dompdf\Tests\Css;

use Dompdf\Dompdf;
use Dompdf\Tests\TestCase;

class FlexTest extends TestCase
{
    public function testDisplayIsIsolatedByDocumentOption(): void
    {
        foreach ([false, true, false, true] as $enabled) {
            $dompdf = new Dompdf(['isFlexboxEnabled' => $enabled]);
            $style = $dompdf->getCss()->create_style();
            $style->set_prop('display', 'flex');
            $this->assertSame($enabled ? 'flex' : 'block', $style->display);
            $style->set_prop('display', 'inline-flex');
            $this->assertSame($enabled ? 'inline-flex' : 'inline-block', $style->display);
            $this->assertSame($enabled, $dompdf->getOptions()->get('isFlexboxEnabled'));
        }
    }

    public function testShorthandsAndInvalidDeclarations(): void
    {
        $style = (new Dompdf())->getCss()->create_style();
        $style->set_prop('flex', '2 3 calc(50% - 10pt)');
        $this->assertSame(2.0, $style->flex_grow);
        $this->assertSame(3.0, $style->flex_shrink);
        $this->assertSame('calc(50% - 10pt)', $style->flex_basis);
        $style->set_prop('flex', 'none');
        $this->assertSame(0.0, $style->flex_shrink);
        $this->assertSame('auto', $style->flex_basis);
        $style->set_prop('flex', '1');
        $this->assertSame('0%', $style->flex_basis);
        $style->set_prop('flex', '-2 3 20pt');
        $this->assertSame(1.0, $style->flex_grow);
        $style->set_prop('gap', '10pt calc(5% + 2pt)');
        $this->assertSame(10.0, $style->row_gap);
        $this->assertSame('calc(5% + 2pt)', $style->column_gap);
        $style->set_prop('gap', '-5pt');
        $this->assertSame(10.0, $style->row_gap);
        $style->set_prop('flex-flow', 'wrap column-reverse');
        $this->assertSame('wrap', $style->flex_wrap);
        $this->assertSame('column-reverse', $style->flex_direction);
    }
}
