<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\Offer;
use App\Models\User;
use App\Support\AdminDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function offer(array $attributes = []): Offer
    {
        return Offer::factory()->create($attributes);
    }

    private function log(array $attributes = []): ActivityLog
    {
        return ActivityLog::create($attributes + [
            'created_at' => now(),
            'action' => 'offer.created',
            'actor_type' => 'user',
            'user_name' => 'Admin Adminovic',
            'user_email' => 'admin@example.test',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
            'description' => 'secret description',
            'changes' => ['x' => ['new' => 'secret value']],
        ]);
    }

    private function clearLog(): void
    {
        ActivityLog::query()->toBase()->delete();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    public function test_counts_cover_normal_withdrawn_and_deleted_offers_and_clients_without_offers(): void
    {
        $admin = $this->admin();
        User::factory()->client()->count(3)->create(); // three clients, none with an offer

        $this->offer();
        $this->offer();
        $this->offer(['offer_date' => now()->subDays(100)->toDateString()]);
        $this->offer()->forceFill(['withdrawn_at' => now()])->save();
        $this->offer()->delete(); // deleted: counted nowhere

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')
            ->where('counts', [
                'clients' => ClientProfile::count(),
                'active_offers' => 3,
                'withdrawn_offers' => 1,
                'offers_last_30_days' => 3, // two today + the withdrawn one; the 100-day-old and the deleted are out
            ]));
    }

    public function test_a_withdrawn_offer_counts_in_the_last_30_days_but_not_as_active(): void
    {
        $this->offer()->forceFill(['withdrawn_at' => now()])->save();

        $counts = AdminDashboard::data()['counts'];

        $this->assertSame(0, $counts['active_offers']);
        $this->assertSame(1, $counts['withdrawn_offers']);
        $this->assertSame(1, $counts['offers_last_30_days']);
    }

    public function test_the_30_day_border_is_inclusive(): void
    {
        $today = now(config('app.timezone'));
        $this->offer(['offer_date' => $today->copy()->subDays(30)->format('Y-m-d')]);
        $this->offer(['offer_date' => $today->copy()->subDays(31)->format('Y-m-d')]);

        $this->assertSame(1, AdminDashboard::data()['counts']['offers_last_30_days']);
    }

    public function test_recent_offers_are_the_newest_five_with_a_fixed_key_list(): void
    {
        $admin = $this->admin();
        foreach (range(1, 7) as $i) {
            $this->offer(['offer_date' => now()->subDays(10 - $i)->toDateString(), 'client_name' => "Klijent $i", 'total_gross_cents' => $i * 100]);
        }
        $this->offer(['offer_date' => now()->toDateString(), 'client_name' => 'Obrisana'])->delete();

        $offers = $this->props($this->actingAs($admin)->get('/admin')->assertOk())['recent_offers'];

        $this->assertCount(5, $offers);
        $this->assertSame(['Klijent 7', 'Klijent 6', 'Klijent 5', 'Klijent 4', 'Klijent 3'], array_column($offers, 'client_name'));
        $this->assertSame(['id', 'number', 'client_name', 'offer_date', 'total_gross_cents', 'withdrawn_at'], array_keys($offers[0]));
        $this->assertNull($offers[0]['withdrawn_at']);
    }

    public function test_a_withdrawn_offer_in_the_list_carries_its_date(): void
    {
        $this->offer()->forceFill(['withdrawn_at' => now()])->save();

        $this->assertSame(now()->format('d.m.Y'), AdminDashboard::data()['recent_offers'][0]['withdrawn_at']);
    }

    public function test_recent_activities_are_the_newest_eight_without_noise_and_without_ip_or_device(): void
    {
        $admin = $this->admin();
        $this->clearLog();

        foreach (range(1, 10) as $i) {
            $this->log(['created_at' => now()->subMinutes(20 - $i), 'subject_label' => "Ponuda $i"]);
        }
        $this->log(['action' => 'auth.login']);
        $this->log(['action' => 'auth.login_failed', 'actor_type' => 'guest', 'user_name' => null]);
        $this->log(['action' => 'offer.pdf_opened']);
        $this->log(['action' => 'offer.pdf_downloaded']);

        $response = $this->actingAs($admin)->get('/admin')->assertOk();
        $props = $this->props($response);
        $activities = $props['recent_activities'];

        $this->assertCount(8, $activities);
        $this->assertSame(['id', 'time', 'actor_type', 'user_name', 'action', 'subject_label'], array_keys($activities[0]));
        $this->assertSame('Ponuda 10', $activities[0]['subject_label']);
        $this->assertSame('Ponuda 3', $activities[7]['subject_label']);

        $json = json_encode($props);
        foreach (['203.0.113.9', 'Chrome', 'secret', 'admin@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_system_and_guest_activities_come_without_a_name(): void
    {
        $this->clearLog();
        $this->log(['action' => 'activitylog.pruned', 'actor_type' => 'system', 'user_name' => null]);

        $activity = AdminDashboard::data()['recent_activities'][0];

        $this->assertSame('system', $activity['actor_type']);
        $this->assertNull($activity['user_name']);
    }

    public function test_no_client_data_beyond_the_snapshot_name_reaches_the_page(): void
    {
        $admin = $this->admin();
        $client = User::factory()->client()->create();
        $client->profile()->update(['jmbg' => '0101990710006', 'pib' => '123456789']);
        $this->offer(['user_id' => $client->id, 'client_pib' => '123456789']);

        $json = json_encode($this->props($this->actingAs($admin)->get('/admin')->assertOk()));

        $this->assertStringNotContainsString('0101990710006', $json);
        $this->assertStringNotContainsString('123456789', $json);
    }

    public function test_empty_states_on_a_fresh_database(): void
    {
        $admin = $this->admin();
        $this->clearLog();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['clients' => 0, 'active_offers' => 0, 'withdrawn_offers' => 0, 'offers_last_30_days' => 0])
            ->where('recent_offers', [])
            ->where('recent_activities', []));
    }

    public function test_the_query_count_does_not_grow_with_the_data(): void
    {
        $this->offer();
        $this->log();

        DB::enableQueryLog();
        AdminDashboard::data();
        $few = count(DB::getQueryLog());

        foreach (range(1, 10) as $i) {
            $this->offer();
        }
        foreach (range(1, 20) as $i) {
            $this->log();
        }

        DB::flushQueryLog();
        AdminDashboard::data();
        $many = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(4, $many);
        $this->assertSame($few, $many);
    }

    public function test_only_the_admin_sees_the_page(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->client()->create())->get('/admin')->assertForbidden();
    }

    public function test_every_action_that_can_reach_the_list_has_a_translation(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true);
        $actions = [];

        // Explicit actions: the first argument of ->log(...), also behind a "cond ? 'a' : 'b'".
        foreach ($this->phpFiles(app_path()) as $file) {
            if (preg_match_all("/->log\(\s*(?:[^'\"\n]*\?\s*'[\w.]+'\s*:\s*)?'([a-z_]+\.[a-z_]+)'/", file_get_contents($file), $m)) {
                array_push($actions, ...$m[1]);
            }
        }

        // Model events: <snake class>.<created|updated|deleted> plus the literals of activityAction().
        foreach ($this->phpFiles(app_path('Models')) as $file) {
            $source = file_get_contents($file);
            if (! preg_match('/use [^;]*\bLogsActivity\b[^;]*;/', $source)) {
                continue;
            }
            $entity = Str::snake(basename($file, '.php'));
            foreach (['created', 'updated', 'deleted'] as $event) {
                $actions[] = "$entity.$event";
            }
            if (str_contains($source, 'function activityAction')
                && preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $source, $m)) {
                array_push($actions, ...array_filter($m[1], fn ($a) => preg_match('/^(offer|client_profile|auth)\./', $a)));
            }
        }

        $hidden = fn (string $a) => in_array($a, AdminDashboard::HIDDEN_ACTIONS, true)
            || collect(AdminDashboard::HIDDEN_ACTION_PREFIXES)->contains(fn ($p) => str_starts_with($a, $p));

        $this->assertGreaterThan(30, count(array_unique($actions)), 'The scan must find the actions');

        $missing = collect($actions)->unique()->reject($hidden)
            ->reject(fn ($a) => isset($translations["activity.action.$a"]))->values()->all();

        $this->assertSame([], $missing, 'Actions without activity.action.* in lang/sr_Latn.json');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
