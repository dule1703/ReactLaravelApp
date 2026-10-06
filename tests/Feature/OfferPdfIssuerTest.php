<?php

namespace Tests\Feature;

use App\Models\IssuerProfile;
use App\Models\Offer;
use App\Models\OfferItem;
use App\Models\OfferItemOption;
use App\Models\User;
use App\Services\OfferCreator;
use App\Services\OfferPdf;
use App\Support\OfferPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferCatalog;
use Tests\TestCase;

/** 5.3: the issuer (dealer) in the header of the PDF, copied into the offer when it is made. */
class OfferPdfIssuerTest extends TestCase
{
    use BuildsOfferCatalog, RefreshDatabase;

    private const JMBG = '0101990710006';

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCatalog();
        $this->client = $this->clientWithProfile(['jmbg' => self::JMBG]);
    }

    private function issuer(array $attributes = []): IssuerProfile
    {
        return IssuerProfile::create([
            'name' => 'Auto Čačak d.o.o.',
            'address' => 'Šumatovačka 5',
            'postal_code' => '32000',
            'city' => 'Čačak',
            'pib' => '123456789',
            'phone' => '+381 32 123 456',
            'email' => 'kancelarija@autocacak.example',
            ...$attributes,
        ]);
    }

    private function offer(): Offer
    {
        return app(OfferCreator::class)->create($this->client, 'Napomena', [[
            'version_id' => $this->version->id, 'quantity' => 1, 'option_ids' => [$this->climatronic->id],
        ]]);
    }

    private function html(Offer $offer): string
    {
        return app(OfferPdf::class)->html($offer->fresh());
    }

    public function test_an_offer_made_after_the_entry_carries_the_snapshot_and_the_header_prints_it(): void
    {
        $this->issuer();
        $offer = $this->offer()->fresh();

        $this->assertSame('Auto Čačak d.o.o.', $offer->issuer_name);
        $this->assertSame('123456789', $offer->issuer_pib);

        $html = $this->html($offer);

        foreach (['Auto Čačak d.o.o.', 'Šumatovačka 5', '32000 Čačak', 'PIB: 123456789', 'Telefon: +381 32 123 456', 'kancelarija@autocacak.example'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringNotContainsString(self::JMBG, $html);
        $this->assertStringNotContainsString('Škoda konfigurator', $html);
    }

    public function test_a_later_change_of_the_issuer_does_not_change_the_pdf_of_an_existing_offer(): void
    {
        $issuer = $this->issuer();
        $offer = $this->offer();
        $before = $this->html($offer);

        $issuer->update(['name' => 'Potpuno Drugi Diler', 'pib' => '987654321', 'city' => 'Niš']);

        $this->assertSame($before, $this->html($offer));
        $this->assertStringNotContainsString('Potpuno Drugi Diler', $this->html($offer));

        // A new offer gets the new details.
        $this->assertStringContainsString('Potpuno Drugi Diler', $this->html($this->offer()));
    }

    public function test_an_offer_without_the_issuer_keeps_the_plain_header(): void
    {
        $offer = $this->offer();

        $this->assertNull($offer->fresh()->issuer_name);
        $this->assertNull(OfferPresenter::issuer($offer->fresh()));
        $this->assertStringContainsString('Škoda konfigurator', $this->html($offer));

        // An offer made before 5.3 (no snapshot at all) is the same, and the issuer entered later does not appear on it.
        $old = Offer::factory()->create(['user_id' => $this->client->id]);
        $this->issuer();

        $this->assertStringContainsString('Škoda konfigurator', $this->html($old));
        $this->assertStringNotContainsString('Auto Čačak', $this->html($old));
    }

    public function test_empty_fields_of_the_issuer_are_left_out(): void
    {
        $this->issuer(['phone' => null, 'email' => null, 'pib' => null, 'address' => null]);
        $offer = $this->offer()->fresh();

        $this->assertNull($offer->issuer_phone);

        $html = $this->html($offer);

        $this->assertStringContainsString('32000 Čačak', $html);
        $this->assertStringNotContainsString('Telefon:', $html);
        $this->assertStringNotContainsString('PIB: ', $html);
    }

    public function test_the_issuer_is_escaped(): void
    {
        $this->issuer(['name' => '<b>x</b><script>alert(1)</script>']);

        $html = $this->html($this->offer());

        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_the_presenter_keys_are_locked_and_the_page_of_the_offer_does_not_get_the_issuer(): void
    {
        $this->issuer();
        $offer = $this->offer()->fresh()->load('items.options');

        $this->assertSame(['name', 'address', 'postal_code', 'city', 'pib', 'phone', 'email'], array_keys(OfferPresenter::issuer($offer)));
        $this->assertSame([], preg_grep('/issuer/', array_keys(OfferPresenter::detail($offer, true))));
        $this->assertSame([], preg_grep('/issuer/', array_keys(OfferPresenter::row($offer->loadCount('items'), true))));
        $this->assertStringNotContainsString('Auto Čačak', json_encode($this->actingAs($this->client)->get(route('offers.show', $offer))->viewData('page')['props']));
    }

    public function test_the_pdf_is_still_a_pdf_with_the_font_and_a_title(): void
    {
        $this->issuer();

        $pdf = app(OfferPdf::class)->render($this->offer()->fresh());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
        $this->assertStringContainsString('/Title', $pdf);
    }

    public function test_a_long_offer_is_rendered_on_several_pages(): void
    {
        $this->issuer();
        $offer = $this->offer();

        foreach (range(1, 14) as $position) {
            $item = OfferItem::factory()->create(['offer_id' => $offer->id, 'position' => $position, 'line_net_cents' => 1_000_000]);
            OfferItemOption::factory()->count(4)->create(['offer_item_id' => $item->id, 'price_cents' => 10_000]);
        }

        $pdf = app(OfferPdf::class)->render($offer->fresh());

        $this->assertGreaterThan(1, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf));
    }

    public function test_unknown_values_print_neither_a_plus_sign_nor_a_lonely_unit(): void
    {
        $offer = $this->offer()->fresh()->load('items.options');
        $data = OfferPresenter::detail($offer, false);
        $data['items'][0]['power_kw'] = null;
        $data['items'][0]['options'][0]['price_cents'] = null;

        $html = view('pdf.offer', ['offer' => $data, 'issuer' => null, 'logo' => null])->render();

        $this->assertStringNotContainsString('+ -', $html);
        $this->assertStringNotContainsString(' kW', $html);
        // A known value still prints with its unit and sign.
        $known = view('pdf.offer', ['offer' => OfferPresenter::detail($offer, false), 'issuer' => null, 'logo' => null])->render();
        $this->assertStringContainsString(' kW', $known);
        $this->assertStringContainsString('+ ', $known);
    }
}
