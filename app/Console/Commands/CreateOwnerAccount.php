<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstraps the single owner account for this system.
 *
 * There is no public registration screen (CLAUDE.md: single-owner system) —
 * the one user account is created interactively via this command instead,
 * so a plaintext password never has to live in a seeder or committed file.
 */
#[Signature('app:create-owner-account {--force : Allow creating another user even if one already exists}')]
#[Description('Create the single owner account for this system')]
class CreateOwnerAccount extends Command
{
    public function handle(): int
    {
        if (User::query()->exists() && ! $this->option('force')) {
            $this->error('An owner account already exists. This is a single-owner system.');
            $this->line('Pass --force if you really intend to create an additional account.');

            return self::FAILURE;
        }

        $name = $this->ask('Name');
        $email = $this->ask('Email');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'confirmed', 'min:12'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Owner account created for {$user->email}.");

        return self::SUCCESS;
    }
}
