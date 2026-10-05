<?php

namespace Database\Seeders\Showcase\Ledger\Concerns;

trait BelongsToLedger
{
    protected function seedArea(): ?string
    {
        return 'ledger';
    }
}
