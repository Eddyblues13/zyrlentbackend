<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * Get user's referral info, stats, and history.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $frontendUrl = config('app.frontend_url', 'https://www.zyrlent.com');

        // Ensure the user has a referral code
        if (empty($user->referral_code)) {
            $user->referral_code = 'ZYR-' . strtoupper(\Illuminate\Support\Str::random(6));
            $user->save();
        }

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referred:id,name,email,created_at')
            ->latest()
            ->get()
            ->map(function ($ref) {
                return [
                    'id' => $ref->id,
                    'name' => $ref->referred->name ?? 'Unknown',
                    'email' => $ref->referred->email ?? '',
                    'status' => $ref->status,
                    'joined_at' => $ref->created_at->format('M d, Y'),
                    'credited_at' => $ref->credited_at?->format('M d, Y'),
                ];
            });

        $totalInvited = $referrals->count();
        $totalQualified = $referrals->whereIn('status', ['qualified', 'credited'])->count();
        $totalCredited = $referrals->where('status', 'credited')->count();
        $totalEarned = $totalCredited * 2000; // ₦2,000 per referral

        return response()->json([
            'referral_code' => $user->referral_code,
            'referral_link' => $frontendUrl . '/register?ref=' . $user->referral_code,
            'stats' => [
                'total_invited' => $totalInvited,
                'total_qualified' => $totalQualified,
                'total_earned' => $totalEarned,
            ],
            'referrals' => $referrals,
        ]);
    }
}
