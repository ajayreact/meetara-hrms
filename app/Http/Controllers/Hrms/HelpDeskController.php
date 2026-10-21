<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\StoreTicketCommentRequest;
use App\Http\Requests\Hrms\StoreTicketRequest;
use App\Services\Hrms\HelpDeskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpDeskController extends Controller
{
    public function index(Request $request, HelpDeskService $desk): Response
    {
        $selected = $request->integer('ticket') ?: null;

        return Inertia::render('help-desk/index', [
            ...$desk->page($request->user(), $selected, [
                'q' => $request->string('q')->toString(),
                'status' => $request->string('status')->toString(),
                'priority' => $request->string('priority')->toString(),
                'category' => $request->string('category')->toString(),
            ]),
            'notice' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(StoreTicketRequest $request, HelpDeskService $desk): RedirectResponse
    {
        $id = $desk->open($request->user(), $request->validated());

        return redirect()->route('hrms.help-desk', ['ticket' => $id])->with('status', 'Ticket opened.');
    }

    public function comment(StoreTicketCommentRequest $request, int $ticket, HelpDeskService $desk): RedirectResponse
    {
        $desk->comment($request->user(), $ticket, (string) $request->validated('body'));

        return back()->with('status', 'Comment added.');
    }

    public function assign(Request $request, int $ticket, HelpDeskService $desk): RedirectResponse
    {
        $assignee = $request->integer('assignee_id');
        abort_unless($assignee > 0, 422);
        $desk->assign($request->user(), $ticket, $assignee);

        return back()->with('status', 'Ticket assigned.');
    }

    public function status(Request $request, int $ticket, HelpDeskService $desk): RedirectResponse
    {
        $status = $request->string('status')->toString();
        $desk->transition($request->user(), $ticket, $status);

        return back()->with('status', 'Ticket updated.');
    }
}
