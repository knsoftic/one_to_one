<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * X1 — use the person's app language. The admin panel and the legal pages stay in English.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $english = $request->is('admin', 'admin/*') || $request->routeIs('legal');
        app()->setLocale($english ? 'en' : Locales::forRequest($request));

        return $next($request);
    }
}
