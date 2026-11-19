<?php

namespace App\Http\Controllers\Hrms;

use App\Auth\CompanyRole;
use App\Http\Controllers\Controller;
use App\Services\Hrms\LeadDirectory;
use App\Services\Hrms\LeadDistribution;
use App\Services\Hrms\LeadFollowUp;
use App\Services\Hrms\LeadSources;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadPoolController extends Controller
{
    public function index(Request $request, LeadDirectory $directory): Response
    {
        $user = $request->user();
        $role = AuthenticatedWorkspace::resolve($user)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        return Inertia::render('leads/index', [
            ...$directory->page($user, $request->query()),
            'canManageSources' => ($permissions['leadsManageSources'] ?? false) === true,
            'canImport' => ($permissions['leadsImport'] ?? false) === true,
            'canAssign' => ($permissions['leadsAssignTeam'] ?? false) === true,
            'canFollowUp' => ($permissions['leadsFollowUpTeam'] ?? false) === true,
            'canReport' => ($permissions['leadsViewAll'] ?? false) === true && ($permissions['leadsReport'] ?? false) === true,
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function show(Request $request, int $lead, LeadDirectory $directory, LeadDistribution $distribution, LeadFollowUp $followUps, LeadSources $sources): Response
    {
        $user = $request->user();
        $role = AuthenticatedWorkspace::resolve($user)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');
        $detail = $directory->detail($user, $lead);
        $canFollowUp = ($permissions['leadsViewAll'] ?? false) === true && ($permissions['leadsFollowUpTeam'] ?? false) === true;
        $canSetSource = ($permissions['leadsManageSources'] ?? false) === true && ($detail['lead']['source'] ?? '') === '';

        return Inertia::render('leads/show', [
            ...$detail,
            'sourceChoices' => $canSetSource ? $sources->choices((int) $user->companyId()) : [],
            'canSetSource' => $canSetSource,
            ...$distribution->timeline((int) $user->companyId(), $lead),
            'canAssign' => ($permissions['leadsAssignTeam'] ?? false) === true,
            'canManage' => false,
            'canFollowUp' => $canFollowUp,
            'followUpUrl' => '/leads/'.$lead.'/follow-ups',
            'statuses' => $followUps->statuses(),
            'backHref' => '/leads',
            'reports' => [],
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }
}
