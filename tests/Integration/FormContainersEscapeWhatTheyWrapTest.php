<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;

/**
 * A container block wraps whatever sits between its delimiters in storage. The
 * donation form runs its whole body through the form's filter, but anywhere
 * else do_blocks meets a container, a post or pattern holding form markup, only
 * the container's callback stands between that markup and the page. The filter
 * it applies has to let every field through untouched, or a donation form
 * quietly loses a control.
 */
final class FormContainersEscapeWhatTheyWrapTest extends IntegrationTestCase
{
    /** @return array<string, array{string}> */
    public static function containers(): array
    {
        return [
            'row'     => ['gratora/row {"columns":2}'],
            'columns' => ['gratora/columns {"columns":2}'],
            'section' => ['gratora/section'],
            'step'    => ['gratora/step'],
            'steps'   => ['gratora/steps'],
        ];
    }

    /** @dataProvider containers */
    public function test_raw_markup_stored_inside_a_container_renders_without_its_handler_or_script(string $opener): void
    {
        $name = strtok($opener, ' ');

        $html = do_blocks(
            '<!-- wp:' . $opener . ' -->'
            . '<img src="x" onerror="window.gratoraProbe=1">'
            . '<script>window.gratoraProbe=2</script>'
            . '<!-- wp:gratora/name /-->'
            . '<!-- /wp:' . $name . ' -->'
        );

        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="profile\[first_name\]"[^>]*required/', $html, 'the field inside the container must still render');
    }

    public function test_every_field_keeps_its_controls_and_attributes_through_nested_containers(): void
    {
        update_option('gratora_privacy', ['privacy_policy_url' => 'https://example.org/privacy']);

        $fields = $this->everyField();
        $bare   = do_blocks($fields);

        $nested = do_blocks(
            '<!-- wp:gratora/steps --><!-- wp:gratora/step {"title":"Give"} -->'
            . '<!-- wp:gratora/section {"background":"rgba(12,34,56,0.5)","shadow":"0 4px 12px rgba(0,0,0,0.1)","padding":{"top":8,"right":0,"bottom":0,"left":0}} -->'
            . '<!-- wp:gratora/row {"columns":2,"gap":12,"gapUnit":"px"} -->'
            . $fields
            . '<!-- /wp:gratora/row -->'
            . '<!-- /wp:gratora/section -->'
            . '<!-- /wp:gratora/step --><!-- /wp:gratora/steps -->'
        );

        $bareTokens   = $this->tagTokens($bare);
        $nestedTokens = $this->tagTokens($nested);

        $this->assertGreaterThan(100, count($bareTokens), 'fixture: the fields rendered');
        $this->assertSame(
            $bareTokens,
            array_slice($nestedTokens, 4, count($bareTokens)),
            'a container changed an element or attribute of a field it wraps'
        );

        $this->assertMatchesRegularExpression('/<button[^>]*data-cents="1000"[^>]*role="radio"[^>]*aria-checked="true"/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*step="0.01"[^>]*min="0.5"[^>]*inputmode="decimal"/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="amount_cents"[^>]*value="1000"/', $nested);
        $this->assertMatchesRegularExpression('/<select[^>]*name="custom\[shirt\]"[^>]*required/', $nested);
        $this->assertMatchesRegularExpression('/<option[^>]*value="large"[^>]*selected/', $nested);
        $this->assertMatchesRegularExpression('/<select[^>]*name="currency"[^>]*aria-label="Currency"/', $nested);
        $this->assertMatchesRegularExpression('/<span[^>]*role="radiogroup"[^>]*aria-label="Currency"/', $nested);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="note_to_org"[^>]*placeholder="[^"]+"[^>]*maxlength="5000"[^>]*required/', $nested);
        $this->assertMatchesRegularExpression('/<fieldset[^>]*data-min="1"[^>]*data-max="2"/', $nested);
        $this->assertMatchesRegularExpression('/<label[^>]*data-pct="2.9"[^>]*data-fixed="30"/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*name="custom\[code\]"[^>]*maxlength="8"[^>]*pattern="\[A-Z\]\{3\}\[0-9\]\+"/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*min="2026-01-01"[^>]*max="2027-12-31"/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="custom\[posted\]"[^>]*checked/', $nested);
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="custom\[size\]"[^>]*checked[^>]*required/', $nested);
        $this->assertMatchesRegularExpression('/<div[^>]*role="progressbar"[^>]*aria-valuemin="0"[^>]*aria-valuemax="100"[^>]*aria-valuenow="\d+"/', $nested);
        $this->assertMatchesRegularExpression('/<div[^>]*tabindex="0"[^>]*role="region"[^>]*aria-label="[^"]+"/', $nested);
        $this->assertMatchesRegularExpression('/<a[^>]*href="https:\/\/example.org\/privacy"[^>]*target="_blank"[^>]*rel="noopener noreferrer"/', $nested);
        $this->assertMatchesRegularExpression('/<button[^>]*type="button"[^>]*class="gratora-submit"[^>]*disabled/', $nested);

        $this->assertStringContainsString('background-color:rgba(12,34,56,0.5)', $nested, 'a Section colour must survive the containers around it');
        $this->assertStringContainsString('box-shadow:0 4px 12px rgba(0,0,0,0.1)', $nested);
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $nested);
        $this->assertStringContainsString('border-top:3px solid #cccccc', $nested);
        $this->assertMatchesRegularExpression('/<div class="gratora-goal__fill" style="width:\d+%"/', $nested);
    }

    private function everyField(): string
    {
        $campaign = Campaign::make();
        $campaign->title        = 'Container Goal';
        $campaign->slug         = 'container-goal-' . uniqid();
        $campaign->status       = 'published';
        $campaign->currency     = 'USD';
        $campaign->goal_type    = 'amount';
        $campaign->goal_cents   = 100000;
        $campaign->raised_cents = 25000;
        $campaign->created_at   = gmdate('Y-m-d H:i:s');
        $campaign->updated_at   = $campaign->created_at;
        $campaign->save();

        return '<!-- wp:gratora/heading {"text":"Your donation","level":3} /-->'
            . '<!-- wp:gratora/paragraph {"text":"Every <strong>donation</strong> helps."} /-->'
            . '<!-- wp:gratora/goal {"campaignId":' . (int) $campaign->id . '} /-->'
            . '<!-- wp:gratora/donation-amount {"presets":[{"cents":1000,"impact":"Feeds a family","preselected":true},{"cents":2500}],"allowCustom":true,"currency":"USD"} /-->'
            . '<!-- wp:gratora/currency-switcher {"currencies":["USD","EUR"],"style":"dropdown"} /-->'
            . '<!-- wp:gratora/currency-switcher {"currencies":["USD","EUR"],"style":"pills"} /-->'
            . '<!-- wp:gratora/recurring-toggle {"helpText":"Monthly helps most"} /-->'
            . '<!-- wp:gratora/cover-fees {"percent":2.9,"fixed":30,"defaultOn":true} /-->'
            . '<!-- wp:gratora/fund-picker {"allowEmpty":true} /-->'
            . '<!-- wp:gratora/name {"firstPlaceholder":"Ada","lastPlaceholder":"Lovelace"} /-->'
            . '<!-- wp:gratora/email {"placeholder":"you@example.org"} /-->'
            . '<!-- wp:gratora/phone {"placeholder":"+1 555","required":true} /-->'
            . '<!-- wp:gratora/address /-->'
            . '<!-- wp:gratora/country {"required":true} /-->'
            . '<!-- wp:gratora/text-input {"label":"Code","field":"code","maxLength":8,"pattern":"[A-Z]{3}[0-9]+","placeholder":"ABC123","helpText":"From your letter","required":true} /-->'
            . '<!-- wp:gratora/number-input {"label":"Guests","field":"guests","min":1,"max":10,"step":1,"placeholder":"2"} /-->'
            . '<!-- wp:gratora/date {"label":"When","field":"when","minDate":"2026-01-01","maxDate":"2027-12-31"} /-->'
            . '<!-- wp:gratora/dropdown {"label":"Shirt","field":"shirt","placeholder":"Pick one","options":[{"label":"Small"},{"label":"Large","isDefault":true}],"required":true} /-->'
            . '<!-- wp:gratora/radio {"label":"Size","field":"size","options":[{"label":"Small","isDefault":true},{"label":"Large"}],"layout":"horizontal","required":true} /-->'
            . '<!-- wp:gratora/multi-select {"label":"Colours","field":"colours","options":[{"label":"Red"},{"label":"Blue","isDefault":true}],"required":true,"minSelections":1,"maxSelections":2} /-->'
            . '<!-- wp:gratora/checkbox {"label":"Keep me posted","field":"posted","defaultOn":true,"helpText":"Now and then"} /-->'
            . '<!-- wp:gratora/comment {"required":true} /-->'
            . '<!-- wp:gratora/anonymous-toggle {"defaultOn":true} /-->'
            . '<!-- wp:gratora/divider {"marginTop":40,"marginBottom":8,"thickness":3,"color":"#cccccc"} /-->'
            . '<!-- wp:gratora/html {"content":"<p class=\"lead\">Thank you</p>"} /-->'
            . '<!-- wp:gratora/terms {"terms":"Be kind to each other.","linkUrl":"https://example.org/terms"} /-->'
            . '<!-- wp:gratora/privacy-notice /-->'
            . '<!-- wp:gratora/payment-gateways /-->'
            . '<!-- wp:gratora/donation-summary {"showGateway":false} /-->'
            . '<!-- wp:gratora/submit-button {"label":"Give","align":"full"} /-->';
    }
}
