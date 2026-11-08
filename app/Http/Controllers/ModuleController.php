<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModuleRequest;
use App\Services\ClinicAccess;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\BranchDB as DB;
use Illuminate\Support\Facades\Storage;

class ModuleController extends Controller
{
    public function __construct(private ClinicAccess $access, private ModuleService $modules) {}

    public function bookingOptions(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'appointments');
        return response()->json([
            'services' => DB::table('services')->where('status', 'active')->get(['id', 'name', 'duration', 'buffer', 'price_cents', 'required_room_id', 'required_device_id']),
            'staff' => DB::table('staff')->where('status', 'active')->get(['id', 'name', 'color']),
            'resources' => DB::table('resources')->where('status', 'active')->get(['id', 'name', 'type']),
            'assignments' => DB::table('service_staff')->get(),
            'packages' => DB::table('client_packages')->join('packages', 'packages.id', '=', 'client_packages.package_id')->whereColumn('sessions_used', '<', 'sessions_total')->select('client_packages.*', 'packages.name', 'packages.service_id')->get(),
        ]);
    }

    public function index(Request $request, string $module): JsonResponse
    {
        $definition = $this->access->authorize($request->user(), $module);
        $request->validate(['from'=>'nullable|date','to'=>'nullable|date|after:from','staff_id'=>'nullable|integer','per_page'=>'nullable|integer|min:1|max:500']);
        $query = DB::table($module);
        if ($search = $request->string('search')->trim()->value()) {
            $fields = array_keys(array_filter($definition['fields'], fn ($f) => in_array($f['type'], ['text', 'email', 'textarea', 'select'], true)));
            if ($fields) { $query->where(function ($q) use ($fields, $search): void { foreach ($fields as $field) { $q->orWhere($field, 'like', '%'.$search.'%'); } }); }
            if ($module === 'invoices') {
                $query->where(function ($q) use ($search): void {
                    $q->where('number', 'like', '%'.$search.'%')->orWhereIn('client_id', DB::table('clients')->where('name', 'like', '%'.$search.'%')->select('id'));
                });
            }
        }
        if ($module === 'appointments') {
            if ($request->filled('from')) { $query->where('starts_at', '>=', $request->date('from')); }
            if ($request->filled('to')) { $query->where('starts_at', '<', $request->date('to')); }
            if ($request->filled('staff_id')) { $query->where('staff_id', (int) $request->input('staff_id')); }
            if ($request->filled('status')) { $query->where('status', $request->input('status')); }
        }
        $page = $query->orderByDesc('id')->paginate(min(500, max(1, $request->integer('per_page', 20))));
        $labels = [];
        foreach ($definition['fields'] as $key => $field) {
            if ($field['type'] === 'reference') { $labels[$key] = $this->modules->labels($field['source']); }
        }
        $rows = $page->getCollection()->map(function ($row) use ($module, $labels): array {
            $row = (array) $row; unset($row['password'], $row['remember_token'], $row['path']);
            if ($module === 'users') {
                $membership=\Illuminate\Support\Facades\DB::table('branch_user')->where('branch_id',app(\App\Services\BranchContext::class)->requireBranch())->where('user_id',$row['id'])->first();
                $row['role_id']=$membership->role_id; $row['active']=$membership->active;
            }
            foreach ($labels as $key => $mapping) { $row[$key.'_label'] = $mapping[$row[$key] ?? ''] ?? '—'; }
            if ($module === 'staff') { $row['working_days'] = json_decode($row['working_days'], true); }
            if ($module === 'services') { $row['staff_ids'] = DB::table('service_staff')->where('service_id', $row['id'])->pluck('staff_id'); }
            if ($module === 'invoices') {
                $row['client_id_label'] = DB::table('clients')->where('id', $row['client_id'])->value('name');
                $row['paid_cents'] = (int) DB::table('payments')->where('invoice_id', $row['id'])->sum('amount_cents');
                $row['balance_cents'] = $row['total_cents'] - $row['paid_cents']; $row['status'] = $row['balance_cents'] === 0 ? 'paid' : ($row['paid_cents'] > 0 ? 'partial' : 'unpaid');
            }
            return $row;
        });
        return response()->json(['rows' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function save(ModuleRequest $request, string $module, ?int $id = null): JsonResponse
    {
        abort_if(in_array($module, ['roles', 'invoices'], true), 405);
        $id = $this->modules->save($module, $request->validated(), $request->user(), $id);
        $this->access->audit($request->user(), $request->route('id') ? 'updated' : 'created', $module, $id);
        return response()->json(['id' => $id, 'message' => 'Saved successfully.']);
    }

    public function lookup(Request $request, string $module, string $field): JsonResponse
    {
        $definition = $this->access->authorize($request->user(), $module);
        $source = $definition['fields'][$field]['source'] ?? null;
        abort_unless($source, 404);
        $labels = $this->modules->labels($source);
        $term = mb_strtolower($request->string('search')->value());
        $options = collect($labels)->filter(fn ($label, $id) => ! $term || str_contains(mb_strtolower($label), $term) || in_array((string) $id, explode(',', $request->string('ids')->value()), true))->take(500)->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values();
        return response()->json($options);
    }

    public function photo(Request $request, int $id): mixed
    {
        $this->access->authorize($request->user(), 'photos');
        $photo = DB::table('photos')->find($id); abort_unless($photo, 404);
        return Storage::disk('local')->response($photo->path, 'client-file-'.$id, ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function client(Request $request, int $id): JsonResponse
    {
        $this->access->authorize($request->user(), 'clients');
        $client = DB::table('clients')->find($id); abort_unless($client, 404);
        $result = ['client' => $client];
        foreach (['appointments' => 'appointments', 'invoices' => 'invoices', 'client_packages' => 'packages', 'memberships' => 'membership_plans', 'tasks' => 'tasks', 'photos' => 'photos', 'consents' => 'form_templates'] as $table => $permission) {
            if ($request->user()->allowed($permission.'.view')) { $result[$table] = DB::table($table)->where('client_id', $id)->orderByDesc('id')->limit(100)->get()->map(function ($r) { unset($r->path); return $r; }); }
        }
        if ($request->user()->allowed('treatment_records.view')) { $result['treatment_records'] = DB::table('treatment_records')->whereIn('appointment_id', DB::table('appointments')->where('client_id', $id)->select('id'))->get(); }
        if ($request->user()->allowed('membership_plans.view')) { $result['reward_points'] = (int) DB::table('reward_entries')->where('client_id', $id)->sum('points'); }
        return response()->json($result);
    }
}
