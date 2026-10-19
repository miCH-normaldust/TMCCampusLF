<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,reviewing,resolved,closed'],
        ]);
        $feedback = DB::table('feedback')
            ->join('users', 'users.id', '=', 'feedback.user_id')
            ->select('feedback.*', 'users.first_name', 'users.last_name', 'users.email')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('feedback.status', $status))
            ->latest('feedback.created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.feedback', ['feedback' => $feedback]);
    }

    public function update(Request $request, int $feedback): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,reviewing,resolved,closed'],
            'admin_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = DB::table('feedback')->where('id', $feedback)->first();
        abort_unless($record, 404);

        DB::transaction(function () use ($request, $record, $data): void {
            DB::table('feedback')->where('id', $record->id)->update([
                ...$data,
                'reviewed_by' => $request->user('admin')->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'feedback_'.$data['status'],
                'target_type' => 'feedback',
                'target_id' => $record->id,
                'reason' => $data['admin_response'] ?: null,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Feedback review saved.');
    }
}
