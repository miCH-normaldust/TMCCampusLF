<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Location;
use App\Models\MeetupLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings', [
            'settings' => DB::table('settings')->pluck('value', 'key'),
            'categories' => Category::orderBy('name')->get(),
            'locations' => Location::orderBy('name')->get(),
            'meetupLocations' => MeetupLocation::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:50'],
            'claims_per_day' => ['required', 'integer', 'min:1', 'max:50'],
            'claim_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'report_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'archive_days' => ['required', 'integer', 'min:1', 'max:60'],
            'handover_code_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'comments_per_day' => ['required', 'integer', 'min:1', 'max:200'],
            'reports_per_day' => ['required', 'integer', 'min:1', 'max:50'],
            'blocked_words' => ['nullable', 'string', 'max:5000'],
            'disclaimer' => ['required', 'string', 'max:4000'],
        ]);
        $this->saveSettings($request, $data);

        return back()->with('status', 'System settings saved.');
    }

    public function createCatalog(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['category', 'area'])],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $model = $data['kind'] === 'category' ? Category::class : Location::class;
        $table = $data['kind'] === 'category' ? 'categories' : 'locations';
        $request->validate(['name' => ['unique:'.$table.',name']]);
        $model::create(['name' => $data['name'], 'is_active' => true]);
        $this->audit($request, $data['kind'].'_created', $data['name']);

        return back()->with('status', 'Campus option added.');
    }

    public function setCatalogStatus(Request $request, string $kind, int $id): RedirectResponse
    {
        $model = match ($kind) {
            'categories' => Category::class,
            'locations' => Location::class,
            default => abort(404),
        };
        $record = $model::findOrFail($id);
        $record->update(['is_active' => ! $record->is_active]);
        $action = $kind === 'categories' ? 'category_status_updated' : 'campus_area_status_updated';
        $this->audit($request, $action, $record->name);

        return back()->with('status', 'Campus option status updated.');
    }

    public function createMeetup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:meetup_locations,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'operating_hours' => ['nullable', 'string', 'max:200'],
        ]);
        MeetupLocation::create($data + ['is_active' => true]);
        $this->audit($request, 'meetup_location_created', $data['name']);

        return back()->with('status', 'Meet-up location added.');
    }

    public function setMeetupStatus(Request $request, MeetupLocation $location): RedirectResponse
    {
        $location->update(['is_active' => ! $location->is_active]);
        $this->audit($request, 'meetup_location_status_updated', $location->name);

        return back()->with('status', 'Meet-up location status updated.');
    }

    private function saveSettings(Request $request, array $settings): void
    {
        DB::transaction(function () use ($request, $settings): void {
            foreach ($settings as $key => $value) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $value ?? '', 'updated_at' => now()],
                );
            }
            $this->audit($request, 'settings_updated', implode(', ', array_keys($settings)));
        });
    }

    private function audit(Request $request, string $action, string $details): void
    {
        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'target_type' => 'system_configuration',
            'target_id' => null,
            'reason' => $details,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
