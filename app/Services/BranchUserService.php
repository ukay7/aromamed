<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Validation\ValidationException;

class BranchUserService
{
    public function save(array $data, User $actor, ?int $id): int
    {
        $context=app(BranchContext::class); $branch=$context->requireBranch();
        return DB::transaction(function () use ($data,$actor,$id,$context,$branch): int {
            $existing=$id?BranchDB::table('users')->lockForUpdate()->find($id):null;
            if ($id && ! $existing) { abort(404); }
            $role=BranchDB::table('roles')->find($data['role_id']); abort_unless($role,422,'Invalid branch role.');
            if ($existing?->is_org_owner) { throw ValidationException::withMessages(['role_id'=>'Organization owner accounts cannot be changed through branch user management.']); }
            if ($role->is_owner && ! $actor->is_org_owner && ! $actor->branchRole()?->is_owner) { abort(403,'Only an administrator can assign the branch administrator role.'); }
            if (! $actor->is_org_owner && ! $actor->branchRole()?->is_owner) {
                $allowed = $actor->branchRole()->permissions()->pluck('permissions.id')->all();
                $assigned = DB::table('permission_role')->where('role_id', $role->id)->pluck('permission_id')->all();
                abort_if(array_diff($assigned, $allowed), 403, 'You cannot assign a role with permissions you do not have.');
            }
            if ($id === $actor->id) { throw ValidationException::withMessages(['role_id'=>'Ask another administrator to manage your own branch access.']); }
            if ($existing && ! $actor->is_org_owner && DB::table('branch_user')->where('user_id',$id)->where('branch_id','!=',$branch)->exists()) {
                if ($existing->name !== $data['name'] || $existing->email !== $data['email'] || ! empty($data['password'])) { throw ValidationException::withMessages(['email'=>'Only the organization owner can change the identity or password of a shared account. Branch access can still be updated.']); }
            }
            $account=['name'=>$data['name'],'email'=>mb_strtolower(trim($data['email'])),'updated_at'=>now()];
            if (! empty($data['password'])) { $account['password']=Hash::make($data['password']); }
            if ($id) { DB::table('users')->where('id',$id)->where('organization_id',$context->organizationId)->update($account); }
            else { $id=DB::table('users')->insertGetId($account+['organization_id'=>$context->organizationId,'is_org_owner'=>false,'role_id'=>$role->id,'active'=>true,'created_at'=>now()]); }
            DB::table('branch_user')->updateOrInsert(['branch_id'=>$branch,'user_id'=>$id],['role_id'=>$role->id,'active'=>(bool)($data['active']??false),'created_at'=>now(),'updated_at'=>now()]);
            return $id;
        });
    }
}
