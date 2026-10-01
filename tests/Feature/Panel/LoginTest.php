<?php

namespace Tests\Feature\Panel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        foreach (['/', '/tickets', '/tickets/1', '/decisions', '/stats'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/login')->assertOk()->assertSee('Вход в панель');
    }

    public function test_login_with_valid_password(): void
    {
        $user = User::factory()->create(['email' => 'op@example.com', 'password' => 'secret-1']);

        $this->post('/login', ['email' => 'Op@Example.com', 'password' => 'secret-1'])->assertRedirect('/tickets');

        $this->assertAuthenticatedAs($user);
        $this->get('/tickets')->assertOk()->assertSee($user->name);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'op@example.com', 'password' => 'secret-1']);

        $this->from('/login')->post('/login', ['email' => 'op@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }
}
