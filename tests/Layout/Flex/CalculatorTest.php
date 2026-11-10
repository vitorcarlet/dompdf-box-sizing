<?php
namespace Dompdf\Tests\Layout\Flex;

use Dompdf\Layout\Flex\Calculator;
use Dompdf\Layout\Flex\Item;
use Dompdf\Tests\TestCase;

class CalculatorTest extends TestCase
{
    private function item(float $base, float $grow = 0, float $shrink = 1, float $min = 0, float $max = INF): Item
    {
        $item = new Item();
        $item->base = $base;
        $item->grow = $grow;
        $item->shrink = $shrink;
        $item->min = $min;
        $item->max = $max;
        return $item;
    }

    public function testGrowRedistributesAfterMaxViolation(): void
    {
        $a = $this->item(100, 1, 1, 0, 120);
        $b = $this->item(100, 1);
        $result = (new Calculator())->main([$a, $b], 400, false, 0);
        $this->assertEqualsWithDelta(120, $result->lines[0]->items[0]->size, 0.001);
        $this->assertEqualsWithDelta(280, $result->lines[0]->items[1]->size, 0.001);
        $this->assertSame(0.0, $a->size, 'Input measurements must not be mutated');
    }

    public function testShrinkUsesScaledFactorsAndFreezesMinimums(): void
    {
        $calculator = new Calculator();
        $result = $calculator->main([$this->item(100), $this->item(200)], 150, false, 0);
        $this->assertEqualsWithDelta(50, $result->lines[0]->items[0]->size, 0.001);
        $this->assertEqualsWithDelta(100, $result->lines[0]->items[1]->size, 0.001);
        $result = $calculator->main([$this->item(100, 0, 1, 90), $this->item(200)], 150, false, 0);
        $this->assertEqualsWithDelta(90, $result->lines[0]->items[0]->size, 0.001);
        $this->assertEqualsWithDelta(60, $result->lines[0]->items[1]->size, 0.001);
    }

    public function testFactorsBelowOneLeaveUndistributedSpace(): void
    {
        $result = (new Calculator())->main([$this->item(100, 0.2), $this->item(100, 0.3)], 400, false, 0);
        $this->assertEqualsWithDelta(140, $result->lines[0]->items[0]->size, 0.001);
        $this->assertEqualsWithDelta(160, $result->lines[0]->items[1]->size, 0.001);
        $result = (new Calculator())->main([$this->item(100, 0, 0.2), $this->item(100, 0, 0.3)], 100, false, 0);
        $this->assertEqualsWithDelta(80, $result->lines[0]->items[0]->size, 0.001);
        $this->assertEqualsWithDelta(70, $result->lines[0]->items[1]->size, 0.001);
    }

    public function testGapParticipatesInWrappingAndDoesNotAddEdges(): void
    {
        $calculator = new Calculator();
        $result = $calculator->main([$this->item(100), $this->item(100), $this->item(100)], 210, true, 10);
        $this->assertCount(2, $result->lines);
        $calculator->align($result, null, true, 10, 20, 'flex-start', 'stretch', false, false);
        $this->assertEqualsWithDelta(0, $result->lines[0]->items[0]->mainPosition, 0.001);
        $this->assertEqualsWithDelta(110, $result->lines[0]->items[1]->mainPosition, 0.001);
        $this->assertEqualsWithDelta(20, $result->lines[1]->position, 0.001);
    }

    public function testAutoMarginsConsumeFreeSpaceBeforeJustification(): void
    {
        $a = $this->item(50);
        $a->mainStart = null;
        $calculator = new Calculator();
        $result = $calculator->main([$a, $this->item(50)], 300, false, 10);
        $calculator->align($result, 100, false, 10, 0, 'space-between', 'stretch', false, false);
        $this->assertEqualsWithDelta(190, $result->lines[0]->items[0]->mainStart, 0.001);
        $this->assertEqualsWithDelta(250, $result->lines[0]->items[1]->mainPosition, 0.001);
        $this->assertEqualsWithDelta(100, $result->lines[0]->items[0]->cross, 0.001);
    }

    public function testStableOrderAndReversedAxes(): void
    {
        $a = $this->item(50);
        $b = $this->item(70);
        $a->order = 2;
        $a->key = 'a';
        $b->key = 'b';
        $calculator = new Calculator();
        $result = $calculator->main([$a, $b], 300, false, 10);
        $calculator->align($result, null, false, 10, 0, 'flex-start', 'stretch', true, false);
        $this->assertSame('b', $result->lines[0]->items[0]->key);
        $this->assertEqualsWithDelta(230, $result->lines[0]->items[0]->mainPosition, 0.001);
        $this->assertEqualsWithDelta(170, $result->lines[0]->items[1]->mainPosition, 0.001);
    }

    public function testIndefiniteMainSizeDoesNotGrow(): void
    {
        $result = (new Calculator())->main([$this->item(50, 1), $this->item(70, 1)], null, true, 10);
        $this->assertEqualsWithDelta(130, $result->main, 0.001);
        $this->assertCount(1, $result->lines);
    }
}
