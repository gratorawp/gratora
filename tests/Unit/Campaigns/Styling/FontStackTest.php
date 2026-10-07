<?php

declare(strict_types=1);

namespace Gratora\Tests\Unit\Campaigns\Styling;

use Gratora\Campaigns\Styling\FontStack;
use PHPUnit\Framework\TestCase;

/**
 * A font stack is written into a style attribute. WordPress before 7.0 filters
 * that attribute while its quotes are still entities and cuts the declaration
 * at the first one, so a family is written without quotes wherever CSS reads it
 * the same.
 */
final class FontStackTest extends TestCase
{
    /** @return array<string, array{0:string, 1:string}> */
    public function stacksThatNeedNoQuotes(): array
    {
        return [
            'the default'             => ['system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif'],
            'single quotes'           => ["'Helvetica Neue', Arial, sans-serif", 'Helvetica Neue, Arial, sans-serif'],
            'three words'             => ['"Times New Roman", serif', 'Times New Roman, serif'],
            'nothing quoted'          => ['Georgia, serif', 'Georgia, serif'],
            'spaces a person typed'   => ['  "Segoe   UI" ,Roboto ', 'Segoe UI, Roboto'],
            'an empty place'          => ['Inter, , sans-serif', 'Inter, sans-serif'],
        ];
    }

    /** @dataProvider stacksThatNeedNoQuotes */
    public function test_a_family_css_reads_the_same_without_quotes_loses_them(string $stack, string $written): void
    {
        $this->assertSame($written, FontStack::forAttribute($stack, true));
        $this->assertSame($written, FontStack::forAttribute($stack, false));
    }

    // A word that starts with a digit is not a CSS identifier, so the name only reads as one family inside quotes.
    public function test_a_family_that_needs_its_quotes_keeps_them_where_they_survive(): void
    {
        $this->assertSame('"Source Sans 3", sans-serif', FontStack::forAttribute('"Source Sans 3", sans-serif', true));
        $this->assertSame('"Exo 2", Arial', FontStack::forAttribute("'Exo 2', Arial", true));
    }

    public function test_where_they_do_not_it_is_left_out_and_the_rest_of_the_stack_stands(): void
    {
        $this->assertSame('sans-serif', FontStack::forAttribute('"Source Sans 3", sans-serif', false));
        $this->assertSame('', FontStack::forAttribute('"Exo 2"', false));
    }
}
