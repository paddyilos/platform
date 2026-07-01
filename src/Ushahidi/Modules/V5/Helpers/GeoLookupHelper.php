<?php

namespace Ushahidi\Modules\V5\Helpers;

use Shapefile\ShapefileReader;
use Shapefile\Geometry\MultiPolygon;

class GeoLookupHelper
{
    /**
     * Resolve a lat/lng coordinate to a Liberia county/district using the
     * County2 administrative-boundary shapefile.
     *
     * @return array{0: string, 1: string} [$county, $district], or ['', ''] if
     *         the point falls outside all polygons or the shapefile is missing.
     */
    public static function lookup(float $lat, float $lng): array
    {
        $shapePath = storage_path('Shapefiles/liberia/County2');
        if (!file_exists($shapePath . '.shp')) {
            return ['', ''];
        }

        try {
            $reader = new ShapefileReader($shapePath);
            foreach ($reader as $geometry) {
                if ($geometry->isDeleted()) continue;

                $polygons = $geometry instanceof MultiPolygon ? $geometry->getPolygons() : [$geometry];
                foreach ($polygons as $polygon) {
                    $points = array_map(fn($point) => $point->getArray(), $polygon->getOuterRing()->getPoints());
                    if (self::pointInPolygon($lng, $lat, $points)) {
                        $county   = $geometry->getData('FIRST_CCNA') ?? '';
                        $district = $geometry->getData('DNAME') ?? '';
                        return [trim($county), trim($district)];
                    }
                }
            }
        } catch (\Exception $e) {
            // Shapefile read error — continue without geographic data
        }

        return ['', ''];
    }

    private static function pointInPolygon(float $px, float $py, array $points): bool
    {
        $n = count($points);
        if ($n < 3) return false;
        $inside = false;
        $j = $n - 1;
        for ($i = 0; $i < $n; $i++) {
            $xi = $points[$i]['x'];
            $yi = $points[$i]['y'];
            $xj = $points[$j]['x'];
            $yj = $points[$j]['y'];
            if ((($yi > $py) !== ($yj > $py)) && ($px < ($xj - $xi) * ($py - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
            $j = $i;
        }
        return $inside;
    }
}
