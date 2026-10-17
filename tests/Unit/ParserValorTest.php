<?php

namespace Tests\Unit;

use App\Support\ParserValor;
use PHPUnit\Framework\TestCase;

class ParserValorTest extends TestCase
{
    public function test_converte_formatos_brasileiros_e_numeros(): void
    {
        $this->assertSame('1234.56', ParserValor::converter('1.234,56'));
        $this->assertSame('150.00', ParserValor::converter('150'));
        $this->assertSame('99.90', ParserValor::converter('R$ 99,9'));
        $this->assertSame('1500.00', ParserValor::converter('1.500'));
        $this->assertSame('10.50', ParserValor::converter('10.5'));
        $this->assertSame('150.50', ParserValor::converter(150.5));
        $this->assertSame('200.00', ParserValor::converter(200));
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(ParserValor::converter('abc'));
        $this->assertNull(ParserValor::converter('-5'));
        $this->assertNull(ParserValor::converter(0));
        $this->assertNull(ParserValor::converter(null));
        $this->assertNull(ParserValor::converter(''));
    }
}
