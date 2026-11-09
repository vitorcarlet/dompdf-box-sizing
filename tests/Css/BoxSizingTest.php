<?php
namespace Dompdf\Tests\Css;

use Dompdf\Css\Style;
use Dompdf\Css\Stylesheet;
use Dompdf\Dompdf;
use Dompdf\Tests\TestCase;

class BoxSizingTest extends TestCase
{
    public function testValuesAndCascade(): void
    {
        $sheet = new Stylesheet(new Dompdf());
        $parent = new Style($sheet);
        $parent->set_prop("box-sizing", "BORDER-BOX");
        $this->assertSame("border-box", $parent->box_sizing);

        $child = new Style($sheet);
        $child->inherit($parent);
        $this->assertSame("content-box", $child->box_sizing);
        $child->set_prop("box-sizing", "inherit");
        $child->inherit($parent);
        $this->assertSame("border-box", $child->box_sizing);

        $child->set_prop("box-sizing", "padding-box");
        $this->assertSame("border-box", $child->box_sizing);
        $child->set_prop("box-sizing", "initial");
        $this->assertSame("content-box", $child->box_sizing);
        $child->set_prop("box-sizing", "border-box");
        $child->set_prop("box-sizing", "unset");
        $child->inherit($parent);
        $this->assertSame("content-box", $child->box_sizing);
    }

    public function testUsedDimensionsDoNotChangeDeclaredSizes(): void
    {
        $style = new Style(new Stylesheet(new Dompdf()));
        $style->set_prop("box-sizing", "border-box");
        $style->set_prop("width", "200pt");
        $style->set_used("width", 176.0);
        $this->assertSame("200pt", $style->get_specified("width"));
        $style->reset();
        $this->assertSame(200.0, $style->width);
    }
}
