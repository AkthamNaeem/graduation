<?php

namespace Tests\Unit;

use App\Support\NameInitials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameInitialsTest extends TestCase
{
    #[DataProvider('names')]
    public function test_it_generates_safe_initials(?string $name, string $expected): void
    {
        $this->assertSame($expected, NameInitials::from($name));
    }

    /** @return array<string, array{0: string|null, 1: string}> */
    public static function names(): array
    {
        return [
            'arabic' => ['أكثم نعيم', 'أ ن'],
            'latin' => ['John Doe', 'J D'],
            'single' => ['SingleName', 'S'],
            'repeated whitespace' => ['  John   Doe  ', 'J D'],
            'empty' => ['', '?'],
            'null' => [null, '?'],
        ];
    }
}
