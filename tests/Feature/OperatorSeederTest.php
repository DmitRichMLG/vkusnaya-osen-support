<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperatorSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_is_created_from_env_and_seeding_twice_is_safe(): void
    {
        config(['promo.operator' => ['email' => 'Admin@Example.com', 'password' => 'secret-1']]);

        $this->seed();
        $this->seed();

        $this->assertSame(1, User::count());
        $user = User::first();
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue(Hash::check('secret-1', $user->password));
    }

    public function test_password_change_in_env_is_applied(): void
    {
        config(['promo.operator' => ['email' => 'admin@example.com', 'password' => 'old']]);
        $this->seed();

        config(['promo.operator' => ['email' => 'admin@example.com', 'password' => 'new']]);
        $this->seed();

        $this->assertTrue(Hash::check('new', User::first()->password));
    }

    public function test_nothing_is_created_without_credentials(): void
    {
        config(['promo.operator' => ['email' => '', 'password' => '']]);

        $this->seed();

        $this->assertSame(0, User::count());
    }
}
