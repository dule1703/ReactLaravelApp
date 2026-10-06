<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ClientAccountCreated;
use Illuminate\Support\Facades\Password;

/**
 * Sends a new client the link to set their password: a token of the standard password broker (the
 * same one the "Forgot your password?" flow uses, so the same expiry and the same reset route)
 * and the ClientAccountCreated email. Throws when the mail cannot be sent; the caller decides
 * what that means (the account already exists, so it only warns the admin). The token and the
 * link are never logged.
 */
class ClientInvitation
{
    public function send(User $client): void
    {
        $token = Password::broker()->createToken($client);

        $client->notify(new ClientAccountCreated($token));
    }
}
