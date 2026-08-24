<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Foundation;

use Dono\Foundation\License\LicenseRefusals;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dono\Foundation\License\LicenseRefusals
 */
final class LicenseRefusalsTest extends TestCase
{
    /** @return array<int,array{id:string,name:string,status:string,entitled:bool}> */
    private function refused(array ...$pairs): array
    {
        $out = [];
        foreach ($pairs as [$name, $status]) {
            $out[] = ['id' => strtolower($name), 'name' => $name, 'status' => $status, 'entitled' => false];
        }

        return $out;
    }

    public function test_a_mistyped_key_is_not_reported_as_a_plan_that_excludes_the_addon(): void
    {
        $groups = LicenseRefusals::group($this->refused(['Dono Events', 'invalid']));

        $this->assertCount(1, $groups);
        $this->assertStringContainsString('not recognised', $groups[0]['headline']);
        $this->assertStringNotContainsString('does not cover', $groups[0]['headline']);
    }

    public function test_a_site_out_of_seats_is_told_freeing_one_is_enough(): void
    {
        $groups = LicenseRefusals::group($this->refused(['Dono Events', 'over_limit']));

        $this->assertStringContainsString('no sites left', $groups[0]['headline']);
        $this->assertStringNotContainsString('does not cover', $groups[0]['headline']);
        $this->assertStringContainsString('Deactivate', $groups[0]['detail']);
    }

    public function test_a_product_outside_the_plan_still_says_so(): void
    {
        $groups = LicenseRefusals::group($this->refused(['Dono Events', 'not_entitled']));

        $this->assertStringContainsString('does not cover', $groups[0]['headline']);
    }

    public function test_a_revoked_licence_is_named_as_revoked(): void
    {
        $groups = LicenseRefusals::group($this->refused(['Dono Events', 'revoked']));

        $this->assertStringContainsString('revoked', $groups[0]['headline']);
    }

    public function test_each_refusal_reason_gets_its_own_message(): void
    {
        $groups = LicenseRefusals::group($this->refused(
            ['Dono Events', 'not_entitled'],
            ['Dono Tributes', 'over_limit'],
            ['Dono Gift Aid', 'not_entitled'],
        ));

        $this->assertCount(2, $groups, 'two distinct reasons, two messages');

        $byStatus = array_column($groups, 'names', 'status');
        $this->assertSame('Dono Events, Dono Gift Aid', $byStatus['not_entitled']);
        $this->assertSame('Dono Tributes', $byStatus['over_limit']);
    }

    public function test_the_reason_the_admin_can_act_on_is_listed_first(): void
    {
        $groups = LicenseRefusals::group($this->refused(
            ['Dono Events', 'revoked'],
            ['Dono Tributes', 'invalid'],
        ));

        $this->assertSame('invalid', $groups[0]['status']);
    }

    public function test_an_unrecognised_status_still_produces_a_message(): void
    {
        $groups = LicenseRefusals::group($this->refused(['Dono Events', 'something_new']));

        $this->assertCount(1, $groups);
        $this->assertNotSame('', $groups[0]['headline']);
        $this->assertStringContainsString('Dono Events', $groups[0]['headline']);
    }
}
