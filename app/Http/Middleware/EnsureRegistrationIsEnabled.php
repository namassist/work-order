<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The registration kill switch (config('registration.enabled')): when it is
 * off, the registration page and its form answer 404.
 */
class EnsureRegistrationIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('registration.enabled'), 404);

        return $next($request);
    }
}
