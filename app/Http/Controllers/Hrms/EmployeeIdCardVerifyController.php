<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Controller;
use App\Models\EmployeeIdCard;
use App\Services\Hrms\EmployeeIdCardIssuer;
use App\Services\Hrms\StandaloneIdCardIssuer;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeIdCardVerifyController extends Controller
{
    public function __construct(
        private readonly EmployeeIdCardIssuer $employeeIssuer,
        private readonly StandaloneIdCardIssuer $standaloneIssuer,
    ) {}

    public function show(string $token): Response
    {
        $verification = $this->resolve($token);

        return Inertia::render('id-cards/verify', [
            'verification' => $verification,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $token): array
    {
        if (Schema::hasTable('employee_id_cards')
            && EmployeeIdCard::query()->where('qr_token', $token)->exists()) {
            return $this->employeeIssuer->verify($token);
        }

        return $this->standaloneIssuer->verify($token);
    }
}
