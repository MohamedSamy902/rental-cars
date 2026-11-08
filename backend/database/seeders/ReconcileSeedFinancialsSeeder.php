<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Kept so older seed entrypoints still resolve.
 * Does not rewrite stored custody balances or historical work-order part totals.
 * New Demo/E2E/Mega rows are written so their own movements and line sums agree.
 * A residual gap on existing rows is disclosed by the read model
 * (computed opening line / parts_cost_other) without fabricating movements.
 */
class ReconcileSeedFinancialsSeeder extends Seeder
{
    public function run(): void
    {
        // No historical rewrite.
    }
}
