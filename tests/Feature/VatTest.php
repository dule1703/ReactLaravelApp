<?php

namespace Tests\Feature;

use App\Support\Money;
use App\Support\Vat;
use Tests\TestCase;

/**
 * Same fixture files as the vitest tests (resources/js/lib/*.test.js): PHP and JS must agree.
 */
class VatTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/$name")), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_net_from_gross_matches_the_shared_cases(): void
    {
        foreach ($this->fixture('vat-cases.json')['netFromGross'] as $case) {
            $this->assertSame(
                $case['net'],
                Vat::netFromGross($case['gross'], $case['rate_bp']),
                "rate {$case['rate_bp']}: gross {$case['gross']}",
            );
        }
    }

    public function test_gross_from_net_matches_the_shared_cases(): void
    {
        foreach ($this->fixture('vat-cases.json')['grossFromNet'] as $case) {
            $this->assertSame(
                $case['gross'],
                Vat::grossFromNet($case['net'], $case['rate_bp']),
                "rate {$case['rate_bp']}: net {$case['net']}",
            );
        }
    }

    public function test_the_documented_examples(): void
    {
        $this->assertSame(1, Vat::netFromGross(1, 2000));
        $this->assertSame(2_500_000, Vat::netFromGross(3_000_000, 2000));
        // Half rounds up: 3 / 1.2 = 2.5 -> 3.
        $this->assertSame(3, Vat::netFromGross(3, 2000));
        $this->assertSame(500_000, Vat::vatAmount(2_500_000, 2000));
    }

    public function test_every_whole_euro_survives_gross_net_gross_at_the_fixture_rate(): void
    {
        $round = $this->fixture('vat-cases.json')['roundTrip'];

        for ($euro = $round['from_euro']; $euro <= $round['to_euro']; $euro++) {
            $gross = $euro * 100;

            $this->assertSame($gross, Vat::grossFromNet(Vat::netFromGross($gross, $round['rate_bp']), $round['rate_bp']), "$euro EUR");
        }
    }

    public function test_money_parsing_matches_the_shared_cases(): void
    {
        $cases = $this->fixture('money-parse-cases.json');

        foreach ($cases['euros']['valid'] as [$input, $cents]) {
            $this->assertSame($cents, Money::parseEuros($input), "euros \"$input\"");
        }
        foreach ($cases['euros']['invalid'] as $input) {
            $this->assertNull(Money::parseEuros($input), "euros \"$input\" must be rejected");
        }
        foreach ($cases['euros']['signedValid'] as [$input, $cents]) {
            $this->assertSame($cents, Money::parseEuros($input, signed: true), "signed euros \"$input\"");
        }
        foreach ($cases['euros']['signedInvalid'] as $input) {
            $this->assertNull(Money::parseEuros($input, signed: true), "signed euros \"$input\" must be rejected");
        }
        foreach ($cases['percent']['valid'] as [$input, $bp]) {
            $this->assertSame($bp, Money::parsePercentBp($input), "percent \"$input\"");
        }
        foreach ($cases['percent']['invalid'] as $input) {
            $this->assertNull(Money::parsePercentBp($input), "percent \"$input\" must be rejected");
        }
        foreach ($cases['percent']['signedValid'] as [$input, $bp]) {
            $this->assertSame($bp, Money::parsePercentBp($input, signed: true), "signed percent \"$input\"");
        }
        foreach ($cases['percent']['signedInvalid'] as $input) {
            $this->assertNull(Money::parsePercentBp($input, signed: true), "signed percent \"$input\" must be rejected");
        }
    }

    public function test_percent_formatting_and_euro_rounding(): void
    {
        $this->assertSame('20', Money::formatPercentBp(2000));
        $this->assertSame('7,5', Money::formatPercentBp(750));
        $this->assertSame('0,29', Money::formatPercentBp(29));
        $this->assertSame('0', Money::formatPercentBp(0));
        $this->assertSame(100, Money::roundToEuro(149));
        $this->assertSame(200, Money::roundToEuro(150));
    }
}
