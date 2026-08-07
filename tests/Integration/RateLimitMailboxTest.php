<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Plugin;

/**
 * The mailbox an address reaches, used to rate-limit outbound mail.
 *
 * Anything that mails on demand can be pointed at somebody else's inbox, and a
 * per-address limit does not stop that: one inbox answers to unlimited
 * addresses, because every plus tag is a distinct address and on some providers
 * so is every placement of a dot.
 */
final class RateLimitMailboxTest extends IntegrationTestCase
{
    private function hasher(): IdentityHasher
    {
        return Plugin::instance()->container->get(IdentityHasher::class);
    }

    private function mailbox(string $email): string
    {
        return $this->hasher()->rateLimitMailbox($email);
    }

    public function test_plus_tags_collapse_to_one_mailbox(): void
    {
        $this->assertSame($this->mailbox('victim@example.com'), $this->mailbox('victim+1@example.com'));
        $this->assertSame($this->mailbox('victim+1@example.com'), $this->mailbox('victim+2@example.com'));
    }

    public function test_gmail_dots_and_aliases_collapse(): void
    {
        $this->assertSame($this->mailbox('firstlast@gmail.com'), $this->mailbox('first.last@gmail.com'));
        $this->assertSame($this->mailbox('firstlast@gmail.com'), $this->mailbox('first.last+charity@googlemail.com'));
    }

    /** Dots are significant almost everywhere else. */
    public function test_dots_are_kept_outside_gmail(): void
    {
        $this->assertNotSame($this->mailbox('first.last@example.com'), $this->mailbox('firstlast@example.com'));
    }

    public function test_distinct_people_stay_distinct(): void
    {
        $this->assertNotSame($this->mailbox('ada@example.com'), $this->mailbox('grace@example.com'));
        $this->assertNotSame($this->mailbox('ada@example.com'), $this->mailbox('ada@example.org'));
    }

    /**
     * Identity must not move. emailHash is the UNIQUE key on the donor table and
     * the handle erasure severs, so collapsing addresses there would merge two
     * people's giving history into one record.
     */
    public function test_identity_is_untouched_by_the_rate_limit_rule(): void
    {
        $this->assertNotSame(
            $this->hasher()->emailHash('victim@gmail.com'),
            $this->hasher()->emailHash('victim+charity@gmail.com'),
            'two addresses are still two donors'
        );
        $this->assertSame('victim+charity@gmail.com', $this->hasher()->normalizeEmail('Victim+Charity@Gmail.com'));
    }
}
