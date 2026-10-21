<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentGatewayException;
use App\Models\Campaign;
use App\Models\Donation;
use App\Services\MidtransService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DonationController extends Controller
{
    public function store(Request $request, Campaign $campaign)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:1000|max:1000000000',
            'method' => 'required|in:qris,bank,ewallet',
            'payment_channel' => [
                'nullable', 'string', 'required_if:method,bank,ewallet',
                Rule::in(array_keys(config('payments.methods.'.(is_string($request->input('method')) ? $request->input('method') : '').'.channels', []))),
            ],
            'message' => 'nullable|string|max:500', 'anonymous' => 'nullable|boolean',
        ]);
        $validated['anonymous'] = $request->boolean('anonymous');
        $validated['payment_channel'] = $validated['payment_channel'] ?? 'other_qris';
        $validated['admin_fee'] = (int) config('payments.methods.'.$validated['method'].'.fee');
        $donation = DB::transaction(function () use ($request, $campaign, $validated) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved' && $locked->deadline->copy()->endOfDay()->isFuture(), 422, 'Campaign tidak menerima donasi saat ini.');

            return $locked->donations()->create($validated + [
                'user_id' => $request->user()->id, 'status' => 'pending', 'order_id' => 'BF-'.Str::uuid(),
            ]);
        });

        return redirect()->route('payments.show', $donation);
    }

    private function own(Donation $donation): void
    {
        abort_unless($donation->user_id === auth()->id() || auth()->user()->role === 'admin', 403);
    }

    public function payment(Donation $donation, MidtransService $midtrans)
    {
        $this->own($donation);
        $donation->load('campaign');

        return view('donations.payment', ['donation' => $donation, 'configured' => $midtrans->configured()]);
    }

    public function checkout(Request $request, Donation $donation, MidtransService $midtrans)
    {
        $this->own($donation);
        try {
            $donation = $midtrans->checkout($donation);
        } catch (PaymentGatewayException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('payments.show', $donation)->withErrors(['payment' => $exception->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['token' => $donation->snap_token, 'redirect_url' => $donation->snap_redirect_url]);
        }

        return redirect()->away($donation->snap_redirect_url);
    }

    public function confirm(Request $request, Donation $donation, MidtransService $midtrans)
    {
        // Browser callbacks and submitted status fields are never proof of payment.
        $this->own($donation);
        try {
            $donation = $midtrans->sync($donation);
        } catch (PaymentGatewayException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('payments.show', $donation)->withErrors(['payment' => $exception->getMessage()]);
        }
        $certificate = $donation->net_amount > 0;
        if ($request->expectsJson()) {
            return response()->json([
                'status' => $donation->status, 'label' => $donation->status_label,
                'redirect_url' => $certificate ? route('certificates.show', $donation) : route('payments.show', $donation),
            ]);
        }

        return redirect()->route($certificate ? 'certificates.show' : 'payments.show', $donation)
            ->with('ok', $certificate ? 'Pembayaran telah diverifikasi Midtrans.' : 'Status: '.$donation->status_label.'.');
    }

    public function finish(Donation $donation)
    {
        $this->own($donation);

        return redirect()->route('payments.show', $donation)
            ->with('ok', 'Periksa status untuk memverifikasi pembayaran Anda.');
    }

    public function notification(Request $request, MidtransService $midtrans)
    {
        $payload = $request->validate([
            'order_id' => 'required|string|max:64', 'status_code' => 'required|string|size:3',
            'gross_amount' => ['required', 'string', 'regex:/^\d{1,12}(?:\.0{1,2})?$/D'],
            'signature_key' => 'required|string|size:128',
        ]);
        abort_unless($midtrans->validSignature($payload), 403, 'Signature Midtrans tidak valid.');
        $donation = Donation::where('order_id', $payload['order_id'])->firstOrFail();
        abort_unless((int) $payload['gross_amount'] === $donation->total_amount, 422, 'Nominal notifikasi tidak cocok dengan total pembayaran.');
        // Signature does not cover transaction_status; verify every notification via GET status.
        $donation = $midtrans->sync($donation);

        return response()->json(['message' => 'Notifikasi diproses.', 'status' => $donation->status]);
    }

    public function history()
    {
        return view('donations.history', [
            'donations' => auth()->user()->donations()->with('campaign', 'user')->latest()->paginate(15),
        ]);
    }

    public function certificate(Donation $donation)
    {
        $this->own($donation);
        abort_unless($donation->net_amount > 0 && $donation->paid_at, 403);
        $donation->load('user', 'campaign');

        return view('donations.certificate', compact('donation'));
    }
}
