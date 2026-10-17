<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

class CobrancaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'valor' => '150.00',
            'vencimento' => today()->addDays(10)->toDateString(),
            'link_pagamento' => 'https://pag.exemplo/'.fake()->uuid(),
            'status' => 'aberta',
        ];
    }
}
