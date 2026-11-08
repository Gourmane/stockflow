
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;


//public function run(): void
    //{
      //  User::updateOrCreate(
         //   ['email' => 'admin@stockflow.test'],
          //  [
           //     'name' => 'StockFlow Admin',
           //     'password' => 'Admin@123456',
           //     'role' => 'admin',
           //     'is_active' => true,
             // ]
        //);
   // }

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Preserve any existing admin
        if (User::where('role', 'admin')->exists()) {
            $this->command->warn(
                'An admin already exists. No changes made.'
            );
            return;
        }

        // 2. Ask for admin information
        $name = trim((string) $this->command->ask('Admin full name'));

        $email = strtolower(trim(
            (string) $this->command->ask('Admin email')
        ));

        $password = $this->command->secret(
            'Admin password (minimum 12 characters)'
        );

        $confirmation = $this->command->secret(
            'Confirm admin password'
        );

        // 3. Validate information
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->command->error($error);
            }

            throw new \RuntimeException('Admin creation cancelled.');
        }

        // 4. Create the first admin
        User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->command->info('Admin created successfully.');
    }
}



