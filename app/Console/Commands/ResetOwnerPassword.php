<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Lets the owner set their own password directly via a hidden prompt —
 * nothing typed here ever appears in shell history or a transcript.
 * Single-owner system: operates on the one existing user account.
 */
#[Signature('app:reset-owner-password')]
#[Description('Interactively reset the owner account password')]
class ResetOwnerPassword extends Command
{
    public function handle(): int
    {
        $user = User::query()->first();

        if ($user === null) {
            $this->error('No owner account exists yet. Run app:create-owner-account first.');

            return self::FAILURE;
        }

        $this->info("Resetting password for {$user->email}.");

        $password = $this->secret('New password');
        $confirmation = $this->secret('Confirm new password');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', 'confirmed', 'min:12']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info('Password updated.');

        return self::SUCCESS;
    }
}
