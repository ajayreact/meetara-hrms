<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Hrms\GpsCapture;
use App\Support\AuthenticatedWorkspace;
use App\Support\MapTiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouteMapController extends Controller
{
    public function __construct(private readonly GpsCapture $gpsCapture) {}

    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if (! ($workspace['permissions']['routeMap'] ?? false)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Route Map',
                'message' => 'Your account cannot open Route Map. Company records are not loaded on this screen.',
            ]);
        }

        $employeeId = ctype_digit((string) $request->query('employee')) ? (int) $request->query('employee') : null;

        return Inertia::render('route-map/index', [
            'workspace' => $workspace,
            'mapTiles' => MapTiles::payload(),
            'map' => $this->gpsCapture->page(
                $user,
                $request->query('from') ? (string) $request->query('from') : null,
                $request->query('to') ? (string) $request->query('to') : null,
                $employeeId,
            ),
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $this->gpsCapture->acknowledge($this->actor($request));

        return back()->with('status', 'The location notice was acknowledged.');
    }

    public function updateCapture(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $this->gpsCapture->setEnabled($this->actor($request), $enabled);

        return back()->with('status', $enabled ? 'GPS capture at clock-in and clock-out is enabled.' : 'GPS capture at clock-in and clock-out is disabled.');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
