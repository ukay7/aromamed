<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{AppointmentService, BillingService};
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClinicWorkflowTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 0));
        $this->seed(ClinicSeeder::class);
    }

    private function owner(): User { return User::where('email', 'owner@aroma.local')->firstOrFail(); }

    private function booking(array $extra = []): array
    {
        return array_replace(['client_id'=>1,'service_id'=>2,'staff_id'=>1,'room_id'=>1,'device_id'=>null,'client_package_id'=>null,'starts_at'=>'2026-10-31T15:00','duration'=>30,'buffer'=>10,'status'=>'confirmed','notes'=>'Test visit'], $extra);
    }

    public function test_all_modules_and_dashboard_load_for_owner(): void
    {
        $this->actingAs($this->owner());
        $this->get('/admin')->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.role', 'Organization Owner');
        foreach (array_keys(config('clinic.modules')) as $module) { $this->getJson('/api/modules/'.$module)->assertOk(); }
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.appointments', 6)->assertJsonPath('stats.outstanding_cents', 5255);
        $this->getJson('/api/reports')->assertOk();
        $this->get('/book')->assertOk();
    }

    public function test_treatment_only_role_has_filtered_navigation_and_api_denials(): void
    {
        $roleId=DB::table('roles')->insertGetId(['name'=>'Records only','is_owner'=>false,'branch_id'=>1]);
        $permission=DB::table('permissions')->where('name','treatment_records.view')->value('id');
        DB::table('permission_role')->insert(['role_id'=>$roleId,'permission_id'=>$permission]);
        $user=User::factory()->create();$user->role_id=$roleId;$user->organization_id=1;$user->save();
        DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user->id,'role_id'=>$roleId,'active'=>true]);
        $this->actingAs($user);
        $boot=$this->getJson('/api/bootstrap')->assertOk()->json();
        $menu=json_encode($boot['menu']);
        $this->assertStringContainsString('Treatment records',$menu);
        $this->assertStringNotContainsString('Payments & invoices',$menu);
        $this->assertStringNotContainsString('Dashboard',$menu);
        $this->getJson('/api/modules/treatment_records')->assertOk();
        $this->getJson('/api/modules/invoices')->assertForbidden();
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->postJson('/api/modules/treatment_records',[])->assertForbidden();
    }

    public function test_booking_conflicts_include_buffer_client_room_and_breaks(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/modules/appointments',$this->booking())->assertOk();
        $this->postJson('/api/modules/appointments',$this->booking(['client_id'=>2,'starts_at'=>'2026-10-31T15:35']))->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/modules/appointments',$this->booking(['staff_id'=>2,'room_id'=>2]))->assertUnprocessable();
        $this->postJson('/api/modules/appointments',$this->booking(['client_id'=>2,'staff_id'=>2]))->assertUnprocessable();
        $this->postJson('/api/modules/appointments',$this->booking(['starts_at'=>'2026-10-31T13:10']))->assertUnprocessable();
        $this->postJson('/api/modules/appointments',$this->booking(['starts_at'=>'2026-10-31T15:40']))->assertOk();
    }

    public function test_service_practitioner_eligibility_is_enforced(): void
    {
        $this->actingAs($this->owner())->postJson('/api/modules/appointments',$this->booking(['staff_id'=>3]))->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_package_consumed_once_only_on_completed_documented_visit(): void
    {
        $this->actingAs($this->owner());
        $payload=$this->booking(['service_id'=>1,'client_package_id'=>1,'duration'=>60]);
        $id=$this->postJson('/api/modules/appointments',$payload)->assertOk()->json('id');
        $this->assertDatabaseHas('client_packages',['id'=>1,'sessions_used'=>0]);
        $this->putJson('/api/modules/appointments/'.$id,array_replace($payload,['status'=>'completed']))->assertUnprocessable();
        $this->postJson('/api/modules/consents',['client_id'=>1,'appointment_id'=>$id,'form_template_id'=>1,'responses'=>'Test acknowledgement','signed_name'=>'Test Client','acknowledged'=>true])->assertOk();
        $this->postJson('/api/modules/treatment_records',['appointment_id'=>$id,'assessment'=>'Test assessment','treatment'=>'Test treatment','status'=>'finalized'])->assertOk();
        $this->putJson('/api/modules/appointments/'.$id,array_replace($payload,['status'=>'completed']))->assertOk();
        $this->assertDatabaseHas('client_packages',['id'=>1,'sessions_used'=>1]);
        $this->putJson('/api/modules/appointments/'.$id,array_replace($payload,['status'=>'completed']))->assertUnprocessable();
        $this->assertDatabaseCount('package_redemptions',1);
    }

    public function test_billing_is_idempotent_and_stock_and_payment_limits_are_enforced(): void
    {
        $this->actingAs($this->owner());
        $payload=['client_id'=>1,'due_on'=>'2026-11-01','discount'=>0,'request_id'=>(string)Str::uuid(),'lines'=>[['type'=>'product','item_id'=>1,'quantity'=>2],['type'=>'package','item_id'=>1,'quantity'=>1]]];
        $id=$this->postJson('/api/billing/invoices',$payload)->assertOk()->json('id');
        $this->postJson('/api/billing/invoices',$payload)->assertOk()->assertJsonPath('id',$id);
        $this->assertDatabaseHas('products',['id'=>1,'stock'=>16]);
        $this->assertDatabaseCount('client_packages',2);
        $invoice=DB::table('invoices')->find($id);$this->assertEquals(119780,$invoice->total_cents);
        $payment=['payment_method_id'=>1,'amount'=>100,'request_id'=>(string)Str::uuid()];
        $payId=$this->postJson("/api/billing/invoices/$id/payments",$payment)->assertOk()->json('id');
        $this->postJson("/api/billing/invoices/$id/payments",$payment)->assertOk()->assertJsonPath('id',$payId);
        $this->postJson("/api/billing/invoices/$id/payments",array_replace($payment,['request_id'=>(string)Str::uuid(),'amount'=>100000]))->assertUnprocessable();
        $refund=array_replace($payment,['request_id'=>(string)Str::uuid(),'refund_of'=>$payId,'amount'=>101]);
        $this->postJson("/api/billing/invoices/$id/payments",$refund)->assertUnprocessable();
        $refund['amount']=50;$this->postJson("/api/billing/invoices/$id/payments",$refund)->assertOk();
        $this->assertEquals(5000,DB::table('payments')->where('invoice_id',$id)->sum('amount_cents'));
    }

    public function test_insufficient_stock_rolls_back_entire_invoice(): void
    {
        $this->actingAs($this->owner())->postJson('/api/billing/invoices',['client_id'=>1,'due_on'=>'2026-11-01','request_id'=>(string)Str::uuid(),'lines'=>[['type'=>'product','item_id'=>2,'quantity'=>10]]])->assertUnprocessable();
        $this->assertDatabaseHas('products',['id'=>2,'stock'=>4]);$this->assertDatabaseCount('invoices',1);
    }

    public function test_client_validation_finalized_records_and_consent_snapshots(): void
    {
        $this->actingAs($this->owner());
        $this->postJson('/api/modules/clients',['name'=>'Test','email'=>'bad','status'=>'active'])->assertUnprocessable();
        $this->postJson('/api/modules/clients',['name'=>'New Client','email'=>'new@example.test','status'=>'active','marketing_consent'=>false])->assertOk();
        $this->postJson('/api/modules/clients',['name'=>'Duplicate','email'=>'new@example.test','status'=>'active'])->assertUnprocessable();
        $record=DB::table('treatment_records')->where('status','finalized')->first();
        $this->putJson('/api/modules/treatment_records/'.$record->id,['appointment_id'=>$record->appointment_id,'assessment'=>'Changed','treatment'=>'Changed','status'=>'draft'])->assertUnprocessable();
        $this->postJson('/api/modules/consents',['client_id'=>2,'appointment_id'=>1,'form_template_id'=>1,'responses'=>'Test','signed_name'=>'Test','acknowledged'=>true])->assertUnprocessable();
        $snapshot=DB::table('consents')->value('template_snapshot');
        $this->putJson('/api/modules/form_templates/1',['name'=>'Updated','content'=>'A new template version','status'=>'active'])->assertOk();
        $this->assertEquals($snapshot,DB::table('consents')->value('template_snapshot'));
        $this->assertDatabaseHas('form_templates',['id'=>1,'version'=>2]);
    }

    public function test_menu_cycles_are_rejected_and_role_creation_adds_view_permission(): void
    {
        $this->actingAs($this->owner());
        $permission=DB::table('permissions')->where('name','treatment_records.edit')->value('id');
        $id=$this->postJson('/api/roles',['name'=>'Custom clinician','description'=>'Test','permissions'=>[$permission]])->assertOk()->json('id');
        $this->assertEquals(2,DB::table('permission_role')->where('role_id',$id)->count());
        $this->putJson('/api/modules/menu_items/1',['label'=>'Cycle','parent_id'=>1,'module'=>null,'icon'=>'circle','sort_order'=>1,'active'=>true])->assertUnprocessable();
    }

    public function test_marketing_respects_consent_and_is_not_sent(): void
    {
        $this->actingAs($this->owner())->postJson('/api/campaigns/1/prepare')->assertOk();
        $count=DB::table('message_outbox')->count();$this->assertGreaterThan(0,$count);
        $this->postJson('/api/campaigns/1/prepare')->assertOk();$this->assertDatabaseCount('message_outbox',$count);
        $this->assertEquals(0,DB::table('message_outbox')->where('status','!=','pending_configuration')->count());
        $this->assertEquals(0,DB::table('message_outbox')->whereIn('client_id',DB::table('clients')->where('marketing_consent',false)->select('id'))->count());
    }

    public function test_unauthenticated_and_disabled_accounts_cannot_read_clinic_data(): void
    {
        $this->getJson('/api/modules/clients')->assertUnauthorized();
        $user=$this->owner();$user->active=false;$user->save();
        $this->actingAs($user)->getJson('/api/bootstrap')->assertForbidden();
    }

    public function test_waitlist_booking_is_linked_and_resource_defaults_are_enforced(): void
    {
        $this->actingAs($this->owner());
        DB::table('services')->where('id',1)->update(['required_room_id'=>1,'required_device_id'=>4]);
        $payload=$this->booking(['client_id'=>8,'service_id'=>1,'room_id'=>null,'device_id'=>null,'duration'=>60,'waitlist_id'=>1]);
        $id=$this->postJson('/api/modules/appointments',$payload)->assertOk()->json('id');
        $this->assertDatabaseHas('appointments',['id'=>$id,'waitlist_id'=>1,'room_id'=>1,'device_id'=>4]);
        $this->assertDatabaseHas('waitlist',['id'=>1,'status'=>'booked']);
        $this->postJson('/api/modules/appointments',array_replace($payload,['starts_at'=>'2026-10-31T17:00']))->assertUnprocessable();
    }

    public function test_removing_a_package_restores_service_price(): void
    {
        $this->actingAs($this->owner());
        $payload=$this->booking(['service_id'=>1,'duration'=>60,'client_package_id'=>1]);
        $id=$this->postJson('/api/modules/appointments',$payload)->assertOk()->json('id');
        $this->assertDatabaseHas('appointments',['id'=>$id,'price_cents'=>0]);
        $this->putJson('/api/modules/appointments/'.$id,array_replace($payload,['client_package_id'=>null]))->assertOk();
        $this->assertDatabaseHas('appointments',['id'=>$id,'price_cents'=>18500]);
    }

    public function test_public_booking_uses_shared_calendar_and_private_files_require_access(): void
    {
        $this->post('/book/aroma-north-york',['name'=>'Online Client','email'=>'public@example.test','phone'=>'5550123','service_id'=>2,'staff_id'=>1,'starts_at'=>'2026-10-31T17:00','acknowledged'=>true])->assertRedirect();
        $client=DB::table('clients')->where('email','public@example.test')->first();
        $this->assertNotNull($client);$this->assertDatabaseHas('appointments',['client_id'=>$client->id,'service_id'=>2]);
        \Illuminate\Support\Facades\Storage::fake('local');
        $upload=\Illuminate\Http\UploadedFile::fake()->image('before.jpg');
        $id=$this->actingAs($this->owner())->post('/api/modules/photos',['client_id'=>1,'caption'=>'Test progress','stage'=>'before','taken_on'=>'2026-10-30','file'=>$upload],['Accept'=>'application/json'])->assertOk()->json('id');
        $this->get('/api/photos/'.$id.'/file')->assertOk();
        $role=DB::table('roles')->where('name','Inventory')->value('id');$user=User::factory()->create();$user->role_id=$role;$user->organization_id=1;$user->save();
        DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user->id,'role_id'=>$role,'active'=>true]);
        $this->actingAs($user)->getJson('/api/photos/'.$id.'/file')->assertForbidden();
    }
}
