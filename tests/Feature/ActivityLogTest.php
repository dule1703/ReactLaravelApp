<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Concerns\LogsActivity;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function last(string $action): ?ActivityLog
    {
        return ActivityLog::where('action', $action)->latest('id')->first();
    }

    public function test_login_is_logged_with_a_snapshot_of_the_user(): void
    {
        $user = User::factory()->client()->create(['name' => 'Petar Petrovic']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $log = $this->last('auth.login');
        $this->assertSame('user', $log->actor_type);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Petar Petrovic', $log->user_name);
        $this->assertSame($user->email, $log->user_email);
        $this->assertSame('client', $log->user_role);
        $this->assertSame("Klijent #{$user->id} Petar Petrovic", $log->subject_label);
        $this->assertSame('127.0.0.1', $log->ip);
    }

    public function test_admin_actions_are_logged_too(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);

        $this->assertSame('admin', $this->last('auth.login')->user_role);
    }

    public function test_logout_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertSame($user->id, $this->last('auth.logout')->user_id);
    }

    public function test_failed_login_is_logged_as_guest_without_the_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'typed-secret-123']);

        $log = $this->last('auth.login_failed');
        $this->assertSame('guest', $log->actor_type);
        $this->assertNull($log->user_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertStringContainsString($user->email, $log->description);
        $this->assertStringNotContainsString('typed-secret-123', json_encode(ActivityLog::all()));
    }

    public function test_failed_login_for_an_unknown_valid_email_is_logged(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'x']);

        $log = $this->last('auth.login_failed');
        $this->assertNull($log->subject_id);
        $this->assertStringContainsString('nobody@example.com', $log->description);
    }

    public function test_failed_login_with_an_invalid_email_does_not_store_the_input(): void
    {
        event(new Failed('web', null, ['email' => 'not an email <script>', 'password' => 'typed-secret-123']));

        $log = $this->last('auth.login_failed');
        $this->assertSame('Neuspela prijava (nevažeći unos)', $log->description);
        $this->assertStringNotContainsString('<script>', json_encode(ActivityLog::all()));
        $this->assertStringNotContainsString('typed-secret-123', json_encode(ActivityLog::all()));
    }

    public function test_password_reset_is_logged(): void
    {
        $user = User::factory()->create();

        event(new PasswordReset($user));

        $this->assertSame($user->id, $this->last('auth.password_reset')->subject_id);
    }

    public function test_password_change_is_logged_without_any_password_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'brand-new-secret-1',
            'password_confirmation' => 'brand-new-secret-1',
        ]);

        $log = $this->last('auth.password_changed');
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['password' => ['redacted' => true]], $log->changes);
        $this->assertStringNotContainsString('brand-new-secret-1', json_encode(ActivityLog::all()));
    }

    public function test_profile_update_logs_old_and_new_values(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($user)->patch('/profile', ['name' => 'New Name', 'email' => 'old@example.com']);

        $log = $this->last('user.updated');
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['name' => ['old' => 'Old Name', 'new' => 'New Name']], $log->changes);
    }

    public function test_an_update_touching_only_remember_token_is_not_logged(): void
    {
        $user = User::factory()->create();
        $before = ActivityLog::count();

        $user->forceFill(['remember_token' => 'abc'])->save();
        $user->touch();

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_registration_is_logged_as_guest(): void
    {
        $this->post('/register', [
            'name' => 'New Client',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $log = $this->last('user.created');
        $this->assertSame('guest', $log->actor_type);
        $this->assertNull($log->user_id);
        $this->assertSame(['redacted' => true], $log->changes['password']);
        $this->assertSame(['new' => 'new@example.com'], $log->changes['email']);
    }

    public function test_actions_outside_a_request_are_logged_as_system(): void
    {
        User::factory()->create();

        $log = $this->last('user.created');
        $this->assertSame('system', $log->actor_type);
        $this->assertNull($log->user_id);
        $this->assertNull($log->ip);
    }

    public function test_the_trait_logs_prices_but_never_sensitive_values(): void
    {
        Schema::create('log_test_items', function ($table) {
            $table->id();
            $table->string('title');
            $table->integer('price');
            $table->string('jmbg')->nullable();
            $table->string('pib')->nullable();
            $table->timestamps();
        });

        $item = new class extends Model
        {
            use LogsActivity;

            protected $table = 'log_test_items';

            protected $guarded = [];
        };

        $item->fill(['title' => 'Octavia', 'price' => 2000000, 'jmbg' => '0101990710006', 'pib' => '123456789'])->save();
        $item->update(['price' => 2100000, 'jmbg' => '0202990710007']);

        $created = ActivityLog::where('description', null)->where('subject_label', 'like', '% #1')->orderBy('id')->get();
        $this->assertCount(2, $created);

        $this->assertSame(['redacted' => true], $created[0]->changes['jmbg']);
        $this->assertSame(['redacted' => true], $created[0]->changes['pib']);
        $this->assertSame(['new' => 2000000], $created[0]->changes['price']);
        $this->assertSame(['old' => 2000000, 'new' => 2100000], $created[1]->changes['price']);
        $this->assertSame(['redacted' => true], $created[1]->changes['jmbg']);

        $dump = json_encode(ActivityLog::all());
        foreach (['0101990710006', '0202990710007', '123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_entries_cannot_be_updated_or_deleted(): void
    {
        User::factory()->create();
        $log = ActivityLog::first();

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Update should be blocked.');
        } catch (LogicException) {
            $this->assertNotSame('tampered', $log->fresh()->action);
        }

        try {
            $log->delete();
            $this->fail('Delete should be blocked.');
        } catch (LogicException) {
            $this->assertNotNull($log->fresh());
        }
    }

    public function test_the_label_survives_deleting_the_subject(): void
    {
        $user = User::factory()->client()->create(['name' => 'Gone Soon']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $label = $this->last('auth.login')->subject_label;

        $user->delete();

        $this->assertSame($label, $this->last('auth.login')->subject_label);
        $this->assertNotNull($this->last('user.deleted'));
    }

    public function test_prune_removes_only_old_entries_and_is_itself_logged(): void
    {
        ActivityLog::create(['created_at' => now()->subDays(400), 'action' => 'test.old']);
        ActivityLog::create(['created_at' => now()->subDays(10), 'action' => 'test.recent']);

        $this->artisan('activitylog:prune', ['--days' => 365])->assertSuccessful();

        $this->assertNull($this->last('test.old'));
        $this->assertNotNull($this->last('test.recent'));
        $this->assertSame('system', $this->last('activitylog.pruned')->actor_type);
    }

    public function test_prune_uses_the_configured_retention_by_default(): void
    {
        config(['activity-log.retention_days' => 30]);
        ActivityLog::create(['created_at' => now()->subDays(40), 'action' => 'test.old']);

        $this->artisan('activitylog:prune')->assertSuccessful();

        $this->assertNull($this->last('test.old'));
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('activitylog:prune')->assertSuccessful();
    }
}
