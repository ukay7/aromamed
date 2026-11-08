<?php

namespace App\Http\Controllers;

use App\Services\AppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use App\Services\BranchDB as DB;
use Illuminate\Validation\ValidationException;

class PublicSiteController extends Controller
{
    public function index(): mixed
    {
        $context=app(\App\Services\BranchContext::class);
        if (! request()->route('branchSlug')) { return view('branch-booking', ['branches'=>\Illuminate\Support\Facades\DB::table('branches')->join('organizations','organizations.id','=','branches.organization_id')->where('branches.active',true)->select('branches.name','branches.slug','branches.location','organizations.name as organization')->get()]); }
        $branch=\Illuminate\Support\Facades\DB::table('branches')->find($context->requireBranch());
        return view('booking', ['branch'=>$branch,'services' => DB::table('services')->where('status', 'active')->where('online_booking', true)->get(), 'staff' => DB::table('staff')->where('status', 'active')->get(['id', 'name', 'specialty']), 'assignments' => DB::table('service_staff')->get(), 'settings' => DB::table('settings')->pluck('value', 'key'), 'pages' => DB::table('website_pages')->where('status', 'published')->get(['title', 'slug'])]);
    }

    public function book(Request $request, AppointmentService $appointments): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255', 'phone' => 'required|string|max:40', 'service_id' => 'required|integer|exists:services,id', 'staff_id' => 'required|integer|exists:staff,id', 'starts_at' => 'required|date_format:Y-m-d\TH:i', 'acknowledged' => 'accepted']);
        $request->validate(['service_id'=>[\App\Services\BranchValidation::exists('services')],'staff_id'=>[\App\Services\BranchValidation::exists('staff')]]);
        $service = DB::table('services')->find($data['service_id']); abort_unless($service->online_booking && $service->status === 'active', 422);
        $notice = (int) DB::table('settings')->where('key', 'booking_notice_hours')->value('value');
        if (CarbonImmutable::parse($data['starts_at'])->lt(now()->addHours($notice))) { throw ValidationException::withMessages(['starts_at' => "Please allow at least $notice hours before your visit."]); }
        DB::transaction(function () use ($data, $service, $appointments): void {
            $clientId = DB::table('clients')->where('email', $data['email'])->value('id');
            $clientId ??= DB::table('clients')->insertGetId(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $appointments->save(['client_id' => $clientId, 'service_id' => $service->id, 'staff_id' => $data['staff_id'], 'starts_at' => $data['starts_at'], 'duration' => $service->duration, 'buffer' => $service->buffer, 'status' => 'confirmed', 'notes' => 'Online booking — verify client identity on arrival.']);
        });
        return back()->with('success', 'Your appointment is booked. Please contact the clinic for changes.');
    }

    public function page(string $branchSlug, string $slug): mixed
    {
        $page = DB::table('website_pages')->where('slug', $slug)->where('status', 'published')->first(); abort_unless($page, 404);
        return view('public-page', compact('page','branchSlug'));
    }
}
