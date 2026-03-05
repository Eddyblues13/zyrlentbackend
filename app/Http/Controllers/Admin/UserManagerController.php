<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;

class UserManagerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->withCount('orders')
            ->with('wallet:id,user_id,balance')
            ->latest()
            ->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

    public function show(User $user)
    {
        $user->load([
            'wallet',
            'orders' => fn($q) => $q->with('service:id,name,color')->latest()->take(10),
            'transactions' => fn($q) => $q->latest()->take(10),
        ]);

        return response()->json($user);
    }

    public function creditWallet(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $wallet = $user->wallet ?? Wallet::create(['user_id' => $user->id, 'balance' => 0]);
        $wallet->credit($request->amount, 'Admin credit: ' . ($request->note ?? 'Manual top-up'));

        return response()->json([
            'message' => "₦" . number_format($request->amount, 2) . " credited to {$user->name}'s wallet.",
            'balance' => $wallet->fresh()->balance,
        ]);
    }
}
