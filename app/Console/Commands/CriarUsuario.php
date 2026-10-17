<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CriarUsuario extends Command
{
    protected $signature = 'usuario:criar {email} {--nome=Administrador}';

    protected $description = 'Cria ou atualiza o usuário que acessa o sistema';

    public function handle(): int
    {
        $senha = (string) $this->secret('Senha');

        if (strlen($senha) < 8) {
            $this->error('A senha precisa ter pelo menos 8 caracteres.');

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('nome'), 'password' => $senha],
        );
        $this->info('Usuário salvo.');

        return self::SUCCESS;
    }
}
