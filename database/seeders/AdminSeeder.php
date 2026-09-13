<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Create (or update) the administrator defined by the ADMIN_* env values.
     */
    public function run(): void
    {
        $config = config('chat.admin');

        $password = $config['password'] ?: Str::password(16);

        $admin = User::query()->firstOrNew(['email' => mb_strtolower($config['email'])]);

        $admin->fill([
            'name' => $config['name'],
            'username' => mb_strtolower($config['username']),
            'phone' => Phone::normalize($config['phone']),
        ]);

        if (! $admin->exists) {
            $admin->password = $password;
        }

        $admin->forceFill([
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => $admin->email_verified_at ?? now(),
        ])->save();

        if ($this->command) {
            $this->command->info("Administrator ready: {$admin->email}");

            if (! $config['password'] && $admin->wasRecentlyCreated) {
                $this->command->warn("Generated admin password: {$password}");
            }
        }
    }
}
