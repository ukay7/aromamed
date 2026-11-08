<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t): void {
            $t->id(); $t->string('name')->unique(); $t->text('description')->nullable(); $t->boolean('is_owner')->default(false); $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t): void {
            $t->id(); $t->string('name')->unique(); $t->string('module'); $t->string('action');
        });
        Schema::create('permission_role', function (Blueprint $t): void {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete(); $t->foreignId('permission_id')->constrained()->cascadeOnDelete(); $t->primary(['role_id', 'permission_id']);
        });
        Schema::table('users', function (Blueprint $t): void {
            $t->foreignId('role_id')->nullable()->constrained(); $t->boolean('active')->default(true);
        });
        Schema::create('menu_items', function (Blueprint $t): void {
            $t->id(); $t->foreignId('parent_id')->nullable()->constrained('menu_items'); $t->string('label'); $t->string('module')->nullable(); $t->string('icon')->default('circle'); $t->unsignedInteger('sort_order')->default(0); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t): void {
            $t->string('key')->primary(); $t->text('value')->nullable();
        });
        Schema::create('clients', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('email')->nullable()->unique(); $t->string('phone')->nullable(); $t->date('birth_date')->nullable(); $t->text('address')->nullable(); $t->text('alerts')->nullable(); $t->text('notes')->nullable(); $t->boolean('marketing_consent')->default(false); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('service_categories', function (Blueprint $t): void {
            $t->id(); $t->string('name')->unique(); $t->string('color')->default('#7c658b'); $t->timestamps();
        });
        Schema::create('services', function (Blueprint $t): void {
            $t->id(); $t->foreignId('category_id')->constrained('service_categories'); $t->string('name'); $t->text('description')->nullable(); $t->unsignedInteger('duration')->default(60); $t->unsignedInteger('buffer')->default(10); $t->unsignedInteger('price_cents'); $t->boolean('requires_consent')->default(false); $t->boolean('online_booking')->default(true); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('staff', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained('users'); $t->string('name'); $t->string('specialty'); $t->string('email')->nullable(); $t->time('starts_at')->default('09:00'); $t->time('ends_at')->default('18:00'); $t->time('break_start')->nullable(); $t->time('break_end')->nullable(); $t->json('working_days'); $t->string('color')->default('#739d92'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('service_staff', function (Blueprint $t): void {
            $t->foreignId('service_id')->constrained()->cascadeOnDelete(); $t->foreignId('staff_id')->constrained('staff')->cascadeOnDelete(); $t->primary(['service_id', 'staff_id']);
        });
        Schema::create('resources', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('type'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('packages', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->foreignId('service_id')->constrained(); $t->unsignedInteger('sessions'); $t->unsignedInteger('valid_days')->default(180); $t->unsignedInteger('price_cents'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('client_packages', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('package_id')->constrained(); $t->date('starts_on'); $t->date('expires_on'); $t->unsignedInteger('sessions_total'); $t->unsignedInteger('sessions_used')->default(0); $t->timestamps();
        });
        Schema::create('appointments', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('service_id')->constrained(); $t->foreignId('staff_id')->constrained('staff'); $t->foreignId('room_id')->nullable()->constrained('resources'); $t->foreignId('device_id')->nullable()->constrained('resources'); $t->foreignId('client_package_id')->nullable()->constrained(); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->dateTime('occupied_until'); $t->unsignedInteger('duration'); $t->unsignedInteger('buffer'); $t->unsignedInteger('price_cents'); $t->string('status')->default('confirmed'); $t->text('notes')->nullable(); $t->timestamps(); $t->index(['staff_id', 'starts_at']); $t->index(['client_id', 'starts_at']);
        });
        Schema::create('package_redemptions', function (Blueprint $t): void {
            $t->id(); $t->foreignId('appointment_id')->unique()->constrained(); $t->foreignId('client_package_id')->constrained(); $t->timestamps();
        });
        Schema::create('blocked_times', function (Blueprint $t): void {
            $t->id(); $t->foreignId('staff_id')->constrained('staff'); $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->string('reason'); $t->timestamps();
        });
        Schema::create('waitlist', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('service_id')->constrained(); $t->date('preferred_date'); $t->text('notes')->nullable(); $t->string('status')->default('waiting'); $t->timestamps();
        });
        Schema::create('treatment_records', function (Blueprint $t): void {
            $t->id(); $t->foreignId('appointment_id')->unique()->constrained(); $t->text('assessment'); $t->text('treatment'); $t->text('aftercare')->nullable(); $t->date('follow_up_on')->nullable(); $t->string('status')->default('draft'); $t->foreignId('author_id')->constrained('users'); $t->timestamps();
        });
        Schema::create('record_amendments', function (Blueprint $t): void {
            $t->id(); $t->foreignId('treatment_record_id')->constrained(); $t->foreignId('author_id')->constrained('users'); $t->text('note'); $t->timestamps();
        });
        Schema::create('form_templates', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->unsignedInteger('version')->default(1); $t->text('content'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('consents', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('appointment_id')->nullable()->constrained(); $t->foreignId('form_template_id')->constrained(); $t->text('template_snapshot'); $t->unsignedInteger('version'); $t->text('responses'); $t->string('signed_name'); $t->timestamp('signed_at'); $t->foreignId('recorded_by')->constrained('users'); $t->timestamps();
        });
        Schema::create('photos', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('appointment_id')->nullable()->constrained(); $t->string('caption'); $t->string('stage'); $t->date('taken_on'); $t->string('path'); $t->string('mime'); $t->timestamps();
        });
        Schema::create('suppliers', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('phone')->nullable(); $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('sku')->unique(); $t->foreignId('supplier_id')->nullable()->constrained(); $t->unsignedInteger('price_cents'); $t->unsignedInteger('cost_cents')->default(0); $t->integer('stock')->default(0); $t->unsignedInteger('reorder_level')->default(5); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t): void {
            $t->id(); $t->foreignId('product_id')->constrained(); $t->integer('quantity'); $t->string('reason'); $t->string('batch')->nullable(); $t->date('expires_on')->nullable(); $t->foreignId('treatment_record_id')->nullable()->constrained(); $t->string('request_id')->unique(); $t->foreignId('user_id')->constrained(); $t->timestamps();
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $t->id(); $t->string('number')->unique(); $t->foreignId('client_id')->constrained(); $t->foreignId('appointment_id')->nullable()->unique()->constrained(); $t->date('due_on'); $t->unsignedInteger('subtotal_cents'); $t->unsignedInteger('discount_cents')->default(0); $t->unsignedInteger('tax_cents')->default(0); $t->unsignedInteger('total_cents'); $t->text('notes')->nullable(); $t->string('request_id')->unique(); $t->timestamps();
        });
        Schema::create('invoice_lines', function (Blueprint $t): void {
            $t->id(); $t->foreignId('invoice_id')->constrained(); $t->string('description'); $t->string('item_type'); $t->unsignedBigInteger('item_id'); $t->unsignedInteger('quantity'); $t->unsignedInteger('unit_price_cents'); $t->unsignedInteger('total_cents');
        });
        Schema::create('payment_methods', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->boolean('active')->default(true); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t): void {
            $t->id(); $t->foreignId('invoice_id')->constrained(); $t->foreignId('payment_method_id')->constrained(); $t->integer('amount_cents'); $t->foreignId('refund_of')->nullable()->constrained('payments'); $t->string('reference')->nullable(); $t->string('request_id')->unique(); $t->foreignId('user_id')->constrained(); $t->timestamps();
        });
        Schema::create('membership_plans', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->unsignedInteger('price_cents'); $t->unsignedInteger('valid_days'); $t->text('benefits'); $t->unsignedInteger('points_per_dollar')->default(1); $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('membership_plan_id')->constrained(); $t->date('starts_on'); $t->date('expires_on'); $t->string('status')->default('active'); $t->timestamps();
        });
        Schema::create('reward_entries', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->integer('points'); $t->string('reason'); $t->string('request_id')->unique(); $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t): void {
            $t->id(); $t->string('title'); $t->foreignId('client_id')->nullable()->constrained(); $t->foreignId('assigned_to')->nullable()->constrained('users'); $t->date('due_on'); $t->string('priority')->default('normal'); $t->string('status')->default('open'); $t->text('notes')->nullable(); $t->timestamps();
        });
        Schema::create('leads', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('phone')->nullable(); $t->foreignId('service_id')->nullable()->constrained(); $t->string('source'); $t->string('status')->default('new'); $t->text('notes')->nullable(); $t->foreignId('client_id')->nullable()->constrained(); $t->timestamps();
        });
        Schema::create('campaigns', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('channel'); $t->string('audience'); $t->string('subject'); $t->text('body'); $t->dateTime('scheduled_at')->nullable(); $t->string('status')->default('draft'); $t->timestamps();
        });
        Schema::create('message_outbox', function (Blueprint $t): void {
            $t->id(); $t->foreignId('client_id')->constrained(); $t->foreignId('campaign_id')->nullable()->constrained(); $t->foreignId('appointment_id')->nullable()->constrained(); $t->string('channel'); $t->string('subject'); $t->text('body'); $t->string('status')->default('pending_configuration'); $t->string('dedup_key')->unique(); $t->timestamps();
        });
        Schema::create('website_pages', function (Blueprint $t): void {
            $t->id(); $t->string('title'); $t->string('slug')->unique(); $t->text('content'); $t->string('status')->default('draft'); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained(); $t->string('action'); $t->string('entity'); $t->unsignedBigInteger('entity_id')->nullable(); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'website_pages', 'message_outbox', 'campaigns', 'leads', 'tasks', 'reward_entries', 'memberships', 'membership_plans', 'payments', 'payment_methods', 'invoice_lines', 'invoices', 'stock_movements', 'products', 'suppliers', 'photos', 'consents', 'form_templates', 'record_amendments', 'treatment_records', 'waitlist', 'blocked_times', 'package_redemptions', 'appointments', 'client_packages', 'packages', 'resources', 'service_staff', 'staff', 'services', 'service_categories', 'clients', 'settings', 'menu_items', 'permission_role'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t): void { $t->dropConstrainedForeignId('role_id'); $t->dropColumn('active'); });
        Schema::dropIfExists('permissions'); Schema::dropIfExists('roles');
    }
};
