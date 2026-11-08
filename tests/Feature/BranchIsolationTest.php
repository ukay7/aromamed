<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BranchProvisioner;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;
use Tests\TestCase;

class BranchIsolationTest extends TestCase
{
    use DatabaseTruncation;
    private int $second;
    private int $secondClient;
    private int $secondStaff;
    private int $secondService;
    private int $secondRole;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(now()->setDate(2026,10,30)->setTime(9,0)); $this->seed(ClinicSeeder::class);
        $this->owner=User::where('is_org_owner',true)->firstOrFail();
        $this->second=app(BranchProvisioner::class)->create(1,['name'=>'Second branch','slug'=>'test-second','location'=>'Second location','currency'=>'CAD','timezone'=>'America/Toronto']);
        $this->secondRole=DB::table('roles')->where('branch_id',$this->second)->where('is_owner',true)->value('id');
        $this->secondClient=DB::table('clients')->insertGetId(['branch_id'=>$this->second,'name'=>'Private second branch client','email'=>'sample1@example.test','status'=>'active']);
        $category=DB::table('service_categories')->insertGetId(['branch_id'=>$this->second,'name'=>'Private category']);
        $this->secondStaff=DB::table('staff')->insertGetId(['branch_id'=>$this->second,'name'=>'Private doctor','specialty'=>'Skin','working_days'=>'["1","2","3","4","5","6"]']);
        $this->secondService=DB::table('services')->insertGetId(['branch_id'=>$this->second,'name'=>'Private treatment','category_id'=>$category,'duration'=>30,'buffer'=>10,'price_cents'=>5000]);
        DB::table('service_staff')->insert(['branch_id'=>$this->second,'staff_id'=>$this->secondStaff,'service_id'=>$this->secondService]);
        DB::table('invoices')->insert(['branch_id'=>$this->second,'number'=>'SECOND-1','client_id'=>$this->secondClient,'due_on'=>'2026-10-30','subtotal_cents'=>5000,'tax_cents'=>0,'total_cents'=>5000,'request_id'=>'second-invoice','created_at'=>now(),'updated_at'=>now()]);
    }

    private function member(int $branch, int $role): User
    {
        $user=User::factory()->create(); $user->organization_id=1; $user->role_id=$role; $user->save();
        DB::table('branch_user')->insert(['branch_id'=>$branch,'user_id'=>$user->id,'role_id'=>$role,'active'=>true]); return $user;
    }

    public function test_login_accepts_seeded_password_and_selects_assigned_branch(): void
    {
        $this->post('/login',['email'=>'OWNER@AROMA.LOCAL','password'=>env('CLINIC_OWNER_PASSWORD')])->assertRedirect('/admin');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('selected_branch',1);
        $this->post('/logout')->assertRedirect('/login');
        $user=$this->member($this->second,$this->secondRole);$user->password='Branch-Test-2026!';$user->save();
        $this->post('/login',['email'=>$user->email,'password'=>'Branch-Test-2026!'])->assertRedirect('/admin');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('selected_branch',$this->second);
    }

    public function test_branch_users_cannot_switch_to_other_branches_or_combined_view(): void
    {
        $user=$this->member($this->second,$this->secondRole);$this->actingAs($user);
        $boot=$this->getJson('/api/bootstrap')->assertOk()->json();$this->assertCount(1,$boot['branches']);
        $this->postJson('/api/branch-selection',['branch_id'=>1])->assertForbidden();
        $this->postJson('/api/branch-selection',['branch_id'=>'all'])->assertForbidden();
        $this->getJson('/api/organization')->assertForbidden();
        $list=$this->getJson('/api/modules/clients')->assertOk()->json('rows');$this->assertCount(1,$list);$this->assertEquals($this->secondClient,$list[0]['id']);
        $this->getJson('/api/clients/1/profile')->assertNotFound();
        $this->getJson('/api/billing/invoices/1')->assertNotFound();
        $this->putJson('/api/roles/1',['name'=>'Attack','description'=>'','permissions'=>[]])->assertNotFound();
    }

    public function test_forged_cross_branch_dropdown_ids_are_rejected(): void
    {
        $this->actingAs($this->owner)->withSession(['branch_id'=>1]);
        $booking=['client_id'=>$this->secondClient,'service_id'=>2,'staff_id'=>1,'starts_at'=>'2026-10-31T16:00','duration'=>30,'buffer'=>10,'status'=>'confirmed'];
        $this->postJson('/api/modules/appointments',$booking)->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $booking['client_id']=1;$booking['staff_id']=$this->secondStaff;$this->postJson('/api/modules/appointments',$booking)->assertUnprocessable()->assertJsonValidationErrors('staff_id');
        $this->postJson('/api/modules/tasks',['title'=>'Cross branch','client_id'=>$this->secondClient,'due_on'=>'2026-10-31','priority'=>'normal','status'=>'open'])->assertUnprocessable();
        $this->postJson('/api/billing/invoices',['client_id'=>$this->secondClient,'due_on'=>'2026-10-31','request_id'=>(string)Str::uuid(),'lines'=>[['type'=>'service','item_id'=>2,'quantity'=>1]]])->assertUnprocessable();
        $lookups=$this->getJson('/api/modules/appointments/lookup/client_id')->assertOk()->json();
        $this->assertNotContains($this->secondClient,array_column($lookups,'id'));
    }

    public function test_new_records_are_stamped_with_active_branch_and_unique_values_are_local(): void
    {
        $this->actingAs($this->owner)->withSession(['branch_id'=>$this->second]);
        $id=$this->postJson('/api/modules/clients',['branch_id'=>1,'name'=>'New second client','email'=>'sample2@example.test','status'=>'active'])->assertOk()->json('id');
        $this->assertDatabaseHas('clients',['id'=>$id,'branch_id'=>$this->second]);
        $this->postJson('/api/modules/clients',['name'=>'Duplicate','email'=>'sample2@example.test','status'=>'active'])->assertUnprocessable();
        $this->postJson('/api/roles',['name'=>'Records only','description'=>'Second branch role','permissions'=>[]])->assertOk();
        $this->postJson('/api/branch-selection',['branch_id'=>1])->assertOk();
        $this->postJson('/api/roles',['name'=>'Records only','description'=>'First branch role','permissions'=>[]])->assertOk();
    }

    public function test_combined_totals_equal_branches_and_records_require_specific_branch(): void
    {
        $this->actingAs($this->owner)->withSession(['branch_id'=>1]);
        $first=$this->getJson('/api/dashboard')->assertOk()->json('stats');
        $this->postJson('/api/branch-selection',['branch_id'=>$this->second])->assertOk();
        $second=$this->getJson('/api/dashboard')->assertOk()->json('stats');
        $this->postJson('/api/branch-selection',['branch_id'=>'all'])->assertOk();
        $data=$this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertEquals($first['clients']+$second['clients'],$data['stats']['clients']);
        $this->assertEquals($first['outstanding_cents']+$second['outstanding_cents'],$data['stats']['outstanding_cents']);
        $this->assertCount(2,$data['branches']);
        $this->getJson('/api/modules/clients')->assertStatus(409);
        $this->postJson('/api/modules/clients',['name'=>'No branch','status'=>'active'])->assertStatus(409);
        $this->getJson('/api/reports?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('aggregate',true);
    }

    public function test_aggregate_money_keeps_different_currencies_separate(): void
    {
        DB::table('branches')->where('id',$this->second)->update(['currency'=>'USD']);
        $this->actingAs($this->owner)->withSession(['branch_id'=>'all']);
        $data=$this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertTrue($data['mixed_currencies']);$this->assertCount(2,$data['currency_totals']);
        $this->assertArrayNotHasKey('outstanding_cents',$data['stats']);
        $report=$this->getJson('/api/reports')->assertOk()->json();$this->assertArrayNotHasKey('outstanding_cents',$report);
    }

    public function test_same_account_can_have_different_roles_in_each_branch(): void
    {
        $firstRole=DB::table('roles')->where('branch_id',1)->where('is_owner',true)->value('id');$user=$this->member(1,$firstRole);
        $limitedRole=DB::table('roles')->insertGetId(['branch_id'=>$this->second,'name'=>'Treatment view only','is_owner'=>false]);
        DB::table('permission_role')->insert(['role_id'=>$limitedRole,'permission_id'=>DB::table('permissions')->where('name','treatment_records.view')->value('id')]);
        DB::table('branch_user')->insert(['user_id'=>$user->id,'branch_id'=>$this->second,'role_id'=>$limitedRole,'active'=>true]);
        $this->actingAs($user)->withSession(['branch_id'=>1]);$this->getJson('/api/modules/clients')->assertOk();
        $this->postJson('/api/branch-selection',['branch_id'=>$this->second])->assertOk();
        $this->getJson('/api/modules/clients')->assertForbidden();$this->getJson('/api/modules/treatment_records')->assertOk();
        $menu=json_encode($this->getJson('/api/bootstrap')->json('menu'));$this->assertStringContainsString('Treatment records',$menu);$this->assertStringNotContainsString('Payments & invoices',$menu);
    }

    public function test_stale_tab_mutations_and_cross_organization_memberships_are_rejected(): void
    {
        $this->actingAs($this->owner)->withSession(['branch_id'=>$this->second]);
        $this->withHeader('X-Clinic-Branch','1')->postJson('/api/modules/clients',['name'=>'Stale write','status'=>'active'])->assertStatus(409);
        $this->flushHeaders();
        $otherOrg=DB::table('organizations')->insertGetId(['name'=>'Other organization','currency'=>'CAD','timezone'=>'America/Toronto']);
        $outside=User::factory()->create();$outside->organization_id=$otherOrg;$outside->save();
        $this->postJson('/api/organization/memberships',['user_id'=>$outside->id,'branch_id'=>$this->second,'role_id'=>$this->secondRole,'active'=>true])->assertUnprocessable();
        $this->postJson('/api/organization/memberships',['user_id'=>$this->member(1,1)->id,'branch_id'=>1,'role_id'=>$this->secondRole,'active'=>true])->assertUnprocessable();
    }

    public function test_public_booking_rejects_another_branchs_service_and_doctor(): void
    {
        $this->postJson('/book/test-second',['name'=>'New Guest','email'=>'guest@example.test','phone'=>'555','service_id'=>2,'staff_id'=>1,'starts_at'=>'2026-10-31T16:00','acknowledged'=>true])->assertUnprocessable();
        $this->get('/book/test-second')->assertOk()->assertSee('Private treatment')->assertDontSee('HydraFacial');
    }

    public function test_mysql_foreign_keys_reject_cross_branch_relationships(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tasks')->insert(['branch_id'=>1,'title'=>'Invalid cross branch','client_id'=>$this->secondClient,'due_on'=>'2026-10-31']);
    }
}
