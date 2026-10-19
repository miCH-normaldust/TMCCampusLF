<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = DB::table('activity_logs')
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->select([
                'activity_logs.id', 'activity_logs.event', 'activity_logs.ip_address',
                'activity_logs.device', 'activity_logs.created_at',
                'users.first_name', 'users.last_name', 'users.email',
            ])
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('activity_logs.created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('activity_logs.created_at', '<=', $date))
            ->latest('activity_logs.created_at');

        return view('admin.activity', ['activities' => $query->paginate(30)->withQueryString()]);
    }

    public function audit(): View
    {
        $logs = DB::table('audit_logs')
            ->join('users as admins', 'admins.id', '=', 'audit_logs.admin_id')
            ->select('audit_logs.*', 'admins.first_name', 'admins.last_name', 'admins.email')
            ->latest('audit_logs.created_at')
            ->paginate(30);

        return view('admin.audit', ['logs' => $logs]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $query = DB::table('activity_logs')
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->select([
                'activity_logs.event', 'activity_logs.ip_address', 'activity_logs.device',
                'activity_logs.created_at', 'users.first_name', 'users.last_name', 'users.email',
            ])
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('activity_logs.created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('activity_logs.created_at', '<=', $date))
            ->orderBy('activity_logs.created_at');

        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => 'activity_exported',
            'target_type' => 'activity_logs',
            'reason' => json_encode($data, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['event', 'ip_address', 'device', 'created_at', 'first_name', 'last_name', 'email']);
            $query->chunk(500, function ($rows) use ($output): void {
                foreach ($rows as $row) {
                    fputcsv($output, array_map(
                        fn ($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value,
                        (array) $row,
                    ));
                }
            });
            fclose($output);
        }, 'tmc-campuslf-activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
