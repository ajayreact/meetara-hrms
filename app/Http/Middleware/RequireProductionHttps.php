<?php

namespace App\Http\Middleware;

use App\Support\DeploymentSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class RequireProductionHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! DeploymentSecurity::requiresHttps(app()->environment())) {
            return $next($request);
        }

        URL::forceScheme('https');

        if (! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri());
        }

        return $next($request);
    }
}
