<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('users:create-admin {email} {--name=}')]
#[Description('Create a new administrator using a hidden password prompt')]
class CreateAdminUserCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the password securely.');

            return self::FAILURE;
        }
        $email = trim((string) $this->argument('email'));
        if (User::query()->where('email', $email)->exists()) {
            $this->error('This email already belongs to an account; no changes were made.');

            return self::FAILURE;
        }
        $name = $this->option('name') ?? $this->ask('Administrator name');
        $password = $this->secret('Administrator password');
        $confirmation = $this->secret('Confirm administrator password');
        $validator = Validator::make([
            'name' => $name, 'email' => $email,
            'password' => $password, 'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = UserRole::ADMIN;
        $user->status = 'active';
        $user->save();
        $this->info('Administrator created: '.$email);

        return self::SUCCESS;
    }
}
