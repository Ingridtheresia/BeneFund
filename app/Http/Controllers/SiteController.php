<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignUpdate;
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
        ], DB::raw(Donation::NET_AMOUNT_SQL))->loadSum('expenses as spent_total', 'amount');

        return view('campaign.show', [
            'campaign' => $campaign,
            'updates' => $campaign->updates()->paginate(10, ['*'], 'updates_page')->withQueryString(),
            'expenses' => $campaign->expenses()->paginate(15, ['*'], 'expenses_page')->withQueryString(),
        ]);
    }

    public function about()
    {
        return view('simple', ['title' => 'Tentang BeneFund', 'description' => 'BeneFund menghubungkan kebaikan dengan kebutuhan nyata melalui campaign sosial dan laporan penggunaan dana yang dapat diperiksa publik.']);
    }

    public function transparency()
    {
        return view('transparency', [
            'campaigns' => Campaign::approved()->withFundingTotals()->latest()->orderByDesc('id')->paginate(20),
            'raised' => $this->publicDonations()->sum(DB::raw(Donation::NET_AMOUNT_SQL)),
            'spent' => Expense::whereHas('campaign', fn (Builder $campaign) => $campaign->approved())->sum('amount'),
        ]);
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
