<?php

namespace Tests\Unit;

use App\Support\ParserData;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ParserDataTest extends TestCase
{
    public function test_converte_formatos_aceitos(): void
    {
        $this->assertSame('2026-10-23', ParserData::converter('23/10/2026')->toDateString());
        $this->assertSame('2026-01-05', ParserData::converter('5/1/2026')->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter('2026-10-23')->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter(new DateTimeImmutable('2026-10-23 15:00'))->toDateString());
        $this->assertSame('2026-10-23', ParserData::converter(46318)->toDateString()); // número de série do Excel
    }

    public function test_rejeita_invalidos(): void
    {
        $this->assertNull(ParserData::converter('31/02/2026'));
        $this->assertNull(ParserData::converter('amanhã'));
        $this->assertNull(ParserData::converter('23/10/26'));
        $this->assertNull(ParserData::converter(null));
        $this->assertNull(ParserData::converter(''));
    }
}
