<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\ApproveDetectedNetworkRequest;
use App\Http\Requests\Hrms\OfficeNetworkRequest;
use App\Http\Requests\Hrms\OfficeRequest;
use App\Http\Requests\Hrms\OfficeScheduleRequest;
use App\Models\Company;
use App\Models\LocationNetwork;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\Hrms\WorkSchedule;
use App\Support\AuthenticatedWorkspace;
use App\Support\ClientAddress;
use App\Support\NetworkAddress;
use App\Support\MapTiles;
use App\Support\OfficeVerification;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OfficeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->actor($request);
        $workspace = AuthenticatedWorkspace::resolve($user);

        if ($user->cannot('viewAny', Office::class)) {
            return Inertia::render('hrms/forbidden', [
                'workspace' => $workspace,
                'title' => 'Offices',
                'message' => 'Your account cannot open Offices. Company records are not loaded on this screen.',
            ]);
        }

        $companyId = $user->companyId();
        $company = Company::query()->find($companyId);
        $shift = Shift::query()->inCompany((int) $companyId)->where('is_default', true)->first();
        $weekOff = is_array($company?->week_off) ? $company->week_off : [];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        return Inertia::render('offices/index', [
            'workspace' => $workspace,
            'offices' => Office::query()->inCompany((int) $companyId)->with('networks')->orderBy('name')->get()->map(fn (Office $office) => [
                'id' => (int) $office->id,
                'name' => (string) $office->name,
                'address' => $office->address,
                'timezone' => $office->timezone ?: ($company?->timezone ?: 'UTC'),
                'status' => (string) ($office->status ?: 'active'),
                'mapLabel' => Schema::hasColumn('locations', 'map_label') ? $office->map_label : null,
                'latitude' => $office->latitude,
                'longitude' => $office->longitude,
                'verificationRadiusMeters' => (int) ($office->verification_radius_meters ?: OfficeVerification::DEFAULT_RADIUS_METERS),
                'gpsVerificationEnabled' => (bool) $office->gps_verification_enabled,
                'verification' => OfficeVerification::describe($office),
                'networks' => $office->networks->map(fn (LocationNetwork $network) => [
                    'id' => (int) $network->id,
                    'cidr' => (string) $network->cidr,
                ])->all(),
            ])->all(),
            'locationFieldsAvailable' => OfficeVerification::columnsAvailable(),
            'mapLabelAvailable' => Schema::hasColumn('locations', 'map_label'),
            'schedule' => [
                'workStart' => $shift?->start_time ? substr((string) $shift->start_time, 0, 5) : '09:30',
                'workEnd' => $shift?->end_time ? substr((string) $shift->end_time, 0, 5) : '18:30',
                'graceMinutes' => (int) ($shift->grace_minutes ?? 10),
                'workingDays' => array_values(array_filter($days, fn (string $day) => ! in_array($day, array_map('strtolower', $weekOff), true))),
            ],
            'mapTiles' => MapTiles::payload(),
            'timezones' => ['Asia/Kolkata', 'Asia/Dubai', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Europe/London', 'UTC'],
            'detectedAddress' => $this->detectedAddress($request),
            'notice' => $request->session()->get('status'),
        ]);
    }

    public function store(OfficeRequest $request): RedirectResponse
    {
        $office = new Office($this->officeAttributes($request));
        $office->forceFill(['tenant_id' => $this->actor($request)->companyId()])->save();

        return back()->with('status', 'Office saved.');
    }

    public function update(OfficeRequest $request, Office $office): RedirectResponse
    {
        $office->fill($this->officeAttributes($request))->save();

        return back()->with('status', 'Office updated.');
    }

    public function schedule(OfficeScheduleRequest $request): RedirectResponse
    {
        $companyId = $this->actor($request)->companyId();
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $working = $request->validated('working_days');
        $weekOff = array_values(array_filter($days, fn (string $day) => ! in_array($day, $working, true)));
        $start = $request->validated('work_start');
        $end = $request->validated('work_end');
        $grace = (int) $request->validated('grace_minutes');
        $minutes = WorkSchedule::minutesBetween($start, $end);

        if ($minutes === null) {
            throw ValidationException::withMessages([
                'work_end' => 'The end time must be different from the start time.',
            ]);
        }

        DB::transaction(function () use ($companyId, $weekOff, $start, $end, $grace, $minutes): void {
            Company::query()->whereKey($companyId)->update(['week_off' => json_encode($weekOff)]);
            $shift = Shift::query()->inCompany((int) $companyId)->where('is_default', true)->first();

            if ($shift === null) {
                $shift = new Shift;
                $shift->forceFill([
                    'tenant_id' => $companyId,
                    'name' => 'General',
                    'type' => 'fixed',
                    'is_default' => true,
                ]);
            }

            $shift->fill([
                'start_time' => $start.':00',
                'end_time' => $end.':00',
                'grace_minutes' => $grace,
                'expected_minutes' => $minutes,
            ])->save();
        });

        return back()->with('status', 'Attendance schedule saved for this company.');
    }

    public function storeNetwork(OfficeNetworkRequest $request, Office $office): RedirectResponse
    {
        $this->rememberNetwork($office, (string) $request->validated('cidr'), 'cidr');

        return back()->with('status', 'Approved network saved.');
    }

    public function approveDetected(ApproveDetectedNetworkRequest $request, Office $office): RedirectResponse
    {
        $detected = $this->detectedAddress($request);

        if (! $detected['approvable'] || ! is_string($detected['ip'])) {
            throw ValidationException::withMessages([
                'confirm_detected' => $detected['message'],
            ]);
        }

        $this->rememberNetwork($office, NetworkAddress::normalizeCidr($detected['ip']), 'confirm_detected');

        return back()->with('status', 'The detected public address was approved for this office.');
    }

    public function destroyNetwork(Request $request, Office $office, int $network): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $office), 403);

        $row = LocationNetwork::query()
            ->whereKey($network)
            ->where('location_id', $office->id)
            ->where('tenant_id', $office->tenant_id)
            ->first();

        abort_if($row === null, 404);
        $row->delete();

        return back()->with('status', 'Approved network removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function officeAttributes(OfficeRequest $request): array
    {
        $attributes = $request->safe()->only(['name', 'address', 'timezone', 'status']);

        if (! OfficeVerification::columnsAvailable()) {
            if ($request->filled('latitude') || $request->filled('longitude') || $request->boolean('gps_verification_enabled')) {
                throw ValidationException::withMessages([
                    'latitude' => 'Office coordinates cannot be saved until the location migration is applied.',
                ]);
            }

            return $attributes;
        }

        $location = $request->safe()->only([
            'latitude',
            'longitude',
            'verification_radius_meters',
            'gps_verification_enabled',
        ]);

        if (Schema::hasColumn('locations', 'map_label')) {
            $location = array_merge($location, $request->safe()->only(['map_label']));
        }

        return array_merge($attributes, $location);
    }

    /**
     * @return array{ip: ?string, approvable: bool, message: string}
     */
    private function detectedAddress(Request $request): array
    {
        $observed = app(ClientAddress::class)->observe($request);

        if (! $observed['ok'] || ! is_string($observed['ip'])) {
            $invalidProxy = str_contains($observed['reason'], 'invalid');

            return [
                'ip' => null,
                'approvable' => false,
                'message' => $invalidProxy
                    ? 'The trusted-proxy configuration is invalid, so this connection cannot be approved.'
                    : 'A forwarded address arrived from an untrusted connection, so this connection cannot be approved.',
            ];
        }

        $ip = $observed['ip'];

        if (NetworkAddress::isForbidden($ip)) {
            $loopback = $ip === '::1' || str_starts_with($ip, '127.');

            return [
                'ip' => null,
                'approvable' => false,
                'message' => $loopback
                    ? 'This connection is localhost. A loopback address is not saved for this company.'
                    : 'This connection is a private address such as 192.168, 10.x, or 172.16. Private addresses are not saved for this company. Approve only the public address of this office network.',
            ];
        }

        return [
            'ip' => $ip,
            'approvable' => true,
            'message' => 'The server sees this public address on the current connection. It is not saved until you confirm it for one office.',
        ];
    }

    private function rememberNetwork(Office $office, string $cidr, string $errorKey): void
    {
        $alreadySaved = LocationNetwork::query()
            ->where('tenant_id', $office->tenant_id)
            ->where('location_id', $office->id)
            ->pluck('cidr')
            ->contains(function (mixed $saved) use ($cidr): bool {
                try {
                    return NetworkAddress::normalizeCidr((string) $saved) === $cidr;
                } catch (\InvalidArgumentException) {
                    return false;
                }
            });

        if ($alreadySaved) {
            throw ValidationException::withMessages([
                $errorKey => 'That network is already approved for this office.',
            ]);
        }

        $network = new LocationNetwork(['cidr' => $cidr]);
        $network->forceFill([
            'tenant_id' => $office->tenant_id,
            'location_id' => $office->id,
        ]);

        try {
            $network->save();
        } catch (QueryException) {
            throw ValidationException::withMessages([
                $errorKey => 'That network is already approved for this office.',
            ]);
        }
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
