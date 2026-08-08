<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Forms\Blocks\BlockRegistry;
use Dono\Forms\FormTemplates;

/**
 * A template is a claim about which fields suit a situation, and the claim is
 * what breaks: a block gets renamed, an add-on's field is borrowed, a copied
 * template keeps a field that made sense on the one it came from.
 */
final class FormTemplateFieldsTest extends IntegrationTestCase
{
    /** @return list<string> every dono/* block name a template uses */
    private function blocksIn(string $id): array
    {
        $t = FormTemplates::find($id);
        $this->assertNotNull($t, "template {$id} is not registered");

        preg_match_all('#wp:(dono/[a-z-]+)#', (string) $t['blocks'], $m);

        return $m[1];
    }

    private function registeredBlockNames(): array
    {
        $registry = Plugin::instance()->container->get(BlockRegistry::class);

        return array_map(static fn ($b): string => $b->name(), $registry->all());
    }

    /** @return list<string> */
    private function templateIds(): array
    {
        return array_map(static fn (array $t): string => $t['id'], FormTemplates::all());
    }

    public function test_every_template_is_findable_and_uniquely_named(): void
    {
        $ids = $this->templateIds();

        $this->assertSame(array_unique($ids), $ids, 'two templates share an id');
        foreach ($ids as $id) {
            $this->assertNotNull(FormTemplates::find($id), "find() cannot resolve {$id}");
        }
    }

    /**
     * The failure this guards against is a core template quietly depending on an
     * add-on: the block renders as nothing on a site without that add-on, and
     * the donor gets a form with a hole in it.
     */
    public function test_no_template_uses_a_block_core_does_not_register(): void
    {
        $registered = $this->registeredBlockNames();

        foreach ($this->templateIds() as $id) {
            foreach (array_unique($this->blocksIn($id)) as $block) {
                $this->assertContains(
                    $block,
                    $registered,
                    "template {$id} uses {$block}, which core does not register"
                );
            }
        }
    }

    public function test_every_paying_template_can_actually_take_a_donation(): void
    {
        foreach ($this->templateIds() as $id) {
            if ($id === 'blank') {
                continue;
            }
            $blocks = $this->blocksIn($id);

            $this->assertCount(1, array_keys($blocks, 'dono/donation-amount', true), "{$id}: needs exactly one amount block");
            $this->assertCount(1, array_keys($blocks, 'dono/payment-gateways', true), "{$id}: needs exactly one gateway block");
            $this->assertCount(1, array_keys($blocks, 'dono/submit-button', true), "{$id}: needs exactly one submit button");
            $this->assertContains('dono/email', $blocks, "{$id}: a receipt needs an email address");
        }
    }

    /** The appeal is the designation; choosing a fund contradicts the ask. */
    public function test_the_emergency_appeal_offers_no_fund_choice(): void
    {
        $this->assertNotContains('dono/fund-picker', $this->blocksIn('emergency-appeal'));
    }

    /** Without a fund picker it is just the everyday form with a longer name. */
    public function test_designated_giving_lets_the_donor_pick_a_fund(): void
    {
        $this->assertContains('dono/fund-picker', $this->blocksIn('designated'));
    }

    /**
     * A sustainer form that defaults to one-time is an everyday form. The
     * frequency is the template, so it is asserted rather than left to copy.
     */
    public function test_the_sustainer_preselects_a_recurring_frequency(): void
    {
        $t = FormTemplates::find('monthly-sustainer');

        $this->assertStringContainsString('"defaultFrequency":"monthly"', (string) $t['blocks']);
        $this->assertStringNotContainsString('one-time', (string) $t['blocks']);
        $this->assertNotContains('one_time', $t['settings']['recurring']['frequencies'] ?? []);
    }

    /**
     * Both fields promise a public supporter list. On a form with no such list a
     * message goes nowhere and hiding a name hides it from nobody.
     */
    public function test_supporter_wall_fields_only_appear_beside_a_goal(): void
    {
        foreach ($this->templateIds() as $id) {
            $blocks = $this->blocksIn($id);
            $social = in_array('dono/comment', $blocks, true)
                || in_array('dono/anonymous-toggle', $blocks, true);

            if (! $social) {
                continue;
            }

            $this->assertContains(
                'dono/goal',
                $blocks,
                "{$id}: carries a message or anonymity field but shows no public campaign progress"
            );
        }
    }

    /** Speed is the whole template; every field on it has to earn its place. */
    public function test_quick_give_stays_quick(): void
    {
        $blocks = $this->blocksIn('quick-give');

        $this->assertNotContains('dono/phone', $blocks);
        $this->assertNotContains('dono/address', $blocks);
        $this->assertNotContains('dono/comment', $blocks);
        $this->assertLessThan(
            count($this->blocksIn('everyday')) + 1,
            count($blocks),
            'quick give should not be longer than the everyday form'
        );
    }
}
