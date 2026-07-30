<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Backward-compatible entry point for older tests and local instructions.
 *
 * @deprecated Use FullProjectSeeder. This wrapper deliberately does not
 * maintain a second, conflicting demo dataset.
 */
class SampleUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(FullProjectSeeder::class);
    }
}
