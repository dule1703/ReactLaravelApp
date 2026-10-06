<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\User;
use App\Services\OfferCreator;
use App\Services\OfferPdf;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

class OfferPdfTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private const JMBG = '0101990710006';

    private const LETTERS = ['ć', 'č', 'đ', 'š', 'ž', 'Ć', 'Č', 'Đ', 'Š', 'Ž'];

    private User $client;

    private User $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile(['jmbg' => self::JMBG]);
        $this->other = $this->clientWithProfile();
        $this->admin = User::factory()->admin()->create();
    }

    private function realOffer(): Offer
    {
        return app(OfferCreator::class)->create($this->client, 'Za firmu', [[
            'version_id' => $this->version->id, 'quantity' => 2, 'option_ids' => [$this->climatronic->id, $this->metallic->id],
        ]]);
    }

    /** An offer whose text has every Serbian letter with a mark, in the snapshot. */
    private function offerWithLetters(): Offer
    {
        $offer = Offer::factory()->create([
            'user_id' => $this->client->id,
            'client_name' => 'Đorđe Žarković Šćepanović',
            'client_address' => 'Šumatovačka 5',
            'client_postal_code' => '32000',
            'client_city' => 'Čačak',
            'note' => 'Napomena: ćirilica, žuta boja, čaša, đak, šuma — ĆČĐŠŽ',
            'vat_rate_bp' => 2000,
            'total_net_cents' => 1_000_000,
            'vat_cents' => 200_000,
            'total_gross_cents' => 1_200_000,
        ]);
        $item = OfferItem::factory()->create([
            'offer_id' => $offer->id, 'car_model_name' => 'Škoda Kodiaq', 'trim_name' => 'Ćuprija',
            'engine_name' => '2.0 TDI', 'quantity' => 1, 'version_price_cents' => 1_000_000, 'line_net_cents' => 1_000_000,
        ]);
        OfferItemOption::factory()->create(['offer_item_id' => $item->id, 'name' => 'Čuvar šoferšajbne ž', 'price_cents' => 12_345]);

        return $offer;
    }

    /** All text of the PDF content: the flate streams, decoded. */
    private function streams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $matches);
        $all = '';

        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            $all .= ($decoded === false ? '' : $decoded)."\n";
        }

        return $all;
    }

    public function test_a_client_gets_the_pdf_of_their_own_offer_inline(): void
    {
        $offer = $this->realOffer();

        $response = $this->actingAs($this->client)->get(route('offers.pdf', $offer));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ponuda-001-2026.pdf"');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('/', trim(explode('filename=', $response->headers->get('Content-Disposition'))[1], '"'));
    }

    public function test_someone_elses_offer_is_not_found_an_admin_gets_any_and_a_guest_goes_to_login(): void
    {
        $offer = $this->realOffer();

        $this->actingAs($this->other)->get(route('offers.pdf', $offer))->assertNotFound();
        $this->actingAs($this->admin)->get(route('offers.pdf', $offer))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        auth()->logout();
        $this->get(route('offers.pdf', $offer))->assertRedirect(route('login'));
    }

    public function test_bad_ids_are_404_and_the_route_is_throttled(): void
    {
        foreach (['abc', '0', '999999'] as $id) {
            $this->actingAs($this->client)->get("/offers/$id/pdf")->assertNotFound();
        }

        $this->assertContains('throttle:30,1', Route::getRoutes()->getByName('offers.pdf')->gatherMiddleware());
    }

    public function test_the_filename_has_no_slash(): void
    {
        $this->assertSame('ponuda-012-2026.pdf', OfferPdf::filename(Offer::factory()->make(['number' => '012/2026'])));
        $this->assertSame('ponuda-1000-2026.pdf', OfferPdf::filename(Offer::factory()->make(['number' => '1000/2026'])));
    }

    public function test_the_template_never_has_the_jmbg_or_the_hash(): void
    {
        $offer = $this->realOffer();
        $html = app(OfferPdf::class)->html($offer);

        $this->assertNotNull($this->client->profile()->jmbg_hash);
        $this->assertStringNotContainsString(self::JMBG, $html);
        $this->assertStringNotContainsString($this->client->profile()->jmbg_hash, $html);
        $this->assertStringNotContainsStringIgnoringCase('jmbg', $html);
        $this->assertStringNotContainsString(self::JMBG, $this->actingAs($this->client)->get(route('offers.pdf', $offer))->getContent());
    }

    public function test_the_template_is_the_snapshot_not_the_live_profile_or_the_catalog(): void
    {
        $offer = $this->realOffer();
        $before = app(OfferPdf::class)->html($offer->fresh());

        $this->client->profile()->update(['full_name' => 'Neko Drugi', 'city' => 'Niš', 'address' => 'Nova 5']);
        $this->version->update(['base_price_cents' => 9_900_000]);
        $this->version->trim->carModel->update(['name' => 'Renamed']);
        $this->climatronic->update(['name' => 'Renamed item', 'is_active' => false]);
        $this->metallic->group->update(['is_active' => false]);
        DB::table('trim_equipment')->where('equipment_item_id', $this->metallic->id)->delete();

        $this->assertSame($before, app(OfferPdf::class)->html($offer->fresh()));
        $this->assertStringContainsString('Petar Petrović', $before);
        $this->assertStringContainsString('Octavia', $before);
    }

    public function test_the_template_has_every_serbian_letter(): void
    {
        $html = app(OfferPdf::class)->html($this->offerWithLetters());

        foreach (['Đorđe Žarković Šćepanović', 'Šumatovačka 5', 'Čačak', 'Škoda Kodiaq', 'Ćuprija', 'Čuvar šoferšajbne ž', 'ćirilica, žuta boja, čaša, đak, šuma'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        foreach (self::LETTERS as $letter) {
            $this->assertStringContainsString($letter, $html);
        }
    }

    public function test_the_pdf_embeds_dejavu_and_has_the_letters_as_glyph_codes(): void
    {
        $pdf = app(OfferPdf::class)->render($this->offerWithLetters());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);

        // The font is embedded with an identity encoding: each character is its 2-byte code point.
        $content = $this->streams($pdf);
        $utf16 = fn (string $text) => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        foreach (self::LETTERS as $letter) {
            $this->assertStringContainsString($utf16($letter), $content, "The letter $letter is not in the PDF.");
        }

        $this->assertStringContainsString($utf16('Đorđe'), $content);
        $this->assertStringContainsString($utf16('€'), $content);
    }

    public function test_the_amounts_are_the_stored_columns_through_money_and_a_missing_amount_is_a_dash(): void
    {
        $offer = $this->realOffer()->fresh();
        $html = app(OfferPdf::class)->html($offer);

        foreach ([$offer->total_net_cents, $offer->vat_cents, $offer->total_gross_cents, 2_500_000, 5_540_000, 120_000, 150_000] as $cents) {
            $this->assertStringContainsString(Money::format($cents), $html);
        }

        $this->assertStringContainsString('PDV 20%', $html);

        $empty = Offer::factory()->create(['user_id' => $this->client->id, 'total_net_cents' => null, 'vat_cents' => null, 'total_gross_cents' => null]);
        OfferItem::factory()->create(['offer_id' => $empty->id, 'line_net_cents' => null]);

        $this->assertStringContainsString('>-<', preg_replace('/\s+/', '', app(OfferPdf::class)->html($empty)));
        $this->assertStringStartsWith('%PDF', app(OfferPdf::class)->render($empty));
    }

    public function test_the_surcharge_is_marked_and_an_unknown_fuel_type_does_not_break_it(): void
    {
        $html = app(OfferPdf::class)->html($this->realOffer());

        $this->assertStringContainsString('doplata', $html);
        $this->assertStringContainsString('Boja karoserije', $html);

        $offer = Offer::factory()->create(['user_id' => $this->client->id]);
        OfferItem::factory()->create(['offer_id' => $offer->id, 'fuel_type' => 'hydrogen', 'drive' => 'tracked']);

        $this->assertStringContainsString('hydrogen', app(OfferPdf::class)->html($offer));
        $this->assertStringStartsWith('%PDF', app(OfferPdf::class)->render($offer));
    }

    public function test_the_pib_is_printed_only_when_it_is_in_the_snapshot(): void
    {
        $without = Offer::factory()->create(['user_id' => $this->client->id, 'client_pib' => null]);
        $with = Offer::factory()->create(['user_id' => $this->client->id, 'client_type' => 'company', 'client_pib' => '123456789']);

        $this->assertStringNotContainsString('PIB', app(OfferPdf::class)->html($without));
        $this->assertStringContainsString('123456789', app(OfferPdf::class)->html($with));
    }

    public function test_the_note_and_the_text_are_escaped(): void
    {
        $offer = Offer::factory()->create(['user_id' => $this->client->id, 'note' => '<script>alert(1)</script> & <b>x</b>']);

        $html = app(OfferPdf::class)->html($offer);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_the_header_is_the_product_name_and_does_not_depend_on_app_name(): void
    {
        config(['app.name' => 'ReactLaravel']);

        $html = app(OfferPdf::class)->html($this->realOffer());

        $this->assertStringContainsString('Škoda konfigurator', $html);
        $this->assertStringNotContainsString('ReactLaravel', $html);
    }

    public function test_the_template_has_no_remote_file_and_no_javascript(): void
    {
        $html = app(OfferPdf::class)->html($this->realOffer());

        $this->assertDoesNotMatchRegularExpression('#(src|href)=["\']https?:#i', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_serving_the_pdf_writes_nothing_to_the_database(): void
    {
        $offer = $this->realOffer();
        $logs = ActivityLog::count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->client)->get(route('offers.pdf', $offer))->assertOk();
        $writes = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']));
        DB::disableQueryLog();

        $this->assertCount(0, $writes);
        $this->assertSame($logs, ActivityLog::count());
    }

    public function test_the_work_folder_is_created_in_storage_by_the_code(): void
    {
        $dir = storage_path('app/pdf');
        app(OfferPdf::class)->render($this->realOffer());

        $this->assertDirectoryExists($dir);
        $this->assertTrue(is_writable($dir));
    }
}
