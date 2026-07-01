<?php

namespace Tests\Unit\Modules\V5\Helpers;

use Ushahidi\Modules\V5\Helpers\GeoLookupHelper;
use Ushahidi\Tests\TestCase;

class GeoLookupHelperTest extends TestCase
{
    public function testLookupResolvesACoordinateInsideLiberiaToACountyAndDistrict()
    {
        // Greater Monrovia, Montserrado
        [$county, $district] = GeoLookupHelper::lookup(6.37, -10.76);

        $this->assertSame('Montserrado', $county);
        $this->assertSame('Greater Monrovia', $district);
    }

    public function testLookupReturnsEmptyStringsForACoordinateOutsideAllPolygons()
    {
        // Middle of the Atlantic Ocean, far from any Liberia county polygon
        [$county, $district] = GeoLookupHelper::lookup(0.0, -30.0);

        $this->assertSame('', $county);
        $this->assertSame('', $district);
    }
}
