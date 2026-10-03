<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Setting;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AdminPricesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CarModel $model;

    private Trim $trim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->model = CarModel::factory()->create(['name' => 'Octavia']);
        $this->trim = Trim::factory()->for($this->model)->create(['name' => 'Style']);
    }

    private function version(int $net = 2_500_000, ?Trim $trim = null): Version
    {
        return Version::factory()->create(['trim_id' => ($trim ?? $this->trim)->id, 'base_price_cents' => $net]);
    }

    private function optional(int $net = 150_000, ?Trim $trim = null): TrimEquipment
    {
        return TrimEquipment::factory()->optional($net)->create(['trim_id' => ($trim ?? $this->trim)->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bulk(array $overrides = []): array
    {
        return array_merge([
            'car_model_id' => $this->model->id,
            'trim_id' => null,
            'targets' => ['versions', 'equipment'],
            'change_type' => 'percent',
            'value' => '5',
        ], $overrides);
    }

    // --- access ---

    public function test_guest_is_redirected_and_client_gets_403_everywhere(): void
    {
        $version = $this->version();
        $equipment = $this->optional();
        $routes = [
            ['get', '/admin/prices', []],
            ['patch', "/admin/prices/versions/{$version->id}", ['mode' => 'net', 'amount' => '100']],
            ['patch', "/admin/prices/equipment/{$equipment->id}", ['mode' => 'net', 'amount' => '100']],
            ['patch', '/admin/prices/vat', ['rate' => '10']],
            ['postJson', '/admin/prices/bulk/preview', $this->bulk()],
            ['postJson', '/admin/prices/bulk/apply', $this->bulk()],
        ];

        foreach ($routes as [$method, $url, $data]) {
            $response = $this->{$method}($url, $data);
            $this->assertContains($response->getStatusCode(), [302, 401], "guest $method $url");
        }

        $client = User::factory()->client()->create();
        foreach ($routes as [$method, $url, $data]) {
            $this->actingAs($client)->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
        $this->assertSame(2000, Setting::vatRateBp());
    }

    // --- screen ---

    public function test_the_screen_lists_net_and_gross_prices_of_the_selected_model(): void
    {
        $version = $this->version(2_500_000);
        $optional = $this->optional(150_000);
        TrimEquipment::factory()->create(['trim_id' => $this->trim->id]); // standard: no price, not listed
        $other = Trim::factory()->for(CarModel::factory()->create(['name' => 'Fabia']))->create();
        $this->version(1_000_000, $other);

        $this->actingAs($this->admin)->get('/admin/prices?model='.$this->model->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Prices')
            ->where('vat', ['rate_bp' => 2000, 'rate_percent' => '20'])
            ->where('selectedModelId', $this->model->id)
            ->has('models', 2)
            ->has('trims', 1)
            ->where('trims.0.versions.0.id', $version->id)
            ->where('trims.0.versions.0.net', 2_500_000)
            ->where('trims.0.versions.0.gross', 3_000_000)
            ->has('trims.0.equipment', 1)
            ->where('trims.0.equipment.0.id', $optional->id)
            ->where('trims.0.equipment.0.net', 150_000)
            ->where('trims.0.equipment.0.gross', 180_000));
    }

    public function test_the_screen_defaults_to_the_first_model_and_copes_with_an_empty_catalog(): void
    {
        $this->actingAs($this->admin)->get('/admin/prices')
            ->assertInertia(fn (Assert $page) => $page->where('selectedModelId', $this->model->id));

        Trim::query()->delete();
        CarModel::query()->delete();
        $this->actingAs($this->admin)->get('/admin/prices')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('models', 0)->where('selectedModelId', null)->has('trims', 0));
    }

    // --- single price ---

    public function test_a_net_price_is_saved_as_typed(): void
    {
        $version = $this->version();

        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'net', 'amount' => '25.500,50'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Cena je sačuvana.');

        $this->assertSame(2_550_050, $version->fresh()->base_price_cents);
    }

    public function test_a_gross_price_is_converted_by_the_server_with_the_current_rate(): void
    {
        $version = $this->version();

        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'gross', 'amount' => '30.000']);
        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);

        Setting::setVatRateBp(1000);
        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'gross', 'amount' => '27.500']);
        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
    }

    public function test_the_server_never_trusts_a_net_value_sent_by_the_browser(): void
    {
        $version = $this->version();

        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", [
            'mode' => 'gross',
            'amount' => '30.000',
            'net' => 1,
            'net_cents' => 1,
            'base_price_cents' => 1,
        ]);

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
    }

    public function test_bad_amounts_are_rejected(): void
    {
        $version = $this->version();

        foreach (['', 'abc', '0', '0,00', '-5', '25,000', '1e5', '1.000.00', '99999999999'] as $bad) {
            $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'net', 'amount' => $bad])
                ->assertSessionHasErrors('amount');
        }
        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'other', 'amount' => '100'])
            ->assertSessionHasErrors('mode');

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
    }

    public function test_a_price_change_is_logged_with_old_and_new_value(): void
    {
        $version = $this->version(2_500_000);

        $this->actingAs($this->admin)->patch("/admin/prices/versions/{$version->id}", ['mode' => 'net', 'amount' => '26.000']);

        $log = ActivityLog::where('action', 'version.updated')->where('subject_id', $version->id)->sole();
        $this->assertEquals(['old' => 2_500_000, 'new' => 2_600_000], $log->changes['base_price_cents']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_an_optional_equipment_price_can_be_set_including_free(): void
    {
        $row = $this->optional(150_000);

        $this->actingAs($this->admin)->patch("/admin/prices/equipment/{$row->id}", ['mode' => 'gross', 'amount' => '1.200'])
            ->assertSessionHasNoErrors();
        $this->assertSame(100_000, $row->fresh()->price_cents);

        $this->actingAs($this->admin)->patch("/admin/prices/equipment/{$row->id}", ['mode' => 'net', 'amount' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $row->fresh()->price_cents);

        $log = ActivityLog::where('action', 'trim_equipment.updated')->orderByDesc('id')->first();
        $this->assertEquals(['old' => 100_000, 'new' => 0], $log->changes['price_cents']);
    }

    public function test_standard_equipment_has_no_price_to_edit(): void
    {
        $standard = TrimEquipment::factory()->create(['trim_id' => $this->trim->id]);

        $this->actingAs($this->admin)->patch("/admin/prices/equipment/{$standard->id}", ['mode' => 'net', 'amount' => '100'])
            ->assertSessionHasErrors('amount');

        $this->assertNull($standard->fresh()->price_cents);
    }

    // --- VAT rate ---

    public function test_the_vat_rate_defaults_to_twenty_percent(): void
    {
        $this->assertSame(2000, Setting::vatRateBp());
        $this->assertSame('2000', Setting::where('key', 'vat_rate_bp')->value('value'));
    }

    public function test_the_vat_rate_is_saved_in_basis_points_and_logged(): void
    {
        $this->actingAs($this->admin)->patch('/admin/prices/vat', ['rate' => '7,5'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'PDV stopa je sačuvana.');

        $this->assertSame(750, Setting::vatRateBp());

        $log = ActivityLog::where('action', 'setting.updated')->sole();
        $this->assertEquals(['old' => '2000', 'new' => '750'], $log->changes['value']);
    }

    public function test_bad_vat_rates_are_rejected(): void
    {
        foreach (['', 'abc', '101', '100,01', '7,555', '-1', '20%'] as $bad) {
            $this->actingAs($this->admin)->patch('/admin/prices/vat', ['rate' => $bad])->assertSessionHasErrors('rate');
        }

        $this->actingAs($this->admin)->patch('/admin/prices/vat', ['rate' => '100'])->assertSessionHasNoErrors();
        $this->assertSame(10000, Setting::vatRateBp());
        $this->actingAs($this->admin)->patch('/admin/prices/vat', ['rate' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, Setting::vatRateBp());

        $this->expectException(InvalidArgumentException::class);
        Setting::setVatRateBp(10001);
    }

    public function test_changing_the_rate_does_not_touch_stored_net_prices(): void
    {
        $version = $this->version(2_500_000);
        $optional = $this->optional(150_000);

        $this->actingAs($this->admin)->patch('/admin/prices/vat', ['rate' => '10']);

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
        $this->assertSame(150_000, $optional->fresh()->price_cents);
        $this->assertSame(0, ActivityLog::where('action', 'version.updated')->count());

        $this->actingAs($this->admin)->get('/admin/prices')->assertInertia(fn (Assert $page) => $page
            ->where('trims.0.versions.0.net', 2_500_000)
            ->where('trims.0.versions.0.gross', 2_750_000));
    }

    // --- bulk preview ---

    public function test_the_preview_shows_old_and_new_net_and_gross_without_changing_anything(): void
    {
        $version = $this->version(2_500_000);

        $response = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['versions']]))
            ->assertOk();

        $response->assertJsonPath('items.0.kind', 'version')
            ->assertJsonPath('items.0.id', $version->id)
            ->assertJsonPath('items.0.old_net', 2_500_000)
            ->assertJsonPath('items.0.old_gross', 3_000_000)
            ->assertJsonPath('items.0.new_gross', 3_150_000)
            ->assertJsonPath('items.0.new_net', 2_625_000)
            ->assertJsonPath('requires_confirmation', false);
        $this->assertNotEmpty($response->json('token'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
        $this->assertSame(0, ActivityLog::where('action', 'version.updated')->count());
    }

    public function test_at_twenty_percent_every_new_gross_price_is_a_whole_euro(): void
    {
        foreach ([1_999_917, 2_345_678, 1_234_567, 999_999, 3_141_593, 1_000_001] as $net) {
            $this->version($net);
        }

        foreach (['3,3', '-2,75', '0,29', '49,99'] as $percent) {
            $items = $this->actingAs($this->admin)
                ->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['versions'], 'value' => $percent]))
                ->assertOk()->json('items');

            $this->assertNotEmpty($items);
            foreach ($items as $item) {
                $this->assertSame(0, $item['new_gross'] % 100, "gross {$item['new_gross']} at $percent%");
                $this->assertSame(Vat::netFromGross($item['new_gross'], 2000), $item['new_net']);
            }
        }
    }

    public function test_a_fixed_amount_can_be_added_to_the_gross_or_the_net_price(): void
    {
        $this->version(2_500_000); // gross 3,000,000

        $gross = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk([
            'targets' => ['versions'], 'change_type' => 'amount', 'amount_mode' => 'gross', 'value' => '250',
        ]))->assertOk()->json('items.0');
        $this->assertSame(3_025_000, $gross['new_gross']);
        $this->assertSame(Vat::netFromGross(3_025_000, 2000), $gross['new_net']);

        $net = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk([
            'targets' => ['versions'], 'change_type' => 'amount', 'amount_mode' => 'net', 'value' => '1.000,40',
        ]))->assertOk()->json('items.0');
        // gross of (2,500,000 + 100,040) = 3,120,048 -> rounded to the whole euro 3,120,000.
        $this->assertSame(3_120_000, $net['new_gross']);
        $this->assertSame(2_600_000, $net['new_net']);

        $down = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk([
            'targets' => ['versions'], 'change_type' => 'amount', 'amount_mode' => 'gross', 'value' => '-100',
        ]))->assertOk()->json('items.0');
        $this->assertSame(2_990_000, $down['new_gross']);
    }

    public function test_the_filter_limits_the_scope(): void
    {
        $style = $this->version(2_500_000);
        $otherTrim = Trim::factory()->for($this->model)->create(['name' => 'Sportline']);
        $sport = $this->version(3_000_000, $otherTrim);
        $this->optional(150_000);
        $foreign = $this->version(1_000_000, Trim::factory()->create());

        $all = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['versions']]))->json('items');
        $this->assertEqualsCanonicalizing([$style->id, $sport->id], array_column($all, 'id'));

        $one = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['versions'], 'trim_id' => $otherTrim->id]))->json('items');
        $this->assertSame([$sport->id], array_column($one, 'id'));

        $equipment = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['equipment']]))->json('items');
        $this->assertSame(['trim_equipment'], array_unique(array_column($equipment, 'kind')));

        $this->assertNotContains($foreign->id, array_column($all, 'id'));
    }

    public function test_large_changes_need_confirmation_and_the_limits_are_hard(): void
    {
        $this->version(2_500_000);

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => '10']))
            ->assertJsonPath('requires_confirmation', false);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => '10,5']))
            ->assertJsonPath('requires_confirmation', true);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => '-50']))
            ->assertOk()->assertJsonPath('requires_confirmation', true);

        foreach (['50,01', '-51', '100'] as $tooMuch) {
            $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => $tooMuch]))
                ->assertStatus(422)->assertJsonValidationErrors('value');
        }
        foreach (['100.001', '-100.001'] as $tooMuch) {
            $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['change_type' => 'amount', 'amount_mode' => 'gross', 'value' => $tooMuch]))
                ->assertStatus(422)->assertJsonValidationErrors('value');
        }
    }

    public function test_bad_bulk_input_is_rejected(): void
    {
        $this->version();

        foreach (['', 'abc', '3,555', '5%', '1e1'] as $bad) {
            $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors('value');
        }

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => []]))->assertStatus(422)->assertJsonValidationErrors('targets');
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['everything']]))->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['car_model_id' => 9999]))->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['trim_id' => Trim::factory()->create()->id]))->assertStatus(422)->assertJsonValidationErrors('trim_id');
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['change_type' => 'amount', 'value' => '100']))->assertStatus(422)->assertJsonValidationErrors('amount_mode');
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['change_type' => 'amount', 'amount_mode' => 'gross', 'value' => '25,000']))->assertStatus(422);
    }

    public function test_a_change_that_modifies_nothing_or_breaks_a_price_is_rejected(): void
    {
        $this->version(2_500_000);

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => '0']))
            ->assertStatus(422)->assertJsonValidationErrors('value');

        // A fixed amount that would make the price negative.
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk([
            'targets' => ['versions'], 'change_type' => 'amount', 'amount_mode' => 'gross', 'value' => '-100.000',
        ]))->assertStatus(422)->assertJsonValidationErrors('value');
    }

    // --- bulk apply ---

    public function test_apply_changes_every_price_through_the_models_and_writes_one_summary(): void
    {
        $first = $this->version(2_500_000);
        $second = $this->version(3_000_000);
        $optional = $this->optional(150_000);

        $preview = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk())->json();

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk() + ['token' => $preview['token']])
            ->assertOk()->assertJsonPath('updated', ['versions' => 2, 'equipment' => 1]);

        foreach ($preview['items'] as $item) {
            $model = $item['kind'] === 'version' ? Version::find($item['id']) : TrimEquipment::find($item['id']);
            $this->assertSame($item['new_net'], $item['kind'] === 'version' ? $model->base_price_cents : $model->price_cents);
        }

        $log = ActivityLog::where('action', 'version.updated')->where('subject_id', $first->id)->sole();
        $this->assertEquals(['old' => 2_500_000, 'new' => $first->fresh()->base_price_cents], $log->changes['base_price_cents']);
        $this->assertSame(1, ActivityLog::where('action', 'trim_equipment.updated')->where('subject_id', $optional->id)->count());

        $summary = ActivityLog::where('action', 'price.bulk_updated')->sole();
        $this->assertEquals(['version' => ['new' => 2], 'trim_equipment' => ['new' => 1]], $summary->changes);
        $this->assertStringContainsString('+5%', $summary->description);
        $this->assertStringContainsString('Octavia', $summary->description);
        $this->assertSame($this->admin->id, $summary->user_id);
        $this->assertNotSame(2_500_000, $first->fresh()->base_price_cents);
        $this->assertNotSame(3_000_000, $second->fresh()->base_price_cents);
    }

    public function test_apply_without_a_matching_token_is_refused(): void
    {
        $version = $this->version();

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk())->assertStatus(409);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk() + ['token' => 'forged'])->assertStatus(409);

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
        $this->assertSame(0, ActivityLog::where('action', 'price.bulk_updated')->count());
    }

    public function test_a_stale_preview_is_refused_when_prices_or_the_rate_changed_meanwhile(): void
    {
        $version = $this->version();
        $token = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk())->json('token');

        $version->update(['base_price_cents' => 2_600_000]);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk() + ['token' => $token])->assertStatus(409);

        $token = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk())->json('token');
        Setting::setVatRateBp(1000);
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk() + ['token' => $token])->assertStatus(409);

        $this->assertSame(2_600_000, $version->fresh()->base_price_cents);
    }

    public function test_the_token_belongs_to_the_exact_change_that_was_previewed(): void
    {
        $version = $this->version();
        $token = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['value' => '2']))->json('token');

        // A different percentage with the token of another change.
        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk(['value' => '9']) + ['token' => $token])->assertStatus(409);

        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);
    }

    public function test_a_large_change_is_applied_only_with_the_explicit_confirmation(): void
    {
        $version = $this->version(2_500_000);
        $params = $this->bulk(['value' => '20']);
        $token = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $params)->assertJsonPath('requires_confirmation', true)->json('token');

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $params + ['token' => $token])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_large');
        $this->assertSame(2_500_000, $version->fresh()->base_price_cents);

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $params + ['token' => $token, 'confirm_large' => true])->assertOk();
        $this->assertSame(3_000_000, $version->fresh()->base_price_cents);
    }

    public function test_a_failure_in_the_middle_changes_nothing(): void
    {
        $first = $this->version(2_500_000);
        $second = $this->version(3_000_000);
        $third = $this->version(3_500_000);
        $token = $this->actingAs($this->admin)->postJson('/admin/prices/bulk/preview', $this->bulk(['targets' => ['versions']]))->json('token');

        $calls = 0;
        Version::updating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('boom');
            }
        });

        $this->actingAs($this->admin)->postJson('/admin/prices/bulk/apply', $this->bulk(['targets' => ['versions']]) + ['token' => $token])
            ->assertStatus(500);

        $this->assertSame(2, $calls);
        $this->assertSame(2_500_000, $first->fresh()->base_price_cents);
        $this->assertSame(3_000_000, $second->fresh()->base_price_cents);
        $this->assertSame(3_500_000, $third->fresh()->base_price_cents);
        // Prices and their log entries are rolled back together.
        $this->assertSame(0, ActivityLog::where('action', 'version.updated')->count());
        $this->assertSame(0, ActivityLog::where('action', 'price.bulk_updated')->count());
    }

    // --- translations ---

    public function test_the_new_log_actions_and_fields_are_translated(): void
    {
        $translations = json_decode(file_get_contents(lang_path('sr_Latn.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach (['setting.created', 'setting.updated', 'setting.deleted', 'price.bulk_updated'] as $action) {
            $this->assertNotEmpty($translations["activity.action.$action"] ?? null, $action);
        }
        foreach (['setting.key', 'setting.value', 'price.version', 'price.trim_equipment'] as $field) {
            $this->assertNotEmpty($translations["activity.field.$field"] ?? null, $field);
        }
    }
}
