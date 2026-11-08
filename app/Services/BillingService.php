<?php

namespace App\Services;

use App\Models\User;
use App\Services\BranchDB as DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function invoice(array $data, User $user): int
    {
        return DB::transaction(function () use ($data, $user): int {
            DB::table('clients')->where('id', $data['client_id'])->lockForUpdate()->first();
            if ($previous = DB::table('invoices')->where('request_id', $data['request_id'])->first()) { return $previous->id; }
            $lines = []; $subtotal = 0;
            if (! empty($data['appointment_id'])) {
                $appointment = DB::table('appointments')->lockForUpdate()->find($data['appointment_id']);
                if ($appointment->client_id !== (int) $data['client_id'] || in_array($appointment->status, ['cancelled', 'no_show'], true) || DB::table('invoices')->where('appointment_id', $appointment->id)->exists()) {
                    throw ValidationException::withMessages(['appointment_id' => 'Choose an uninvoiced appointment belonging to this client.']);
                }
                $service = DB::table('services')->find($appointment->service_id);
                $lines[] = ['description' => $service->name, 'item_type' => 'appointment', 'item_id' => $appointment->id, 'quantity' => 1, 'unit_price_cents' => $appointment->price_cents, 'total_cents' => $appointment->price_cents];
                $subtotal += $appointment->price_cents;
            }
            foreach ($data['lines'] ?? [] as $line) {
                $table = ['service' => 'services', 'product' => 'products', 'package' => 'packages', 'membership' => 'membership_plans'][$line['type']];
                $item = DB::table($table)->lockForUpdate()->find($line['item_id']);
                if (! $item || (isset($item->status) && $item->status !== 'active')) { throw ValidationException::withMessages(['lines' => 'An item is missing or inactive.']); }
                $total = $item->price_cents * $line['quantity']; $subtotal += $total;
                $lines[] = ['description' => $item->name, 'item_type' => $line['type'], 'item_id' => $item->id, 'quantity' => $line['quantity'], 'unit_price_cents' => $item->price_cents, 'total_cents' => $total];
            }
            if (! count($lines)) { throw ValidationException::withMessages(['lines' => 'Add an appointment or at least one item.']); }
            if ($subtotal > 2000000000) { throw ValidationException::withMessages(['lines' => 'This invoice exceeds the supported total. Split it into smaller invoices.']); }
            $discount = (int) round(($data['discount'] ?? 0) * 100);
            if ($discount > $subtotal) { throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']); }
            $taxRate = (float) (DB::table('settings')->where('key', 'tax_percent')->value('value') ?? 0);
            $tax = (int) round(($subtotal - $discount) * $taxRate / 100);
            $id = DB::table('invoices')->insertGetId(['number' => 'AM-'.strtoupper(substr($data['request_id'], 0, 8)), 'client_id' => $data['client_id'], 'appointment_id' => $data['appointment_id'] ?? null, 'due_on' => $data['due_on'], 'subtotal_cents' => $subtotal, 'discount_cents' => $discount, 'tax_cents' => $tax, 'total_cents' => $subtotal - $discount + $tax, 'notes' => $data['notes'] ?? null, 'request_id' => $data['request_id'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $index => $line) {
                DB::table('invoice_lines')->insert($line + ['invoice_id' => $id]);
                if ($line['item_type'] === 'product') {
                    $this->stock(['product_id' => $line['item_id'], 'quantity' => -$line['quantity'], 'reason' => 'Invoice #'.$id, 'request_id' => 'invoice-'.$id.'-'.$index], $user);
                }
                if ($line['item_type'] === 'package') {
                    $package = DB::table('packages')->find($line['item_id']);
                    for ($i = 0; $i < $line['quantity']; $i++) {
                        DB::table('client_packages')->insert(['client_id' => $data['client_id'], 'package_id' => $package->id, 'starts_on' => today(), 'expires_on' => today()->addDays($package->valid_days), 'sessions_total' => $package->sessions, 'sessions_used' => 0, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                if ($line['item_type'] === 'membership') {
                    $plan = DB::table('membership_plans')->find($line['item_id']);
                    DB::table('memberships')->insert(['client_id' => $data['client_id'], 'membership_plan_id' => $plan->id, 'starts_on' => today(), 'expires_on' => today()->addDays($plan->valid_days * $line['quantity']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            return $id;
        }, 3);
    }

    public function payment(int $invoiceId, array $data, User $user): int
    {
        return DB::transaction(function () use ($invoiceId, $data, $user): int {
            $invoice = DB::table('invoices')->lockForUpdate()->find($invoiceId); abort_unless($invoice, 404);
            if ($existing = DB::table('payments')->where('request_id', $data['request_id'])->first()) { return $existing->id; }
            $method = DB::table('payment_methods')->find($data['payment_method_id']);
            if (! $method->active || $method->name !== 'Cash') { throw ValidationException::withMessages(['payment_method_id' => 'Only cash receipts and cash refunds are enabled.']); }
            $amount = (int) round($data['amount'] * 100);
            $paid = (int) DB::table('payments')->where('invoice_id', $invoiceId)->sum('amount_cents');
            if (! empty($data['refund_of'])) {
                $original = DB::table('payments')->where('invoice_id', $invoiceId)->where('amount_cents', '>', 0)->find($data['refund_of']);
                $refunded = abs((int) DB::table('payments')->where('refund_of', $data['refund_of'])->sum('amount_cents'));
                if (! $original || $amount > $original->amount_cents - $refunded) { throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining original payment.']); }
                $amount = -$amount;
            } elseif ($amount > $invoice->total_cents - $paid) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the invoice balance.']);
            }
            $id = DB::table('payments')->insertGetId(['invoice_id' => $invoiceId, 'payment_method_id' => $method->id, 'amount_cents' => $amount, 'refund_of' => $data['refund_of'] ?? null, 'reference' => $data['reference'] ?? null, 'request_id' => $data['request_id'], 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $membership = DB::table('memberships')->join('membership_plans', 'membership_plans.id', '=', 'memberships.membership_plan_id')->where('client_id', $invoice->client_id)->where('status', 'active')->whereDate('starts_on', '<=', today())->whereDate('expires_on', '>=', today())->orderByDesc('points_per_dollar')->first();
            if ($membership && $amount > 0) {
                DB::table('reward_entries')->insert(['client_id' => $invoice->client_id, 'points' => (int) floor($amount / 100) * $membership->points_per_dollar, 'reason' => 'Payment #'.$id, 'request_id' => 'payment-'.$id, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($amount < 0) {
                $earned = DB::table('reward_entries')->where('request_id', 'payment-'.$data['refund_of'])->value('points') ?? 0;
                $priorReversed = abs((int) DB::table('reward_entries')->where('reason', 'Refund of payment #'.$data['refund_of'])->sum('points'));
                $targetReversal = (int) floor($earned * ($refunded + abs($amount)) / $original->amount_cents);
                DB::table('reward_entries')->insert(['client_id' => $invoice->client_id, 'points' => -max(0, $targetReversal - $priorReversed), 'reason' => 'Refund of payment #'.$data['refund_of'], 'request_id' => 'payment-'.$id, 'created_at' => now(), 'updated_at' => now()]);
            }
            return $id;
        }, 3);
    }

    public function stock(array $data, User $user): int
    {
        return DB::transaction(function () use ($data, $user): int {
            $product = DB::table('products')->lockForUpdate()->find($data['product_id']);
            if ($previous = DB::table('stock_movements')->where('request_id', $data['request_id'])->first()) { return $previous->id; }
            if ($product->stock + $data['quantity'] < 0) { throw ValidationException::withMessages(['quantity' => 'Insufficient stock for '.$product->name.'.']); }
            DB::table('products')->where('id', $product->id)->increment('stock', $data['quantity']);
            return DB::table('stock_movements')->insertGetId($data + ['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
