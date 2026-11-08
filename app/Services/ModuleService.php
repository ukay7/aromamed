<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use App\Services\BranchDB as DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ModuleService
{
    public function save(string $module, array $data, User $user, ?int $id = null): int
    {
        $definition = app(ClinicAccess::class)->definition($module);
        abort_if(($definition['readonly'] ?? false) || (($definition['immutable'] ?? false) && $id), 403, 'This record is immutable.');
        if ($module === 'users') { return app(BranchUserService::class)->save($data,$user,$id); }
        return DB::transaction(function () use ($module, $data, $user, $id, $definition): int {
            $existing = $id ? DB::table($module)->lockForUpdate()->find($id) : null;
            if ($id && ! $existing) { abort(404); }
            foreach ($definition['fields'] as $key => $field) {
                if ($field['type'] === 'money' && isset($data[$key])) { $data[$key] = (int) round($data[$key] * 100); }
                if ($field['type'] === 'checkbox') { $data[$key] = (bool) ($data[$key] ?? false); }
                if ($field['type'] === 'datetime-local' && ! empty($data[$key])) { $data[$key] = CarbonImmutable::parse($data[$key])->format('Y-m-d H:i:s'); }
            }
            if ($module === 'appointments') { return app(AppointmentService::class)->save($data, $id); }
            if ($module === 'stock_movements') { return app(BillingService::class)->stock($data, $user); }
            if ($module === 'menu_items') {
                $validModules = [...array_keys(config('clinic.modules')), 'dashboard', 'reports', 'settings'];
                if (! empty($data['module']) && ! in_array($data['module'], $validModules, true)) { throw ValidationException::withMessages(['module' => 'Choose an existing module key.']); }
                $parent = $data['parent_id'] ?? null; $seen = [];
                while ($parent) {
                    if ((int) $parent === $id || in_array($parent, $seen, true)) { throw ValidationException::withMessages(['parent_id' => 'A sidebar item cannot contain itself or create a cycle.']); }
                    $seen[] = $parent; $parent = DB::table('menu_items')->where('id', $parent)->value('parent_id');
                }
            }
            if ($module === 'staff') {
                if (! empty($data['break_start']) !== ! empty($data['break_end'])) { throw ValidationException::withMessages(['break_start' => 'Set both break start and end times.']); }
                if (! empty($data['break_start']) && ($data['break_start'] < $data['starts_at'] || $data['break_end'] > $data['ends_at'])) { throw ValidationException::withMessages(['break_start' => 'Breaks must be inside working hours.']); }
                $data['working_days'] = json_encode($data['working_days']);
            }
            if ($module === 'menu_items') { $data['icon'] = $data['icon'] ?: 'circle'; }
            if ($module === 'leads' && $existing && $existing->client_id) { $data['status'] = 'converted'; }
            if ($module === 'services') {
                foreach (['required_room_id' => ['room', 'chair'], 'required_device_id' => ['device']] as $field => $types) {
                    if (! empty($data[$field]) && ! DB::table('resources')->where('id', $data[$field])->where('status', 'active')->whereIn('type', $types)->exists()) { throw ValidationException::withMessages([$field => 'Choose an active resource of the correct type.']); }
                }
            }
            if ($module === 'services') { $staffIds = $data['staff_ids']; unset($data['staff_ids']); }
            if ($module === 'blocked_times') {
                DB::table('staff')->where('id', $data['staff_id'])->lockForUpdate()->first();
                if (DB::table('appointments')->where('staff_id', $data['staff_id'])->whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '<', $data['ends_at'])->where('occupied_until', '>', $data['starts_at'])->exists()) { throw ValidationException::withMessages(['starts_at' => 'Move existing appointments before blocking this time.']); }
            }
            if ($module === 'client_packages') {
                $package = DB::table('packages')->find($data['package_id']);
                if ($package->status !== 'active') { throw ValidationException::withMessages(['package_id' => 'Choose an active package.']); }
                $data['sessions_total'] = $package->sessions;
                $data['expires_on'] = CarbonImmutable::parse($data['starts_on'])->addDays($package->valid_days)->toDateString();
            }
            if ($module === 'memberships') {
                $plan = DB::table('membership_plans')->find($data['membership_plan_id']);
                $data['expires_on'] = CarbonImmutable::parse($data['starts_on'])->addDays($plan->valid_days)->toDateString();
            }
            if ($module === 'treatment_records') {
                if ($existing && $existing->status === 'finalized') { throw ValidationException::withMessages(['status' => 'Finalized records cannot be edited. Add an amendment instead.']); }
                $data['author_id'] = $existing->author_id ?? $user->id;
                $appointment = DB::table('appointments')->find($data['appointment_id']);
                if (in_array($appointment->status, ['cancelled', 'no_show'], true)) { throw ValidationException::withMessages(['appointment_id' => 'Cannot document a cancelled or missed appointment.']); }
            }
            if ($module === 'record_amendments') {
                if (DB::table('treatment_records')->where('id', $data['treatment_record_id'])->value('status') !== 'finalized') { throw ValidationException::withMessages(['treatment_record_id' => 'Finalize the treatment record before adding an amendment.']); }
                $data['author_id'] = $user->id;
            }
            if ($module === 'form_templates' && $existing && $existing->content !== $data['content']) { $data['version'] = $existing->version + 1; }
            if (in_array($module, ['photos', 'consents'], true) && ! empty($data['appointment_id'])) {
                if (! DB::table('appointments')->where('id', $data['appointment_id'])->where('client_id', $data['client_id'])->exists()) { throw ValidationException::withMessages(['appointment_id' => 'The appointment must belong to the selected client.']); }
            }
            if ($module === 'consents') {
                $template = DB::table('form_templates')->find($data['form_template_id']);
                if ($template->status !== 'active') { throw ValidationException::withMessages(['form_template_id' => 'Choose an active consent form.']); }
                unset($data['acknowledged']); $data['template_snapshot'] = $template->content; $data['version'] = $template->version; $data['signed_at'] = now(); $data['recorded_by'] = $user->id;
            }
            if ($module === 'photos') {
                $data['mime'] = $data['file']->getMimeType(); $data['path'] = $data['file']->store('branches/'.app(BranchContext::class)->requireBranch().'/client-files', 'local'); unset($data['file']);
            }
            if ($module === 'reward_entries') {
                DB::table('clients')->where('id', $data['client_id'])->lockForUpdate()->first();
                if ($previous = DB::table('reward_entries')->where('request_id', $data['request_id'])->first()) { return $previous->id; }
                if (DB::table('reward_entries')->where('client_id', $data['client_id'])->sum('points') + $data['points'] < 0) { throw ValidationException::withMessages(['points' => 'Not enough reward points.']); }
            }
            if ($module === 'campaigns' && $existing && $existing->status !== 'draft') { throw ValidationException::withMessages(['name' => 'Queued campaigns are preserved. Create a new draft.']); }
            $data['updated_at'] = now();
            if ($id) { DB::table($module)->where('id', $id)->update($data); }
            else { $id = DB::table($module)->insertGetId($data + ['created_at' => now()]); }
            if (isset($staffIds)) {
                DB::table('service_staff')->where('service_id', $id)->delete();
                foreach ($staffIds as $staffId) { DB::table('service_staff')->insert(['service_id' => $id, 'staff_id' => $staffId]); }
            }
            if ($module === 'treatment_records' && ! empty($data['follow_up_on'])) {
                DB::table('tasks')->updateOrInsert(['title' => 'Follow-up for treatment #'.$id], ['client_id' => $appointment->client_id, 'assigned_to' => $user->id, 'due_on' => $data['follow_up_on'], 'priority' => 'normal', 'status' => 'open', 'notes' => 'Review the linked treatment record.', 'created_at' => now(), 'updated_at' => now()]);
            }
            return $id;
        }, 3);
    }

    public function labels(string $table): array
    {
        return match ($table) {
            'appointments' => DB::table('appointments')->join('clients', 'clients.id', '=', 'appointments.client_id')->join('services', 'services.id', '=', 'appointments.service_id')->select('appointments.id', 'clients.name', 'services.name as service', 'appointments.starts_at')->get()->mapWithKeys(fn ($r): array => [$r->id => '#'.$r->id.' · '.$r->name.' · '.$r->service.' · '.$r->starts_at])->all(),
            'client_packages' => DB::table('client_packages')->join('clients', 'clients.id', '=', 'client_packages.client_id')->join('packages', 'packages.id', '=', 'client_packages.package_id')->select('client_packages.*', 'clients.name', 'packages.name as package')->get()->mapWithKeys(fn ($r): array => [$r->id => $r->name.' · '.$r->package.' · '.($r->sessions_total - $r->sessions_used).' left'])->all(),
            'treatment_records' => DB::table('treatment_records')->get(['id', 'appointment_id'])->mapWithKeys(fn ($r): array => [$r->id => 'Record #'.$r->id.' · Appointment #'.$r->appointment_id])->all(),
            'waitlist' => DB::table('waitlist')->join('clients', 'clients.id', '=', 'waitlist.client_id')->select('waitlist.id', 'clients.name', 'waitlist.preferred_date', 'waitlist.status')->get()->mapWithKeys(fn ($r): array => [$r->id => $r->name.' · '.$r->preferred_date.' · '.$r->status])->all(),
            'menu_items' => DB::table('menu_items')->pluck('label', 'id')->all(),
            default => DB::table($table)->pluck('name', 'id')->all(),
        };
    }
}
