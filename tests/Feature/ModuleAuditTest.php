<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BranchProvisioner;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleAuditTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(9, 0));
        $this->seed(ClinicSeeder::class);
        $this->actingAs(User::where('is_org_owner', true)->firstOrFail());
    }

    private function saveAndRead(string $module, array $data, bool $editable = true): int
    {
        $id = $this->postJson('/api/modules/'.$module, $data)->assertOk()->json('id');
        $this->assertDatabaseHas($module, ['id' => $id, 'branch_id' => 1]);
        $rows = $this->getJson('/api/modules/'.$module.'?per_page=500')->assertOk()->json('rows');
        $this->assertContains($id, array_column($rows, 'id'));
        if ($editable) { $this->putJson('/api/modules/'.$module.'/'.$id, $data)->assertOk(); }
        else { $this->putJson('/api/modules/'.$module.'/'.$id, $data)->assertForbidden(); }
        return $id;
    }

    public function test_catalog_crm_membership_and_care_forms_persist_and_link(): void
    {
        $client = $this->saveAndRead('clients', ['name'=>'Audit Client','email'=>'audit@example.test','status'=>'active','marketing_consent'=>true]);
        $category = $this->saveAndRead('service_categories', ['name'=>'Audit category','color'=>'#123456']);
        $resource = $this->saveAndRead('resources', ['name'=>'Audit room','type'=>'room','status'=>'active']);
        $staff = $this->saveAndRead('staff', ['name'=>'Audit practitioner','specialty'=>'Skin','starts_at'=>'09:00','ends_at'=>'18:00','working_days'=>['1','2','3','4','5','6'],'color'=>'#123456','status'=>'active']);
        $service = $this->saveAndRead('services', ['name'=>'Audit service','category_id'=>$category,'duration'=>30,'buffer'=>10,'price_cents'=>75.50,'required_room_id'=>$resource,'staff_ids'=>[$staff],'online_booking'=>true,'status'=>'active']);
        $this->assertDatabaseHas('services', ['id'=>$service,'price_cents'=>7550]);
        $this->assertDatabaseHas('service_staff', ['branch_id'=>1,'service_id'=>$service,'staff_id'=>$staff]);
        $package = $this->saveAndRead('packages', ['name'=>'Audit package','service_id'=>$service,'sessions'=>3,'valid_days'=>90,'price_cents'=>200,'status'=>'active']);
        $this->saveAndRead('client_packages', ['client_id'=>$client,'package_id'=>$package,'starts_on'=>'2026-10-30'], false);
        $plan = $this->saveAndRead('membership_plans', ['name'=>'Audit membership','price_cents'=>100,'valid_days'=>30,'benefits'=>'Member benefits','points_per_dollar'=>2]);
        $this->saveAndRead('memberships', ['client_id'=>$client,'membership_plan_id'=>$plan,'starts_on'=>'2026-10-30','status'=>'active']);
        $this->saveAndRead('reward_entries', ['client_id'=>$client,'points'=>10,'reason'=>'Welcome','request_id'=>(string) Str::uuid()], false);
        $supplier = $this->saveAndRead('suppliers', ['name'=>'Audit supplier','email'=>'supplier@example.test']);
        $product = $this->saveAndRead('products', ['name'=>'Audit product','sku'=>'AUDIT-1','supplier_id'=>$supplier,'price_cents'=>20,'cost_cents'=>10,'reorder_level'=>2,'status'=>'active']);
        $this->saveAndRead('stock_movements', ['product_id'=>$product,'quantity'=>5,'reason'=>'Receipt','batch'=>'AUDIT','expires_on'=>'2027-10-30','request_id'=>(string) Str::uuid()], false);
        $this->assertDatabaseHas('products', ['id'=>$product,'stock'=>5]);
        $this->postJson('/api/modules/payment_methods', ['name'=>'Audit bank transfer','active'=>true])->assertForbidden();
        $this->saveAndRead('tasks', ['title'=>'Audit task','client_id'=>$client,'assigned_to'=>1,'due_on'=>'2026-11-01','priority'=>'high','status'=>'open']);
        $lead = $this->saveAndRead('leads', ['name'=>'Audit lead','email'=>'lead-audit@example.test','service_id'=>$service,'source'=>'website','status'=>'new']);
        $converted = $this->postJson('/api/leads/'.$lead.'/convert')->assertOk()->json('id');
        $this->postJson('/api/leads/'.$lead.'/convert')->assertOk()->assertJsonPath('id', $converted);
        $this->saveAndRead('campaigns', ['name'=>'Audit campaign','channel'=>'email','audience'=>'all_opted_in','subject'=>'Hello','body'=>'Hello {name}']);
        $this->saveAndRead('website_pages', ['title'=>'Audit page','slug'=>'audit-page','content'=>'Branch-specific page','status'=>'published']);
        $this->get('/page/aroma-north-york/audit-page')->assertOk()->assertSee('Branch-specific page');
        $template = $this->saveAndRead('form_templates', ['name'=>'Audit consent','content'=>'Consent text','status'=>'active']);
        $this->saveAndRead('consents', ['client_id'=>$client,'form_template_id'=>$template,'responses'=>'Acknowledged','signed_name'=>'Audit Client','acknowledged'=>true], false);
        $this->saveAndRead('blocked_times', ['staff_id'=>$staff,'starts_at'=>'2026-11-02T09:00','ends_at'=>'2026-11-02T10:00','reason'=>'Training']);
        $this->saveAndRead('waitlist', ['client_id'=>$client,'service_id'=>$service,'preferred_date'=>'2026-11-03','status'=>'waiting']);
        $this->saveAndRead('menu_items', ['label'=>'Audit clients','parent_id'=>null,'module'=>'clients','icon'=>'users','sort_order'=>100,'active'=>true]);
        $record = DB::table('treatment_records')->where('status','finalized')->first();
        $this->saveAndRead('record_amendments', ['treatment_record_id'=>$record->id,'note'=>'Additional documented observation'], false);
        $profile = $this->getJson('/api/clients/'.$client.'/profile')->assertOk();
        $profile->assertJsonCount(1, 'client_packages')->assertJsonCount(1, 'memberships')->assertJsonCount(1, 'consents')->assertJsonPath('reward_points', 10);
    }

    public function test_private_upload_and_all_reference_fields_are_accessible(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF");
        $id = $this->post('/api/modules/photos', ['client_id'=>1,'caption'=>'Audit document','stage'=>'document','taken_on'=>'2026-10-30','file'=>$file], ['Accept'=>'application/json'])->assertOk()->json('id');
        $photo = DB::table('photos')->find($id);
        Storage::disk('local')->assertExists($photo->path);
        $this->get('/api/photos/'.$id.'/file')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        foreach (config('clinic.modules') as $module=>$definition) {
            foreach ($definition['fields'] as $field=>$config) {
                if (isset($config['source'])) { $this->getJson('/api/modules/'.$module.'/lookup/'.$field)->assertOk(); }
            }
        }
    }

    public function test_read_only_role_cannot_write_any_module_or_special_action(): void
    {
        $role = DB::table('roles')->insertGetId(['branch_id'=>1,'name'=>'Audit read only','is_owner'=>false]);
        foreach (DB::table('permissions')->where('action','view')->pluck('id') as $permission) { DB::table('permission_role')->insert(['role_id'=>$role,'permission_id'=>$permission]); }
        $user = User::factory()->create(['organization_id'=>1,'role_id'=>$role]);
        DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user->id,'role_id'=>$role,'active'=>true]);
        $this->actingAs($user);
        $bootstrap = $this->getJson('/api/bootstrap')->assertOk()->json();
        foreach (config('clinic.modules') as $module=>$definition) {
            $this->getJson('/api/modules/'.$module)->assertOk();
            $this->assertFalse($bootstrap['modules'][$module]['can_create']);
            $this->assertFalse($bootstrap['modules'][$module]['can_edit']);
            $this->postJson('/api/modules/'.$module, [])->assertForbidden();
            $this->putJson('/api/modules/'.$module.'/1', [])->assertForbidden();
        }
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/reports')->assertOk();
        $this->putJson('/api/settings', [])->assertForbidden();
        $this->postJson('/api/roles', [])->assertForbidden();
        $this->postJson('/api/billing/invoices', [])->assertForbidden();
        $this->postJson('/api/billing/invoices/1/payments', [])->assertForbidden();
        $this->postJson('/api/campaigns/1/prepare')->assertForbidden();
        $this->postJson('/api/leads/1/convert')->assertForbidden();
    }

    public function test_new_branch_is_empty_and_all_combined_reports_include_branch_details(): void
    {
        $branch = $this->postJson('/api/organization/branches', ['name'=>'Audit new branch','slug'=>'audit-new','location'=>'Toronto','currency'=>'CAD','timezone'=>'America/Toronto'])->assertOk()->json('id');
        $this->postJson('/api/branch-selection', ['branch_id'=>$branch])->assertOk();
        foreach (config('clinic.modules') as $module=>$definition) {
            $response = $this->getJson('/api/modules/'.$module)->assertOk();
            if (! in_array($module, ['roles','users','menu_items','payment_methods'], true)) { $response->assertJsonPath('total', 0); }
        }
        $this->postJson('/api/modules/clients', ['name'=>'New branch client','email'=>'sample1@example.test','status'=>'active'])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('stats.clients',1)->assertJsonPath('stats.outstanding_cents',0);
        $this->postJson('/api/branch-selection', ['branch_id'=>'all'])->assertOk();
        $this->getJson('/api/reports?from=2026-10-01&to=2026-11-30')->assertOk()->assertJsonCount(2,'branches')->assertJsonStructure(['services','stock','payment_methods','package_redemptions','currency_totals']);
        $this->get('/api/reports?export=1')->assertOk()->assertDownload('aroma-report.csv');
        foreach (array_keys(config('clinic.modules')) as $module) { $this->postJson('/api/modules/'.$module, [])->assertStatus(409); }
    }

    public function test_access_manager_cannot_assign_a_more_powerful_custom_role(): void
    {
        $role = DB::table('roles')->insertGetId(['branch_id'=>1,'name'=>'Limited user manager','is_owner'=>false]);
        foreach (DB::table('permissions')->whereIn('name',['access.view','access.create','access.edit'])->pluck('id') as $permission) { DB::table('permission_role')->insert(['role_id'=>$role,'permission_id'=>$permission]); }
        $user = User::factory()->create(['organization_id'=>1,'role_id'=>$role]);
        DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user->id,'role_id'=>$role,'active'=>true]);
        $powerful = DB::table('roles')->insertGetId(['branch_id'=>1,'name'=>'Clinical editor','is_owner'=>false]);
        DB::table('permission_role')->insert(['role_id'=>$powerful,'permission_id'=>DB::table('permissions')->where('name','treatment_records.edit')->value('id')]);
        $this->actingAs($user)->postJson('/api/modules/users', ['name'=>'Escalation','email'=>'escalation@example.test','role_id'=>$powerful,'password'=>'Audit-Test-2026!','active'=>true])->assertForbidden();
    }

    public function test_cash_receipts_refunds_and_reports_reconcile(): void
    {
        $options = $this->getJson('/api/billing/options')->assertOk()->json('payment_methods');
        $this->assertSame(['Cash'], array_column($options, 'name'));
        $before = $this->getJson('/api/dashboard')->assertOk()->json('stats');
        $payment = ['payment_method_id'=>$options[0]['id'],'amount'=>20,'request_id'=>(string) Str::uuid()];
        $receipt = $this->postJson('/api/billing/invoices/1/payments', $payment)->assertOk()->json('id');
        $this->postJson('/api/billing/invoices/1/payments', $payment)->assertOk()->assertJsonPath('id', $receipt);
        $this->postJson('/api/billing/invoices/1/payments', ['payment_method_id'=>$options[0]['id'],'amount'=>5,'refund_of'=>$receipt,'request_id'=>(string) Str::uuid()])->assertOk();
        $after = $this->getJson('/api/dashboard')->assertOk()->json('stats');
        $this->assertSame($before['collected_cents']+1500, $after['collected_cents']);
        $this->assertSame($before['outstanding_cents']-1500, $after['outstanding_cents']);
        $card = DB::table('payment_methods')->insertGetId(['branch_id'=>1,'name'=>'Card','active'=>true]);
        $this->postJson('/api/billing/invoices/1/payments', ['payment_method_id'=>$card,'amount'=>1,'request_id'=>(string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/billing/options')->assertOk()->assertJsonCount(1,'payment_methods');
        $branchReport = $this->getJson('/api/reports')->assertOk()->json();
        $this->postJson('/api/branch-selection', ['branch_id'=>'all'])->assertOk();
        $this->getJson('/api/reports')->assertOk()->assertJsonPath('collected_cents',$branchReport['collected_cents'])->assertJsonPath('outstanding_cents',$branchReport['outstanding_cents']);
    }

    public function test_custom_role_user_login_password_edit_and_deactivation(): void
    {
        $owner = User::where('is_org_owner',true)->firstOrFail();
        $permission = DB::table('permissions')->where('name','treatment_records.view')->value('id');
        $role = $this->postJson('/api/roles', ['name'=>'Audit records viewer','description'=>'Records only','permissions'=>[$permission]])->assertOk()->json('id');
        $data = ['name'=>'Audit operator','email'=>'operator@example.test','role_id'=>$role,'password'=>'Audit-Login-2026!','active'=>true];
        $user = $this->postJson('/api/modules/users',$data)->assertOk()->json('id');
        $this->assertDatabaseHas('branch_user',['user_id'=>$user,'branch_id'=>1,'role_id'=>$role,'active'=>true]);
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login',['email'=>$data['email'],'password'=>$data['password']])->assertRedirect('/admin');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.role','Audit records viewer');
        $this->getJson('/api/modules/treatment_records')->assertOk();
        $this->getJson('/api/modules/clients')->assertForbidden();
        $this->actingAs($owner);
        $data['password']='Audit-Updated-2026!';
        $this->putJson('/api/modules/users/'.$user,$data)->assertOk();
        $this->post('/logout');
        $this->post('/login',['email'=>$data['email'],'password'=>$data['password']])->assertRedirect('/admin');
        $this->actingAs($owner);
        $data['active']=false;
        $this->putJson('/api/modules/users/'.$user,$data)->assertOk();
        $this->post('/logout');
        $this->post('/login',['email'=>$data['email'],'password'=>$data['password']])->assertSessionHasErrors('email');
    }

    public function test_settings_are_branch_specific_and_invalid_calendar_filters_are_rejected(): void
    {
        $settings = $this->getJson('/api/settings')->assertOk()->json();
        $settings['clinic_name']='Audit clinic'; $settings['phone']=null; $settings['email']=null;
        $this->putJson('/api/settings',$settings)->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('settings.clinic_name','Audit clinic');
        $settings['currency']='USD';
        $this->putJson('/api/settings',$settings)->assertUnprocessable();
        $branch = app(BranchProvisioner::class)->create(1,['name'=>'Settings branch','slug'=>'settings-branch','location'=>'Test','currency'=>'CAD','timezone'=>'America/Toronto']);
        $this->postJson('/api/branch-selection',['branch_id'=>$branch])->assertOk();
        $this->assertNotSame('Audit clinic',$this->getJson('/api/settings')->assertOk()->json('clinic_name'));
        $this->getJson('/api/modules/appointments?from=not-a-date')->assertUnprocessable();
        $this->getJson('/api/modules/appointments?from=2026-11-01&to=2026-10-01')->assertUnprocessable();
    }
}
