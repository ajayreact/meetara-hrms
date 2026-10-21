<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireProductionHttps;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        URL::forceScheme(null);
        TrustProxies::at([]);
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    public function test_local_http_is_not_redirected_and_production_http_is(): void
    {
        $this->get('http://127.0.0.1:8000/login')->assertOk();

        $this->app['env'] = 'production';
        $response = (new RequireProductionHttps)->handle(
            Request::create('http://127.0.0.1:8000/login', 'GET'),
            fn () => response('ok', 200)
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('https://', (string) $response->headers->get('Location'));
    }

    public function test_configured_proxies_are_applied_after_configuration_loads(): void
    {
        config(['attendance.trusted_proxies' => ['198.51.100.8']]);
        $provider = new AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'applyTrustedProxies');
        $method->invoke($provider);

        $request = Request::create('http://127.0.0.1:8000/login', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.20',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        app(TrustProxies::class)->handle($request, fn () => response('ok'));

        $this->assertTrue($request->isSecure());
        $this->assertSame('203.0.113.20', $request->ip());
    }

    public function test_untrusted_forwarded_headers_do_not_change_https_or_the_client_address(): void
    {
        $request = Request::create('http://127.0.0.1:8000/login', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]);

        $this->assertFalse($request->isSecure());
        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_debug_off_hides_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/testing-security-fault', function (): void {
            throw new \RuntimeException('marker-not-for-the-browser');
        });

        $this->get('/testing-security-fault')
            ->assertStatus(500)
            ->assertDontSee('marker-not-for-the-browser')
            ->assertDontSee('APP_KEY');
    }

    public function test_repeated_login_failures_are_rejected(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', [
                'email' => 'nobody@example.test',
                'password' => 'wrong-password',
            ])->assertRedirect();
        }

        $response = $this->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'wrong-password',
        ]);

        $this->assertContains($response->status(), [302, 429]);
        $this->assertNotSame(url('/dashboard'), $response->headers->get('Location'));
    }
}
