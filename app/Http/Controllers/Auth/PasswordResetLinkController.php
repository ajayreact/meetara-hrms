<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\MailTransport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the password reset link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        if ($problem = app(MailTransport::class)->problem()) {
            throw ValidationException::withMessages([
                'email' => $problem,
            ]);
        }

        try {
            $status = Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            app(MailTransport::class)->logFailure('Password reset', $exception);

            throw ValidationException::withMessages([
                'email' => 'The password reset email could not be sent. Check the mail settings and try again.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => 'Please wait a minute before requesting another password reset email.',
            ]);
        }

        return back()->with('status', __('A reset link will be sent if the account exists.'));
    }
}
