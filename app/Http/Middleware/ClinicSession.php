<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ClinicSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(\App\Services\BranchContext::class);
        $context->resolve($request);
        if ($request->is('api/*') && ! $request->is('api/bootstrap','api/branch-selection') && $request->hasHeader('X-Clinic-Branch')) {
            abort_unless($request->header('X-Clinic-Branch') === ($context->aggregate ? 'all' : (string)$context->branchId),409,'Your active branch changed. Refresh the workspace before continuing.');
        }
        $timezone = $context->branchId ? DB::table('branches')->where('id',$context->branchId)->value('timezone') : DB::table('organizations')->where('id',$context->organizationId)->value('timezone');
        $timezone ??= 'America/Toronto';
        config(['app.timezone' => $timezone]); date_default_timezone_set($timezone);
        $response = $next($request); $response->headers->set('Cache-Control', 'private, no-store'); $response->headers->set('X-Content-Type-Options', 'nosniff'); $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }
}
