<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Plugin;

/**
 * Locks the create-drawer-facing behaviour added to CampaignService::create():
 * default_fund_id + image_attachment_id now persist at create time, and
 * currency defaults to the org currency (not a hardcoded EUR) when the form
 * does not send one. Verified against the persisted row, not the return value
 * (dono_queryable_silent_write_failure.md).
 */
final class CampaignCreateTest extends IntegrationTestCase
{
    private function service(): CampaignService
    {
        return Plugin::instance()->container->get(CampaignService::class);
    }

    public function test_create_persists_fund_image_and_defaults_currency_to_org(): void
    {
        $imageId = (int) self::factory()->attachment->create_object('reef.jpg', 0, [
            'post_mime_type' => 'image/jpeg',
        ]);

        $created = $this->service()->create([
            'title'               => 'Reef Drive',
            'goal_type'           => 'donors',
            'goal_count'          => 300,
            'default_fund_id'     => 7,
            'image_attachment_id' => $imageId,
            // currency intentionally omitted
        ]);

        $row = Campaign::query()->where('id', $created->id)->get();

        $this->assertNotNull($row);
        $this->assertSame('Reef Drive', $row->title);
        $this->assertSame('donors', $row->goal_type);
        $this->assertSame(300, (int) $row->goal_count);
        $this->assertSame(7, (int) $row->default_fund_id);
        $this->assertSame($imageId, (int) $row->image_attachment_id);
        $this->assertSame(Money::defaultCurrency(), $row->currency);
    }

    public function test_create_without_fund_or_image_leaves_them_null(): void
    {
        $created = $this->service()->create(['title' => 'No Extras']);

        $row = Campaign::query()->where('id', $created->id)->get();

        $this->assertNotNull($row);
        $this->assertNull($row->default_fund_id);
        $this->assertNull($row->image_attachment_id);
    }

    public function test_create_rejects_a_non_image_attachment(): void
    {
        // A PDF/audio attachment must be rejected at create, mirroring update().
        $pdfId = (int) self::factory()->attachment->create_object('brief.pdf', 0, [
            'post_mime_type' => 'application/pdf',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create(['title' => 'Bad Image', 'image_attachment_id' => $pdfId]);
    }

    public function test_campaign_currency_is_locked_to_org_currency(): void
    {
        // Campaigns report in the single org currency; an explicit currency in
        // the payload is ignored on both create and update.
        $created = $this->service()->create(['title' => 'Forced', 'currency' => 'EUR']);
        $this->assertSame(Money::defaultCurrency(), $created->currency);

        $this->service()->update($created, ['currency' => 'GBP', 'title' => 'Forced 2']);
        $row = Campaign::query()->where('id', $created->id)->get();
        $this->assertSame(Money::defaultCurrency(), $row->currency);
    }
}
