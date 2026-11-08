<?php

namespace App\Http\Controllers;

use App\Services\BillingService;
use App\Services\ClinicAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\BranchDB as DB;

class BillingController extends Controller
{
    public function options(Request $request, ClinicAccess $access): JsonResponse
    {
        $access->authorize($request->user(), 'invoices');
        return response()->json(['clients' => DB::table('clients')->get(['id', 'name']), 'services' => DB::table('services')->where('status', 'active')->get(['id', 'name', 'price_cents']), 'products' => DB::table('products')->where('status', 'active')->get(['id', 'name', 'price_cents', 'stock']), 'packages' => DB::table('packages')->where('status', 'active')->get(['id', 'name', 'price_cents']), 'membership_plans' => DB::table('membership_plans')->get(['id', 'name', 'price_cents']), 'appointments' => DB::table('appointments')->whereNotIn('id', DB::table('invoices')->whereNotNull('appointment_id')->select('appointment_id'))->whereNotIn('status', ['cancelled', 'no_show'])->get(['id', 'client_id', 'service_id', 'starts_at', 'price_cents']), 'payment_methods' => DB::table('payment_methods')->where('active', true)->where('name', 'Cash')->get(['id', 'name'])]);
    }

    public function invoice(Request $request, BillingService $billing, ClinicAccess $access): JsonResponse
    {
        $access->authorize($request->user(), 'invoices', 'create');
        $data = $request->validate(['client_id' => 'required|integer|exists:clients,id', 'appointment_id' => 'nullable|integer|exists:appointments,id', 'due_on' => 'required|date_format:Y-m-d', 'discount' => 'nullable|numeric|min:0|max:1000000', 'notes' => 'nullable|string|max:5000', 'request_id' => 'required|uuid', 'lines' => 'nullable|array|max:50', 'lines.*.type' => 'required|in:service,product,package,membership', 'lines.*.item_id' => 'required|integer|min:1', 'lines.*.quantity' => 'required|integer|between:1,100']);
        $request->validate(['client_id'=>[\App\Services\BranchValidation::exists('clients')],'appointment_id'=>['nullable',\App\Services\BranchValidation::exists('appointments')]]);
        $id = $billing->invoice($data, $request->user());
        $access->audit($request->user(), 'issued', 'invoices', $id);
        return response()->json(['id' => $id, 'message' => 'Invoice issued. Stock and entitlements updated.']);
    }

    public function payment(Request $request, int $id, BillingService $billing, ClinicAccess $access): JsonResponse
    {
        $access->authorize($request->user(), 'invoices', 'edit');
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:1000000', 'payment_method_id' => 'required|integer|exists:payment_methods,id', 'reference' => 'nullable|string|max:255', 'refund_of' => 'nullable|integer|exists:payments,id', 'request_id' => 'required|uuid']);
        $request->validate(['payment_method_id'=>[\App\Services\BranchValidation::exists('payment_methods')],'refund_of'=>['nullable',\App\Services\BranchValidation::exists('payments')]]);
        $paymentId = $billing->payment($id, $data, $request->user());
        $access->audit($request->user(), empty($data['refund_of']) ? 'payment_recorded' : 'refund_recorded', 'payments', $paymentId);
        return response()->json(['id' => $paymentId, 'message' => 'Ledger updated.']);
    }

    public function detail(Request $request, int $id, ClinicAccess $access): JsonResponse
    {
        $access->authorize($request->user(), 'invoices');
        $invoice = DB::table('invoices')->find($id); abort_unless($invoice, 404);
        return response()->json(['invoice' => $invoice, 'client' => DB::table('clients')->find($invoice->client_id), 'lines' => DB::table('invoice_lines')->where('invoice_id', $id)->get(), 'payments' => DB::table('payments')->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')->where('invoice_id', $id)->select('payments.*', 'payment_methods.name as method')->get()]);
    }
}
