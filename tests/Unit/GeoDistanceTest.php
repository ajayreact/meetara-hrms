<?php

namespace Tests\Unit;

use App\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_distance_matches_known_points(): void
    {
        $this->assertSame(0.0, GeoDistance::meters(22.572645, 88.363895, 22.572645, 88.363895));
        $this->assertEqualsWithDelta(111194.9, GeoDistance::meters(0, 0, 0, 1), 1);
        $this->assertEqualsWithDelta(111194.9, GeoDistance::meters(0, 0, 1, 0), 1);
        $this->assertGreaterThan(1000000, GeoDistance::meters(22.572645, 88.363895, 0, 0));
    }
}
