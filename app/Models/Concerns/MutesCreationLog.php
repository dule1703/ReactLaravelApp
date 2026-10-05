<?php

namespace App\Models\Concerns;

/**
 * Lets ONE service skip the per-row `created` log entry of the models it creates in bulk, because
 * the service writes a single summary entry instead (OfferCreator: `offer.items_created`).
 *
 * The flag is a property of the instance, not an attribute: it is not fillable, not in
 * toArray()/JSON and cannot come from a request. Only `created` is muted; updates and deletes
 * stay logged. Call muteCreationLog() only from the service that writes the summary entry.
 */
trait MutesCreationLog
{
    private bool $creationLogMuted = false;

    public function muteCreationLog(): static
    {
        $this->creationLogMuted = true;

        return $this;
    }

    public function activityMuted(string $event): bool
    {
        return $event === 'created' && $this->creationLogMuted;
    }
}
