<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\User;
use App\Support\Jmbg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminClientsTest extends TestCase
{
    use RefreshDatabase;

    private const JMBG = '0101990710008';

    private const OTHER_JMBG = '0202990710009';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Admin', 'email' => 'admin@example.com']);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $user
     */
    private function client(array $profile = [], array $user = []): ClientProfile
    {
        $profileModel = User::factory()->client()->create($user)->clientProfile;
        $profileModel->update($profile);

        return $profileModel->fresh();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string> client names on the page, in order
     */
    private function names(array $query = []): array
    {
        $names = [];

        $this->actingAs($this->admin)->get('/admin/clients?'.http_build_query($query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $names = array_column($page->toArray()['props']['clients']['data'], 'name');
            });

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'individual',
            'full_name' => 'Izmenjeno Ime',
            'jmbg' => '',
            'pib' => '',
            'address' => 'Nova 1',
            'postal_code' => '21000',
            'city' => 'Novi Sad',
            'country' => 'RS',
        ], $overrides);
    }

    // --- access ---

    public function test_guest_and_client_cannot_use_the_admin_client_routes(): void
    {
        $profile = $this->client();
        $client = $profile->user;

        $requests = [
            ['get', '/admin/clients'],
            ['get', "/admin/clients/{$profile->id}"],
            ['patch', "/admin/clients/{$profile->id}", $this->payload()],
            ['delete', "/admin/clients/{$profile->id}"],
            ['delete', "/admin/clients/{$profile->id}/jmbg"],
            ['postJson', "/admin/clients/{$profile->id}/reveal", ['field' => 'pib']],
        ];

        // Guests first: actingAs() would stay authenticated for the following requests.
        foreach ($requests as $request) {
            [$method, $url, $data] = $request + [2 => []];
            $guest = $this->{$method}($url, $data);
            $this->assertTrue(in_array($guest->getStatusCode(), [302, 401], true), "guest $method $url");
        }

        foreach ($requests as $request) {
            [$method, $url, $data] = $request + [2 => []];
            $this->actingAs($client)->{$method}($url, $data)->assertForbidden();
        }

        $this->assertNotNull($profile->fresh());
    }

    // --- list ---

    public function test_admin_sees_the_list_without_full_jmbg_or_hash(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG, 'pib' => '123456789', 'full_name' => 'Petar Petrović', 'city' => 'Niš']);

        $response = $this->actingAs($this->admin)->get('/admin/clients');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Index')
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'Petar Petrović')
            ->where('clients.data.0.jmbg_masked', '0101******008')
            ->where('clients.data.0.pib_masked', '12*****89')
            ->where('clients.data.0.country', 'Srbija')
            ->missing('clients.data.0.jmbg')
            ->missing('clients.data.0.jmbg_hash')
            ->missing('clients.data.0.pib'));

        $content = $response->getContent();
        $this->assertStringNotContainsString(self::JMBG, $content);
        $this->assertStringNotContainsString('123456789', $content);
        $this->assertStringNotContainsString($profile->jmbg_hash, $content);
        $this->assertSame(0, ActivityLog::where('action', 'client_profile.sensitive_viewed')->count());
    }

    public function test_list_is_sorted_by_registration_date_descending_with_id_as_tiebreaker(): void
    {
        $old = $this->client(['full_name' => 'Stari'], ['created_at' => now()->subDays(5)]);
        $firstOfDay = $this->client(['full_name' => 'Prvi danas'], ['created_at' => now()->startOfDay()]);
        $secondOfDay = $this->client(['full_name' => 'Drugi danas'], ['created_at' => now()->startOfDay()]);

        $this->assertSame(['Drugi danas', 'Prvi danas', 'Stari'], $this->names());
        $this->assertTrue($secondOfDay->id > $firstOfDay->id && $firstOfDay->id > $old->id);
    }

    public function test_search_matches_every_listed_field(): void
    {
        $this->client([
            'full_name' => 'Zoran Zoranović', 'address' => 'Kralja Petra 5', 'city' => 'Kragujevac',
            'postal_code' => '34000', 'pib' => '987654321',
        ], ['email' => 'zoran@primer.rs']);
        $this->client(['full_name' => 'Drugi Klijent', 'city' => 'Subotica', 'postal_code' => '24000'], ['email' => 'drugi@primer.rs']);

        foreach (['Zoranović', 'zoran@primer', 'Kragujevac', 'Kralja Petra', '34000', '987654321'] as $term) {
            $this->assertSame(['Zoran Zoranović'], $this->names(['q' => $term]), $term);
        }
        $this->assertSame(['Drugi Klijent'], $this->names(['q' => 'subotica']));
        $this->assertSame([], $this->names(['q' => 'nepostojeće']));
    }

    public function test_search_finds_an_exact_jmbg_through_the_hash_only(): void
    {
        $this->client(['full_name' => 'Ima JMBG', 'jmbg' => self::JMBG]);
        $this->client(['full_name' => 'Drugi', 'jmbg' => self::OTHER_JMBG]);

        $this->assertSame(['Ima JMBG'], $this->names(['q' => self::JMBG]));
        $this->assertSame(['Ima JMBG'], $this->names(['q' => '01 01-990 710 008']));
        // A partial JMBG is not searchable (the column is encrypted).
        $this->assertSame([], $this->names(['q' => '0101990']));
        $this->assertSame([], $this->names(['q' => '0101990710007']));
    }

    public function test_like_wildcards_in_the_search_are_escaped(): void
    {
        $this->client(['full_name' => 'Sa procentom', 'city' => 'Grad 50%']);
        $this->client(['full_name' => 'Sa donjom crtom', 'city' => 'Novi_Sad']);
        $this->client(['full_name' => 'Obican', 'city' => 'Beograd']);
        $this->client(['full_name' => 'Sa uzvikom', 'city' => 'Hej! Grad']);

        $this->assertSame(['Sa procentom'], $this->names(['q' => '%']));
        $this->assertSame(['Sa donjom crtom'], $this->names(['q' => '_']));
        $this->assertSame([], $this->names(['q' => '\\']));
        $this->assertSame(['Sa uzvikom'], $this->names(['q' => '!']));
        $this->assertSame([], $this->names(['q' => '%%']));
    }

    public function test_pagination_and_rows_per_page_keep_the_query_string(): void
    {
        foreach (range(1, 12) as $i) {
            $this->client(['full_name' => "Klijent $i", 'city' => 'Grad']);
        }
        $this->client(['full_name' => 'Drugi', 'city' => 'Niš']);

        $this->assertCount(10, $this->names());
        $this->assertCount(3, $this->names(['page' => 2]));
        $this->assertCount(13, $this->names(['per_page' => 25]));

        $this->actingAs($this->admin)->get('/admin/clients?q=Grad&per_page=10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients.total', 12)
                ->where('clients.per_page', 10)
                ->where('filters', ['q' => 'Grad', 'per_page' => 10])
                ->where('clients.next_page_url', fn ($url) => str_contains($url, 'q=Grad') && str_contains($url, 'per_page=10')));

        $this->actingAs($this->admin)->get('/admin/clients?per_page=7')->assertSessionHasErrors('per_page');
    }

    public function test_admins_are_not_listed(): void
    {
        $this->client(['full_name' => 'Samo klijent']);

        $this->assertSame(['Samo klijent'], $this->names());
    }

    // --- edit ---

    public function test_edit_page_shows_full_pib_masked_jmbg_and_logs_the_pib_view(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG, 'pib' => '123456789']);

        $response = $this->actingAs($this->admin)->get("/admin/clients/{$profile->id}");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Clients/Edit')
            ->where('profile.pib', '123456789')
            ->where('profile.jmbg_masked', '0101******008')
            ->missing('profile.jmbg')
            ->missing('profile.jmbg_hash'));
        $this->assertStringNotContainsString(self::JMBG, $response->getContent());

        $log = ActivityLog::where('action', 'client_profile.sensitive_viewed')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($profile->getKey(), $log->subject_id);
        $this->assertSame('pib', $log->description);
        $this->assertStringNotContainsString('123456789', ActivityLog::all()->toJson());
    }

    public function test_edit_page_without_a_pib_logs_nothing(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);

        $this->actingAs($this->admin)->get("/admin/clients/{$profile->id}")->assertOk();

        $this->assertSame(0, ActivityLog::where('action', 'client_profile.sensitive_viewed')->count());
    }

    public function test_admin_updates_a_client_profile_through_the_shared_request(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);
        $hash = $profile->jmbg_hash;

        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload())
            ->assertRedirect('/admin/clients')
            ->assertSessionHas('success');

        $profile = $profile->fresh();
        $this->assertSame('Izmenjeno Ime', $profile->full_name);
        $this->assertSame('Novi Sad', $profile->city);
        // Blank JMBG keeps the stored one and its hash.
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertSame($hash, $profile->jmbg_hash);

        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload(['jmbg' => self::OTHER_JMBG]));
        $this->assertSame(Jmbg::hash(self::OTHER_JMBG), $profile->fresh()->jmbg_hash);
    }

    public function test_admin_update_validates_and_hides_duplicates(): void
    {
        $this->client(['jmbg' => self::JMBG]);
        $profile = $this->client();

        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload(['jmbg' => self::JMBG]))
            ->assertSessionHasErrors(['jmbg' => 'JMBG nije ispravan.']);
        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload(['postal_code' => '1', 'jmbg' => self::OTHER_JMBG]))
            ->assertSessionHasErrors('postal_code');
    }

    // --- reveal ---

    public function test_reveal_returns_the_full_value_to_an_admin_and_logs_without_it(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG, 'pib' => '123456789']);

        $response = $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'jmbg']);
        $response->assertOk()->assertExactJson(['value' => self::JMBG]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'pib'])
            ->assertOk()->assertExactJson(['value' => '123456789']);

        $logs = ActivityLog::where('action', 'client_profile.sensitive_viewed')->orderBy('id')->get();
        $this->assertSame(['jmbg', 'pib'], $logs->pluck('description')->all());
        $this->assertSame($this->admin->id, $logs[0]->user_id);
        $this->assertSame($profile->getKey(), $logs[0]->subject_id);

        $dump = ActivityLog::all()->toJson();
        foreach ([self::JMBG, '123456789', $profile->jmbg_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_reveal_of_an_empty_value_logs_nothing(): void
    {
        $profile = $this->client();

        $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'jmbg'])
            ->assertOk()->assertExactJson(['value' => null]);

        $this->assertSame(0, ActivityLog::where('action', 'client_profile.sensitive_viewed')->count());
    }

    public function test_reveal_rejects_unknown_fields(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);

        foreach (['password', 'jmbg_hash', 'city', '', 'JMBG'] as $field) {
            $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => $field])
                ->assertStatus(422)->assertJsonValidationErrors('field');
        }
        $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal")->assertStatus(422);
        $this->assertSame(0, ActivityLog::where('action', 'client_profile.sensitive_viewed')->count());
    }

    public function test_reveal_is_denied_to_clients_including_the_owner(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);

        $this->actingAs($profile->user)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'jmbg'])
            ->assertForbidden();
        $this->assertFalse(Gate::forUser($profile->user)->allows('viewSensitive', $profile));
        $this->assertSame(0, ActivityLog::where('action', 'client_profile.sensitive_viewed')->count());
    }

    public function test_reveal_is_throttled(): void
    {
        $profile = $this->client(['pib' => '123456789']);

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'pib'])->assertOk();
        }

        $this->actingAs($this->admin)->postJson("/admin/clients/{$profile->id}/reveal", ['field' => 'pib'])
            ->assertStatus(429);
    }

    // --- deleting ---

    public function test_admin_deletes_a_jmbg_and_it_is_logged(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG, 'city' => 'Niš']);

        $this->actingAs($this->admin)->from("/admin/clients/{$profile->id}")
            ->delete("/admin/clients/{$profile->id}/jmbg")
            ->assertRedirect("/admin/clients/{$profile->id}")
            ->assertSessionHas('success', 'JMBG je obrisan.');

        $profile = $profile->fresh();
        $this->assertNull($profile->jmbg);
        $this->assertNull($profile->jmbg_hash);
        $this->assertSame('Niš', $profile->city);

        $log = ActivityLog::where('action', 'client_profile.jmbg_deleted')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['redacted' => true], $log->changes['jmbg']);
        $this->assertStringNotContainsString(self::JMBG, ActivityLog::all()->toJson());

        // The freed JMBG can be used by another client.
        $other = $this->client();
        $this->actingAs($this->admin)->patch("/admin/clients/{$other->id}", $this->payload(['jmbg' => self::JMBG]))
            ->assertSessionHasNoErrors();
    }

    public function test_blank_jmbg_in_the_admin_form_never_deletes_it(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);

        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload(['jmbg' => null]));

        $this->assertSame(self::JMBG, $profile->fresh()->jmbg);
        $this->assertSame(0, ActivityLog::where('action', 'client_profile.jmbg_deleted')->count());
    }

    public function test_admin_deletes_a_client_and_the_log_survives(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG], ['name' => 'Za Brisanje']);
        $userId = $profile->user_id;
        $logsBefore = ActivityLog::count();

        $this->actingAs($this->admin)->delete("/admin/clients/{$profile->id}")
            ->assertRedirect('/admin/clients')
            ->assertSessionHas('success', 'Klijent je obrisan.');

        $this->assertNull(User::find($userId));
        $this->assertNull(ClientProfile::find($profile->id));
        $this->assertGreaterThan($logsBefore, ActivityLog::count());
        $this->assertTrue(ActivityLog::where('action', 'user.deleted')->where('subject_id', $userId)->exists());
        $this->assertTrue(ActivityLog::where('action', 'client_profile.deleted')->where('subject_id', $profile->id)->exists());
        $this->assertStringNotContainsString(self::JMBG, ActivityLog::all()->toJson());
    }

    public function test_an_admin_cannot_delete_themselves_or_another_admin(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        // Admins have no profile by design; one is forced here to prove the policy still refuses.
        $adminProfile = ClientProfile::factory()->for($otherAdmin)->create(['jmbg' => null]);
        $ownProfile = ClientProfile::factory()->for($this->admin)->create(['jmbg' => null]);

        $this->actingAs($this->admin)->delete("/admin/clients/{$adminProfile->id}")->assertForbidden();
        $this->actingAs($this->admin)->delete("/admin/clients/{$ownProfile->id}")->assertForbidden();

        $this->assertNotNull($otherAdmin->fresh());
        $this->assertNotNull($this->admin->fresh());
        $this->assertFalse(Gate::forUser($this->admin)->allows('delete', $otherAdmin));
        $this->assertFalse(Gate::forUser($this->admin)->allows('delete', $this->admin));
        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $this->client()->user));
    }

    public function test_a_client_cannot_delete_a_jmbg_or_a_client(): void
    {
        $profile = $this->client(['jmbg' => self::JMBG]);

        $this->actingAs($profile->user)->delete("/admin/clients/{$profile->id}/jmbg")->assertForbidden();
        $this->actingAs($profile->user)->delete("/admin/clients/{$profile->id}")->assertForbidden();

        $this->assertSame(self::JMBG, $profile->fresh()->jmbg);
    }

    // --- session / navigation ---

    public function test_a_rejected_jmbg_is_not_flashed_to_the_session(): void
    {
        $profile = $this->client();

        $this->actingAs($this->admin)->patch("/admin/clients/{$profile->id}", $this->payload([
            'jmbg' => '0101990710007',
            'postal_code' => '1',
        ]))->assertSessionHasErrors(['jmbg', 'postal_code']);

        $this->assertNull(session()->getOldInput('jmbg'));
        $this->assertSame('Novi Sad', session()->getOldInput('city'));
    }

    public function test_a_rejected_jmbg_from_the_client_form_is_not_flashed_either(): void
    {
        $client = $this->client()->user;

        $this->actingAs($client)->patch('/client-profile', $this->payload(['jmbg' => '1234567890123']))
            ->assertSessionHasErrors('jmbg');

        $this->assertNull(session()->getOldInput('jmbg'));
    }

    public function test_admin_navigation_has_an_active_clients_item_and_no_my_profile(): void
    {
        $nav = collect($this->actingAs($this->admin)->get('/admin/clients')->viewData('page')['props']['nav']);

        $clients = $nav->firstWhere('key', 'clients');
        $this->assertSame('/admin/clients', $clients['href']);
        $this->assertFalse($clients['soon']);
        $this->assertNull($nav->firstWhere('key', 'profile'));
    }

    public function test_activity_log_page_labels_the_new_actions(): void
    {
        foreach (['sensitive_viewed', 'jmbg_deleted', 'updated'] as $action) {
            $this->assertNotSame("activity.action.client_profile.$action", __("activity.action.client_profile.$action"));
        }
    }
}
