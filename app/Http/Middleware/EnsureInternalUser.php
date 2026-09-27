<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps client company (IC) users out of the executor's internal pages:
 * everything in routes/admin.php. They get 404, whatever permissions they
 * hold, so those pages are not confirmed to exist.
 */
class EnsureInternalUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isClient() ?? false, 404);

        return $next($request);
    }
}
