<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-user
    {--name= : The person\'s name}
    {--email= : The email address they log in with}
    {--password= : Their password. Leave it out to be asked, or to get a generated one when running without prompts}')]
#[Description('Create a login account. This is the only way to get one.')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateNewUser $createNewUser): int
    {
        if (! $this->input->isInteractive() && (blank($this->option('name')) || blank($this->option('email')))) {
            $this->components->error('Pass --name and --email when running without prompts.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text(label: 'Name', required: true);
        $email = $this->option('email') ?: text(label: 'Email', required: true);
        $password = $this->option('password')
            ?: ($this->input->isInteractive() ? password(label: 'Password', required: true) : null);
        $generatedPassword = $password === null ? Str::password(24) : null;

        try {
            $user = $createNewUser->create([
                'name' => $name,
                'email' => $email,
                'password' => $password ?? $generatedPassword,
                'password_confirmation' => $password ?? $generatedPassword,
            ]);
        } catch (ValidationException $exception) {
            foreach (Arr::flatten($exception->errors()) as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->components->info("Created an account for {$user->email}.");

        if ($generatedPassword !== null) {
            $this->components->twoColumnDetail('Password', $generatedPassword);
            $this->components->warn('Change this password after logging in, under Settings > Security.');
        }

        return self::SUCCESS;
    }
}
