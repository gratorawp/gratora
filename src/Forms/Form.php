<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Donation form definition.
 *
 * Every form lives under a campaign. Deleting the campaign deletes the forms;
 * there is no detached / orphan state.
 *
 * @version 1.0.0
 */
final class Form extends Model
{
    protected string $table = 'dono_forms';
    protected string $version = '1.0.0';

    /** Model relations. */
    protected function relations(): array
    {
        return [
            'donation_stats' => [
                'type'       => 'hasOne',
                'table'      => 'dono_form_donation_stats',
                'primaryKey' => 'id',
                'foreignKey' => 'form_id',
            ],
        ];
    }

    public int $id;
    public string $title;
    public string $slug;
    public string $status = 'draft';
    public string $form_type = 'donation';
    public string $blocks = '';
    public ?array $spec = null;
    public int $spec_version = 1;
    public ?array $settings = null;
    public int $campaign_id;
    public ?int $default_fund_id = null;
    public ?int $author_id = null;
    public ?string $published_at = null;
    public ?string $archived_at = null;
    public string $created_at;
    public string $updated_at;
}

Form::schema(function (Table $t): void {
    $t->id();
    $t->string('title', 200);
    $t->string('slug', 200);
    $t->string('status', 20)->default('draft');
    $t->string('form_type', 32)->default('donation')->index();
    $t->longText('blocks');
    $t->json('spec')->nullable();
    $t->integer('spec_version')->unsigned()->default(1);
    $t->json('settings')->nullable();
    $t->bigInteger('campaign_id')->unsigned()->index();
    $t->bigInteger('default_fund_id')->unsigned()->nullable()->index();
    $t->bigInteger('author_id')->unsigned()->nullable();
    $t->datetime('published_at')->nullable();
    $t->datetime('archived_at')->nullable();
    $t->datetime('created_at');
    $t->datetime('updated_at');
    $t->unique(['slug']);
    $t->index(['status', 'updated_at']);
});
