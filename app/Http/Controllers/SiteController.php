<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignUpdate;
use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    private function publicDonations(): Builder
    {
        return Donation::whereIn('status', ['paid', 'partially_refunded'])
            ->whereHas('campaign', fn (Builder $campaign) => $campaign->approved());
    }

    public function home()
    {
        return view('home', [
            'campaigns' => Campaign::approved()->withFundingTotals()->latest()->orderByDesc('id')->take(6)->get(),
            'total' => $this->publicDonations()->sum(DB::raw(Donation::NET_AMOUNT_SQL)),
            'donors' => $this->publicDonations()->distinct()->count('user_id'),
        ]);
    }

    public function campaigns(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', Rule::in(Campaign::CATEGORIES)],
        ]);
        $campaigns = Campaign::approved()->withFundingTotals();

        if ($request->filled('q')) {
            $campaigns->where('title', 'like', '%'.$filters['q'].'%');
        }
        if ($request->filled('category')) {
            $campaigns->where('category', $filters['category']);
        }

        return view('campaigns', ['campaigns' => $campaigns->latest()->orderByDesc('id')->paginate(9)->withQueryString()]);
    }

    public function show(Campaign $campaign)
    {
        abort_unless($campaign->status === 'approved' || (auth()->check() && (auth()->id() === $campaign->user_id || auth()->user()->role === 'admin')), 404);
        $campaign->load('user')->loadSum([
            'donations as raised_total' => fn (Builder $donations) => $donations->whereIn('status', ['paid', 'partially_refunded']),
        ], DB::raw(Donation::NET_AMOUNT_SQL))->loadSum('expenses as spent_total', 'amount')->loadSum([
            'disbursements as reserved_total' => fn (Builder $disbursements) => $disbursements->whereIn('status', Disbursement::RESERVED_STATUSES),
        ], 'amount')->loadSum([
            'disbursements as confirmed_total' => fn (Builder $disbursements) => $disbursements->where('status', 'received'),
        ], 'amount');

        return view('campaign.show', [
            'campaign' => $campaign,
            'updates' => $campaign->updates()->paginate(10, ['*'], 'updates_page')->withQueryString(),
            'expenses' => $campaign->expenses()->paginate(15, ['*'], 'expenses_page')->withQueryString(),
            'disbursements' => $campaign->disbursements()->with('events')->latest()->orderByDesc('id')->limit(20)->get(),
            'fundingSummary' => $campaign->fundingSummary(),
        ]);
    }

    public function about()
    {
        return view('about');
    }

    public function transparency()
    {
        $summary = $this->publicFundingSummary();

        return view('transparency', [
            'campaigns' => Campaign::approved()->withFundingTotals()->latest()->orderByDesc('id')->paginate(20),
            'summary' => $summary,
        ] + $summary);
    }

    public function transparencyStatus()
    {
        $campaigns = Campaign::approved()->withFundingTotals()->latest()->orderByDesc('id')->paginate(20);

        return response()->json([
            'summary' => $this->publicFundingSummary(),
            'campaigns' => $campaigns->getCollection()->map(fn (Campaign $campaign) => [
                'id' => $campaign->id, 'title' => $campaign->title, 'url' => route('campaign.show', $campaign),
            ] + $campaign->fundingSummary())->values(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function publicFundingSummary(): array
    {
        $publicCampaign = fn (Builder $campaign) => $campaign->approved();
        $raised = (int) $this->publicDonations()->sum(DB::raw(Donation::NET_AMOUNT_SQL));
        $spent = (int) Expense::whereHas('campaign', $publicCampaign)->sum('amount');
        $reserved = (int) Disbursement::whereIn('status', Disbursement::RESERVED_STATUSES)->whereHas('campaign', $publicCampaign)->sum('amount');
        $confirmed = (int) Disbursement::where('status', 'received')->whereHas('campaign', $publicCampaign)->sum('amount');

        return ['raised' => $raised, 'spent' => $spent, 'reserved' => $reserved, 'available' => $raised - $spent - $reserved, 'confirmed' => $confirmed];
    }

    public function news()
    {
        return view('news', [
            'updates' => CampaignUpdate::whereHas('campaign', fn (Builder $campaign) => $campaign->approved())
                ->with('campaign')->latest()->orderByDesc('id')->paginate(12),
        ]);
    }

    public function topDonors()
    {
        return view('top', [
            'donors' => $this->publicDonations()->with('user')->where('anonymous', false)
                ->selectRaw('user_id, SUM('.Donation::NET_AMOUNT_SQL.') as total, COUNT(*) as frequency')
                ->groupBy('user_id')->orderByDesc('total')->orderBy('user_id')->limit(20)->get(),
        ]);
    }
}
