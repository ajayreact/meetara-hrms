<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CompanyFixture;
use Tests\TestCase;

class I983CaseListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_case_list_searches_sorts_and_pages_inside_one_company(): void
    {
        $company = CompanyFixture::make('Veltrix Digital Solutions', 'i983-list');
        $other = CompanyFixture::make('Other Employer', 'i983-list-other');
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Zoe Earlier',
            'candidate_email' => 'zoe.earlier@example.test',
        ])->assertRedirect();
        $this->actingAs($company->hr)->post('/i983', [
            'candidate_name' => 'Amy Later',
            'candidate_email' => 'amy.later@example.test',
        ])->assertRedirect();
        $this->actingAs($other->hr)->post('/i983', [
            'candidate_name' => 'Amy Later',
            'candidate_email' => 'amy.other@example.test',
        ])->assertRedirect();

        $this->actingAs($company->hr)->get('/i983?q=amy')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matching', 1)
            ->where('cases', fn ($cases): bool => count($cases) === 1 && $cases[0]['candidate'] === 'Amy Later' && $cases[0]['email'] === 'amy.later@example.test')
            ->where('cases.0.canReviewEvaluation', false)
        );
        $reference = (string) DB::table('i983_cases')->where('tenant_id', $company->id)->where('candidate_name', 'Zoe Earlier')->value('reference');
        $this->actingAs($company->hr)->get('/i983?q='.urlencode($reference))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matching', 1)
            ->where('cases.0.candidate', 'Zoe Earlier')
        );
        $this->actingAs($company->hr)->get('/i983?sort=name&direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cases.0.candidate', 'Amy Later')
            ->where('cases.1.candidate', 'Zoe Earlier')
            ->where('sort', 'name')
            ->where('direction', 'asc')
        );
        $this->actingAs($company->hr)->get('/i983?per_page=10&page=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('perPage', 10)
            ->where('cases.0.candidate', 'Amy Later')
            ->where('cases.0.stage', 'student_submission')
        );
        $this->actingAs($company->lead)->get('/i983')->assertForbidden();
        $this->actingAs($company->employee)->get('/i983')->assertForbidden();
        $this->actingAs($company->hr)->get('/i983?q='.urlencode('%_\\'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('matching', 0)
        );
    }
}
