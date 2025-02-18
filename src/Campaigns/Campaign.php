<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Campaigns\Styling\CampaignStyleResolver;
use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Fundraising campaign. Each campaign owns one public-facing WP page
 * (linked via `page_id`) and zero or more donation forms.
 *
 * @version 1.0.0
 */
final class Campaign extends Model
{
    protected string $table = 'dono_campaigns';
    protected string $version = '1.0.0';

    public int $id;
    public string $title;
    public string $slug;
    public ?string $description = null;
    public ?int $image_attachment_id = null;
    public string $status = 'draft';
    /**
     * Campaign-type discriminator. 'standard' by default. VARCHAR so add-ons
     * can register new types without a schema change.
     */
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
     * Campaign-level styling choice. Shape:
     *   - null / [] : use the org default preset
     *   - ['preset_id' => '<id>']                       : pick a named brand preset
     *   - ['preset_id' => '<id>', 'tokens' => [...]]    : preset + inline overrides
     *
     * Effective tokens for a render are resolved by CampaignStyleResolver.
     */
    public ?array $style = null;

    /** Hide the theme's header/footer on this campaign's public pages. */
    public bool $hide_header = false;
    public bool $hide_footer = false;

    public string $created_at;
    public string $updated_at;

    /**
     * Resolved accent color for this campaign. Used by server-rendered campaign
     * blocks (donate-button, hero, progress, top-donors, etc.) that need a
     * single colour rather than the full token map.
     */
    public function accentColor(): string
    {
        return (new CampaignStyleResolver())->accentFor($this);
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
    $t->boolean('hide_header')->default(0);
    $t->boolean('hide_footer')->default(0);
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->unique(['slug']);
    $t->index(['status', 'ends_at']);
});
