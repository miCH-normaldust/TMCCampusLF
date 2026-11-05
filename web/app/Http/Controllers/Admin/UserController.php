<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->where('role', 'user')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(fn ($builder) => $builder
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('tmc_id_no', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.users', ['users' => $users]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'tmc_id_no' => ['required', 'string', 'max:50', 'unique:users,tmc_id_no'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'user_type' => ['required', 'in:student,staff,instructor,other'],
            'user_type_other' => ['required_if:user_type,other', 'nullable', 'string', 'max:100'],
            'department' => ['required', 'in:CCS,CRIM,POLSCI,CAS,BSOA,BSED'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $emailDomain = strtolower(substr(strrchr($data['email'], '@') ?: '', 1));
        if (! hash_equals('gmail.com', $emailDomain)) {
            throw ValidationException::withMessages(['email' => 'Use a Gmail address ending in @gmail.com.']);
        }

        $masterlist = DB::table('tmc_masterlist')
            ->where('tmc_id_no', $data['tmc_id_no'])
            ->first();

        if (! $masterlist) {
            throw ValidationException::withMessages([
                'tmc_id_no' => 'This TMC ID is not in the official masterlist.',
            ]);
        }

        $masterlistName = $this->normalizeMasterlistValue($masterlist->full_name);
        $submittedName = $this->normalizeMasterlistValue($data['first_name'].' '.$data['last_name']);
        if ($masterlistName !== $submittedName) {
            throw ValidationException::withMessages([
                'first_name' => 'The name does not match the official masterlist.',
            ]);
        }
        if (strtolower($masterlist->email) !== strtolower($data['email'])) {
            throw ValidationException::withMessages([
                'email' => 'The email does not match the official masterlist.',
            ]);
        }
        if (strtolower($masterlist->user_type) !== $data['user_type']) {
            throw ValidationException::withMessages([
                'user_type' => 'The user type does not match the official masterlist.',
            ]);
        }
        if ($data['user_type'] === 'other'
            && $this->normalizeMasterlistValue($masterlist->user_type_other ?? '')
                !== $this->normalizeMasterlistValue($data['user_type_other'] ?? '')) {
            throw ValidationException::withMessages([
                'user_type_other' => 'The specified user type does not match the official masterlist.',
            ]);
        }
        if (strtoupper($masterlist->department) !== $data['department']) {
            throw ValidationException::withMessages([
                'department' => 'The department does not match the official masterlist.',
            ]);
        }

        $user = User::create([
            ...$data,
            'email' => strtolower($data['email']),
            'role' => 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->audit($request, $user, 'user_created', 'Created by administrator.');

        return redirect()->route('admin.users.index')->with('status', 'User account created.');
    }

    private function normalizeMasterlistValue(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)) ?? '');
    }

    public function setStatus(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'user', 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'pending', 'suspended', 'deactivated'])],
            'reason' => ['required_if:status,suspended,deactivated', 'nullable', 'string', 'max:1000'],
        ]);
        $user->update(['status' => $data['status']]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'account_status',
            'data' => [
                'title' => 'Account status updated',
                'body' => $data['reason'] ?? 'Your TMC CampusLF account is now '.$data['status'].'.',
            ],
        ]);
        $this->audit($request, $user, 'user_'.$data['status'], $data['reason'] ?? null);

        return back()->with('status', 'User account status updated.');
    }

    public function import(Request $request): View|RedirectResponse
    {
        $request->validate(['masterlist' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $handle = fopen($request->file('masterlist')->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['masterlist' => 'The CSV file could not be read.']);
        }

        $headers = fgetcsv($handle);
        $legacyHeaders = ['tmc_id_no', 'full_name', 'email', 'user_type', 'department'];
        $expected = ['tmc_id_no', 'full_name', 'email', 'user_type', 'user_type_other', 'department'];
        if (is_array($headers) && isset($headers[0])) {
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        }
        $headers = is_array($headers)
            ? array_map(static function (string $header): string {
                $header = strtolower(trim($header));

                return match ($header) {
                    'other', 'user type other' => 'user_type_other',
                    default => $header,
                };
            }, $headers)
            : [];
        $hasOtherTypeColumn = $headers === $expected;
        if (! $hasOtherTypeColumn && $headers !== $legacyHeaders) {
            fclose($handle);
            throw ValidationException::withMessages([
                'masterlist' => 'Use the header order: '.implode(',', $expected)
                    .'. The user_type_other column may also be named other.',
            ]);
        }
        $activeHeaders = $hasOtherTypeColumn ? $expected : $legacyHeaders;

        $rows = [];
        $seenIds = [];
        $seenEmails = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (count($values) !== count($activeHeaders)) {
                fclose($handle);
                throw ValidationException::withMessages(['masterlist' => "CSV row {$line} must contain ".count($activeHeaders).' columns.']);
            }
            if ($hasOtherTypeColumn) {
                [$id, $name, $email, $type, $typeOther, $department] = array_map('trim', $values);
            } else {
                [$id, $name, $email, $type, $department] = array_map('trim', $values);
                $typeOther = '';
            }
            if ($id === '' || $name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! in_array(strtolower($type), ['student', 'staff', 'instructor', 'other'], true)
                || (strtolower($type) === 'other' && $typeOther === '')
                || ! in_array(strtoupper($department), ['CCS', 'CRIM', 'POLSCI', 'CAS', 'BSOA', 'BSED'], true)) {
                fclose($handle);
                throw ValidationException::withMessages(['masterlist' => "CSV row {$line} contains invalid data."]);
            }
            $email = strtolower($email);
            $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
            if (! hash_equals('gmail.com', $emailDomain)) {
                fclose($handle);
                throw ValidationException::withMessages(['masterlist' => "CSV row {$line} must use a Gmail address ending in @gmail.com."]);
            }
            if (isset($seenIds[$id]) || isset($seenEmails[$email])) {
                fclose($handle);
                throw ValidationException::withMessages(['masterlist' => "CSV row {$line} duplicates a TMC ID or email address."]);
            }
            $seenIds[$id] = true;
            $seenEmails[$email] = true;
            $rows[] = [
                'tmc_id_no' => $id,
                'full_name' => $name,
                'email' => $email,
                'user_type' => strtolower($type),
                'user_type_other' => strtolower($type) === 'other' ? $typeOther : null,
                'department' => strtoupper($department),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (count($rows) > 10000) {
                fclose($handle);
                throw ValidationException::withMessages(['masterlist' => 'A masterlist import cannot exceed 10,000 rows.']);
            }
        }
        fclose($handle);
        if ($rows === []) {
            throw ValidationException::withMessages(['masterlist' => 'The CSV contains no records.']);
        }
        if ($this->hasMasterlistEmailConflict($rows)) {
            throw ValidationException::withMessages([
                'masterlist' => 'An email in this CSV is already assigned to a different TMC ID in the current masterlist. Correct the conflicting email or TMC ID, then upload the CSV again.',
            ]);
        }

        $request->session()->put('masterlist_import_preview', $rows);

        return view('admin.masterlist-preview', ['rows' => array_slice($rows, 0, 20), 'count' => count($rows)]);
    }

    public function confirmImport(Request $request): RedirectResponse
    {
        $rows = $request->session()->get('masterlist_import_preview');
        abort_unless(is_array($rows) && $rows !== [], 409, 'Upload and preview a masterlist before confirming.');

        $imported = DB::transaction(function () use ($rows, $request): bool {
            if ($this->hasMasterlistEmailConflict($rows)) {
                return false;
            }

            foreach (array_chunk($rows, 500) as $batch) {
                DB::table('tmc_masterlist')->upsert(
                    $batch,
                    ['tmc_id_no'],
                    ['full_name', 'email', 'user_type', 'user_type_other', 'department', 'updated_at'],
                );
            }
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'masterlist_imported',
                'target_type' => 'tmc_masterlist',
                'target_id' => null,
                'reason' => count($rows).' records imported or updated.',
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });

        if (! $imported) {
            return redirect()->route('admin.users.index')->withErrors([
                'masterlist' => 'An email in this CSV is now assigned to a different TMC ID in the current masterlist. Re-upload after correcting the conflicting record.',
            ]);
        }

        $request->session()->forget('masterlist_import_preview');

        return redirect()->route('admin.users.index')->with('status', count($rows).' masterlist records imported.');
    }

    private function hasMasterlistEmailConflict(array $rows): bool
    {
        $idByEmail = [];
        foreach ($rows as $row) {
            $idByEmail[strtolower($row['email'])] = $row['tmc_id_no'];
        }

        foreach (array_chunk(array_keys($idByEmail), 500) as $emails) {
            $existingRows = DB::table('tmc_masterlist')
                ->whereIn(DB::raw('LOWER(email)'), $emails)
                ->get(['email', 'tmc_id_no']);

            foreach ($existingRows as $existingRow) {
                if (($idByEmail[strtolower($existingRow->email)] ?? null) !== $existingRow->tmc_id_no) {
                    return true;
                }
            }
        }

        return false;
    }

    private function audit(Request $request, User $target, string $action, ?string $reason): void
    {
        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'target_type' => User::class,
            'target_id' => $target->id,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
