<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use Dono\GiftAid\GiftAidClaims;
use PHPUnit\Framework\TestCase;

/**
 * HMRC matches a claim on house name-or-number plus postcode, and the address
 * we hold is one free-text line. Getting this wrong silently mismatches a claim
 * against HMRC's records, so the shapes UK addresses actually come in are
 * pinned here rather than assumed.
 */
final class GiftAidHouseTest extends TestCase
{
    /**
     * @dataProvider addresses
     */
    public function test_the_house_is_taken_from_the_address_line(string $line1, string $expected): void
    {
        $this->assertSame($expected, GiftAidClaims::houseFrom($line1));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function addresses(): array
    {
        return [
            'plain number'      => ['14 Acacia Avenue', '14'],
            'flat and number'   => ['Flat 2, 14 Acacia Avenue', 'Flat 2, 14'],
            'number with slash' => ['14/2 Acacia Avenue', '14/2'],
            'number range'      => ['14-16 Acacia Avenue', '14-16'],
            'letter suffix'     => ['14a Acacia Avenue', '14a'],
            'named house'       => ['Rosecroft, Acacia Avenue', 'Rosecroft'],
            'named house alone' => ['Rosecroft Acacia Avenue', 'Rosecroft Acacia Avenue'],
            'apartment letter'  => ['Apartment 5B, The Mill', 'Apartment 5B'],
            'leading space'     => ['  14 Acacia Avenue', '14'],
            'empty'             => ['', ''],
        ];
    }
}
