<?php

namespace Tests\Feature;

use App\Support\OfferCalculationException;
use App\Support\OfferCalculator;
use App\Support\Vat;
use Tests\TestCase;

/**
 * Same fixture file as resources/js/lib/offer.test.js: PHP and JS must agree. The expected values
 * in the fixture are worked out by hand ("why"), not generated from either implementation.
 */
class OfferCalculatorTest extends TestCase
{
    /**
     * @return array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>}
     */
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/offer-calc-cases.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_matches_the_shared_cases(): void
    {
        foreach ($this->fixture()['valid'] as $case) {
            $this->assertSame(
                $case['expected'],
                OfferCalculator::calculate($case['items'], $case['vat_rate_bp']),
                $case['name'],
            );
        }
    }

    public function test_it_rejects_the_shared_bad_cases_with_the_same_category(): void
    {
        foreach ($this->fixture()['invalid'] as $case) {
            try {
                OfferCalculator::calculate($case['items'], $case['vat_rate_bp']);
                $this->fail("Case '{$case['name']}' should have been rejected.");
            } catch (OfferCalculationException $e) {
                $this->assertSame($case['category'], $e->category, $case['name']);
            }
        }
    }

    public function test_the_results_are_integers_never_floats(): void
    {
        $result = OfferCalculator::calculate([
            ['version_price_cents' => OfferCalculator::MAX_PRICE_CENTS, 'quantity' => 100, 'options' => []],
        ], Vat::RATE_MAX_BP);

        foreach (['total_net_cents', 'vat_cents', 'total_gross_cents'] as $key) {
            $this->assertIsInt($result[$key]);
        }
        $this->assertIsInt($result['items'][0]['line_net_cents']);
    }

    public function test_the_worst_case_before_the_total_limit_stays_an_int(): void
    {
        $worst = (OfferCalculator::MAX_PRICE_CENTS + OfferCalculator::MAX_OPTIONS * OfferCalculator::MAX_PRICE_CENTS) * OfferCalculator::MAX_QUANTITY;

        $this->assertIsInt($worst);
        $this->assertLessThan(9_007_199_254_740_991, $worst);
    }

    public function test_the_limits_match_the_js_constants(): void
    {
        $this->assertSame([1_000_000_000, 999, 200, 100_000_000_000, 20, 500], [
            OfferCalculator::MAX_PRICE_CENTS,
            OfferCalculator::MAX_QUANTITY,
            OfferCalculator::MAX_OPTIONS,
            OfferCalculator::MAX_TOTAL_NET_CENTS,
            OfferCalculator::MAX_ITEMS,
            OfferCalculator::MAX_TOTAL_OPTIONS,
        ]);
    }

    public function test_booleans_and_numeric_strings_are_not_integers(): void
    {
        foreach ([true, '1', 1.0] as $bad) {
            try {
                OfferCalculator::calculate([['version_price_cents' => $bad, 'quantity' => 1, 'options' => []]], 2000);
                $this->fail('A non-integer price should be rejected.');
            } catch (OfferCalculationException $e) {
                $this->assertSame(OfferCalculationException::TYPE, $e->category);
            }
        }
    }

    public function test_vat_goes_through_the_vat_class_once_on_the_total(): void
    {
        $result = OfferCalculator::calculate([
            ['version_price_cents' => 3, 'quantity' => 1, 'options' => []],
            ['version_price_cents' => 3, 'quantity' => 1, 'options' => []],
        ], 2000);

        $this->assertSame(Vat::vatAmount(6, 2000), $result['vat_cents']);
        $this->assertSame(Vat::grossFromNet(6, 2000), $result['total_gross_cents']);
    }
}
