<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Ushahidi\Modules\V5\Helpers\GeoLookupHelper;
use Ushahidi\Modules\V5\Models\Alert;

class AlertController extends V5Controller
{
    /**
     * Subscribe to geographic alerts.
     * POST /api/v3/get-alerts
     * Public — no auth required.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'radius'     => 'required|integer',
            'email'      => 'required|email|max:255|unique:alerts,email',
            'latitude'   => 'required|string|max:255',
            'longitude'  => 'required|string|max:255',
            'location'   => 'nullable|string|max:255',
            'categories' => 'nullable|array',
        ]);

        Alert::create([
            'radius'     => $data['radius'],
            'email'      => $data['email'],
            'latitude'   => $data['latitude'],
            'longitude'  => $data['longitude'],
            'location'   => $data['location'] ?? null,
            'categories' => isset($data['categories']) ? json_encode($data['categories']) : null,
            'status'     => 1,
            'hash'       => substr(md5($data['email'] . time()), 0, 7),
            'created'    => time(),
            'updated'    => time(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Resolve a map pin's coordinates to a Liberia county/district name.
     * GET /api/v3/get-alerts/lookup-location
     * Public — no auth required.
     */
    public function lookupLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        [$county, $district] = GeoLookupHelper::lookup((float)$data['lat'], (float)$data['lng']);

        return response()->json(['county' => $county, 'district' => $district]);
    }

    /**
     * Unsubscribe from alerts via hash token.
     * GET /api/v3/get-alerts/unsubscribe-email/{hash}
     * Public — hash is the token.
     */
    public function unsubscribe(string $hash): Response
    {
        $alert = Alert::where('hash', $hash)->where('status', 1)->first();

        if (!$alert) {
            return response('Invalid or already used unsubscribe link.', 404)
                ->header('Content-Type', 'text/plain');
        }

        $alert->update(['status' => 0, 'hash' => null, 'updated' => time()]);

        return response('You have been successfully unsubscribed from iReport Liberia alerts.')
            ->header('Content-Type', 'text/plain');
    }
}
