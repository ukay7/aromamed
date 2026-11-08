<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $links = [
        'services'=>['category_id'=>'service_categories','required_room_id'=>'resources','required_device_id'=>'resources'],
        'service_staff'=>['service_id'=>'services','staff_id'=>'staff'],
        'packages'=>['service_id'=>'services'],
        'client_packages'=>['client_id'=>'clients','package_id'=>'packages'],
        'appointments'=>['client_id'=>'clients','service_id'=>'services','staff_id'=>'staff','room_id'=>'resources','device_id'=>'resources','client_package_id'=>'client_packages','waitlist_id'=>'waitlist'],
        'package_redemptions'=>['appointment_id'=>'appointments','client_package_id'=>'client_packages'],
        'blocked_times'=>['staff_id'=>'staff'],
        'waitlist'=>['client_id'=>'clients','service_id'=>'services'],
        'treatment_records'=>['appointment_id'=>'appointments'],
        'record_amendments'=>['treatment_record_id'=>'treatment_records'],
        'consents'=>['client_id'=>'clients','appointment_id'=>'appointments','form_template_id'=>'form_templates'],
        'photos'=>['client_id'=>'clients','appointment_id'=>'appointments'],
        'products'=>['supplier_id'=>'suppliers'],
        'stock_movements'=>['product_id'=>'products','treatment_record_id'=>'treatment_records'],
        'invoices'=>['client_id'=>'clients','appointment_id'=>'appointments'],
        'invoice_lines'=>['invoice_id'=>'invoices'],
        'payments'=>['invoice_id'=>'invoices','payment_method_id'=>'payment_methods','refund_of'=>'payments'],
        'memberships'=>['client_id'=>'clients','membership_plan_id'=>'membership_plans'],
        'reward_entries'=>['client_id'=>'clients'],
        'tasks'=>['client_id'=>'clients'],
        'leads'=>['client_id'=>'clients','service_id'=>'services'],
        'message_outbox'=>['client_id'=>'clients','campaign_id'=>'campaigns','appointment_id'=>'appointments'],
        'menu_items'=>['parent_id'=>'menu_items'],
        'branch_user'=>['role_id'=>'roles'],
    ];

    private function referencedTables(): array
    {
        $tables=[];
        foreach ($this->links as $links) { $tables=[...$tables,...array_values($links)]; }
        return array_values(array_unique($tables));
    }

    public function up(): void
    {
        foreach ($this->referencedTables() as $name) { Schema::table($name, function (Blueprint $t): void { $t->unique(['branch_id','id']); }); }
        foreach ($this->links as $table=>$links) {
            foreach ($links as $column=>$parent) {
                Schema::table($table, function (Blueprint $t) use ($table,$column,$parent): void { $t->foreign(['branch_id',$column],'fk_branch_'.$table.'_'.$column)->references(['branch_id','id'])->on($parent); });
            }
        }
        Schema::table('invoices', function (Blueprint $t): void { $t->dropUnique(['request_id']); $t->unique(['branch_id','request_id']); });
    }

    public function down(): void
    {
        foreach ($this->links as $table=>$links) { foreach ($links as $column=>$parent) { Schema::table($table, function (Blueprint $t) use ($table,$column): void { $t->dropForeign('fk_branch_'.$table.'_'.$column); }); } }
        foreach ($this->referencedTables() as $name) { Schema::table($name, function (Blueprint $t): void { $t->dropUnique(['branch_id','id']); }); }
        Schema::table('invoices', function (Blueprint $t): void { $t->dropUnique(['branch_id','request_id']); $t->unique('request_id'); });
    }
};
