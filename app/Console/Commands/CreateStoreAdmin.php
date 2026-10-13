<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateStoreAdmin extends Command
{
    protected $signature = 'store:create-admin {email} {--name= : Administrator name} {--generate-password : Generate a random password for local setup}';

    protected $description = 'Create the first Nella & Vale administrator interactively';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $name = $this->option('name') ?: $this->ask('Name');
        $generated = (bool) $this->option('generate-password');
        if ($generated && ! app()->environment(['local', 'testing'])) {
            $this->error('Generated-password setup is local only.');

            return self::FAILURE;
        }
        $password = $generated ? Str::password(24) : $this->secret('Password (at least 12 characters, letters and numbers)');
        $v = validator(['name' => $name, 'email' => $email, 'password' => $password], ['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:254|unique:users',
            'password' => ['required', Password::min(12)->letters()->numbers()]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        DB::transaction(function () use ($name, $email, $password) {
            $user = User::create(compact('name', 'email', 'password'));
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole('admin');
        });
        $this->info('Administrator created.');
        if ($generated) {
            $this->line('Email: '.$email);
            $this->line('Password: '.$password);
        }

        return self::SUCCESS;
    }
}
