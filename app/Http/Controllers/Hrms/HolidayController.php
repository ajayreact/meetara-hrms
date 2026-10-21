<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\HolidayRequest;
use App\Models\Company;
use App\Models\Holiday;
use App\Services\Hrms\HolidayDirectory;
use App\Services\Hrms\LeaveRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HolidayController extends Controller
{
    public function index(Request $request, HolidayDirectory $directory): Response
    {
        $calendar = $request->string('calendar')->toString() ?: 'india';
        $zone = Company::query()->whereKey($request->user()->companyId())->value('timezone') ?: config('app.timezone');
        $year = (int) $request->integer('year', (int) now($zone)->year);

        return Inertia::render('holidays/index', [
            ...$directory->page($request->user(), $calendar, $year),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(HolidayRequest $request, HolidayDirectory $directory): RedirectResponse
    {
        return $this->save($request, $directory, null);
    }

    public function update(HolidayRequest $request, Holiday $holiday, HolidayDirectory $directory): RedirectResponse
    {
        return $this->save($request, $directory, $holiday);
    }

    private function save(HolidayRequest $request, HolidayDirectory $directory, ?Holiday $holiday): RedirectResponse
    {
        try {
            $directory->save($request->user(), $request->validated(), $holiday);
        } catch (LeaveRejected $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', $holiday === null ? 'Holiday added.' : 'Holiday updated.');
    }
}
