<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use Illuminate\Http\Request;

class ReferralManagerController extends Controller
{
    /**
     * List all referrals — paginated, searchable.
     */
    public function index(Request $request)
    {
        $query = Referral::with([
            'referrer:id,name,email,referral_code',
            'referred:id,name,email,created_at',
        ]);

        if ($search = $request->input('search')) {
            $query->whereHas('referrer', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                  ->orWhereHas('referred', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $referrals = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($referrals);
    }

    /**
     * Get referral stats.
     */
    public function stats()
    {
        return response()->json([
            'total_referrals'   => Referral::count(),
            'credited_referrals' => Referral::whereNotNull('credited_at')->count(),
            'pending_referrals'  => Referral::whereNull('credited_at')->count(),
        ]);
    }
}
