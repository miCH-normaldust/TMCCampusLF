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
        $headers = is_array($headers) ? array_map('trim', $headers) : [];
        $hasOtherTypeColumn = $headers === $expected;
        if (! $hasOtherTypeColumn && $headers !== $legacyHeaders) {
            fclose($handle);
            throw ValidationException::withMessages([
                'masterlist' => 'Use the header order: '.implode(',', $expected).'.',
            ]);
        }
        $activeHeaders = $hasOtherTypeColumn ? $expected : $legacyHeaders;

        $rows = [];
        $seenIds = [];
        $seenEmails = [];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
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

        $request->session()->put('masterlist_import_preview', $rows);

        return view('admin.masterlist-preview', ['rows' => array_slice($rows, 0, 20), 'count' => count($rows)]);
    }

    public function confirmImport(Request $request): RedirectResponse
    {
        $rows = $request->session()->pull('masterlist_import_preview');
        abort_unless(is_array($rows) && $rows !== [], 409, 'Upload and preview a masterlist before confirming.');

        DB::transaction(function () use ($rows, $request): void {
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
        });

        return redirect()->route('admin.users.index')->with('status', count($rows).' masterlist records imported.');
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
