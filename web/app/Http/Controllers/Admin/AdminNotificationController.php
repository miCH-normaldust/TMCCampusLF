<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user('admin')->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back()->with('status', 'Notification marked as read.');
    }
}
