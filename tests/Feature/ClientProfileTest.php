<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Models\ActivityLog;
use App\Models\ClientProfile;
use App\Models\User;
use App\Support\Jmbg;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    private const JMBG = '0101990710008';

    public function test_user_and_profile_are_related_one_to_one(): void
    {
        $profile = ClientProfile::factory()->create();

        $this->assertTrue($profile->user->clientProfile->is($profile));
        $this->assertSame(1, $profile->user->clientProfile()->count());
    }

    public function test_a_user_cannot_have_two_profiles(): void
    {
        $user = User::factory()->client()->create();

        $this->expectException(QueryException::class);

        ClientProfile::factory()->for($user)->create();
    }

    public function test_profile_defaults_to_an_individual_in_serbia(): void
    {
        $profile = User::factory()->client()->create()->clientProfile->fresh();

        $this->assertSame(ClientType::Individual, $profile->type);
        $this->assertSame('RS', $profile->country);
    }

    public function test_client_factory_state_creates_an_empty_profile_but_admin_gets_none(): void
    {
        $client = User::factory()->client()->create(['name' => 'Pera Peric']);
        $admin = User::factory()->admin()->create();

        $this->assertSame('Pera Peric', $client->clientProfile->full_name);
        $this->assertNull($client->clientProfile->jmbg);
        $this->assertNull($admin->clientProfile);
    }

    public function test_registration_creates_an_empty_profile(): void
    {
        $this->post('/register', [
            'name' => 'Nova Klijentkinja',
            'email' => 'nova@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $profile = User::where('email', 'nova@example.com')->firstOrFail()->clientProfile;

        $this->assertNotNull($profile);
        $this->assertSame('Nova Klijentkinja', $profile->full_name);
        $this->assertSame(ClientType::Individual, $profile->type);
    }

    public function test_user_id_and_hash_are_not_mass_assignable(): void
    {
        $user = User::factory()->client()->create();
        $other = User::factory()->client()->create();

        $user->clientProfile->update(['user_id' => $other->id, 'jmbg_hash' => 'x', 'city' => 'Nis']);

        $profile = $user->clientProfile->fresh();
        $this->assertSame($user->id, $profile->user_id);
        $this->assertNull($profile->jmbg_hash);
        $this->assertSame('Nis', $profile->city);
    }

    public function test_jmbg_is_encrypted_in_the_database(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG]);

        $raw = DB::table('client_profiles')->where('id', $profile->id)->value('jmbg');

        $this->assertNotSame(self::JMBG, $raw);
        $this->assertStringNotContainsString(self::JMBG, $raw);
        $this->assertSame(self::JMBG, $profile->fresh()->jmbg);
    }

    public function test_hash_is_stable_keyed_and_computed_on_the_normalized_value(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG]);
        $expected = hash_hmac('sha256', self::JMBG, config('app.jmbg_hash_key'));

        $this->assertSame($expected, $profile->fresh()->jmbg_hash);
        $this->assertSame($expected, Jmbg::hash(self::JMBG));
        $this->assertSame($expected, Jmbg::hash("  01 01-990 710 008\n"));
        $this->assertNotSame(hash('sha256', self::JMBG), $expected);

        config(['app.jmbg_hash_key' => str_repeat('k', 40)]);
        $this->assertNotSame($expected, Jmbg::hash(self::JMBG));
    }

    public function test_changing_or_clearing_jmbg_updates_the_hash(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG]);
        $first = $profile->fresh()->jmbg_hash;

        $profile->update(['jmbg' => '0202990710007']);
        $second = $profile->fresh()->jmbg_hash;
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);

        $profile->update(['jmbg' => null]);
        $this->assertNull($profile->fresh()->jmbg_hash);
    }

    public function test_saving_other_fields_does_not_touch_the_hash_or_need_the_key(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG]);
        $hash = $profile->fresh()->jmbg_hash;

        config(['app.jmbg_hash_key' => null]);
        $profile = $profile->fresh();
        $profile->update(['city' => 'Nis']);

        $this->assertSame($hash, $profile->fresh()->jmbg_hash);
    }

    public function test_jmbg_must_be_unique_through_its_hash(): void
    {
        ClientProfile::factory()->create(['jmbg' => self::JMBG]);

        $this->expectException(QueryException::class);

        ClientProfile::factory()->create(['jmbg' => '01-01-990-710-008']);
    }

    public function test_empty_jmbgs_and_equal_pibs_are_allowed(): void
    {
        ClientProfile::factory()->count(2)->create(['jmbg' => null, 'pib' => '123456789']);

        $this->assertSame(2, ClientProfile::whereNull('jmbg_hash')->count());
    }

    public function test_missing_or_short_hash_key_fails_fast(): void
    {
        foreach ([null, '', 'too-short'] as $key) {
            config(['app.jmbg_hash_key' => $key]);

            try {
                ClientProfile::factory()->create(['jmbg' => self::JMBG]);
                $this->fail('Saving a JMBG without a proper key must throw.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('JMBG_HASH_KEY', $e->getMessage());
            }
        }

        $this->assertSame(0, ClientProfile::whereNotNull('jmbg_hash')->count());
    }

    public function test_sensitive_fields_are_not_serialized(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG, 'pib' => '123456789']);
        $array = $profile->fresh()->toArray();

        $this->assertArrayNotHasKey('jmbg', $array);
        $this->assertArrayNotHasKey('jmbg_hash', $array);
        $this->assertStringNotContainsString(self::JMBG, $profile->fresh()->toJson());
    }

    public function test_activity_log_keeps_only_field_names_of_sensitive_fields(): void
    {
        $profile = ClientProfile::factory()->create(['jmbg' => self::JMBG, 'pib' => '123456789']);
        $profile->update(['jmbg' => '0202990710007', 'pib' => '987654321', 'city' => 'Nis']);

        $logs = ActivityLog::where('subject_type', $profile->getMorphClass())->get();
        $this->assertCount(2, $logs);

        foreach ($logs as $log) {
            foreach (['jmbg', 'jmbg_hash', 'pib'] as $field) {
                if (isset($log->changes[$field])) {
                    $this->assertSame(['redacted' => true], $log->changes[$field]);
                }
            }
        }

        $this->assertSame(['redacted' => true], $logs[1]->changes['jmbg']);
        $this->assertSame(['redacted' => true], $logs[1]->changes['jmbg_hash']);
        $this->assertSame(['redacted' => true], $logs[1]->changes['city']); // the address of a person is a field name only (6.3)

        $dump = ActivityLog::all()->toJson();
        foreach ([self::JMBG, '0202990710007', '123456789', '987654321'] as $value) {
            $this->assertStringNotContainsString($value, $dump);
        }
    }

    public function test_check_digit_helper(): void
    {
        $this->assertTrue(Jmbg::isValid(self::JMBG));
        $this->assertFalse(Jmbg::isValid('0101990710007'));
        $this->assertFalse(Jmbg::isValid('123'));
    }

    public function test_backfill_gives_existing_clients_a_profile_but_not_admins(): void
    {
        $client = User::factory()->create(['name' => 'Stari Klijent']);
        $admin = User::factory()->admin()->create();

        Schema::drop('client_profiles');
        (require database_path('migrations/2026_10_04_100000_create_client_profiles_table.php'))->up();

        $this->assertSame('Stari Klijent', ClientProfile::where('user_id', $client->id)->value('full_name'));
        $this->assertFalse(ClientProfile::where('user_id', $admin->id)->exists());
        $this->assertSame(1, ClientProfile::count());
    }

    public function test_seeder_creates_demo_profiles_with_valid_jmbg_and_hash(): void
    {
        $this->seed(DatabaseSeeder::class);

        $person = User::where('email', 'client@example.com')->first()->clientProfile;
        $company = User::where('email', 'firma@example.com')->first()->clientProfile;

        $this->assertTrue(Jmbg::isValid($person->jmbg));
        $this->assertSame(Jmbg::hash($person->jmbg), $person->jmbg_hash);
        $this->assertSame(ClientType::Company, $company->type);
        $this->assertSame(9, strlen($company->pib));
    }
}
