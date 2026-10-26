<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Services\ActivityNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    private function authorizeOwner(Campaign $campaign): void
    {
        abort_unless(auth()->user()->role === 'admin' || $campaign->user_id === auth()->id(), 403);
    }

    private function fields(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(Campaign::CATEGORIES)],
            'location' => ['nullable', 'string', 'max:150'],
            'description' => ['required', 'string', 'min:30', 'max:20000'],
            'target' => ['required', 'integer', 'min:100000', 'max:999999999999'],
            'deadline' => ['required', 'date_format:Y-m-d', 'after:today'],
            'image_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
    }

    public function index()
    {
        $campaigns = Campaign::withFundingTotals()->latest()->orderByDesc('id');
        if (auth()->user()->role !== 'admin') {
            $campaigns->where('user_id', auth()->id());
        }

        return view('manage.index', ['campaigns' => $campaigns->paginate(15)]);
    }

    public function create()
    {
        return view('manage.form', ['campaign' => new Campaign]);
    }

    public function store(Request $request)
    {
        $fields = $this->fields($request);
        DB::transaction(function () use ($fields) {
            $campaign = Campaign::create($fields + ['user_id' => auth()->id(), 'status' => 'pending']);
            app(ActivityNotificationService::class)->campaignSubmitted($campaign);
        }, 3);

        return redirect()->route('manage.campaigns')->with('ok', 'Campaign menunggu verifikasi admin.');
    }

    public function edit(Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        return view('manage.form', compact('campaign'));
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorizeOwner($campaign);
        $fields = $this->fields($request);

        DB::transaction(function () use ($campaign, $fields) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($locked);
            $locked->update($fields + ['status' => 'pending']);
            if ($locked->wasChanged(array_merge(array_keys($fields), ['status']))) {
                app(ActivityNotificationService::class)->campaignSubmitted($locked);
            }
        }, 3);

        return redirect()->route('manage.campaigns')->with('ok', 'Perubahan menunggu verifikasi.');
    }

    public function postUpdate(Request $request, Campaign $campaign)
    {
        $this->authorizeOwner($campaign);
        $fields = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($campaign, $fields) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->authorizeOwner($locked);
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['campaign' => 'Campaign harus disetujui sebelum menerbitkan update.']);
            }
            $locked->updates()->create($fields);
        }, 3);

        return back()->with('ok', 'Update berhasil diterbitkan.');
    }

    public function expense(Request $request, Campaign $campaign)
    {
        $this->authorizeOwner($campaign);
        throw ValidationException::withMessages([
            'disbursement' => 'Pengeluaran harus melalui alur Salurkan Donasi agar persetujuan, bukti transfer, dan penerimaan dana tercatat.',
        ]);
    }
}
