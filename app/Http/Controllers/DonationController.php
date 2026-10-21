<?php
namespace App\Http\Controllers;
use App\Models\Campaign;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DonationController extends Controller {
 public function store(Request $r,Campaign $campaign){abort_unless($campaign->status==='approved' && $campaign->deadline->endOfDay()->isFuture(),422);$v=$r->validate(['amount'=>'required|integer|min:1000|max:1000000000','method'=>'required|in:qris,bank,ewallet','message'=>'nullable|string|max:500','anonymous'=>'nullable|boolean']);$d=$campaign->donations()->create($v+['user_id'=>auth()->id(),'status'=>'pending']);return redirect()->route('payments.show',$d);}
 private function own(Donation $d){abort_unless($d->user_id===auth()->id()||auth()->user()->role==='admin',403);}
 public function payment(Donation $donation){$this->own($donation);return view('donations.payment',compact('donation'));}
 public function confirm(Donation $donation){$this->own($donation);DB::transaction(function()use($donation){$d=Donation::whereKey($donation->id)->lockForUpdate()->firstOrFail();if($d->status==='pending')$d->update(['status'=>'paid','paid_at'=>now()]);});return redirect()->route('certificates.show',$donation);}
 public function history(){return view('donations.history',['donations'=>auth()->user()->donations()->with('campaign')->latest()->paginate(15)]);}
 public function certificate(Donation $donation){$this->own($donation);abort_unless($donation->status==='paid',403);return view('donations.certificate',compact('donation'));}
}
