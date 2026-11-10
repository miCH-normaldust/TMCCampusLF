<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Handover;
use App\Models\LostSighting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'in:claims,proofs,handovers,users'],
        ]);
        $category = $data['category'] ?? 'users';

        if ($category === 'claims') {
            $activities = ClaimRequest::with([
                'post:id,item_name,type',
                'claimant:id,first_name,last_name,email',
            ])
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
                ->latest()
                ->paginate(30)
                ->withQueryString();

            return view('admin.activity', compact('activities', 'category'));
        }

        if ($category === 'proofs') {
            $activities = LostSighting::with([
                'post:id,item_name,type',
                'user:id,first_name,last_name,email',
            ])
                ->where('answer', 'yes')
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
                ->latest()
                ->paginate(30)
                ->withQueryString();

            return view('admin.activity', compact('activities', 'category'));
        }

        if ($category === 'handovers') {
            $activities = Handover::with([
                'claimRequest.post:id,item_name,type,user_id',
                'claimRequest.post.author:id,first_name,last_name',
                'claimRequest.claimant:id,first_name,last_name,email',
                'sighting.post:id,item_name,type,user_id',
                'sighting.post.author:id,first_name,last_name',
                'sighting.user:id,first_name,last_name,email',
                'location:id,name',
            ])
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
                ->latest()
                ->paginate(30)
                ->withQueryString();

            return view('admin.activity', compact('activities', 'category'));
        }

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

        return view('admin.activity', [
            'activities' => $query->paginate(30)->withQueryString(),
            'category' => $category,
        ]);
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
            'category' => ['nullable', 'in:claims,proofs,handovers,users'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $category = $data['category'] ?? 'users';
        $query = match ($category) {
            'claims' => ClaimRequest::with([
                'post:id,item_name,type',
                'claimant:id,first_name,last_name,email',
            ]),
            'proofs' => LostSighting::query()
                ->select('lost_sightings.*')
                ->selectSub(
                    DB::table('lost_sightings as previous_sightings')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('previous_sightings.post_id', 'lost_sightings.post_id')
                        ->whereColumn('previous_sightings.user_id', 'lost_sightings.user_id')
                        ->whereColumn('previous_sightings.id', '<=', 'lost_sightings.id')
                        ->where('previous_sightings.answer', 'yes'),
                    'attempt_no',
                )
                ->with([
                    'post:id,item_name,type',
                    'user:id,first_name,last_name,email',
                ])
                ->where('answer', 'yes'),
            'handovers' => Handover::with([
                'claimRequest.post:id,item_name,type,user_id',
                'claimRequest.post.author:id,first_name,last_name,email',
                'claimRequest.claimant:id,first_name,last_name,email',
                'sighting.post:id,item_name,type,user_id',
                'sighting.post.author:id,first_name,last_name,email',
                'sighting.user:id,first_name,last_name,email',
                'location:id,name',
            ]),
            default => DB::table('activity_logs')
                ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
                ->select([
                    'activity_logs.event', 'activity_logs.ip_address', 'activity_logs.device',
                    'activity_logs.created_at', 'users.first_name', 'users.last_name', 'users.email',
                ]),
        };
        $dateColumn = $category === 'users' ? 'activity_logs.created_at' : 'created_at';
        $query
            ->when($data['from'] ?? null, fn ($builder, $date) => $builder->whereDate($dateColumn, '>=', $date))
            ->when($data['to'] ?? null, fn ($builder, $date) => $builder->whereDate($dateColumn, '<=', $date))
            ->orderBy($dateColumn);

        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => 'activity_exported',
            'target_type' => $category === 'users' ? 'activity_logs' : $category,
            'reason' => json_encode([
                'category' => $category,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headers = match ($category) {
            'claims' => ['item', 'post_type', 'claimant_name', 'claimant_email', 'attempt', 'status', 'owner_action', 'owner_reason', 'admin_action', 'admin_reason', 'submitted_at'],
            'proofs' => ['item', 'post_type', 'submitter_name', 'submitter_email', 'attempt', 'owner_action', 'owner_reason', 'admin_action', 'admin_reason', 'evidence', 'submitted_at'],
            'handovers' => ['item', 'post_type', 'status', 'owner_name', 'other_participant_name', 'owner_agreement_at', 'other_participant_agreement_at', 'scheduled_at', 'place', 'reschedule_reason', 'failure_reason', 'created_at', 'updated_at'],
            default => ['event', 'ip_address', 'device', 'created_at', 'first_name', 'last_name', 'email'],
        };
        $filename = "tmc-campuslf-{$category}.csv";

        return response()->streamDownload(function () use ($query, $category, $headers): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $headers);
            $query->chunk(500, function ($rows) use ($output, $category): void {
                foreach ($rows as $row) {
                    $values = match ($category) {
                        'claims' => [
                            $row->post?->item_name,
                            $row->post?->type,
                            trim(($row->claimant?->first_name ?? '').' '.($row->claimant?->last_name ?? '')),
                            $row->claimant?->email,
                            $row->attempt_no,
                            $row->status,
                            $row->author_decision,
                            $row->author_decision_reason,
                            $row->reviewed_at ? $row->status : 'Awaiting admin',
                            $row->review_reason,
                            $row->created_at,
                        ],
                        'proofs' => [
                            $row->post?->item_name,
                            $row->post?->type,
                            trim(($row->user?->first_name ?? '').' '.($row->user?->last_name ?? '')),
                            $row->user?->email,
                            $row->attempt_no,
                            $row->author_decision,
                            $row->author_decision_reason,
                            $row->admin_decision,
                            $row->admin_reason,
                            $row->evidence_text,
                            $row->created_at,
                        ],
                        'handovers' => $this->handoverExportRow($row),
                        default => [
                            $row->event,
                            $row->ip_address,
                            $row->device,
                            $row->created_at,
                            $row->first_name,
                            $row->last_name,
                            $row->email,
                        ],
                    };
                    fputcsv($output, array_map(
                        fn ($value) => $this->spreadsheetSafeValue($value),
                        $values,
                    ));
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, mixed>
     */
    private function handoverExportRow(Handover $handover): array
    {
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $post = $claim?->post ?? $sighting?->post;
        $owner = $claim?->claimant ?? $post?->author;
        $otherParticipant = $claim?->post?->author ?? $sighting?->user;

        return [
            $post?->item_name,
            $post?->type,
            $handover->status,
            trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')),
            trim(($otherParticipant?->first_name ?? '').' '.($otherParticipant?->last_name ?? '')),
            $handover->owner_confirmed_at,
            $handover->finder_confirmed_at,
            $handover->scheduled_at,
            $handover->location?->name,
            $handover->reschedule_reason,
            $handover->failure_reason,
            $handover->created_at,
            $handover->updated_at,
        ];
    }

    private function spreadsheetSafeValue(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)
            ? "'".$value
            : $value;
    }
}
