<?php

declare(strict_types=1);

namespace Dono\Webhooks;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Incoming webhook delivery log. Backs dedup, raw-payload replay, and
 * missing-donation debugging.
 *
 * @since 1.0.0
 */
final class WebhookLog extends Model
{
    protected string $table = 'dono_webhooks_log';
    protected string $version = '1.0.0';

    public int $id;
    public string $gateway;
    public string $external_id;
    public string $event_type;
    public bool $signature_ok;
    public string $payload;
    public ?array $headers = null;
    public bool $processed = false;
    public ?string $processed_at = null;
    public ?string $error = null;
    public string $received_at;
}

WebhookLog::schema(function (Table $t): void {
    $t->id();
    $t->string('gateway', 32);
    $t->string('external_id', 128);
    $t->string('event_type', 64);
    $t->boolean('signature_ok');
    $t->longText('payload');
    $t->json('headers')->nullable();
    $t->boolean('processed')->default(0);
    $t->datetime('processed_at')->nullable();
    $t->text('error')->nullable();
    $t->datetime('received_at');

    // Dedup: gateways resend the same event freely.
    $t->unique(['gateway', 'external_id']);
    // Reprocessing queue: unprocessed webhooks, oldest first.
    $t->index(['processed', 'received_at']);
});
