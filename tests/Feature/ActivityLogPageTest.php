<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityLogPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        // Start from a clean log so the factory's own "user.created" rows do not interfere.
        ActivityLog::query()->toBase()->delete();
    }

    private function entry(array $attributes = []): ActivityLog
    {
        return ActivityLog::create($attributes + ['created_at' => now(), 'action' => 'test.event']);
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin)->get('/admin/activity-log?'.http_build_query($query));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/activity-log')->assertRedirect(route('login'));
    }

    public function test_client_gets_403(): void
    {
        $this->actingAs(User::factory()->client()->create())
            ->get('/admin/activity-log')
            ->assertForbidden();
    }

    public function test_admin_sees_entries_of_all_users_including_other_admins(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();
        ActivityLog::query()->toBase()->delete(); // drop the "user.created" rows of the factories
        $this->entry(['user_id' => $otherAdmin->id, 'user_name' => $otherAdmin->name, 'user_role' => 'admin', 'action' => 'auth.login']);
        $this->entry(['user_id' => $client->id, 'user_name' => $client->name, 'user_role' => 'client', 'action' => 'auth.login']);
        $this->entry(['user_id' => $this->admin->id, 'user_name' => $this->admin->name, 'user_role' => 'admin', 'action' => 'auth.logout']);

        $this->page()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ActivityLog')
            ->has('logs.data', 3)
            ->has('users', 3));
    }

    public function test_rows_carry_the_displayed_fields(): void
    {
        $this->entry([
            'created_at' => '2026-10-02 14:03:05',
            'user_id' => $this->admin->id,
            'user_name' => 'Ana Admin',
            'user_email' => 'ana@example.com',
            'user_role' => 'admin',
            'action' => 'user.updated',
            'subject_label' => 'Klijent #5 Petar',
            'ip' => '10.0.0.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'changes' => ['price' => ['old' => 1, 'new' => 2], 'password' => ['redacted' => true]],
        ]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.time', '02.10.2026 14:03:05')
            ->where('logs.data.0.user_name', 'Ana Admin')
            ->where('logs.data.0.user_email', 'ana@example.com')
            ->where('logs.data.0.user_role', 'admin')
            ->where('logs.data.0.subject_label', 'Klijent #5 Petar')
            ->where('logs.data.0.ip', '10.0.0.7')
            ->where('logs.data.0.device', 'Chrome · Windows')
            ->where('logs.data.0.changes.price', ['old' => 1, 'new' => 2])
            ->where('logs.data.0.changes.password', ['redacted' => true]));
    }

    public function test_newest_entries_come_first(): void
    {
        $this->entry(['created_at' => now()->subHour(), 'description' => 'older']);
        $this->entry(['created_at' => now(), 'description' => 'newer']);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.description', 'newer')
            ->where('logs.data.1.description', 'older'));
    }

    public function test_pagination_is_25_per_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->entry();
        }

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 25)
            ->where('logs.total', 30));

        $this->page(['page' => 2])->assertInertia(fn (Assert $page) => $page->has('logs.data', 5));
    }

    public function test_filter_by_user(): void
    {
        $this->entry(['user_id' => 7]);
        $this->entry(['user_id' => 8]);

        $this->page(['user_id' => 7])->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)->where('logs.data.0.id', ActivityLog::where('user_id', 7)->value('id')));
    }

    public function test_filter_by_role(): void
    {
        $this->entry(['user_role' => 'admin']);
        $this->entry(['user_role' => 'client']);
        $this->entry(['user_role' => 'client']);

        $this->page(['role' => 'client'])->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
    }

    public function test_filter_by_action_and_actions_list(): void
    {
        $this->entry(['action' => 'auth.login']);
        $this->entry(['action' => 'auth.logout']);

        $this->page(['action' => 'auth.login'])->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('actions', ['auth.login', 'auth.logout']));
    }

    public function test_filter_by_period(): void
    {
        $this->entry(['created_at' => '2026-09-01 10:00:00', 'description' => 'sep']);
        $this->entry(['created_at' => '2026-10-02 23:59:59', 'description' => 'oct-end']);
        $this->entry(['created_at' => '2026-10-03 00:00:01', 'description' => 'next']);

        $this->page(['from' => '2026-10-01', 'to' => '2026-10-02'])->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)->where('logs.data.0.description', 'oct-end'));
    }

    public function test_filter_by_ip_prefix(): void
    {
        $this->entry(['ip' => '192.168.1.10']);
        $this->entry(['ip' => '10.0.0.1']);

        $this->page(['ip' => '192.168'])->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)->where('logs.data.0.ip', '192.168.1.10'));
    }

    public function test_search_matches_name_email_subject_and_description(): void
    {
        $this->entry(['user_name' => 'Marko Markovic']);
        $this->entry(['user_email' => 'ana@example.com']);
        $this->entry(['subject_label' => 'Ponuda 001/2026']);
        $this->entry(['description' => 'Neuspela prijava za zoran@example.com']);
        $this->entry(['description' => 'unrelated']);

        foreach (['Markovic', 'ana@', '001/2026', 'zoran@'] as $term) {
            $this->page(['q' => $term])->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        }
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->entry(['description' => 'abc']);

        $this->page(['q' => '%'])->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->page(['role' => 'superuser'])->assertSessionHasErrors('role');
        $this->page(['from' => '2026-10-05', 'to' => '2026-10-01'])->assertSessionHasErrors('to');
    }

    public function test_nobody_can_modify_the_log_through_the_policy(): void
    {
        $log = $this->entry();

        foreach ([$this->admin, User::factory()->client()->create()] as $user) {
            $this->assertTrue(Gate::forUser($user)->denies('update', $log));
            $this->assertTrue(Gate::forUser($user)->denies('delete', $log));
        }

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', ActivityLog::class));
        $this->assertTrue(Gate::forUser(User::factory()->client()->create())->denies('viewAny', ActivityLog::class));
    }

    public function test_there_are_no_write_routes_for_the_log(): void
    {
        $log = $this->entry();

        $this->actingAs($this->admin)->delete("/admin/activity-log/{$log->id}")->assertStatus(404);
        $this->actingAs($this->admin)->patch("/admin/activity-log/{$log->id}")->assertStatus(404);
        $this->assertNotNull($log->fresh());
    }
}
