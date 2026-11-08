<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    private array $tables = ['roles','menu_items','settings','clients','service_categories','services','staff','service_staff','resources','packages','client_packages','appointments','package_redemptions','blocked_times','waitlist','treatment_records','record_amendments','form_templates','consents','photos','suppliers','products','stock_movements','invoices','invoice_lines','payment_methods','payments','membership_plans','memberships','reward_entries','tasks','leads','campaigns','message_outbox','website_pages','audit_logs'];

    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('currency', 3)->default('CAD'); $t->string('timezone')->default('America/Toronto'); $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t): void {
            $t->id(); $t->foreignId('organization_id')->constrained(); $t->string('name'); $t->string('slug', 100)->unique(); $t->string('location'); $t->string('currency', 3); $t->string('timezone'); $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['organization_id', 'name']);
        });
        $settings = DB::table('settings')->pluck('value', 'key');
        $organization = DB::table('organizations')->insertGetId(['name'=>$settings['clinic_name'] ?? 'Aroma Med Spa', 'currency'=>$settings['currency'] ?? 'CAD', 'timezone'=>$settings['timezone'] ?? 'America/Toronto', 'created_at'=>now(),'updated_at'=>now()]);
        $branch = DB::table('branches')->insertGetId(['organization_id'=>$organization,'name'=>'North York','slug'=>'aroma-north-york','location'=>$settings['location'] ?? 'North York, Toronto','currency'=>$settings['currency'] ?? 'CAD','timezone'=>$settings['timezone'] ?? 'America/Toronto','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $t): void { $t->foreignId('branch_id')->nullable()->constrained('branches'); });
            DB::table($name)->update(['branch_id'=>$branch]);
            Schema::table($name, function (Blueprint $t): void { $t->unsignedBigInteger('branch_id')->nullable(false)->change(); });
        }
        foreach (['roles'=>'name','clients'=>'email','service_categories'=>'name','products'=>'sku','website_pages'=>'slug','invoices'=>'number','payments'=>'request_id','stock_movements'=>'request_id','reward_entries'=>'request_id','message_outbox'=>'dedup_key'] as $table=>$column) {
            Schema::table($table, function (Blueprint $t) use ($column): void { $t->dropUnique([$column]); $t->unique(['branch_id',$column]); });
        }
        Schema::table('settings', function (Blueprint $t): void { $t->dropPrimary(); $t->primary(['branch_id','key']); });
        Schema::table('users', function (Blueprint $t): void {
            $t->foreignId('organization_id')->nullable()->constrained(); $t->boolean('is_org_owner')->default(false);
        });
        DB::table('users')->update(['organization_id'=>$organization]);
        DB::table('users')->whereIn('role_id', DB::table('roles')->where('is_owner',true)->select('id'))->update(['is_org_owner'=>true]);
        Schema::create('branch_user', function (Blueprint $t): void {
            $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('user_id')->constrained(); $t->foreignId('role_id')->constrained(); $t->boolean('active')->default(true); $t->timestamps(); $t->unique(['branch_id','user_id']);
        });
        foreach (DB::table('users')->whereNotNull('role_id')->get() as $user) { DB::table('branch_user')->insert(['branch_id'=>$branch,'user_id'=>$user->id,'role_id'=>$user->role_id,'active'=>$user->active,'created_at'=>now(),'updated_at'=>now()]); }
        Schema::table('appointments', function (Blueprint $t): void { $t->index(['branch_id','starts_at']); $t->index(['room_id','starts_at']); $t->index(['device_id','starts_at']); });
        Schema::table('invoices', function (Blueprint $t): void { $t->index(['branch_id','created_at']); });
        Schema::table('payments', function (Blueprint $t): void { $t->index(['branch_id','created_at']); });
        Schema::table('tasks', function (Blueprint $t): void { $t->index(['branch_id','due_on']); });
    }

    public function down(): void
    {
        if (DB::table('branches')->count() > 1) { throw new RuntimeException('Do not roll back tenancy after creating additional branches. Restore a pre-tenancy backup instead.'); }
        Schema::dropIfExists('branch_user');
        Schema::table('users', function (Blueprint $t): void { $t->dropConstrainedForeignId('organization_id'); $t->dropColumn('is_org_owner'); });
        Schema::table('appointments', function (Blueprint $t): void { $t->dropIndex(['branch_id','starts_at']); $t->dropIndex(['room_id','starts_at']); $t->dropIndex(['device_id','starts_at']); });
        Schema::table('invoices', function (Blueprint $t): void { $t->dropIndex(['branch_id','created_at']); });
        Schema::table('payments', function (Blueprint $t): void { $t->dropIndex(['branch_id','created_at']); });
        Schema::table('tasks', function (Blueprint $t): void { $t->dropIndex(['branch_id','due_on']); });
        Schema::table('settings', function (Blueprint $t): void { $t->dropPrimary(); $t->primary('key'); });
        foreach (['roles'=>'name','clients'=>'email','service_categories'=>'name','products'=>'sku','website_pages'=>'slug','invoices'=>'number','payments'=>'request_id','stock_movements'=>'request_id','reward_entries'=>'request_id','message_outbox'=>'dedup_key'] as $table=>$column) {
            Schema::table($table, function (Blueprint $t) use ($column): void { $t->dropUnique(['branch_id',$column]); $t->unique($column); });
        }
        foreach ($this->tables as $name) { Schema::table($name, function (Blueprint $t): void { $t->dropConstrainedForeignId('branch_id'); }); }
        Schema::dropIfExists('branches'); Schema::dropIfExists('organizations');
    }
};
