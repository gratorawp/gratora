<?php

declare(strict_types=1);

namespace FundKit\Campaigns;

defined('ABSPATH') || exit;

use FundKit\Campaigns\Styling\CampaignStyleResolver;
use FundKit\Foundation\Time\ScheduleWindow;
use FundKit\Vendor\Queryable\Model;
use FundKit\Vendor\Queryable\Schema\Table;

/**
 * Owns one WP page and zero or more forms.
 *
 * @since 1.0.0
 */
final class Campaign extends Model
{
    protected string $table = 'fundkit_campaigns';
    protected string $version = '1.0.0';

    public int $id;
    public string $title;
    public string $slug;
    public ?string $description = null;
    public ?int $image_attachment_id = null;
    public string $status = 'draft';
    /** Varchar allows add-on types without migrations. */
    public string $campaign_type = 'standard';
    public string $goal_type = 'amount';
    public ?int $goal_cents = null;
    public ?int $goal_count = null;
    public string $currency = 'USD';
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public int $raised_cents = 0;
    public int $donations_count = 0;
    public int $donors_count = 0;
    public ?int $page_id = null;
    public ?int $default_form_id = null;
    public ?int $default_fund_id = null;
    public ?array $default_amount_presets = null;

    /**
     * Resolved by CampaignStyleResolver: null/[] = org default preset;
     * ['preset_id' => id] picks a brand preset, optionally with 'tokens' inline overrides.
     */
    public ?array $style = null;

    public bool $close_at_goal = false;

    public bool $hide_header = false;
    public bool $hide_footer = false;

    public string $created_at;
    public string $updated_at;

    /** @since 1.0.0 */
    public function accentColor(): string
    {
        return (new CampaignStyleResolver())->accentFor($this);
    }

    /**
     * $now is a UTC stamp.
     *
     * @since 1.0.0
     */
    public function acceptsDonations(?string $now = null): bool
    {
        return $this->notAcceptingReason($now) === null;
    }

    /**
     * @return null|'draft'|'archived'|'scheduled'|'ended'|'goal_met'
     *
     * @since 1.0.0
     */
    public function notAcceptingReason(?string $now = null): ?string
    {
        if ($this->status === 'archived')  return 'archived';
        if ($this->status !== 'published') return 'draft';

        $now ??= gmdate('Y-m-d H:i:s');

        $starts = $this->startsAtUtc();
        if ($starts !== null && $starts > $now) return 'scheduled';

        $ends = $this->endsAtUtc();
        if ($ends !== null && $ends < $now) return 'ended';

        if ($this->close_at_goal && $this->goalMet()) return 'goal_met';

        return null;
    }

    /**
     * Whether the campaign has reached the target its goal_type measures.
     *
     * A goal of null or zero is not a goal, and must never read as met: it is
     * what every campaign without one holds, and treating it as reached would
     * close them all on their first donation.
     *
     * @since 1.0.0
     */
    public function goalMet(): bool
    {
        return match ($this->goal_type) {
            'donations' => $this->goal_count !== null && $this->goal_count > 0
                && $this->donations_count >= $this->goal_count,
            'donors'    => $this->goal_count !== null && $this->goal_count > 0
                && $this->donors_count >= $this->goal_count,
            default     => $this->goal_cents !== null && $this->goal_cents > 0
                && $this->raised_cents >= $this->goal_cents,
        };
    }

    /**
     * UTC opening instant, or null without a start date.
     *
     * @since 1.0.0
     */
    public function startsAtUtc(): ?string
    {
        return ScheduleWindow::startsAtUtc($this->starts_at);
    }

    /**
     * The last instant the campaign is open, as UTC, or null when it has no end
     * date. Anything measuring "days left" against the clock belongs here too,
     * or it counts the remaining days of a different timezone's calendar.
     *
     * @since 1.0.0
     */
    public function endsAtUtc(): ?string
    {
        return ScheduleWindow::endsAtUtc($this->ends_at);
    }
}

Campaign::schema(function (Table $t): void {
    $t->id();
    $t->string('title', 200);
    $t->string('slug', 200);
    $t->longText('description')->nullable();
    $t->bigInteger('image_attachment_id')->unsigned()->nullable();
    $t->string('status', 20)->default('draft');
    $t->string('campaign_type', 32)->default('standard')->index();
    $t->string('goal_type', 20)->default('amount');
    $t->bigInteger('goal_cents')->unsigned()->nullable();
    $t->integer('goal_count')->unsigned()->nullable();
    $t->string('currency', 3)->default('USD');
    $t->datetime('starts_at')->nullable();
    $t->datetime('ends_at')->nullable();
    $t->bigInteger('raised_cents')->unsigned()->default(0);
    $t->integer('donations_count')->unsigned()->default(0);
    $t->integer('donors_count')->unsigned()->default(0);
    $t->bigInteger('page_id')->unsigned()->nullable()->index();
    $t->bigInteger('default_form_id')->unsigned()->nullable()->index();
    $t->bigInteger('default_fund_id')->unsigned()->nullable()->index();
    $t->json('default_amount_presets')->nullable();
    $t->json('style')->nullable();
    $t->boolean('close_at_goal')->default(0);
    $t->boolean('hide_header')->default(0);
    $t->boolean('hide_footer')->default(0);
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->unique(['slug']);
    $t->index(['status', 'ends_at']);
});
