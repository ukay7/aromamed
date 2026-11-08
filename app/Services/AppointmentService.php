<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use App\Services\BranchDB as DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function save(array $data, ?int $id = null): int
    {
        return DB::transaction(function () use ($data, $id): int {
            $existing = $id ? DB::table('appointments')->lockForUpdate()->find($id) : null;
            if ($id && ! $existing) { abort(404); }
            if ($existing && in_array($existing->status, ['completed', 'cancelled', 'no_show'], true)) {
                throw ValidationException::withMessages(['status' => 'Closed appointments are preserved. Create a new booking to reschedule.']);
            }
            $staff = DB::table('staff')->lockForUpdate()->find($data['staff_id']);
            $client = DB::table('clients')->lockForUpdate()->find($data['client_id']);
            $service = DB::table('services')->find($data['service_id']);
            if ($existing && ($existing->client_id !== (int) $data['client_id'] || $existing->service_id !== (int) $data['service_id']) && (DB::table('invoices')->where('appointment_id', $id)->exists() || DB::table('treatment_records')->where('appointment_id', $id)->exists() || DB::table('consents')->where('appointment_id', $id)->exists())) {
                throw ValidationException::withMessages(['client_id' => 'This visit already has linked clinical or financial records. Its client and service cannot be changed.']);
            }
            if ($service->required_room_id) {
                if (! empty($data['room_id']) && (int) $data['room_id'] !== $service->required_room_id) { throw ValidationException::withMessages(['room_id' => 'This service requires its configured room or chair.']); }
                $data['room_id'] = $service->required_room_id;
            }
            if ($service->required_device_id) {
                if (! empty($data['device_id']) && (int) $data['device_id'] !== $service->required_device_id) { throw ValidationException::withMessages(['device_id' => 'This service requires its configured device.']); }
                $data['device_id'] = $service->required_device_id;
            }
            if (! empty($data['waitlist_id'])) {
                $waitlist = DB::table('waitlist')->lockForUpdate()->find($data['waitlist_id']);
                if ($waitlist->client_id !== (int) $data['client_id'] || $waitlist->service_id !== (int) $data['service_id'] || (! $id && ! in_array($waitlist->status, ['waiting', 'contacted'], true))) {
                    throw ValidationException::withMessages(['waitlist_id' => 'Choose a waiting entry matching this client and service.']);
                }
            }
            if ($staff->status !== 'active' || $client->status !== 'active' || $service->status !== 'active') {
                throw ValidationException::withMessages(['service_id' => 'Choose an active client, practitioner and service.']);
            }
            if (! DB::table('service_staff')->where('staff_id', $staff->id)->where('service_id', $service->id)->exists()) {
                throw ValidationException::withMessages(['staff_id' => 'This practitioner is not assigned to the selected service.']);
            }
            $start = CarbonImmutable::parse($data['starts_at']);
            $end = $start->addMinutes($data['duration']);
            $occupied = $end->addMinutes($data['buffer']);
            $closing = $start->setTimeFromTimeString($staff->ends_at);
            $opening = $start->setTimeFromTimeString($staff->starts_at);
            $activeBooking = ! in_array($data['status'], ['cancelled', 'no_show'], true);
            if ($activeBooking && (! in_array((string) $start->dayOfWeek, array_map('strval', json_decode($staff->working_days, true)), true) || $start < $opening || $occupied > $closing)) {
                throw ValidationException::withMessages(['starts_at' => 'The appointment and buffer must fit the practitioner’s working days and hours.']);
            }
            if ($activeBooking && $staff->break_start && $staff->break_end && $start < $start->setTimeFromTimeString($staff->break_end) && $occupied > $start->setTimeFromTimeString($staff->break_start)) {
                throw ValidationException::withMessages(['starts_at' => 'This slot overlaps the practitioner’s break.']);
            }
            $resourceIds = array_filter([$data['room_id'] ?? null, $data['device_id'] ?? null]);
            foreach (DB::table('resources')->whereIn('id', $resourceIds)->orderBy('id')->lockForUpdate()->get() as $resource) {
                $expected = (int) ($data['device_id'] ?? 0) === $resource->id ? ['device'] : ['room', 'chair'];
                if ($resource->status !== 'active' || ! in_array($resource->type, $expected, true)) {
                    throw ValidationException::withMessages(['room_id' => 'Choose active resources of the correct type.']);
                }
            }
            if ($activeBooking) {
                $conflict = DB::table('appointments')->whereNotIn('status', ['cancelled', 'no_show'])->when($id, fn ($q) => $q->where('id', '!=', $id))->where('starts_at', '<', $occupied)->where('occupied_until', '>', $start)->where(function ($q) use ($data, $resourceIds): void {
                    $q->where('staff_id', $data['staff_id'])->orWhere('client_id', $data['client_id']);
                    if ($resourceIds) { $q->orWhereIn('room_id', $resourceIds)->orWhereIn('device_id', $resourceIds); }
                })->exists();
                $blocked = DB::table('blocked_times')->where('staff_id', $staff->id)->where('starts_at', '<', $occupied)->where('ends_at', '>', $start)->exists();
                if ($conflict || $blocked) {
                    throw ValidationException::withMessages(['starts_at' => 'This slot conflicts with a client, practitioner, room, device or blocked time. Choose another time.']);
                }
            }
            $package = null;
            if (! empty($data['client_package_id'])) {
                $package = DB::table('client_packages')->lockForUpdate()->find($data['client_package_id']);
                $plan = DB::table('packages')->find($package->package_id);
                if ($package->client_id !== (int) $data['client_id'] || $plan->service_id !== (int) $data['service_id'] || $start->toDateString() < $package->starts_on || $start->toDateString() > $package->expires_on || $package->sessions_used >= $package->sessions_total) {
                    throw ValidationException::withMessages(['client_package_id' => 'This package does not cover this client, service, date, or has no sessions remaining.']);
                }
            }
            if ($data['status'] === 'completed' && $service->requires_consent && (! $id || ! DB::table('consents')->where('appointment_id', $id)->where('client_id', $data['client_id'])->exists())) {
                throw ValidationException::withMessages(['status' => 'Record client consent for this appointment before completing it.']);
            }
            if ($data['status'] === 'completed' && (! $id || ! DB::table('treatment_records')->where('appointment_id', $id)->where('status', 'finalized')->exists())) {
                throw ValidationException::withMessages(['status' => 'Finalize the treatment record before completing the appointment.']);
            }
            $data['starts_at'] = $start; $data['ends_at'] = $end; $data['occupied_until'] = $occupied;
            $data['price_cents'] = $package ? 0 : ($existing && ! $existing->client_package_id && $existing->service_id === (int) $data['service_id'] ? $existing->price_cents : $service->price_cents);
            $data['updated_at'] = now();
            if ($id) { DB::table('appointments')->where('id', $id)->update($data); }
            else { $id = DB::table('appointments')->insertGetId($data + ['created_at' => now()]); }
            if (! empty($data['waitlist_id'])) { DB::table('waitlist')->where('id', $data['waitlist_id'])->update(['status' => 'booked', 'updated_at' => now()]); }
            if ($package && $data['status'] === 'completed') {
                DB::table('package_redemptions')->insert(['appointment_id' => $id, 'client_package_id' => $package->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('client_packages')->where('id', $package->id)->increment('sessions_used');
            }
            return $id;
        }, 3);
    }
}
