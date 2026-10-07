<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\OfferCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/**
 * 6.3: the activity log keeps the NAME of a field for personal data, never the value. The address of
 * a client (profile and offer snapshot) is such data; the client's name stays (it says who the entry
 * is about).
 */
class ActivityLogPrivacyTest extends TestCase
{
    use BuildsOfferCatalog;
    use RefreshDatabase;

    private const ADDRESS = 'Tajna ulica 77';

    private const NEW_ADDRESS = 'Nova tajna ulica 5';

    private const POSTAL = '21000';

    private const CITY = 'Novi Sad';

    /** @return list<string> */
    private function secrets(): array
    {
        return [self::ADDRESS, self::NEW_ADDRESS, self::POSTAL, self::CITY, 'Knez Mihailova 1'];
    }

    private function dump(): string
    {
        return ActivityLog::all()->toJson(JSON_UNESCAPED_UNICODE);
    }

    public function test_a_profile_update_logs_the_names_of_the_address_fields_only(): void
    {
        $client = $this->clientWithProfile(['address' => self::ADDRESS, 'postal_code' => self::POSTAL, 'city' => self::CITY]);

        $this->actingAs($client)->patch('/client-profile', [
            'type' => 'company', 'full_name' => 'Petar Petrović Novi', 'address' => self::NEW_ADDRESS,
            'postal_code' => '11000', 'city' => 'Beograd', 'country' => 'RS', 'pib' => '123456789', 'jmbg' => '',
        ])->assertSessionHasNoErrors();

        $log = ActivityLog::query()->where('action', 'client_profile.updated')->latest('id')->firstOrFail();

        foreach (['address', 'postal_code', 'city'] as $field) {
            $this->assertSame(['redacted' => true], $log->changes[$field], "$field must be reduced to its name");
        }

        // The name stays, with the old and the new value.
        $this->assertSame('Petar Petrović', $log->changes['full_name']['old']);
        $this->assertSame('Petar Petrović Novi', $log->changes['full_name']['new']);

        foreach ($this->secrets() as $value) {
            $this->assertStringNotContainsString($value, $this->dump());
        }
    }

    public function test_creating_an_offer_logs_no_address_of_the_client(): void
    {
        $this->buildCatalog();
        $client = $this->clientWithProfile(['address' => self::ADDRESS, 'postal_code' => self::POSTAL, 'city' => self::CITY]);

        app(OfferCreator::class)->create($client, null, [['version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => []]]);

        $log = ActivityLog::query()->where('action', 'offer.created')->firstOrFail();

        foreach (['client_address', 'client_postal_code', 'client_city'] as $field) {
            $this->assertSame(['redacted' => true], $log->changes[$field], "$field must be reduced to its name");
        }
        $this->assertSame('Petar Petrović', $log->changes['client_name']['new'] ?? $log->changes['client_name']);

        foreach ($this->secrets() as $value) {
            $this->assertStringNotContainsString($value, $this->dump());
        }
    }

    public function test_the_admin_creating_a_client_logs_no_address_either(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/clients', [
            'type' => 'individual', 'full_name' => 'Mika Mikić', 'email' => 'mika@example.test', 'address' => self::ADDRESS,
            'postal_code' => self::POSTAL, 'city' => self::CITY, 'country' => 'RS',
        ])->assertSessionHasNoErrors();

        foreach ($this->secrets() as $value) {
            $this->assertStringNotContainsString($value, $this->dump());
        }
    }

    /** A rejected form flashes the old input back into the page; secrets must not be part of it. */
    public function test_a_rejected_form_does_not_flash_pib_jmbg_or_passwords(): void
    {
        $client = $this->clientWithProfile();

        $this->actingAs($client)->from('/client-profile')->patch('/client-profile', [
            'type' => 'company', 'full_name' => 'Petar', 'address' => 'Ulica 1', 'postal_code' => 'xx',
            'city' => 'Beograd', 'country' => 'RS', 'pib' => '12345', 'jmbg' => 'abc',
        ])->assertSessionHasErrors('postal_code');

        $this->assertTrue(session()->hasOldInput('full_name'), 'control: other fields are flashed');
        $this->assertFalse(session()->hasOldInput('pib'));
        $this->assertFalse(session()->hasOldInput('jmbg'));

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'typed-secret-123']);
        $this->assertFalse(session()->hasOldInput('password'));
        $this->assertFalse(session()->hasOldInput('password_confirmation'));
    }
}
