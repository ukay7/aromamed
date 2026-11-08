<?php

namespace App\Console\Commands;

use App\Services\BranchProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class InitializeOwner extends Command
{
    protected $signature = 'clinic:initialize-owner';

    protected $description = 'Initialize a fresh clinic and its first owner without sample records';

    public function handle(BranchProvisioner $provisioner): int
    {
        if (DB::table('users')->exists() || DB::table('roles')->exists()) {
            $this->error('Setup requires a fresh database with no users or roles. Existing accounts were not changed.');

            return self::FAILURE;
        }
        if (DB::table('branches')->count() !== 1 || DB::table('organizations')->count() !== 1) {
            $this->error('Run migrations on a fresh database first; setup expects one initial organization and branch.');

            return self::FAILURE;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the owner password securely.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->ask('Owner name'),
            'email' => $this->ask('Owner email'),
            'password' => $this->secret('Owner password (at least 12 characters, mixed case, number and symbol)', false),
            'password_confirmation' => $this->secret('Confirm owner password', false),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data, $provisioner): void {
            $branch = DB::table('branches')->lockForUpdate()->first();
            if (DB::table('users')->exists() || DB::table('roles')->exists()) {
                throw new \RuntimeException('Clinic setup has already been performed.');
            }
            $modules = array_unique([
                ...array_map(fn (string $key, array $module): string => $module['permission'] ?? $key, array_keys(config('clinic.modules')), config('clinic.modules')),
                'dashboard', 'reports', 'settings',
            ]);
            foreach ($modules as $module) {
                foreach (['view', 'create', 'edit'] as $action) {
                    DB::table('permissions')->updateOrInsert(['name' => $module.'.'.$action], ['module' => $module, 'action' => $action]);
                }
            }
            $provisioner->initialize((int) $branch->id);
            $roleId = DB::table('roles')->where('branch_id', $branch->id)->where('is_owner', true)->value('id');
            $userId = DB::table('users')->insertGetId([
                'name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']),
                'organization_id' => $branch->organization_id, 'is_org_owner' => true,
                'role_id' => $roleId, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('branch_user')->insert([
                'branch_id' => $branch->id, 'user_id' => $userId, 'role_id' => $roleId,
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->info('Clinic initialized. Sign in with the owner email and password you just entered.');
        $this->info('Initial branch: North York; currency: CAD; timezone: America/Toronto; tax: 0%. Review branch settings before use.');

        return self::SUCCESS;
    }
}
