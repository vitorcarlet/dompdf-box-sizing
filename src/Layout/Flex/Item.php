<?php
namespace Dompdf\Layout\Flex;

/** Internal content-box measurements in pt; null margins represent auto. */
final class Item
{
    public $key;
    public $order = 0;
    public $base = 0.0;
    public $min = 0.0;
    public $max = INF;
    public $grow = 0.0;
    public $shrink = 1.0;
    public $mainDecoration = 0.0;
    public $mainStart = 0.0;
    public $mainEnd = 0.0;
    public $cross = 0.0;
    public $crossMin = 0.0;
    public $crossMax = INF;
    public $crossDecoration = 0.0;
    public $crossStart = 0.0;
    public $crossEnd = 0.0;
    public $crossAuto = true;
    public $align = 'stretch';
    public $baseline = 0.0;
    public $collapsed = false;

    // Result fields. Calculator clones its inputs before assigning these.
    public $size = 0.0;
    public $mainPosition = 0.0;
    public $crossPosition = 0.0;
    public $line = 0;

    public function outerMain(float $size): float
    {
        return $size + $this->mainDecoration + ($this->mainStart ?? 0) + ($this->mainEnd ?? 0);
    }

    public function outerCross(): float
    {
        return $this->cross + $this->crossDecoration + ($this->crossStart ?? 0) + ($this->crossEnd ?? 0);
    }
}
