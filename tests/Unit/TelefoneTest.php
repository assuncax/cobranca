<?php

namespace Tests\Unit;

use App\Support\Telefone;
use PHPUnit\Framework\TestCase;

class TelefoneTest extends TestCase
{
    public function test_normaliza_formatos_comuns(): void
    {
        $this->assertSame('+5511999998888', Telefone::normalizar('(11) 99999-8888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('11999998888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('+55 11 99999-8888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('5511999998888'));
        $this->assertSame('+5511999998888', Telefone::normalizar('011 99999-8888'));
        $this->assertSame('+551133334444', Telefone::normalizar('(11) 3333-4444'));
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(Telefone::normalizar('99999-8888'));
        $this->assertNull(Telefone::normalizar('11899998888'));
        $this->assertNull(Telefone::normalizar('abc'));
        $this->assertNull(Telefone::normalizar('+1 415 555 2671'));
    }

    public function test_variantes_incluem_nono_digito_para_celular_antigo(): void
    {
        $this->assertSame(['+551199998888', '+5511999998888'], Telefone::variantes('+551199998888'));
        $this->assertSame(['+551133334444'], Telefone::variantes('+551133334444'));
        $this->assertSame(['+5511999998888'], Telefone::variantes('+5511999998888'));
    }
}
