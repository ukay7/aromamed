<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchContext
{
    public ?int $organizationId = null;
    public ?int $branchId = null;
    public array $branchIds = [];
    public bool $aggregate = false;

    public function resolve(Request $request): void
    {
        $this->clear();
        if ($request->route('branchSlug')) {
            $branch = DB::table('branches')->where('slug',$request->route('branchSlug'))->where('active',true)->first();
            abort_unless($branch,404); $this->setBranch($branch); return;
        }
        $user = $request->user();
        if (! $user) { return; }
        abort_unless($user->active && $user->organization_id,403,'Your account has no active organization access.');
        $this->organizationId = (int) $user->organization_id;
        $accessible = $this->accessible($user);
        abort_if($accessible->isEmpty(),403,'No active branch is assigned to this account.');
        $selection = $request->session()->get('branch_id');
        if ($selection === 'all' && $user->is_org_owner) {
            $this->aggregate = true; $this->branchIds = $accessible->pluck('id')->map(fn ($id): int => (int)$id)->all(); return;
        }
        $branch = $accessible->firstWhere('id',(int)$selection) ?? $accessible->first();
        $this->setBranch($branch); $request->session()->put('branch_id',$this->branchId);
    }

    public function accessible(User $user): \Illuminate\Support\Collection
    {
        $query = DB::table('branches')->where('organization_id',$user->organization_id)->where('active',true);
        if (! $user->is_org_owner) { $query->whereIn('id',DB::table('branch_user')->where('user_id',$user->id)->where('active',true)->select('branch_id')); }
        return $query->orderBy('id')->get();
    }

    public function setBranch(object $branch): void
    {
        $this->organizationId=(int)$branch->organization_id; $this->branchId=(int)$branch->id; $this->branchIds=[$this->branchId]; $this->aggregate=false;
    }

    public function requireBranch(): int
    {
        abort_unless($this->branchId && ! $this->aggregate,409,'Select an individual branch before working with records.');
        return $this->branchId;
    }

    public function clear(): void
    {
        $this->organizationId=null; $this->branchId=null; $this->branchIds=[]; $this->aggregate=false;
    }
}
