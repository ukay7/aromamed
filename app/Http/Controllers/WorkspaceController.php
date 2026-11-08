<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\ClinicAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\BranchDB as DB;
use App\Services\BranchContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function bootstrap(Request $request, ClinicAccess $access): JsonResponse
    {
        $user = $request->user();
        $context = app(BranchContext::class);
        abort_unless($user->active, 403);
        $modules = [];
        foreach (config('clinic.modules') as $key => $definition) {
            $permission = $definition['permission'] ?? $key;
            if (! $context->aggregate && $user->allowed($permission.'.view')) {
                $modules[$key] = $definition + ['can_create' => ! ($definition['readonly'] ?? false) && $user->allowed($permission.'.create'), 'can_edit' => ! (($definition['readonly'] ?? false) || ($definition['immutable'] ?? false)) && $user->allowed($permission.'.edit')];
            }
        }
        $organization = \Illuminate\Support\Facades\DB::table('organizations')->find($context->organizationId);
        $branches = $context->accessible($user);
        $branch = $branches->firstWhere('id',$context->branchId);
        $settings = $context->aggregate ? ['clinic_name'=>$organization->name,'location'=>'All branches','currency'=>$organization->currency,'timezone'=>$organization->timezone,'tax_percent'=>0] : DB::table('settings')->whereIn('key',['clinic_name','location','currency','timezone','tax_percent'])->pluck('value','key')->all();
        if ($context->aggregate && $branches->pluck('currency')->unique()->count() === 1) { $settings['currency']=$branches->first()->currency; }
        return response()->json(['theme'=>app(\App\Services\BranchTheme::class)->current(),'themes'=>config('clinic_themes'),'user'=>['name'=>$user->name,'role'=>$user->is_org_owner ? 'Organization Owner' : $user->branchRole()?->name,'is_org_owner'=>$user->is_org_owner], 'organization'=>$organization,'branches'=>$branches,'branch'=>$branch,'selected_branch'=>$context->aggregate?'all':$context->branchId,'aggregate'=>$context->aggregate,'menu'=>$access->menu($user),'modules'=>$modules,'settings'=>$settings,'booking_url'=>$branch?'/book/'.$branch->slug:'/book','permissions'=>['dashboard'=>$user->allowed('dashboard.view'),'reports'=>$user->allowed('reports.view'),'settings'=>!$context->aggregate && $user->allowed('settings.view'),'settings_edit'=>!$context->aggregate && $user->allowed('settings.edit')]]);
    }

    public function login(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $data['email'] = mb_strtolower(trim($data['email']));
        if (! Auth::attempt($data + ['active' => true])) { throw ValidationException::withMessages(['email' => 'The login details are incorrect or the account is inactive.']); }
        if (! Auth::user()->organization_id || app(BranchContext::class)->accessible(Auth::user())->isEmpty()) {
            Auth::logout(); throw ValidationException::withMessages(['email'=>'Your account has no active branch access. Ask your organization owner to assign a branch.']);
        }
        $request->session()->regenerate();
        $request->session()->forget('branch_id');
        return redirect('/admin');
    }

    public function logout(Request $request): mixed
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect('/login');
    }

    public function roles(Request $request): JsonResponse
    {
        abort_unless($request->user()->allowed('access.view'), 403);
        $branch = app(BranchContext::class)->requireBranch();
        return response()->json(['roles' => Role::where('branch_id',$branch)->with('permissions')->get(), 'permissions' => DB::table('permissions')->orderBy('module')->orderBy('id')->get()]);
    }

    public function saveRole(Request $request, ClinicAccess $access, ?int $id = null): JsonResponse
    {
        abort_unless($request->user()->allowed('access.'.($id ? 'edit' : 'create')), 403);
        $branch = app(BranchContext::class)->requireBranch();
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('roles')->where('branch_id',$branch)->ignore($id)], 'description' => 'nullable|string|max:1000', 'permissions' => 'present|array', 'permissions.*' => 'integer|exists:permissions,id|distinct']);
        $role = DB::transaction(function () use ($id, $data, $request): Role {
            $role = $id ? Role::where('branch_id',app(BranchContext::class)->requireBranch())->lockForUpdate()->findOrFail($id) : new Role;
            if ($role->is_owner || ($role->id && $role->id === $request->user()->branchRole()?->id)) { throw ValidationException::withMessages(['name' => 'The administrator role and your own assigned role are protected from permission changes.']); }
            if (! $request->user()->is_org_owner && ! $request->user()->branchRole()?->is_owner) {
                $allowed = $request->user()->branchRole()->permissions()->pluck('permissions.id')->all();
                if (array_diff($data['permissions'], $allowed)) { abort(403, 'You cannot grant permissions you do not have.'); }
            }
            $role->branch_id=app(BranchContext::class)->requireBranch();
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            $selected = DB::table('permissions')->whereIn('id', $data['permissions'])->get();
            $viewIds = DB::table('permissions')->whereIn('module', $selected->pluck('module'))->where('action', 'view')->pluck('id');
            $role->permissions()->sync(array_unique([...$data['permissions'], ...$viewIds->all()]));
            return $role;
        });
        $access->audit($request->user(), 'permissions_updated', 'roles', $role->id);
        return response()->json(['id' => $role->id, 'message' => 'Role and permissions saved.']);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->allowed('settings.view'), 403);
        app(BranchContext::class)->requireBranch();
        return response()->json(DB::table('settings')->pluck('value', 'key'));
    }

    public function saveSettings(Request $request, ClinicAccess $access): JsonResponse
    {
        abort_unless($request->user()->allowed('settings.edit'), 403);
        $branch=app(BranchContext::class)->requireBranch();
        $data = $request->validate(['clinic_name' => 'required|string|max:100', 'location' => 'required|string|max:150', 'currency' => 'required|in:CAD,USD,GBP,PKR,AED,EUR', 'timezone' => 'required|timezone', 'tax_percent' => 'required|numeric|between:0,100', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'booking_notice_hours' => 'required|integer|between:0,168', 'reminder_hours' => 'required|integer|between:1,168','theme_key'=>['sometimes','required',Rule::in([...array_keys(config('clinic_themes')),'custom'])],'theme_primary'=>'required_if:theme_key,custom|nullable|regex:/^#[a-fA-F0-9]{6}$/','theme_sidebar'=>'required_if:theme_key,custom|nullable|regex:/^#[a-fA-F0-9]{6}$/','theme_background'=>'required_if:theme_key,custom|nullable|regex:/^#[a-fA-F0-9]{6}$/']);
        app(\App\Services\BranchTheme::class)->validateColors($data);
        DB::transaction(function () use ($data,$branch): void {
            $existing=\Illuminate\Support\Facades\DB::table('branches')->where('id',$branch)->lockForUpdate()->first();
            if ($data['currency'] !== $existing->currency && DB::table('invoices')->exists()) { throw ValidationException::withMessages(['currency'=>'Currency cannot change after invoices exist.']); }
            foreach ($data as $key => $value) { DB::table('settings')->updateOrInsert(['key'=>$key],['value'=>$value]); }
            \Illuminate\Support\Facades\DB::table('branches')->where('id',$branch)->update(['location'=>$data['location'],'currency'=>$data['currency'],'timezone'=>$data['timezone'],'updated_at'=>now()]);
        });
        $access->audit($request->user(), 'updated', 'settings', null);
        return response()->json(['message' => 'Clinic settings saved.']);
    }
}
