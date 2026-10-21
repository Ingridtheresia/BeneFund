<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index()
    {
        $donations = auth()->user()->donations();

        return view('dashboard', [
            'donations' => (clone $donations)->with('campaign')->latest()->orderByDesc('id')->take(8)->get(),
            'donatedTotal' => (clone $donations)->whereIn('status', ['paid', 'partially_refunded'])->sum(DB::raw(Donation::NET_AMOUNT_SQL)),
            'donationCount' => (clone $donations)->whereIn('status', ['paid', 'partially_refunded'])->count(),
            'campaignCount' => auth()->user()->campaigns()->count(),
        ]);
    }

    public function profile()
    {
        return view('profile');
    }

    public function updateProfile(Request $r)
    {
        $user = $r->user();
        if (is_string($r->input('email'))) {
            $r->merge(['email' => Str::lower(trim($r->input('email')))]);
        }
        $values = $r->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->getKey())],
            'current_password' => ['nullable', 'required_with:password', 'string', 'current_password'],
            'password' => ['nullable', 'string', 'confirmed', 'max:72', Password::min(8), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('Password maksimal 72 byte dan tidak boleh berisi karakter null.');
                }
            }],
        ]);
        $user->fill(['name' => $values['name'], 'email' => $values['email']]);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        if ($r->filled('password')) {
            $user->password = $values['password'];
            $user->setRememberToken(Str::random(60));
        }
        $user->save();
        if ($r->filled('password')) {
            $r->session()->regenerate();
        }

        return back()->with('ok', $r->filled('password') ? 'Profil dan password tersimpan.' : 'Profil tersimpan.');
    }

    public function favorite(Campaign $campaign)
    {
        DB::transaction(function () use ($campaign) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'approved', 404);
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail()->favorites()->toggle($locked->id);
        }, 3);

        return back()->with('ok', 'Daftar favorit diperbarui.');
    }

    public function favorites()
    {
        return view('campaigns', ['campaigns' => auth()->user()->favorites()->approved()->withFundingTotals()->orderByDesc('favorites.created_at')->orderByDesc('campaigns.id')->paginate(9)]);
    }

    public function admin()
    {
        return view('admin.index', ['pending' => Campaign::where('status', 'pending')->with('user')->latest()->orderByDesc('id')->paginate(15), 'total' => Donation::whereIn('status', ['paid', 'partially_refunded'])->sum(DB::raw(Donation::NET_AMOUNT_SQL)), 'users' => User::count(), 'campaignCount' => Campaign::count()]);
    }

    private function moderate(Campaign $campaign, string $status): void
    {
        DB::transaction(function () use ($campaign, $status) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['campaign' => 'Hanya campaign yang menunggu verifikasi yang dapat diputuskan.']);
            }
            if ($status === 'approved' && (! $locked->deadline || $locked->deadline->endOfDay()->isPast())) {
                throw ValidationException::withMessages(['campaign' => 'Batas waktu campaign telah berakhir. Penggalang perlu memperbarui deadline.']);
            }
            $locked->update(['status' => $status]);
        }, 3);
    }

    public function approve(Campaign $campaign)
    {
        $this->moderate($campaign, 'approved');

        return back()->with('ok', 'Campaign disetujui.');
    }

    public function reject(Campaign $campaign)
    {
        $this->moderate($campaign, 'rejected');

        return back()->with('ok', 'Campaign ditolak.');
    }

    public function users()
    {
        return view('admin.users', ['users' => User::latest()->orderByDesc('id')->paginate(20)]);
    }

    public function transactions()
    {
        return view('donations.history', ['donations' => Donation::with('campaign', 'user')->latest()->orderByDesc('id')->paginate(20)]);
    }
}
