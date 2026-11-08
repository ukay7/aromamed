<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};

class ClinicSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('roles')->exists()) { return; }
        if (! app()->environment(['local', 'testing'])) { throw new \RuntimeException('Sample data can only be seeded locally or in tests.'); }
        DB::transaction(function (): void {
            if (! DB::table('organizations')->exists()) {
                DB::table('organizations')->insert(['id'=>1,'name'=>'Aroma Med Spa','currency'=>'CAD','timezone'=>'America/Toronto','created_at'=>now(),'updated_at'=>now()]);
                DB::table('branches')->insert(['id'=>1,'organization_id'=>1,'name'=>'North York','slug'=>'aroma-north-york','location'=>'North York, Toronto','currency'=>'CAD','timezone'=>'America/Toronto','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            }
            $insert = static fn (string $table, array $data): int => DB::table($table)->insertGetId($data + (in_array($table,config('tenancy.tables'),true)?['branch_id'=>1]:[]) + ['created_at' => now(), 'updated_at' => now()]);
            $owner = $insert('roles', ['name' => 'Owner', 'description' => 'Clinic ownership and full administration', 'is_owner' => true]);
            $permissionModules = array_unique([...array_map(fn ($key, $module) => $module['permission'] ?? $key, array_keys(config('clinic.modules')), config('clinic.modules')), 'dashboard', 'reports', 'settings']);
            foreach ($permissionModules as $module) { foreach (['view', 'create', 'edit'] as $action) { DB::table('permissions')->insert(['name' => $module.'.'.$action, 'module' => $module, 'action' => $action]); } }
            $roleScopes = [
                'Manager' => array_diff($permissionModules, ['access']),
                'Receptionist' => ['dashboard','appointments','blocked_times','waitlist','clients','services','staff','packages','invoices','tasks','form_templates'],
                'Practitioner' => ['dashboard','appointments','clients','treatment_records','form_templates','photos','services','tasks'],
                'Stylist' => ['dashboard','appointments','clients','treatment_records','services','tasks'],
                'Accountant' => ['dashboard','invoices','reports','clients'],
                'Inventory' => ['dashboard','products'],
                'Marketing' => ['dashboard','campaigns','tasks'],
            ];
            foreach ($roleScopes as $name => $modules) {
                $role = $insert('roles', ['name' => $name, 'description' => $name.' workspace']);
                foreach (DB::table('permissions')->whereIn('module', $modules)->pluck('id') as $permission) { DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => $permission]); }
            }
            $password = env('CLINIC_OWNER_PASSWORD');
            if (! $password) { throw new \RuntimeException('Set CLINIC_OWNER_PASSWORD in .env before seeding.'); }
            $user = $insert('users', ['name' => 'Clinic Owner', 'email' => 'owner@aroma.local', 'password' => Hash::make($password), 'role_id' => $owner, 'active' => true,'organization_id'=>1,'is_org_owner'=>true]);
            DB::table('branch_user')->insert(['branch_id'=>1,'user_id'=>$user,'role_id'=>$owner,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            foreach (['clinic_name' => 'Aroma Med Spa', 'location' => 'North York, Toronto', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'tax_percent' => '13', 'email' => 'hello@example.test', 'phone' => '+1 (416) 555-0100', 'booking_notice_hours' => '12', 'reminder_hours' => '24'] as $key => $value) { DB::table('settings')->insert(compact('key', 'value')+['branch_id'=>1]); }
            $groups = []; $menu = []; $order = 0;
            foreach (['Your clinic', 'Care & treatments', 'Business', 'Manage'] as $label) { $groups[$label] = $insert('menu_items', ['label' => $label, 'sort_order' => $order++ * 100]); }
            $insert('menu_items', ['label' => 'Overview', 'module' => 'dashboard', 'parent_id' => $groups['Your clinic'], 'icon' => 'th-large', 'sort_order' => 0]);
            $menuOrder = ['appointments', 'clients', ...array_diff(array_keys(config('clinic.modules')), ['appointments','clients'])];
            foreach ($menuOrder as $key) {
                $module = config('clinic.modules.'.$key);
                $menu[$key] = $insert('menu_items', ['label' => $module['title'], 'module' => $key, 'parent_id' => isset($module['parent']) ? $menu[$module['parent']] : $groups[$module['group']], 'icon' => $module['icon'], 'sort_order' => ++$order]);
            }
            $insert('menu_items', ['label' => 'Reports & insights', 'module' => 'reports', 'parent_id' => $groups['Business'], 'icon' => 'chart-bar', 'sort_order' => 200]);
            $insert('menu_items', ['label' => 'Clinic settings', 'module' => 'settings', 'parent_id' => $groups['Manage'], 'icon' => 'cog', 'sort_order' => 200]);
            $names = ['Maya Bennett','Olivia Chen','Sofia Laurent','Amelia Parker','Noah Williams','Isabella Patel','Ethan Brooks','Ava Thompson'];
            foreach ($names as $i => $name) { $insert('clients', ['name' => $name, 'email' => 'sample'.($i + 1).'@example.test', 'phone' => '+1 416 555 01'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'birth_date' => '1993-'.today()->format('m').'-15', 'alerts' => $i === 0 ? 'Sample alert: adhesive sensitivity' : null, 'notes' => 'Synthetic preview client', 'marketing_consent' => $i % 2 === 0, 'status' => 'active']); }
            foreach ([['Skin & aesthetics','#857299'],['Hair & styling','#c7936f'],['Spa & wellness','#71998b']] as [$name, $color]) { $insert('service_categories', compact('name', 'color')); }
            foreach ([['Emma Wilson','Skin specialist','#8c769e'],['Isabella Rose','Aesthetic practitioner','#6c978b'],['Lily Morgan','Hair & beauty','#c99570']] as [$name, $specialty, $color]) { $insert('staff', ['name' => $name, 'specialty' => $specialty, 'color' => $color, 'starts_at' => '09:00', 'ends_at' => '19:00', 'break_start' => '13:00', 'break_end' => '14:00', 'working_days' => json_encode(['1','2','3','4','5','6']), 'status' => 'active']); }
            $serviceData = [['HydraFacial',1,60,18500,true],['Skin consultation',1,30,7500,false],['Exosome consultation',1,45,12000,true],['Microdermabrasion',1,45,14500,true],['Signature haircut',2,45,6500,false],['Hair color & finish',2,90,16000,false],['Relaxation massage',3,60,11000,false],['Signature facial',3,60,13500,true]];
            foreach ($serviceData as [$name, $category, $duration, $price, $consent]) {
                $service = $insert('services', ['name' => $name, 'category_id' => $category, 'description' => 'Personalized care with our specialist team.', 'duration' => $duration, 'buffer' => 10, 'price_cents' => $price, 'requires_consent' => $consent, 'online_booking' => true, 'status' => 'active']);
                foreach (($category === 2 ? [3] : [1,2]) as $staff) { DB::table('service_staff')->insert(['service_id' => $service, 'staff_id' => $staff,'branch_id'=>1]); }
            }
            foreach ([['The Skin Suite','room'],['The Wellness Room','room'],['Styling Chair 01','chair'],['HydraFacial Device','device']] as [$name,$type]) { $insert('resources', compact('name','type')); }
            $insert('packages', ['name' => 'The Skin Ritual · 6 visits', 'service_id' => 1, 'sessions' => 6, 'valid_days' => 180, 'price_cents' => 99000]);
            $insert('packages', ['name' => 'Facial Refresh · 3 visits', 'service_id' => 8, 'sessions' => 3, 'valid_days' => 90, 'price_cents' => 36000]);
            $insert('client_packages', ['client_id' => 1, 'package_id' => 1, 'starts_on' => today()->subDays(7), 'expires_on' => today()->addDays(173), 'sessions_total' => 6, 'sessions_used' => 0]);
            $form = $insert('form_templates', ['name' => 'Consultation & treatment acknowledgement', 'content' => 'Record the client’s concerns, relevant history, explained procedure, alternatives and aftercare. This sample template must be reviewed for your clinic before real use.']);
            foreach ([[1,1,1,'09:00','confirmed'],[2,8,2,'10:00','checked_in'],[3,5,3,'11:00','confirmed'],[4,2,1,'11:00','confirmed'],[5,6,3,'14:00','confirmed'],[6,4,2,'15:00','confirmed']] as [$client,$serviceId,$staff,$time,$status]) {
                $s = DB::table('services')->find($serviceId); $start = today()->setTimeFromTimeString($time);
                $appointment = $insert('appointments', ['client_id' => $client, 'service_id' => $serviceId, 'staff_id' => $staff, 'room_id' => $staff, 'device_id' => $serviceId === 1 ? 4 : null, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($s->duration), 'occupied_until' => $start->copy()->addMinutes($s->duration + 10), 'duration' => $s->duration, 'buffer' => 10, 'price_cents' => $s->price_cents, 'status' => $status, 'notes' => 'Sample appointment']);
                if ($client === 2) {
                    $insert('consents', ['client_id' => 2, 'appointment_id' => $appointment, 'form_template_id' => $form, 'template_snapshot' => 'Sample consent snapshot', 'version' => 1, 'responses' => 'Synthetic example only.', 'signed_name' => 'Sample Olivia Chen', 'signed_at' => now(), 'recorded_by' => $user]);
                    $insert('treatment_records', ['appointment_id' => $appointment, 'assessment' => 'Sample consultation notes', 'treatment' => 'Sample treatment documentation', 'aftercare' => 'Sample follow-up notes', 'author_id' => $user, 'status' => 'draft']);
                }
            }
            for ($day = 1; $day <= 6; $day++) {
                for ($visit = 0; $visit < ($day % 3) + 2; $visit++) {
                    $start = today()->subDays($day)->setTime(9 + $visit, 0);
                    $id = $insert('appointments', ['client_id' => $visit + 1, 'service_id' => 2, 'staff_id' => 1, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'occupied_until' => $start->copy()->addMinutes(40), 'duration' => 30, 'buffer' => 10, 'price_cents' => 7500, 'status' => 'completed']);
                    $insert('treatment_records', ['appointment_id' => $id, 'assessment' => 'Synthetic historical visit', 'treatment' => 'Sample consultation completed', 'status' => 'finalized', 'author_id' => $user]);
                }
            }
            $insert('suppliers', ['name' => 'Sample Skin Supply Co.', 'email' => 'supplier@example.test']);
            foreach ([['Gentle Cleanser','CLN-001',3500,1800,18,5],['Hydrating Serum','SRM-002',6800,3200,4,6],['SPF 50 Daily Shield','SPF-003',4200,2100,24,8]] as [$name,$sku,$price,$cost,$stock,$reorder]) {
                $id = $insert('products', ['name'=>$name,'sku'=>$sku,'supplier_id'=>1,'price_cents'=>$price,'cost_cents'=>$cost,'stock'=>$stock,'reorder_level'=>$reorder]);
                $insert('stock_movements', ['product_id'=>$id,'quantity'=>$stock,'reason'=>'Sample opening stock','batch'=>'DEMO-2026','expires_on'=>today()->addYear(),'request_id'=>'seed-stock-'.$id,'user_id'=>$user]);
            }
            $insert('payment_methods', ['name'=>'Cash']);
            $invoice = $insert('invoices', ['number'=>'AM-00001','client_id'=>2,'due_on'=>today()->addDays(7),'subtotal_cents'=>13500,'discount_cents'=>0,'tax_cents'=>1755,'total_cents'=>15255,'request_id'=>'sample-invoice-1']);
            DB::table('invoice_lines')->insert(['invoice_id'=>$invoice,'description'=>'Signature facial','item_type'=>'service','item_id'=>8,'quantity'=>1,'unit_price_cents'=>13500,'total_cents'=>13500,'branch_id'=>1]);
            $insert('payments', ['invoice_id'=>$invoice,'payment_method_id'=>1,'amount_cents'=>10000,'request_id'=>'sample-payment-1','user_id'=>$user]);
            $insert('membership_plans', ['name'=>'Aroma Circle','price_cents'=>9900,'valid_days'=>30,'benefits'=>'Priority booking and loyalty points on payments.','points_per_dollar'=>2]);
            $insert('memberships', ['client_id'=>2,'membership_plan_id'=>1,'starts_on'=>today()->subDays(5),'expires_on'=>today()->addDays(25)]);
            $insert('reward_entries', ['client_id'=>2,'points'=>200,'reason'=>'Sample welcome points','request_id'=>'seed-reward']);
            foreach (['Review Sofia’s consultation intake','Reorder hydrating serum','Follow up on Maya’s treatment plan'] as $i=>$title) { $insert('tasks', ['title'=>$title,'client_id'=>$i === 1 ? null : $i + 1,'assigned_to'=>$user,'due_on'=>today()->addDays($i),'priority'=>$i === 1 ? 'high' : 'normal','status'=>'open']); }
            $insert('waitlist', ['client_id'=>8,'service_id'=>1,'preferred_date'=>today()->addDay(),'notes'=>'Afternoon preferred']);
            $insert('leads', ['name'=>'Sample enquiry','email'=>'enquiry@example.test','service_id'=>1,'source'=>'website','status'=>'new']);
            $insert('campaigns', ['name'=>'A little birthday care','channel'=>'email','audience'=>'birthday_month','subject'=>'A birthday wish from Aroma','body'=>'Hello {name}, wishing you a wonderful birthday month.']);
            $insert('website_pages', ['title'=>'Our approach to care','slug'=>'our-care','content'=>'A welcoming space for thoughtful skin, hair and wellness care. Book a consultation with our team.','status'=>'published']);
        });
    }
}
