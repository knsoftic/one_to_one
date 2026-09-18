<?php

namespace App\Http\Middleware;

use App\Services\MonetisationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The paid features (Y2) exist only while the admin's master switch is on: every purchase,
 * promotion and referral route answers 404 otherwise. Balances and admin pages keep working.
 */
class EnsurePaidEnabled
{
    public function __construct(private readonly MonetisationService $money) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->money->enabled(), 404);

        return $next($request);
    }
}
