<?php

namespace Database\Seeders\Showcase\Developers\Concerns;

trait BelongsToDevelopers
{
    protected function seedArea(): ?string
    {
        return 'developers';
    }
}
