<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\IssuerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\IssuerProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 5.3: the details of the dealer that issues the offers (admin screen). */
class IssuerProfileTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'name' => 'Auto Čačak d.o.o.',
        'address' => 'Šumatovačka 5',
        'postal_code' => '32000',
        'city' => 'Čačak',
        'pib' => '123456789',
        'phone' => '+381 32 123 456',
        'email' => 'kancelarija@autocacak.example',
    ];

    private User $admin;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->client = User::factory()->client()->create();
    }

    public function test_the_admin_opens_the_screen_with_empty_fields_at_first(): void
    {
        $this->actingAs($this->admin)->get(route('issuer.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Issuer')
                ->where('issuer.name', '')
                ->where('issuer.pib', ''));
    }

    public function test_a_client_gets_403_and_a_guest_goes_to_login(): void
    {
        $this->actingAs($this->client)->get(route('issuer.edit'))->assertForbidden();
        $this->actingAs($this->client)->patch(route('issuer.update'), self::VALID)->assertForbidden();
        $this->assertSame(0, IssuerProfile::count());

        auth()->logout();
        $this->get(route('issuer.edit'))->assertRedirect(route('login'));
        $this->patch(route('issuer.update'), self::VALID)->assertRedirect(route('login'));
    }

    public function test_the_admin_saves_and_a_second_save_updates_the_same_row(): void
    {
        $this->actingAs($this->admin)->patch(route('issuer.update'), self::VALID)
            ->assertRedirect(route('issuer.edit'))
            ->assertSessionHas('success');

        $this->assertSame(self::VALID, IssuerProfile::sole()->only(array_keys(self::VALID)));

        $this->actingAs($this->admin)->patch(route('issuer.update'), [...self::VALID, 'phone' => null, 'city' => 'Beograd'])->assertRedirect();

        $this->assertSame(1, IssuerProfile::count());
        $this->assertSame('Beograd', IssuerProfile::sole()->city);
        $this->assertNull(IssuerProfile::sole()->phone);

        $this->get(route('issuer.edit'))->assertInertia(fn (Assert $page) => $page->where('issuer.city', 'Beograd')->where('issuer.phone', ''));
    }

    public function test_invalid_input_is_422_and_nothing_is_written(): void
    {
        $cases = [
            'name' => [['name' => ''], ['name' => str_repeat('a', 151)]],
            'postal_code' => [['postal_code' => '1234'], ['postal_code' => '12a45']],
            'pib' => [['pib' => '12345678'], ['pib' => '12345678a']],
            'email' => [['email' => 'nije-email']],
            'phone' => [['phone' => 'poziv me'], ['phone' => str_repeat('1', 31)]],
            'address' => [['address' => str_repeat('a', 151)]],
            'city' => [['city' => str_repeat('a', 101)]],
        ];

        foreach ($cases as $field => $variants) {
            foreach ($variants as $override) {
                $this->actingAs($this->admin)->patch(route('issuer.update'), [...self::VALID, ...$override])->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, IssuerProfile::count());
    }

    public function test_the_change_is_logged_and_the_pib_only_by_field_name(): void
    {
        $this->actingAs($this->admin)->patch(route('issuer.update'), self::VALID)->assertRedirect();
        $this->actingAs($this->admin)->patch(route('issuer.update'), [...self::VALID, 'pib' => '987654321', 'city' => 'Niš'])->assertRedirect();

        $created = ActivityLog::where('action', 'issuer_profile.created')->sole();
        $updated = ActivityLog::where('action', 'issuer_profile.updated')->sole();

        $this->assertSame(['redacted' => true], $created->changes['pib']);
        $this->assertSame(['redacted' => true], $updated->changes['pib']);
        $this->assertSame(['redacted' => true], $updated->changes['city']); // address fields are redacted by name for every model (6.3)
        $this->assertSame($this->admin->id, $updated->user_id);

        $all = ActivityLog::all()->toJson();
        $this->assertStringNotContainsString('123456789', $all);
        $this->assertStringNotContainsString('987654321', $all);
    }

    public function test_saving_the_same_values_writes_no_entry(): void
    {
        $this->actingAs($this->admin)->patch(route('issuer.update'), self::VALID);
        $this->actingAs($this->admin)->patch(route('issuer.update'), self::VALID)->assertRedirect();

        $this->assertSame(0, ActivityLog::where('action', 'issuer_profile.updated')->count());
    }

    public function test_the_actions_and_the_fields_have_translations(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['created', 'updated', 'deleted'] as $event) {
            $this->assertNotEmpty($translations["activity.action.issuer_profile.$event"] ?? null, $event);
        }

        $skip = ['id', 'created_at', 'updated_at'];

        foreach (array_diff(Schema::getColumnListing('issuer_profiles'), $skip) as $column) {
            $label = $translations["activity.field.issuer_profile.$column"] ?? $translations["activity.field.$column"] ?? null;
            $this->assertNotEmpty($label, "issuer_profiles.$column");
        }

        foreach (preg_grep('/^issuer_/', Schema::getColumnListing('offers')) as $column) {
            $this->assertNotEmpty($translations["activity.field.$column"] ?? null, "offers.$column");
        }
    }

    public function test_the_seeder_creates_a_demo_issuer_and_never_overwrites_an_entered_one(): void
    {
        $this->app->make(IssuerProfileSeeder::class)->run();
        $this->assertStringContainsString('(demo)', IssuerProfile::sole()->name);

        IssuerProfile::sole()->update(['name' => 'Pravi diler']);
        $this->app->make(IssuerProfileSeeder::class)->run();

        $this->assertSame(1, IssuerProfile::count());
        $this->assertSame('Pravi diler', IssuerProfile::sole()->name);
    }

    #[DataProvider('environments')]
    public function test_the_database_seeder_adds_the_demo_issuer_only_outside_production(string $environment, int $expected): void
    {
        $this->app['env'] = $environment;
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame($expected, IssuerProfile::count());
    }

    /** @return array<string, array{string, int}> */
    public static function environments(): array
    {
        return ['production' => ['production', 0], 'staging' => ['staging', 1], 'local' => ['local', 1], 'testing' => ['testing', 0]];
    }
}
