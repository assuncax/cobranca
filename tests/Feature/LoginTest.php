<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar');
    }

    public function test_login_com_senha_correta(): void
    {
        $user = User::factory()->create(['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123']);

        $this->post('/login', ['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_com_senha_errada(): void
    {
        User::factory()->create(['email' => 'dono@exemplo.com', 'password' => 'senha-forte-123']);

        $this->post('/login', ['email' => 'dono@exemplo.com', 'password' => 'errada'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_comando_cria_usuario(): void
    {
        $this->artisan('usuario:criar', ['email' => 'dono@exemplo.com'])
            ->expectsQuestion('Senha', 'senha-forte-123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('senha-forte-123', User::where('email', 'dono@exemplo.com')->value('password')));
    }

    public function test_comando_recusa_senha_curta(): void
    {
        $this->artisan('usuario:criar', ['email' => 'dono@exemplo.com'])
            ->expectsQuestion('Senha', '123')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
