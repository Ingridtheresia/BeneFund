<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $fields = $request->validate(['filter' => ['sometimes', Rule::in(['all', 'unread'])]]);
        $filter = $fields['filter'] ?? 'all';
        $notifications = $request->user()->notifications()->orderByDesc('id');
        if ($filter === 'unread') {
            $notifications->whereNull('read_at');
        }

        return view('notifications.index', [
            'notifications' => $notifications->paginate(15)->withQueryString(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'filter' => $filter,
        ]);
    }

    public function status(Request $request)
    {
        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'latest_id' => $request->user()->notifications()->orderByDesc('id')->value('id'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $request, string $notification)
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
        }

        return redirect()->route('notifications.index')->with('ok', 'Notifikasi ditandai sudah dibaca.');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
        }

        return redirect()->route('notifications.index')->with('ok', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
