<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BranchProvisioner
{
    public function create(int $organizationId, array $data): int
    {
        return DB::transaction(function () use ($organizationId, $data): int {
            $source = DB::table('branches')->where('organization_id',$organizationId)->orderBy('id')->first();
            $branchId = DB::table('branches')->insertGetId($data + ['organization_id'=>$organizationId,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            $this->initialize($branchId,$source?->id);
            return $branchId;
        });
    }

    public function initialize(int $branchId, ?int $sourceId = null): void
    {
        $branch = DB::table('branches')->find($branchId);
        $defaults = ['clinic_name'=>DB::table('organizations')->where('id',$branch->organization_id)->value('name'),'location'=>$branch->location,'currency'=>$branch->currency,'timezone'=>$branch->timezone,'tax_percent'=>'0','email'=>'','phone'=>'','booking_notice_hours'=>'12','reminder_hours'=>'24'];
        foreach ($defaults as $key=>$value) { DB::table('settings')->updateOrInsert(['branch_id'=>$branchId,'key'=>$key],['value'=>$value]); }
        if (DB::table('roles')->where('branch_id',$branchId)->exists()) { return; }
        $ownerRole = DB::table('roles')->insertGetId(['branch_id'=>$branchId,'name'=>'Branch Administrator','description'=>'Manage this branch only','is_owner'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $starterScopes = [
            'Receptionist'=>['dashboard','appointments','blocked_times','waitlist','clients','services','staff','packages','invoices','tasks','form_templates'],
            'Practitioner'=>['dashboard','appointments','clients','treatment_records','form_templates','photos','services','tasks'],
            'Stylist'=>['dashboard','appointments','clients','treatment_records','services','tasks'],
            'Accountant'=>['dashboard','invoices','reports','clients'],
            'Inventory'=>['dashboard','products'], 'Marketing'=>['dashboard','campaigns','tasks'],
        ];
        foreach ($starterScopes as $name=>$modules) {
            $roleId=DB::table('roles')->insertGetId(['branch_id'=>$branchId,'name'=>$name,'description'=>$name.' at '.$branch->name,'is_owner'=>false,'created_at'=>now(),'updated_at'=>now()]);
            foreach (DB::table('permissions')->whereIn('module',$modules)->pluck('id') as $permissionId) { DB::table('permission_role')->insert(['role_id'=>$roleId,'permission_id'=>$permissionId]); }
        }
        foreach (DB::table('users')->where('organization_id',$branch->organization_id)->where('is_org_owner',true)->get() as $owner) {
            DB::table('branch_user')->updateOrInsert(['branch_id'=>$branchId,'user_id'=>$owner->id],['role_id'=>$ownerRole,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        }
        $parents=[]; $position=0;
        foreach (['Your clinic','Care & treatments','Business','Manage'] as $label) { $parents[$label]=DB::table('menu_items')->insertGetId(['branch_id'=>$branchId,'label'=>$label,'sort_order'=>$position++ * 100,'created_at'=>now(),'updated_at'=>now()]); }
        DB::table('menu_items')->insert(['branch_id'=>$branchId,'label'=>'Overview','module'=>'dashboard','parent_id'=>$parents['Your clinic'],'icon'=>'th-large','sort_order'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $menu=[];
        foreach (['appointments','clients',...array_diff(array_keys(config('clinic.modules')),['appointments','clients'])] as $key) {
            $module=config('clinic.modules.'.$key);
            $menu[$key]=DB::table('menu_items')->insertGetId(['branch_id'=>$branchId,'label'=>$module['title'],'module'=>$key,'parent_id'=>isset($module['parent'])?$menu[$module['parent']]:$parents[$module['group']],'icon'=>$module['icon'],'sort_order'=>++$position,'created_at'=>now(),'updated_at'=>now()]);
        }
        foreach (['reports'=>['Reports & insights','Business','chart-bar'],'settings'=>['Branch settings','Manage','cog']] as $key=>[$label,$group,$icon]) { DB::table('menu_items')->insert(['branch_id'=>$branchId,'label'=>$label,'module'=>$key,'parent_id'=>$parents[$group],'icon'=>$icon,'sort_order'=>200,'created_at'=>now(),'updated_at'=>now()]); }
        DB::table('payment_methods')->insert(['branch_id'=>$branchId,'name'=>'Cash','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    }
}
