<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Первый оператор панели из .env. Запускается при каждом старте, повторный запуск ничего не ломает. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim((string) config('promo.operator.email')));
        $password = (string) config('promo.operator.password');

        if ($email === '' || $password === '') {
            $this->command?->warn('OPERATOR_EMAIL или OPERATOR_PASSWORD пусты: оператор не создан.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name = $user->name ?: 'Оператор';
        if (! $user->exists || ! Hash::check($password, $user->password)) {
            $user->password = $password; // хешируется кастом модели
        }
        $user->save();
    }
}
