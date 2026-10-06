<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ClientProfile;
use App\Models\User;
use App\Support\Jmbg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a client for the admin (salon flow): a user with the role client and a profile, in ONE
 * transaction together with the duplicate check, so there is never a user without a profile and
 * the unique keys stay the last line of defence (a race ends in the same 422). The duplicate check
 * takes NO row lock: lower(email) cannot use the index, so FOR UPDATE could lock the whole users
 * table on MySQL until the end of the transaction. Protection against a race is the unique index on
 * users.email / client_profiles.jmbg_hash and the caught UniqueConstraintViolationException.
 *
 * The role is set here, never read from a request. The password is random (Hash::make of 40
 * random characters) and is never shown, logged or sent: the client sets their own through the
 * emailed link (ClientInvitation). `email_verified_at` stays null (the user model does not use
 * email verification). The automatic `created` log entries of the user and the profile are muted
 * for these two instances only (no lasting state); the caller writes ONE summary entry.
 *
 * Duplicates: the email (any letter case) and the JMBG hash are a hard block, the PIB is a
 * warning the admin confirms (two branches of one company); the error names the existing client
 * (`existing_client.<field>` = the id of its profile) for the admin's link.
 */
class ClientCreator
{
    /**
     * @param  array<string, mixed>  $data  validated: email, type, full_name, jmbg?, pib?, address, postal_code, city, country
     *
     * @throws ValidationException on a duplicate
     */
    public function create(array $data, bool $confirmDuplicatePib = false): ClientProfile
    {
        try {
            return DB::transaction(function () use ($data, $confirmDuplicatePib) {
                $this->assertNoDuplicates($data, $confirmDuplicatePib);

                $user = (new User)->fill([
                    'name' => $data['full_name'],
                    'email' => $data['email'],
                    'password' => Hash::make(Str::random(40)),
                ]);
                $user->role = UserRole::Client;
                $user->muteCreationLog()->save();

                // Through the model, so jmbg_hash is derived from the JMBG.
                $profile = $user->clientProfile()->make(Arr::only($data, ['type', 'full_name', 'jmbg', 'pib', 'address', 'postal_code', 'city', 'country']));
                $profile->muteCreationLog()->save();

                return $profile->setRelation('user', $user);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => __('A client with this email or JMBG already exists.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoDuplicates(array $data, bool $confirmDuplicatePib): void
    {
        $errors = [];

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();

        if ($user !== null) {
            $errors['email'] = [__('A user with this email already exists.')];
            $this->link($errors, 'email', $user->clientProfile?->id);
        }

        if (filled($data['jmbg'] ?? null)) {
            $existing = ClientProfile::query()->where('jmbg_hash', Jmbg::hash((string) $data['jmbg']))->first();

            if ($existing !== null) {
                $errors['jmbg'] = [__('A client with this JMBG already exists.')];
                $this->link($errors, 'jmbg', $existing->id);
            }
        }

        if (filled($data['pib'] ?? null) && ! $confirmDuplicatePib) {
            $existing = ClientProfile::query()->where('pib', $data['pib'])->first();

            if ($existing !== null) {
                $errors['pib'] = [__('A client with this PIB already exists. If it is another branch of the same company, confirm to create a new client.')];
                $this->link($errors, 'pib', $existing->id);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function link(array &$errors, string $field, ?int $profileId): void
    {
        if ($profileId !== null) {
            $errors["existing_client.$field"] = [(string) $profileId];
        }
    }
}
