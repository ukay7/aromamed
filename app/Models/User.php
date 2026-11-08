<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['active' => true];
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function allowed(string $permission): bool
    {
        $context = app(\App\Services\BranchContext::class);
        if (! $this->active || ! $this->organization_id || (int)$this->organization_id !== $context->organizationId) { return false; }
        if ($this->is_org_owner) { return true; }
        $role = $this->branchRole();
        return $role && ((bool)$role->is_owner || $role->permissions()->where('name',$permission)->exists());
    }

    public function branchRole(): ?Role
    {
        $context = app(\App\Services\BranchContext::class);
        if (! $context->branchId) { return null; }
        $id = \Illuminate\Support\Facades\DB::table('branch_user')->where('branch_id',$context->branchId)->where('user_id',$this->id)->where('active',true)->value('role_id');
        return $id ? Role::where('branch_id',$context->branchId)->find($id) : null;
    }
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'is_org_owner' => 'boolean',
        ];
    }
}
