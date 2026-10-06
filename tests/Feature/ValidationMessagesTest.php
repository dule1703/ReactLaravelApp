<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invalid input on the key screens produces messages with Serbian field names only: no raw field
 * name (full_name), no English word, no ijekavian leftovers. Which fields have a name is checked
 * for every Form Request by ValidationAttributesTest; this one checks what a person really reads.
 */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const ENGLISH = ['field', 'must', 'required', 'invalid', 'number', 'the ', 'cijen', 'provjer', 'vrijed'];

    /** @return list<string> */
    private function messages(): array
    {
        return collect(session('errors')->getBag('default')->all())->all();
    }

    private function assertReadable(array $messages): void
    {
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/[a-z]+_[a-z_]+/', $message, "Raw field name in: $message");

            foreach (self::ENGLISH as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $message, "English or ijekavian text in: $message");
            }
        }
    }

    /** The account name field is labelled "Ime i prezime" on the forms, never the catalog's "naziv". */
    private function assertNameMessage(string $message): void
    {
        $this->assertStringContainsString('ime i prezime', $message);
        $this->assertStringNotContainsString('naziv', $message);
    }

    public function test_registration(): void
    {
        $this->post('/register', ['name' => '', 'email' => 'x', 'password' => 'a', 'password_confirmation' => 'b'])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertReadable($this->messages());
        $this->assertNameMessage(session('errors')->first('name'));
    }

    public function test_account_update(): void
    {
        $this->actingAs(User::factory()->client()->create())->patch('/profile', ['name' => '', 'email' => 'x'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertReadable($this->messages());
        $this->assertNameMessage(session('errors')->first('name'));
    }

    public function test_client_profile(): void
    {
        $client = User::factory()->client()->create();

        $this->actingAs($client)->patch('/client-profile', [
            'type' => 'x', 'full_name' => '', 'pib' => 'abc', 'postal_code' => 'abc', 'jmbg' => 'abc',
        ])->assertSessionHasErrors();

        $this->assertReadable($this->messages());
    }

    public function test_admin_new_client(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/clients', [])->assertSessionHasErrors();

        $this->assertReadable($this->messages());
    }

    public function test_offer_note(): void
    {
        $client = User::factory()->client()->create();
        $offer = Offer::factory()->create(['user_id' => $client->id]);

        $this->actingAs($client)->patch("/offers/{$offer->id}/note", ['note' => str_repeat('a', 5000)])->assertSessionHasErrors('note');

        $this->assertReadable($this->messages());
    }

    public function test_issuer(): void
    {
        $this->actingAs(User::factory()->admin()->create())->patch('/admin/issuer', [
            'name' => '', 'postal_code' => 'x', 'pib' => 'y', 'email' => 'z', 'phone' => '??',
        ])->assertSessionHasErrors();

        $this->assertReadable($this->messages());
    }

    public function test_vat_rate(): void
    {
        $this->actingAs(User::factory()->admin()->create())->patch('/admin/prices/vat', ['rate' => ''])->assertSessionHasErrors('rate');

        $this->assertReadable($this->messages());
    }
}
