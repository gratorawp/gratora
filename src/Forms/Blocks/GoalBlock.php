<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Campaigns\CampaignRepository;
use Dono\Forms\FormRepository;
use Dono\Foundation\Helpers\View;
use Dono\Vendor\Queryable\DB;

/**
 * Goal progress block. Display only: shows the form's own goal or the
 * parent campaign's goal; the goal itself is never configured on the block.
 *
 * @version 1.0.0
 */
final class GoalBlock implements Block
{
    /** Inject campaign and form repositories. */
    public function __construct(
        private CampaignRepository $campaigns,
        private FormRepository $forms,
    ) {
    }

    /** Block name. */
    public function name(): string
    {
        return 'dono/goal';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'source'       => ['type' => 'string',  'default' => 'campaign'],
            'showAmount'   => ['type' => 'boolean', 'default' => true],
            'showDonors'   => ['type' => 'boolean', 'default' => true],
            'showDeadline' => ['type' => 'boolean', 'default' => false],
            'campaignId'   => ['type' => 'number',  'default' => 0],
            'formId'       => ['type' => 'number',  'default' => 0],
            'condition'    => ['type' => 'object',  'default' => null],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return (string) ($attrs['source'] ?? 'campaign') === 'form'
            ? $this->renderForm($attrs)
            : $this->renderCampaign($attrs);
    }

    /** Render against the linked campaign's goal. */
    private function renderCampaign(array $attrs): string
    {
        $campaignId = (int) ($attrs['campaignId'] ?? 0);
        $campaign   = $campaignId ? $this->campaigns->findById($campaignId) : null;
        if (! $campaign) {
            return $this->missing(__('Goal will appear once the form is linked to a campaign.', 'dono'));
        }

        $type = (string) ($campaign->goal_type ?: 'amount');
        if (! in_array($type, ['amount', 'donations', 'donors'], true)) {
            $type = 'amount';
        }
        $target  = $type === 'amount'
            ? (int) ($campaign->goal_cents ?? 0)
            : (int) ($campaign->goal_count ?? 0);
        if ($target <= 0) {
            return $this->missing(__('The campaign has no goal set yet.', 'dono'));
        }
        $current = match ($type) {
            'donations' => (int) ($campaign->donations_count ?? 0),
            'donors'    => (int) ($campaign->donors_count ?? 0),
            default     => (int) ($campaign->raised_cents ?? 0),
        };

        return $this->view(
            goalType:    $type,
            current:     $current,
            target:      $target,
            currency:    (string) $campaign->currency,
            donorsCount: (int) ($campaign->donors_count ?? 0),
            endsAt:      $campaign->ends_at,
            attrs:       $attrs,
        );
    }

    /** Render against the form's own configured goal. */
    private function renderForm(array $attrs): string
    {
        $formId = (int) ($attrs['formId'] ?? 0);
        $form   = $formId ? $this->forms->findById($formId) : null;
        if (! $form) {
            return $this->missing(__('Goal will appear once the form is published.', 'dono'));
        }

        $settings = is_array($form->settings) ? $form->settings : [];
        $goal     = is_array($settings['goal'] ?? null) ? $settings['goal'] : [];
        $type     = (string) ($goal['type'] ?? 'none');
        if (! in_array($type, ['amount', 'donations', 'donors'], true)) {
            return $this->missing(__('This form has no goal set. Add one in the form settings.', 'dono'));
        }
        $target = $type === 'amount'
            ? (int) ($goal['amount_cents'] ?? 0)
            : (int) ($goal['count'] ?? 0);
        if ($target <= 0) {
            return $this->missing(__('This form has no goal set. Add one in the form settings.', 'dono'));
        }

        $stats = DB::table('dono_form_donation_stats')
            ->where('form_id', $formId)
            ->get();
        $raisedCents    = is_array($stats) ? (int) ($stats['raised_cents'] ?? 0) : 0;
        $donorsCount    = is_array($stats) ? (int) ($stats['donors_count'] ?? 0) : 0;
        $donationsCount = is_array($stats) ? (int) ($stats['donations_count'] ?? 0) : 0;
        $current = match ($type) {
            'donations' => $donationsCount,
            'donors'    => $donorsCount,
            default     => $raisedCents,
        };

        $campaign = $form->campaign_id ? $this->campaigns->findById((int) $form->campaign_id) : null;
        $currency = $campaign
            ? (string) $campaign->currency
            : (string) (get_option('dono_default_currency') ?: 'USD');

        return $this->view(
            goalType:    $type,
            current:     $current,
            target:      $target,
            currency:    $currency,
            donorsCount: $donorsCount,
            endsAt:      null,
            attrs:       $attrs,
        );
    }

    /** Compute the percentage and render the goal view. */
    private function view(
        string $goalType,
        int $current,
        int $target,
        string $currency,
        int $donorsCount,
        ?string $endsAt,
        array $attrs,
    ): string {
        $percent = $target > 0 ? min(100, (int) round(($current / $target) * 100)) : 0;

        return View::loadRelative(__DIR__, 'views/goal', [
            'goalType'     => $goalType,
            'current'      => $current,
            'target'       => $target,
            'percent'      => $percent,
            'currency'     => $currency,
            'donorsCount'  => $donorsCount,
            'endsAt'       => $endsAt,
            'showAmount'   => (bool) ($attrs['showAmount']   ?? true),
            'showDonors'   => (bool) ($attrs['showDonors']   ?? true),
            'showDeadline' => (bool) ($attrs['showDeadline'] ?? false),
        ]);
    }

    /** Fallback markup shown when no goal can be resolved. */
    private function missing(string $message): string
    {
        // Setup hints are for the admin building the form; public donors see
        // nothing rather than an editor instruction.
        if (! (is_user_logged_in() && current_user_can('edit_posts'))) {
            return '';
        }
        return '<div class="dono-block dono-block--goal dono-goal dono-goal--missing">'
             . esc_html($message)
             . '</div>';
    }
}
