<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\ClientAccountCreated;
use App\Services\ClientCreator;
use App\Services\ClientInvitation;
use App\Services\OfferCreator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/**
 * The salon flow (4.5c): an admin makes a client; the client sets their own password through the
 * emailed link.
 */
class AdminCreateClientTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private const JMBG = '0101990710008';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'email' => 'novi.klijent@example.com',
            'type' => 'individual',
            'full_name' => 'Đorđe Žarković',
            'address' => 'Knez Mihailova 1',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'country' => 'RS',
        ], $override);
    }

    private function store(array $override = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('clients.store'), $this->payload($override));
    }

    private function counts(): array
    {
        return [User::count(), ClientProfile::count()];
    }

    private function existingClient(array $user = [], array $profile = []): ClientProfile
    {
        $client = User::factory()->client()->create($user);
        $client->profile()->update($profile);

        return $client->profile()->fresh();
    }

    public function test_an_admin_creates_an_individual_without_a_jmbg_and_the_client_is_notified(): void
    {
        Notification::fake();

        $this->store()->assertRedirect(route('clients.index'))->assertSessionHas('success');

        $user = User::where('email', 'novi.klijent@example.com')->sole();
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('Đorđe Žarković', $user->name);
        $this->assertNull($user->email_verified_at);

        $profile = $user->profile();
        $this->assertSame('individual', $profile->type->value);
        $this->assertSame('Đorđe Žarković', $profile->full_name);
        $this->assertSame('Knez Mihailova 1', $profile->address);
        $this->assertSame('11000', $profile->postal_code);
        $this->assertSame('Beograd', $profile->city);
        $this->assertSame('RS', $profile->country);
        $this->assertNull($profile->jmbg);
        $this->assertNull($profile->jmbg_hash);

        Notification::assertSentTo($user, ClientAccountCreated::class);
        Notification::assertCount(1);
    }

    public function test_the_password_is_random_and_nothing_that_was_sent(): void
    {
        Notification::fake();

        $this->store(['password' => 'Lozinka-123!', 'password_confirmation' => 'Lozinka-123!'])->assertRedirect();

        $user = User::where('email', 'novi.klijent@example.com')->sole();
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::check('Lozinka-123!', $user->password));
        $this->assertFalse(Hash::check('', $user->password));

        // Two clients never share a password.
        $this->store(['email' => 'drugi@example.com'])->assertRedirect();
        $this->assertNotSame($user->password, User::where('email', 'drugi@example.com')->sole()->password);
    }

    public function test_the_whole_flow_the_token_from_the_email_sets_the_password_and_the_client_signs_in(): void
    {
        Notification::fake();
        $this->store()->assertRedirect();

        $user = User::where('email', 'novi.klijent@example.com')->sole();
        $token = Notification::sent($user, ClientAccountCreated::class)->first()->token;

        auth()->logout();
        $this->flushSession();

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'novi.klijent@example.com',
            'password' => 'Nova-Lozinka-123!',
            'password_confirmation' => 'Nova-Lozinka-123!',
        ])->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => 'novi.klijent@example.com', 'password' => 'Nova-Lozinka-123!'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_email_has_the_reset_route_the_expiry_and_no_password(): void
    {
        $user = User::factory()->client()->create(['name' => 'Petar']);
        $mail = (new ClientAccountCreated('abc123token'))->toMail($user);

        $this->assertSame(
            url(route('password.reset', ['token' => 'abc123token', 'email' => $user->email], false)),
            $mail->actionUrl,
        );
        $this->assertStringContainsString('/reset-password/abc123token', $mail->actionUrl);

        $text = implode("\n", array_merge([$mail->subject, $mail->greeting], $mail->introLines, $mail->outroLines));
        $this->assertStringContainsString('Petar', $text);
        $this->assertStringContainsString('60 minuta', $text);
        $this->assertStringContainsString('Zaboravili ste lozinku?', $text);
        $this->assertStringContainsString(url(route('password.request', absolute: false)), $text);
        $this->assertStringNotContainsStringIgnoringCase('lozinka:', $text);
    }

    public function test_the_mail_is_not_queued(): void
    {
        $this->assertNotInstanceOf(ShouldQueue::class, new ClientAccountCreated('x'));
    }

    public function test_the_role_from_the_request_is_ignored(): void
    {
        Notification::fake();

        $this->store(['role' => 'admin', 'is_admin' => true, 'user_id' => $this->admin->id])->assertRedirect();

        $this->assertSame(UserRole::Client, User::where('email', 'novi.klijent@example.com')->sole()->role);
    }

    public function test_the_email_is_trimmed_and_lowercased(): void
    {
        Notification::fake();

        $this->store(['email' => '  Novi.Klijent@Example.COM '])->assertRedirect();

        $this->assertSame(1, User::where('email', 'novi.klijent@example.com')->count());
    }

    public function test_a_duplicate_email_in_another_letter_case_is_refused_with_a_link_and_nothing_is_written(): void
    {
        Notification::fake();
        $existing = $this->existingClient(['email' => 'marko@example.com']);
        $before = $this->counts();

        $response = $this->store(['email' => 'MARKO@Example.com']);

        $response->assertSessionHasErrors(['email', 'existing_client.email']);
        $this->assertSame((string) $existing->id, session('errors')->get('existing_client.email')[0]);
        $this->assertSame($before, $this->counts());
        Notification::assertNothingSent();
    }

    public function test_an_email_of_an_admin_is_refused_without_a_link(): void
    {
        $before = $this->counts();

        $this->store(['email' => 'ADMIN@example.com'])->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('existing_client.email');

        $this->assertSame($before, $this->counts());
    }

    public function test_a_duplicate_jmbg_is_refused_with_a_link_and_nothing_is_written(): void
    {
        Notification::fake();
        $existing = $this->existingClient([], ['jmbg' => self::JMBG]);
        $before = $this->counts();

        $response = $this->store(['jmbg' => '0101990-710008']);

        $response->assertSessionHasErrors(['jmbg', 'existing_client.jmbg']);
        $this->assertSame((string) $existing->id, session('errors')->get('existing_client.jmbg')[0]);
        $this->assertSame($before, $this->counts());
        Notification::assertNothingSent();
    }

    public function test_a_jmbg_is_saved_when_given_and_the_hash_follows_it(): void
    {
        Notification::fake();

        $this->store(['jmbg' => self::JMBG])->assertRedirect();

        $profile = User::where('email', 'novi.klijent@example.com')->sole()->profile();
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertNotNull($profile->jmbg_hash);
    }

    public function test_a_wrong_jmbg_is_a_validation_error(): void
    {
        $before = $this->counts();

        $this->store(['jmbg' => '1234567890123'])->assertSessionHasErrors('jmbg');
        $this->store(['jmbg' => '0101990710008x'])->assertSessionHasErrors('jmbg');

        $this->assertSame($before, $this->counts());
    }

    public function test_a_duplicate_pib_is_a_warning_the_admin_confirms(): void
    {
        Notification::fake();
        $existing = $this->existingClient([], ['type' => 'company', 'pib' => '123456789']);
        $before = $this->counts();
        $company = ['type' => 'company', 'pib' => '123456789', 'full_name' => 'Firma d.o.o. - filijala'];

        $this->store($company)->assertSessionHasErrors(['pib', 'existing_client.pib']);
        $this->assertSame((string) $existing->id, session('errors')->get('existing_client.pib')[0]);
        $this->assertSame($before, $this->counts());

        $this->store($company + ['confirm_duplicate_pib' => true])->assertRedirect(route('clients.index'));

        $this->assertSame(2, ClientProfile::where('pib', '123456789')->count());
    }

    public function test_a_company_needs_a_pib_and_an_individual_may_have_one(): void
    {
        Notification::fake();

        $this->store(['type' => 'company'])->assertSessionHasErrors('pib');
        $this->store(['pib' => '111222333'])->assertRedirect();

        $this->assertSame('111222333', User::where('email', 'novi.klijent@example.com')->sole()->profile()->pib);
    }

    public function test_an_invalid_profile_creates_no_user(): void
    {
        $before = $this->counts();

        foreach ([['postal_code' => 'abc'], ['address' => ''], ['city' => ''], ['country' => 'XX'], ['full_name' => ''], ['type' => 'bogus'], ['email' => ''], ['email' => 'not-an-email']] as $bad) {
            $this->store($bad)->assertSessionHasErrors();
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_a_failure_while_writing_leaves_no_user_without_a_profile(): void
    {
        try {
            // Passes no validation here on purpose: the profile cannot be written (an unknown type), after the user was.
            app(ClientCreator::class)->create($this->payload(['type' => 'bogus']));
            $this->fail('The write should have failed.');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(0, User::where('email', 'novi.klijent@example.com')->count());
        $this->assertSame(0, ClientProfile::count());
    }

    public function test_a_client_and_a_guest_cannot_create(): void
    {
        $client = User::factory()->client()->create();
        $before = $this->counts();

        $this->store([], $client)->assertForbidden();
        $this->actingAs($client)->get(route('clients.create'))->assertForbidden();

        auth()->logout();
        $this->post(route('clients.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('clients.create'))->assertRedirect(route('login'));

        $this->assertSame($before, $this->counts());
    }

    public function test_the_page_is_the_create_form_and_not_the_edit_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/clients/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Create')
            ->has('countries')
            ->where('linkMinutes', 60));
    }

    public function test_the_policy_allows_only_an_admin_to_create(): void
    {
        $this->assertTrue($this->admin->can('create', ClientProfile::class));
        $this->assertFalse(User::factory()->client()->create()->can('create', ClientProfile::class));
    }

    public function test_one_summary_entry_without_jmbg_pib_password_or_token(): void
    {
        Notification::fake();

        $this->store(['jmbg' => self::JMBG, 'type' => 'company', 'pib' => '123456789', 'full_name' => 'Firma d.o.o.'])->assertRedirect();

        $user = User::where('email', 'novi.klijent@example.com')->sole();
        $entry = ActivityLog::where('action', 'client.created_by_admin')->sole();

        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertSame('admin', $entry->user_role);
        $this->assertSame($user->profile()->getMorphClass(), $entry->subject_type);
        $this->assertSame($user->profile()->id, $entry->subject_id);
        $this->assertSame('Admin', $entry->user_name);
        $this->assertStringContainsString('poslat', $entry->description);
        $this->assertSame(['redacted' => true], $entry->changes['jmbg']);
        $this->assertSame(['redacted' => true], $entry->changes['pib']);
        $this->assertSame(['redacted' => true], $entry->changes['password']);

        // The automatic entries of the user and the profile are muted for this flow only.
        $this->assertSame(0, ActivityLog::where('action', 'user.created')->where('subject_id', $user->id)->count());
        $this->assertSame(0, ActivityLog::where('action', 'client_profile.created')->where('subject_id', $user->profile()->id)->count());

        $token = Notification::sent($user, ClientAccountCreated::class)->first()->token;
        $json = json_encode(ActivityLog::all()->toArray());

        foreach ([self::JMBG, '123456789', $token, $user->password, $user->profile()->jmbg_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_a_failed_email_keeps_the_account_warns_the_admin_and_says_so_in_the_entry(): void
    {
        $this->mock(ClientInvitation::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new \RuntimeException('smtp down')));

        $this->store()->assertRedirect(route('clients.index'))->assertSessionHas('error')->assertSessionMissing('success');

        $user = User::where('email', 'novi.klijent@example.com')->sole();
        $this->assertNotNull($user->profile());
        $this->assertStringContainsString('NIJE', ActivityLog::where('action', 'client.created_by_admin')->sole()->description);
    }

    public function test_the_ordinary_registration_still_writes_the_automatic_entries(): void
    {
        $this->post(route('register'), [
            'name' => 'Marko Marković', 'email' => 'marko.markovic@example.com',
            'password' => 'Lozinka-123!', 'password_confirmation' => 'Lozinka-123!',
        ])->assertRedirect();

        $user = User::where('email', 'marko.markovic@example.com')->sole();

        $this->assertSame(1, ActivityLog::where('action', 'user.created')->where('subject_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'client_profile.created')->where('subject_id', $user->profile()->id)->count());
    }

    public function test_the_mute_does_not_outlive_the_flow(): void
    {
        Notification::fake();
        $this->store()->assertRedirect();

        $later = User::factory()->client()->create(['email' => 'later@example.com']);

        $this->assertSame(1, ActivityLog::where('action', 'user.created')->where('subject_id', $later->id)->count());
    }

    public function test_a_client_without_a_jmbg_can_get_an_offer(): void
    {
        Notification::fake();
        $this->buildCatalog();

        $this->store()->assertRedirect();
        $client = User::where('email', 'novi.klijent@example.com')->sole();

        $offer = app(OfferCreator::class)->create($client, null, [[
            'version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => [$this->climatronic->id],
        ]]);

        $this->assertInstanceOf(Offer::class, $offer);
        $this->assertSame('Đorđe Žarković', $offer->client_name);
        $this->assertNull($client->profile()->jmbg);
    }

    public function test_an_admin_can_edit_a_profile_without_a_jmbg_but_the_client_still_must_give_one(): void
    {
        Notification::fake();
        $this->store()->assertRedirect();
        $client = User::where('email', 'novi.klijent@example.com')->sole();
        $profile = $client->profile();
        $edit = ['type' => 'individual', 'full_name' => 'Đorđe Ž.', 'address' => 'Nova 5', 'postal_code' => '11000', 'city' => 'Beograd', 'country' => 'RS'];

        $this->actingAs($this->admin)->patch(route('clients.update', $profile), $edit)
            ->assertSessionHasNoErrors()->assertRedirect(route('clients.index'));
        $this->assertSame('Nova 5', $profile->fresh()->address);

        $this->actingAs($client)->patch(route('client-profile.update'), $edit)->assertSessionHasErrors('jmbg');
    }

    public function test_saving_is_throttled(): void
    {
        $this->assertContains('throttle:20,1', Route::getRoutes()->getByName('clients.store')->gatherMiddleware());
    }
}
