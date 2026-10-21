<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Disbursement;
use App\Services\DisbursementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DisbursementController extends Controller
{
    public function index(Request $request)
    {
        $query = Disbursement::with('campaign', 'requester', 'recipient')->latest()->orderByDesc('id');
        if ($request->user()->role !== 'admin') {
            $query->where(fn ($q) => $q->where('requested_by', $request->user()->id)->orWhere('recipient_user_id', $request->user()->id));
        }

        return view('disbursements.index', ['disbursements' => $query->paginate(15)]);
    }

    public function create(Request $request, Campaign $campaign, DisbursementService $service)
    {
        $service->authorizeCampaign($campaign, $request->user());
        abort_unless($campaign->status === 'approved', 422, 'Campaign harus disetujui terlebih dahulu.');

        return view('disbursements.create', ['campaign' => $campaign, 'requestKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, Campaign $campaign, DisbursementService $service)
    {
        $service->authorizeCampaign($campaign, $request->user());
        if (is_string($request->input('recipient_email'))) {
            $request->merge(['recipient_email' => Str::lower(trim($request->input('recipient_email')))]);
        }
        $fields = $request->validate([
            'request_key' => 'required|uuid', 'recipient_email' => 'required|string|email|max:254',
            'recipient_label' => 'required|string|max:150', 'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000', 'recipient_bank' => 'required|string|max:100',
            'recipient_account_name' => 'required|string|max:150',
            'recipient_account_number' => ['required', 'string', 'regex:/^\d{6,30}$/D'],
            'amount' => 'required|integer|min:1000|max:999999999999',
        ]);
        $disbursement = $service->request($campaign, $request->user(), $fields);

        return $this->result($request, $disbursement, 'Pengajuan tersimpan dan dana dicadangkan. Menunggu persetujuan admin.', 201);
    }

    public function show(Request $request, Disbursement $disbursement)
    {
        $this->participant($request, $disbursement);
        $disbursement->load('campaign', 'requester', 'recipient', 'events');

        return response()->view('disbursements.show', [
            'disbursement' => $disbursement,
            'fundingSummary' => $disbursement->campaign->fundingSummary(),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, Disbursement $disbursement, DisbursementService $service)
    {
        return $this->result($request, $service->approve($disbursement, $request->user()), 'Pengajuan disetujui. Admin dapat melakukan transfer melalui bank.');
    }

    public function reject(Request $request, Disbursement $disbursement, DisbursementService $service)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $fields = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->result($request, $service->reject($disbursement, $request->user(), $fields['reason']), 'Pengajuan ditolak dan cadangan dana dilepas.');
    }

    public function cancel(Request $request, Disbursement $disbursement, DisbursementService $service)
    {
        abort_unless($request->user()->role === 'admin' || $disbursement->requested_by === $request->user()->id, 403);
        $fields = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->result($request, $service->cancel($disbursement, $request->user(), $fields['reason']), 'Pengajuan dibatalkan dan cadangan dana dilepas.');
    }

    public function transfer(Request $request, Disbursement $disbursement, DisbursementService $service)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $fields = $request->validate([
            'transfer_reference' => 'required|string|max:120',
            'transferred_at' => 'nullable|date|before_or_equal:now',
            'proof' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        return $this->result($request, $service->transfer($disbursement, $request->user(), $fields, $request->file('proof')),
            'Transfer dan bukti dicatat. Menunggu konfirmasi akun penerima.');
    }

    public function receive(Request $request, Disbursement $disbursement, DisbursementService $service)
    {
        abort_unless($disbursement->recipient_user_id === $request->user()->id, 403);
        $request->validate(['confirmed' => 'required|accepted']);

        return $this->result($request, $service->receive($disbursement, $request->user()), 'Penerimaan dana dikonfirmasi. Terima kasih.');
    }

    public function proof(Request $request, Disbursement $disbursement)
    {
        $this->participant($request, $disbursement);
        abort_unless($disbursement->proof_path && Storage::disk('local')->exists($disbursement->proof_path), 404);
        $extension = pathinfo($disbursement->proof_path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($disbursement->proof_path, 'bukti-penyaluran-'.$disbursement->public_id.'.'.$extension, [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function status(Request $request, Disbursement $disbursement)
    {
        $this->participant($request, $disbursement);

        return response()->json([
            'status' => $disbursement->status, 'status_label' => $disbursement->status_label,
            'summary' => $disbursement->campaign->fundingSummary(), 'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function publicStatus(Campaign $campaign)
    {
        abort_unless($campaign->status === 'approved', 404);
        $rows = $campaign->disbursements()->with('events')->latest()->orderByDesc('id')->limit(20)->get();

        // Deliberate allowlist: public feeds never serialize bank details, proof,
        // private notes, transfer references, account IDs, or recipient emails.
        return response()->json([
            'summary' => $campaign->fundingSummary(),
            'disbursements' => $rows->map(fn (Disbursement $row) => [
                'id' => $row->public_id, 'title' => $row->title, 'description' => $row->description,
                'recipient_label' => $row->recipient_label, 'amount' => $row->amount,
                'status' => $row->status, 'status_label' => $row->status_label,
                'created_at' => $row->requested_at?->toIso8601String(),
                'transferred_at' => $row->transferred_at?->toIso8601String(),
                'received_at' => $row->received_at?->toIso8601String(),
                'events' => $row->events->map(fn ($event) => [
                    'status' => $event->status, 'status_label' => $event->status_label,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values(),
            ])->values(),
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    private function participant(Request $request, Disbursement $disbursement): void
    {
        abort_unless($request->user()->role === 'admin'
            || $request->user()->id === $disbursement->requested_by
            || $request->user()->id === $disbursement->recipient_user_id, 403);
    }

    private function result(Request $request, Disbursement $disbursement, string $message, int $status = 200)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'id' => $disbursement->id, 'status' => $disbursement->status,
                'message' => $message, 'redirect_url' => route('disbursements.show', $disbursement),
            ], $status);
        }

        return redirect()->route('disbursements.show', $disbursement)->with('ok', $message);
    }
}
