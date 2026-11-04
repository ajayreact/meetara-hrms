<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\UpdateI983EmployerProfileRequest;
use App\Models\User;
use App\Services\Hrms\I983EmployerProfile;
use Illuminate\Http\RedirectResponse;

class I983EmployerProfileController extends Controller
{
    public function update(UpdateI983EmployerProfileRequest $request, I983EmployerProfile $profiles): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $profiles->save($actor, $request->validated());

        return back()->with('status', 'U.S. employer information saved. Existing I-983 cases were not changed.');
    }
}
