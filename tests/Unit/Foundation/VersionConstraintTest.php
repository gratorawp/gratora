<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\Modules\VersionConstraint;
use PHPUnit\Framework\TestCase;

final class VersionConstraintTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function test_satisfies(string $version, string $constraint, bool $expected): void
    {
        $this->assertSame(
            $expected,
            VersionConstraint::satisfies($version, $constraint),
            "{$version} against {$constraint}"
        );
    }

    /** @return array<string, array{0:string,1:string,2:bool}> */
    public static function cases(): array
    {
        return [
            // Acceptance criteria (ticket A1).
            'caret minor-locked, below'      => ['0.1.0', '^0.6', false],
            'caret minor-locked, in range'   => ['0.6.3', '^0.6', true],
            'gte lower bound met'            => ['0.6.0', '>=0.6', true],
            'range below lower bound'        => ['0.5.9', '0.6 - 0.9', false],
            'caret 0.x rejects 1.0'          => ['1.0.0', '^0.6', false],

            // Caret further coverage.
            'caret patch floor, below patch' => ['0.6.2', '^0.6.3', false],
            'caret patch floor, at patch'    => ['0.6.3', '^0.6.3', true],
            'caret 0.x ceiling exclusive'    => ['0.7.0', '^0.6', false],
            'caret >=1 locks major, in'      => ['1.9.9', '^1.2', true],
            'caret >=1 locks major, out'     => ['2.0.0', '^1.2', false],

            // Tilde.
            'tilde allows patch'             => ['0.6.9', '~0.6', true],
            'tilde rejects next minor'       => ['0.7.0', '~0.6', false],
            'tilde with patch floor'         => ['1.2.2', '~1.2.3', false],

            // Lower bound.
            'gte above'                      => ['1.4.0', '>=0.6', true],
            'gte below'                      => ['0.5.0', '>=0.6', false],

            // Range (inclusive lower, partial upper allows any patch).
            'range within'                   => ['0.7.5', '0.6 - 0.9', true],
            'range at upper partial'         => ['0.9.9', '0.6 - 0.9', true],
            'range above upper'              => ['0.10.0', '0.6 - 0.9', false],

            // Exact.
            'exact match'                    => ['0.6.0', '0.6.0', true],
            'exact mismatch'                 => ['0.6.1', '0.6.0', false],
        ];
    }
}
