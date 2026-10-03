<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Http\Requests\UpdateClientProfileRequest;
use App\Models\ClientProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientProfileValidationTest extends TestCase
{
    use RefreshDatabase;

    private const JMBG = '0101990710008';

    private const OTHER_JMBG = '0202990710009';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function individual(array $overrides = []): array
    {
        return array_merge([
            'type' => 'individual',
            'full_name' => 'Petar Petrović',
            'jmbg' => self::JMBG,
            'address' => 'Nemanjina 10',
            'postal_code' => '11000',
            'city' => 'Beograd',
            'country' => 'RS',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function company(array $overrides = []): array
    {
        return array_merge($this->individual(), [
            'type' => 'company',
            'full_name' => 'Auto-Servis 2000 d.o.o.',
            'jmbg' => null,
            'pib' => '123456789',
        ], $overrides);
    }

    /**
     * Runs the real request pipeline (prepareForValidation, authorize, rules) without a route.
     *
     * @param  array<string, mixed>  $data
     */
    private function request(array $data, ClientProfile $profile, ?User $actor = null): UpdateClientProfileRequest
    {
        $request = UpdateClientProfileRequest::create('/', 'PUT', $data)
            ->forProfile($profile)
            ->setContainer($this->app)
            ->setRedirector($this->app['redirect']);
        $actor ??= $profile->user;
        $request->setUserResolver(fn () => $actor);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> validated data
     */
    private function passes(array $data, ClientProfile $profile): array
    {
        $request = $this->request($data, $profile);
        $request->validateResolved();

        return $request->validated();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>> errors by field
     */
    private function errors(array $data, ClientProfile $profile): array
    {
        try {
            $this->request($data, $profile)->validateResolved();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Validation was expected to fail.');
    }

    private function profile(): ClientProfile
    {
        return User::factory()->client()->create()->clientProfile;
    }

    public function test_valid_individual_and_company_pass(): void
    {
        $this->assertSame(self::JMBG, $this->passes($this->individual(), $this->profile())['jmbg']);
        $this->assertSame('123456789', $this->passes($this->company(), $this->profile())['pib']);
    }

    public function test_individual_requires_jmbg_and_pib_is_optional(): void
    {
        $profile = $this->profile();

        $this->assertArrayHasKey('jmbg', $this->errors($this->individual(['jmbg' => null]), $profile));
        $this->assertArrayHasKey('jmbg', $this->errors($this->individual(['jmbg' => '  ']), $profile));
        $this->passes($this->individual(['pib' => null]), $profile);
        $this->passes($this->individual(['pib' => '123456789']), $profile);
    }

    public function test_company_requires_pib_and_jmbg_is_optional(): void
    {
        $profile = $this->profile();

        $this->assertArrayHasKey('pib', $this->errors($this->company(['pib' => null]), $profile));
        $this->passes($this->company(['jmbg' => null]), $profile);
        $this->passes($this->company(['jmbg' => self::JMBG]), $profile);
    }

    public function test_optional_jmbg_of_a_company_is_still_checked_when_given(): void
    {
        $this->assertArrayHasKey('jmbg', $this->errors($this->company(['jmbg' => '0101990710007']), $this->profile()));
    }

    public function test_jmbg_needs_13_digits_and_a_valid_check_digit(): void
    {
        $profile = $this->profile();

        foreach (['0101990710007', '010199071000', '01019907100088'] as $bad) {
            $this->assertArrayHasKey('jmbg', $this->errors($this->individual(['jmbg' => $bad]), $profile), $bad);
        }
    }

    public function test_jmbg_with_spaces_and_dashes_is_normalized(): void
    {
        $validated = $this->passes($this->individual(['jmbg' => ' 01 01-990 710 008 ']), $this->profile());

        $this->assertSame(self::JMBG, $validated['jmbg']);
    }

    public function test_jmbg_with_letters_or_other_symbols_fails(): void
    {
        $profile = $this->profile();

        foreach (['0101990710008x', 'x0101990710008', '0101990.710008', '0101990710008/'] as $bad) {
            $this->assertArrayHasKey('jmbg', $this->errors($this->individual(['jmbg' => $bad]), $profile), $bad);
        }
    }

    public function test_jmbg_of_another_profile_is_rejected_but_own_is_accepted(): void
    {
        $owner = $this->profile();
        $owner->update(['jmbg' => self::JMBG]);

        $this->passes($this->individual(), $owner->fresh());
        $this->assertArrayHasKey('jmbg', $this->errors($this->individual(), $this->profile()));
        // Formatting does not hide a duplicate.
        $this->assertArrayHasKey('jmbg', $this->errors($this->individual(['jmbg' => '01-01-990-710-008']), $this->profile()));
    }

    public function test_duplicate_message_does_not_reveal_that_the_jmbg_exists(): void
    {
        $this->profile()->update(['jmbg' => self::JMBG]);
        $profile = $this->profile();

        $duplicate = $this->errors($this->individual(), $profile)['jmbg'];
        $typo = $this->errors($this->individual(['jmbg' => '0101990710007']), $profile)['jmbg'];

        $this->assertSame($typo, $duplicate);
        $this->assertSame(['JMBG nije ispravan.'], $duplicate);
    }

    public function test_pib_and_postal_code_need_exact_lengths(): void
    {
        $profile = $this->profile();

        foreach (['12345678', '1234567890', '12345678a'] as $bad) {
            $this->assertArrayHasKey('pib', $this->errors($this->company(['pib' => $bad]), $profile), $bad);
        }
        foreach (['1100', '110000', '1100a'] as $bad) {
            $this->assertArrayHasKey('postal_code', $this->errors($this->individual(['postal_code' => $bad]), $profile), $bad);
        }
    }

    public function test_address_city_and_postal_code_are_required(): void
    {
        $errors = $this->errors($this->individual(['address' => null, 'city' => '', 'postal_code' => null]), $this->profile());

        $this->assertEqualsCanonicalizing(['address', 'city', 'postal_code'], array_keys($errors));
    }

    public function test_names_allow_digits_symbols_and_diacritics_but_not_control_characters(): void
    {
        $profile = $this->profile();

        $this->passes($this->company(['full_name' => 'Škoda & Sinovi 2000, d.o.o. (Čačak)']), $profile);
        $this->assertArrayHasKey('full_name', $this->errors($this->individual(['full_name' => "Pera\x00Peric"]), $profile));
        $this->assertArrayHasKey('full_name', $this->errors($this->individual(['full_name' => str_repeat('a', 256)]), $profile));
        $this->assertArrayHasKey('city', $this->errors($this->individual(['city' => str_repeat('a', 101)]), $profile));
    }

    public function test_country_must_be_in_the_list(): void
    {
        $profile = $this->profile();

        $this->passes($this->individual(['country' => 'DE']), $profile);
        foreach (['XX', 'SRB', 'R', null] as $bad) {
            $this->assertArrayHasKey('country', $this->errors($this->individual(['country' => $bad]), $profile));
        }
    }

    public function test_every_listed_country_has_a_serbian_name_and_serbia_is_first(): void
    {
        $codes = config('countries.codes');

        $this->assertSame('RS', $codes[0]);
        $this->assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $code) {
            $this->assertNotSame("country.$code", __("country.$code"));
        }
    }

    public function test_type_is_required_and_must_be_known(): void
    {
        $profile = $this->profile();

        $this->assertArrayHasKey('type', $this->errors($this->individual(['type' => null]), $profile));
        $this->assertArrayHasKey('type', $this->errors($this->individual(['type' => 'government']), $profile));
    }

    public function test_changing_type_rechecks_requirements_and_keeps_the_stored_identifier(): void
    {
        $profile = $this->profile();
        $profile->update(['type' => ClientType::Individual, 'jmbg' => self::JMBG]);

        // individual -> company: PIB becomes required, JMBG left out of the input is preserved.
        $toCompany = $this->company();
        unset($toCompany['jmbg']);
        $this->assertArrayHasKey('pib', $this->errors([...$toCompany, 'pib' => null], $profile));
        $validated = $this->passes($toCompany, $profile);
        $this->assertArrayNotHasKey('jmbg', $validated);

        $profile->update($validated);
        $profile = $profile->fresh();
        $this->assertSame(ClientType::Company, $profile->type);
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertNotNull($profile->jmbg_hash);

        // company -> individual: the stored JMBG satisfies "required", a blank one keeps it,
        // and a PIB left out is preserved.
        $toIndividual = $this->individual(['jmbg' => null]);
        unset($toIndividual['pib']);
        $validated = $this->passes($toIndividual, $profile);
        $this->assertArrayNotHasKey('jmbg', $validated);
        $this->assertArrayNotHasKey('pib', $validated);

        $profile->update($validated);
        $profile = $profile->fresh();
        $this->assertSame(self::JMBG, $profile->jmbg);
        $this->assertSame('123456789', $profile->pib);

        // ...but an individual without any stored JMBG still has to provide one.
        $this->assertArrayHasKey('jmbg', $this->errors($toIndividual, $this->profile()));
    }

    public function test_request_is_authorized_only_for_owner_or_admin(): void
    {
        $profile = $this->profile();
        $stranger = User::factory()->client()->create();
        $admin = User::factory()->admin()->create();

        $this->request($this->individual(), $profile, $profile->user)->validateResolved();
        $this->request($this->individual(), $profile, $admin)->validateResolved();

        $this->expectException(AuthorizationException::class);
        $this->request($this->individual(), $profile, $stranger)->validateResolved();
    }

    public function test_policy_matrix(): void
    {
        $profile = $this->profile();
        $other = $this->profile();
        $admin = User::factory()->admin()->create();

        foreach (['view', 'update'] as $ability) {
            $this->assertTrue(Gate::forUser($profile->user)->allows($ability, $profile), "owner $ability");
            $this->assertFalse(Gate::forUser($profile->user)->allows($ability, $other), "stranger $ability");
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $profile), "admin $ability");
            $this->assertFalse(Gate::forUser(null)->allows($ability, $profile), "guest $ability");
        }

        foreach (['create', 'delete'] as $ability) {
            $this->assertFalse(Gate::forUser($admin)->allows($ability, $profile), "admin $ability");
        }
    }

    public function test_profile_helper_creates_a_missing_profile_once(): void
    {
        $client = User::factory()->client()->create(['name' => 'Pre Dvojke']);
        $client->clientProfile()->delete();
        $client = $client->fresh();

        $first = $client->profile();
        $second = $client->fresh()->profile();

        $this->assertSame('Pre Dvojke', $first->full_name);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, ClientProfile::where('user_id', $client->id)->count());
    }

    public function test_profile_helper_returns_the_existing_profile_untouched(): void
    {
        $client = User::factory()->client()->create();
        $client->clientProfile->update(['city' => 'Niš']);

        $this->assertSame('Niš', $client->fresh()->profile()->city);
        $this->assertSame(1, ClientProfile::count());
    }

    public function test_profile_helper_returns_null_for_admin_and_creates_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertNull($admin->profile());
        $this->assertSame(0, ClientProfile::count());
    }
}
