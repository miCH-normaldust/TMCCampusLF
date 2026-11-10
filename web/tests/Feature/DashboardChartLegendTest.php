<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardChartLegendTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_chart_series_and_legend_markers_share_the_same_colors(): void
    {
        $this->app->make(Vite::class)->useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
        $admin = User::create([
            'first_name' => 'Chart',
            'last_name' => 'Admin',
            'tmc_id_no' => 'CHART-ADMIN',
            'email' => 'chart-admin@tmc.test',
            'password' => 'LongTestPassword!123',
            'role' => 'admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        foreach ([
            'lost_sighting_submitted',
            'claim_submitted',
            'handover_reschedule_requested',
            'system_notice',
        ] as $type) {
            $admin->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'data' => ['title' => 'Dashboard chart test'],
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<i style="background:#9AC9E6"></i>Lost posts', false)
            ->assertSee('style="stroke:#9AC9E6"', false)
            ->assertSee('<i style="background:#3E5663"></i>Found posts', false)
            ->assertSee('style="stroke:#3E5663"', false)
            ->assertSee('<i style="background:#D2A84F"></i>Reports', false)
            ->assertSee('style="height:', false)
            ->assertSee('background:#D2A84F', false)
            ->assertSee('<i style="background:#C9E9FF"></i>Proofs', false)
            ->assertSee('background:#C9E9FF', false)
            ->assertSee('<i style="background:#3E5663"></i>Claims', false)
            ->assertSee('conic-gradient(#9AC9E6 0% 25%, #3E5663 25% 50%, #D2A84F 50% 75%, #C9E9FF 75% 100%)', false);
    }
}
