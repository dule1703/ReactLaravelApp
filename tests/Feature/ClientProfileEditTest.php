<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\User;
use App\Support\Jmbg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ClientProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private const JMBG = '0101990710008';

    private const OTHER_JMBG = '0202990710009';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'individual',
            'full_name' => 'Petar Petrović',
            'jmbg' => self::JMBG,
            'pib' => '',
            'address' => 'Nemanjina 10',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'country' => 'RS',
        ], $overrides);
    }

    private function client(): User
    {
        return User::factory()->client()->create();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/client-profile')->assertRedirect('/login');
        $this->patch('/client-profile', $this->payload())->assertRedirect('/login');
    }

    public function test_admin_gets_403(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/client-profile')->assertForbidden();
        $this->actingAs($admin)->patch('/client-profile', $this->payload())->assertForbidden();
        $this->assertSame(0, ClientProfile::count());
    }

    public function test_client_sees_own_profile_with_masked_jmbg_only(): void
    {
        $user = $this->client();
        $user->clientProfile->update(['jmbg' => self::JMBG, 'city' => 'Niš', 'pib' => '123456789']);
        $this->client()->clientProfile->update(['city' => 'Tuđi grad', 'jmbg' => self::OTHER_JMBG]);

        $response = $this->actingAs($user)->get('/client-profile');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ClientProfile/Edit')
            ->where('profile.city', 'Niš')
            ->where('profile.pib', '123456789')
            ->where('profile.jmbg_masked', '0101******008')
            ->missing('profile.jmbg')
            ->missing('profile.jmbg_hash')
            ->has('countries', count(config('countries.codes')))
            ->where('countries.0', ['code' => 'RS', 'name' => 'Srbija']));

        $content = $response->getContent();
        $this->assertStringNotContainsString(self::JMBG, $content);
        $this->assertStringNotContainsString(self::OTHER_JMBG, $content);
        $this->assertStringNotContainsString($user->clientProfile->fresh()->jmbg_hash, $content);
        $this->assertStringNotContainsString('Tuđi grad', $content);
    }

    public function test_profile_without_jmbg_has_no_mask(): void
    {
        $this->actingAs($this->client())->get('/client-profile')
            ->assertInertia(fn (Assert $page) => $page->where('profile.jmbg_masked', null));
    }

    public function test_page_creates_a_missing_profile(): void
    {
        $user = $this->client();
        $user->clientProfile()->delete();

        $this->actingAs($user)->get('/client-profile')->assertOk();

        $this->assertSame(1, ClientProfile::where('user_id', $user->id)->count());
    }

    public function test_client_updates_own_profile_and_gets_a_flash_message(): void
    {
        $user = $this->client();

        $this->actingAs($user)->patch('/client-profile', $this->payload())
            ->assertRedirect('/client-profile')
            ->assertSessionHas('success', 'Profil je sačuvan.');

        $profile = $user->clientProfile->fresh();
        $this->assertSame('Beograd', $profile->city);
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertNotNull($profile->jmbg_hash);
    }

    public function test_a_client_cannot_touch_another_profile(): void
    {
        $user = $this->client();
        $other = $this->client();

        // There is no id in the route; foreign ids in the body are ignored.
        $this->actingAs($user)->patch('/client-profile', $this->payload([
            'id' => $other->clientProfile->id,
            'user_id' => $other->id,
            'jmbg_hash' => 'x',
        ]))->assertRedirect('/client-profile');

        $this->assertNull($other->clientProfile->fresh()->city);
        $this->assertSame($user->id, $user->clientProfile->fresh()->user_id);
        $this->assertNotSame('x', $user->clientProfile->fresh()->jmbg_hash);
        $this->get('/client-profile/'.$other->clientProfile->id)->assertNotFound();
    }

    public function test_validation_errors_are_reported_per_field(): void
    {
        $this->actingAs($this->client())->patch('/client-profile', $this->payload([
            'full_name' => '',
            'postal_code' => '1100',
            'city' => '',
            'country' => 'XX',
        ]))->assertSessionHasErrors(['full_name', 'postal_code', 'city', 'country']);
    }

    public function test_input_that_looks_like_the_mask_is_rejected(): void
    {
        $user = $this->client();
        $user->clientProfile->update(['jmbg' => self::JMBG]);

        $this->actingAs($user)->patch('/client-profile', $this->payload(['jmbg' => '0101******008']))
            ->assertSessionHasErrors(['jmbg' => 'JMBG nije ispravan.']);

        $this->assertSame(self::JMBG, $user->clientProfile->fresh()->jmbg);
    }

    public function test_blank_jmbg_keeps_the_stored_one(): void
    {
        $user = $this->client();
        $user->clientProfile->update(['jmbg' => self::JMBG]);
        $hash = $user->clientProfile->fresh()->jmbg_hash;

        foreach ([null, '', '   '] as $blank) {
            $this->actingAs($user)->patch('/client-profile', $this->payload(['jmbg' => $blank, 'city' => 'Niš']))
                ->assertSessionHasNoErrors();
        }

        $profile = $user->clientProfile->fresh();
        $this->assertSame('Niš', $profile->city);
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertSame($hash, $profile->jmbg_hash);
    }

    public function test_individual_without_a_stored_jmbg_must_provide_one(): void
    {
        $this->actingAs($this->client())->patch('/client-profile', $this->payload(['jmbg' => '']))
            ->assertSessionHasErrors('jmbg');
    }

    public function test_jmbg_can_be_replaced(): void
    {
        $user = $this->client();
        $user->clientProfile->update(['jmbg' => self::JMBG]);

        $this->actingAs($user)->patch('/client-profile', $this->payload(['jmbg' => '02 02-990 710 009']));

        $profile = $user->clientProfile->fresh();
        $this->assertSame(self::OTHER_JMBG, $profile->jmbg);
        $this->assertSame(Jmbg::hash(self::OTHER_JMBG), $profile->jmbg_hash);
    }

    public function test_type_can_be_switched_both_ways(): void
    {
        $user = $this->client();
        $this->actingAs($user)->patch('/client-profile', $this->payload());

        $company = $this->payload(['type' => 'company', 'full_name' => 'Auto d.o.o.', 'jmbg' => '', 'pib' => '123456789']);
        $this->actingAs($user)->patch('/client-profile', $company)->assertSessionHasNoErrors();
        $profile = $user->clientProfile->fresh();
        $this->assertSame(ClientType::Company, $profile->type);
        $this->assertSame('123456789', $profile->pib);
        $this->assertSame(self::JMBG, $profile->jmbg);

        $this->actingAs($user)->patch('/client-profile', [...$company, 'pib' => ''])
            ->assertSessionHasErrors('pib');

        $this->actingAs($user)->patch('/client-profile', $this->payload(['jmbg' => '', 'pib' => '123456789']))
            ->assertSessionHasNoErrors();
        $this->assertSame(ClientType::Individual, $user->clientProfile->fresh()->type);
    }

    public function test_duplicate_jmbg_gets_the_generic_message(): void
    {
        $this->client()->clientProfile->update(['jmbg' => self::JMBG]);
        $user = $this->client();

        $this->actingAs($user)->patch('/client-profile', $this->payload())
            ->assertSessionHasErrors(['jmbg' => 'JMBG nije ispravan.']);

        $this->assertNull($user->clientProfile->fresh()->jmbg);
    }

    public function test_a_twelve_digit_jmbg_reports_a_single_message(): void
    {
        $response = $this->actingAs($this->client())
            ->patch('/client-profile', $this->payload(['jmbg' => '010199071000']));

        $this->assertCount(1, session('errors')->get('jmbg'));
        $response->assertSessionHasErrors('jmbg');
    }

    public function test_a_unique_constraint_race_gets_the_same_message(): void
    {
        $user = $this->client();

        // Validation passes, then the database reports a duplicate hash on save.
        ClientProfile::updating(function () {
            throw new UniqueConstraintViolationException('sqlite', 'update client_profiles', [], new RuntimeException('UNIQUE'));
        });

        $this->actingAs($user)->patch('/client-profile', $this->payload())
            ->assertSessionHasErrors(['jmbg' => 'JMBG nije ispravan.']);
    }

    public function test_activity_log_has_no_jmbg_or_pib_values(): void
    {
        $user = $this->client();

        $this->actingAs($user)->patch('/client-profile', $this->payload(['type' => 'company', 'pib' => '123456789']));
        $this->actingAs($user)->patch('/client-profile', $this->payload(['jmbg' => '02 02 990 710 009', 'pib' => '987654321']));

        $logs = ActivityLog::where('subject_type', (new ClientProfile)->getMorphClass())->get();
        $this->assertNotEmpty($logs);

        $dump = ActivityLog::all()->toJson();
        foreach ([self::JMBG, self::OTHER_JMBG, '123456789', '987654321', $user->clientProfile->fresh()->jmbg_hash] as $value) {
            $this->assertStringNotContainsString($value, $dump);
        }
        $this->assertSame(['redacted' => true], $logs->last()->changes['jmbg']);
    }

    public function test_navigation_shows_my_profile_for_clients_only(): void
    {
        $nav = fn (User $user) => $this->actingAs($user)->get('/client-profile')->viewData('page')['props']['nav'] ?? [];

        $clientNav = $nav($this->client());
        $this->assertSame('/client-profile', collect($clientNav)->firstWhere('key', 'profile')['href']);
        $this->assertFalse(collect($clientNav)->firstWhere('key', 'profile')['soon']);

        $adminResponse = $this->actingAs(User::factory()->admin()->create())->get('/admin');
        $adminKeys = array_column($adminResponse->viewData('page')['props']['nav'], 'key');
        $this->assertNotContains('profile', $adminKeys);
        $this->assertNotContains('client-profile', $adminKeys);
    }
}
