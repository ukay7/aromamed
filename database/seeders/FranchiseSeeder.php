<?php

namespace Database\Seeders;

use App\Services\BranchProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};

class FranchiseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local','testing'])) { throw new \RuntimeException('Franchise sample data is limited to local/test environments.'); }
        $org=(int)DB::table('organizations')->value('id');
        $branch=DB::table('branches')->where('organization_id',$org)->where('slug','aroma-mississauga')->value('id');
        if (! $branch) {
            $branch=app(BranchProvisioner::class)->create($org,['name'=>'Mississauga','slug'=>'aroma-mississauga','location'=>'Mississauga, Ontario','currency'=>'CAD','timezone'=>'America/Toronto']);
            $insert=static fn (string $table,array $data): int => DB::table($table)->insertGetId($data+['branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]);
            $client=$insert('clients',['name'=>'Sample Branch Two Client','email'=>'branch-two@example.test','status'=>'active','marketing_consent'=>false]);
            $category=$insert('service_categories',['name'=>'Skin & wellness','color'=>'#739d92']);
            $staff=$insert('staff',['name'=>'Sample Dr. Sarah Ahmed','specialty'=>'Skin practitioner','starts_at'=>'09:00','ends_at'=>'18:00','working_days'=>json_encode(['1','2','3','4','5','6']),'color'=>'#739d92','status'=>'active']);
            $service=$insert('services',['name'=>'Skin consultation','category_id'=>$category,'description'=>'Sample consultation at the second branch','duration'=>30,'buffer'=>10,'price_cents'=>8500,'status'=>'active']);
            DB::table('service_staff')->insert(['branch_id'=>$branch,'service_id'=>$service,'staff_id'=>$staff]);
            $start=now('America/Toronto')->startOfDay()->setTime(15,0);
            $appointment=$insert('appointments',['client_id'=>$client,'service_id'=>$service,'staff_id'=>$staff,'starts_at'=>$start,'ends_at'=>$start->copy()->addMinutes(30),'occupied_until'=>$start->copy()->addMinutes(40),'duration'=>30,'buffer'=>10,'price_cents'=>8500,'status'=>'confirmed','notes'=>'Synthetic branch isolation example']);
            $invoice=$insert('invoices',['number'=>'MISS-00001','client_id'=>$client,'appointment_id'=>$appointment,'due_on'=>$start->toDateString(),'subtotal_cents'=>8500,'tax_cents'=>0,'discount_cents'=>0,'total_cents'=>8500,'request_id'=>'sample-second-branch']);
            DB::table('invoice_lines')->insert(['branch_id'=>$branch,'invoice_id'=>$invoice,'description'=>'Skin consultation','item_type'=>'appointment','item_id'=>$appointment,'quantity'=>1,'unit_price_cents'=>8500,'total_cents'=>8500]);
        }
        foreach ([1=>['North York Manager','north-york@aroma.local'],$branch=>['Mississauga Manager','mississauga@aroma.local']] as $branchId=>[$name,$email]) {
            $role=DB::table('roles')->where('branch_id',$branchId)->where('is_owner',true)->value('id');
            $user=DB::table('users')->where('email',$email)->value('id');
            if (! $user) {
                $password=env('CLINIC_BRANCH_PASSWORD');
                if (! $password) { throw new \RuntimeException('Set CLINIC_BRANCH_PASSWORD for the local branch manager logins.'); }
                $user=DB::table('users')->insertGetId(['organization_id'=>$org,'is_org_owner'=>false,'name'=>$name,'email'=>$email,'password'=>Hash::make($password),'role_id'=>$role,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
                DB::table('branch_user')->insert(['branch_id'=>$branchId,'user_id'=>$user,'role_id'=>$role,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }
}
