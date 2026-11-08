<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\BranchDB as DB;

class InsightsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user(); abort_unless($user->allowed('dashboard.view'), 403);
        if (app(\App\Services\BranchContext::class)->aggregate) { return response()->json(app(\App\Services\OrganizationInsights::class)->dashboard()); }
        $today = today(); $data = ['date' => $today->toDateString(), 'stats' => [], 'appointments' => [], 'week' => [], 'tasks' => [], 'services' => [], 'low_stock' => []];
        if ($user->allowed('appointments.view')) {
            $query = DB::table('appointments')->whereDate('appointments.starts_at', $today);
            $data['stats']['appointments'] = (clone $query)->whereNotIn('status', ['cancelled', 'no_show'])->count();
            $data['stats']['completed'] = (clone $query)->where('status', 'completed')->count();
            $data['appointments'] = (clone $query)->join('clients', 'clients.id', '=', 'appointments.client_id')->join('services', 'services.id', '=', 'appointments.service_id')->join('staff', 'staff.id', '=', 'appointments.staff_id')->select('appointments.*', 'clients.name as client', 'services.name as service', 'staff.name as practitioner', 'staff.color')->orderBy('appointments.starts_at')->get();
            for ($i = 6; $i >= 0; $i--) {
                $date = today()->subDays($i); $data['week'][] = ['day' => $date->format('D'), 'date' => $date->toDateString(), 'count' => DB::table('appointments')->whereDate('starts_at', $date)->whereNotIn('status', ['cancelled', 'no_show'])->count()];
            }
        }
        if ($user->allowed('clients.view')) { $data['stats']['clients'] = DB::table('clients')->where('status', 'active')->count(); }
        if ($user->allowed('invoices.view')) {
            $data['stats']['collected_cents'] = (int) DB::table('payments')->whereDate('created_at', $today)->sum('amount_cents');
            $data['stats']['outstanding_cents'] = (int) DB::table('invoices')->sum('total_cents') - (int) DB::table('payments')->sum('amount_cents');
        }
        if ($user->allowed('tasks.view')) { $data['tasks'] = DB::table('tasks')->where('status', '!=', 'completed')->orderBy('due_on')->limit(5)->get(); }
        if ($user->allowed('products.view')) { $data['low_stock'] = DB::table('products')->whereColumn('stock', '<=', 'reorder_level')->where('status', 'active')->limit(5)->get(['id', 'name', 'stock', 'reorder_level']); }
        if ($user->allowed('services.view')) { $data['services'] = DB::table('services')->join('service_categories', 'service_categories.id', '=', 'services.category_id')->where('services.status', 'active')->select('services.*', 'service_categories.name as category', 'service_categories.color')->limit(4)->get(); }
        return response()->json($data);
    }

    public function reports(Request $request): mixed
    {
        $user = $request->user(); abort_unless($user->allowed('reports.view'), 403);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from = CarbonImmutable::parse($request->input('from', today()->startOfMonth()->toDateString()))->startOfDay();
        $to = CarbonImmutable::parse($request->input('to', today()->toDateString()))->endOfDay();
        $data = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        if ($user->allowed('invoices.view')) {
            $data['invoiced_cents'] = (int) DB::table('invoices')->whereBetween('created_at', [$from, $to])->sum('total_cents');
            $data['collected_cents'] = (int) DB::table('payments')->whereBetween('created_at', [$from, $to])->sum('amount_cents');
            $data['outstanding_cents'] = (int) DB::table('invoices')->sum('total_cents') - (int) DB::table('payments')->sum('amount_cents');
            $data['payment_methods'] = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')->whereBetween('payments.created_at', [$from, $to])->groupBy('payment_methods.id', 'payment_methods.name')->selectRaw('payment_methods.name, SUM(amount_cents) as amount_cents')->get();
        }
        if ($user->allowed('appointments.view')) {
            $data['statuses'] = DB::table('appointments')->whereBetween('starts_at', [$from, $to])->selectRaw('status, COUNT(*) as count')->groupBy('status')->get();
            $data['practitioners'] = DB::table('appointments')->join('staff', 'staff.id', '=', 'appointments.staff_id')->whereBetween('appointments.starts_at', [$from, $to])->selectRaw('staff.name, COUNT(*) as appointments, SUM(CASE WHEN appointments.status = \'completed\' THEN 1 ELSE 0 END) as completed')->groupBy('staff.id', 'staff.name')->get();
            $data['services'] = DB::table('appointments')->join('services', 'services.id', '=', 'appointments.service_id')->whereBetween('appointments.starts_at', [$from, $to])->whereNotIn('appointments.status', ['cancelled', 'no_show'])->selectRaw('services.name, COUNT(*) as appointments')->groupBy('services.id', 'services.name')->orderByDesc('appointments')->get();
        }
        if ($user->allowed('packages.view')) { $data['package_redemptions'] = DB::table('package_redemptions')->whereBetween('created_at', [$from, $to])->count(); }
        if ($user->allowed('products.view')) { $data['stock'] = DB::table('products')->where('status', 'active')->get(['name', 'sku', 'stock', 'reorder_level']); }
        if (app(\App\Services\BranchContext::class)->aggregate) { $data=app(\App\Services\OrganizationInsights::class)->reports($from->toDateString(),$to->toDateString()); }
        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($data): void {
                $file = fopen('php://output', 'w'); fputcsv($file, ['Section', 'Metric', 'Value'], ',', '"', '');
                foreach ($data as $key => $value) {
                    if (is_scalar($value)) { fputcsv($file, [$key, '', $value], ',', '"', ''); }
                    else { foreach ($value as $row) { foreach ((array) $row as $metric => $amount) { $safe = preg_match('/^[=+@\-]/', (string) $amount) ? "'".$amount : $amount; fputcsv($file, [$key, $metric, $safe], ',', '"', ''); } } }
                }
                fclose($file);
            }, 'aroma-report.csv', ['Content-Type' => 'text/csv']);
        }
        return response()->json($data);
    }
}
