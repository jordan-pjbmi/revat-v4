<?php

namespace Database\Seeders\Test;

use App\Enums\SupportLevel;
use App\Models\Admin;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Seed admin users for each support level.
     */
    public function run(): void
    {
        $admins = [
            ['name' => 'Super Admin', 'email' => 'super@revat.test', 'support_level' => SupportLevel::Super],
            ['name' => 'Manager Admin', 'email' => 'manager@revat.test', 'support_level' => SupportLevel::Manager],
            ['name' => 'Agent Admin', 'email' => 'agent@revat.test', 'support_level' => SupportLevel::Agent],
        ];

        foreach ($admins as $data) {
            Admin::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => 'password',
                'support_level' => $data['support_level'],
            ]);
        }
    }
}
