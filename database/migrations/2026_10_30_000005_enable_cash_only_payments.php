<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('payment_methods')->where('name', '!=', 'Cash')->update(['active'=>false]);
            foreach (DB::table('branches')->pluck('id') as $branch) {
                $cash = DB::table('payment_methods')->where('branch_id', $branch)->where('name', 'Cash')->first();
                if ($cash) { DB::table('payment_methods')->where('id', $cash->id)->update(['active'=>true]); }
                else { DB::table('payment_methods')->insert(['branch_id'=>$branch,'name'=>'Cash','active'=>true,'created_at'=>now(),'updated_at'=>now()]); }
            }
        });
    }

    public function down(): void
    {
        // Historical method activation cannot be inferred safely; keep the ledger intact.
    }
};
