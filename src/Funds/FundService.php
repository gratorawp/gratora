<?php

declare(strict_types=1);

namespace Gratora\Funds;

use Gratora\Async\AsyncDispatcher;
use Gratora\Campaigns\Campaign;
use Gratora\Donations\Donation;
use Gratora\Forms\Form;
use Gratora\Foundation\Time\Clock;
use Gratora\Recurring\RecurringPlan;
use Gratora\Vendor\Queryable\DB;
use InvalidArgumentException;
use RuntimeException;

/** @since 1.0.0 */
final class FundService
{
    /** @since 1.0.0 */
    public function __construct(
        private FundRepository $funds,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     *
     * @since 1.0.0
     */
    public function create(array $input): Fund
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException(esc_html__('A fund code is required.', 'gratora'));
        }
        if ($this->funds->codeExists($code)) {
            throw new InvalidArgumentException(esc_html__('Fund code is already in use.', 'gratora'));
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(esc_html__('A fund name is required.', 'gratora'));
        }

        $fund = Fund::make();
        $fund->code            = $code;
        $fund->name            = $name;
        $fund->description     = $this->nullableString($input['description'] ?? null);
        $fund->is_restricted   = (bool) ($input['is_restricted'] ?? false);
        $fund->is_default      = (bool) ($input['is_default'] ?? false);
        $fund->is_active       = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
        // The default takes every untagged donation, and an inactive fund is
        // offered to nobody. update() refuses this pairing too.
        if ($fund->is_default && ! $fund->is_active) {
            throw new InvalidArgumentException(
                esc_html__('A fund that is not active cannot be the default. Every donation with no fund chosen goes to the default.', 'gratora')
            );
        }
        $fund->sort_order      = (int) ($input['sort_order'] ?? 0);
        $fund->parent_fund_id  = $this->resolveParent($input['parent_fund_id'] ?? null, null);
        $fund->goal_cents      = $this->nullableInt($input['goal_cents'] ?? null);
        $fund->starts_at       = self::scheduleDate($input['starts_at'] ?? null, __('start date', 'gratora'));
        $fund->ends_at         = self::scheduleDate($input['ends_at'] ?? null, __('end date', 'gratora'));
        $fund->accounting_code = $this->nullableString($input['accounting_code'] ?? null);
        $fund->raised_cents    = 0;
        $fund->created_at      = $now;
        $fund->updated_at      = $now;
        // update() holds the same rule. A window that starts after it ends is
        // never open, so the fund is offered to nobody and every later save is
        // refused by the guard the create never ran.
        $this->assertWindowOrder($fund);
        $this->assertDefaultHasNoWindow($fund);

        DB::transaction(function () use ($fund): void {
            $fund->save();
            if ($fund->is_default) {
                $this->demoteOtherDefaults((int) $fund->id);
            }
        });

        do_action('gratora.fund.created', $fund);
        return $fund;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @since 1.0.0
     */
    public function update(Fund $fund, array $input): Fund
    {
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code === '') {
                throw new InvalidArgumentException(esc_html__('A fund code is required.', 'gratora'));
            }
            if ($code !== $fund->code && $this->funds->codeExists($code, (int) $fund->id)) {
                throw new InvalidArgumentException(esc_html__('Fund code is already in use.', 'gratora'));
            }
            $fund->code = $code;
        }

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name !== '') {
                $fund->name = $name;
            }
        }

        foreach (['description', 'accounting_code'] as $field) {
            if (array_key_exists($field, $input)) {
                $fund->$field = $this->nullableString($input[$field]);
            }
        }

        $dates = [
            'starts_at' => __('start date', 'gratora'),
            'ends_at'   => __('end date', 'gratora'),
        ];
        foreach ($dates as $field => $label) {
            if (array_key_exists($field, $input)) {
                $fund->$field = self::scheduleDate($input[$field], $label);
            }
        }

        $this->assertWindowOrder($fund);

        if (array_key_exists('is_restricted', $input)) {
            $fund->is_restricted = (bool) $input['is_restricted'];
        }

        if (array_key_exists('is_active', $input)) {
            $next = (bool) $input['is_active'];
            if ($next) {
                $this->assertNotReassigning(
                    (int) $fund->id,
                    esc_html__('This fund is being reassigned and will be removed when that finishes, so it cannot be reactivated.', 'gratora')
                );
            }
            if (! $next && $fund->is_default) {
                throw new InvalidArgumentException(
                    esc_html__('The default fund cannot be deactivated. Set another fund as default first.', 'gratora')
                );
            }
            $fund->is_active = $next;
        }

        if (array_key_exists('sort_order', $input)) {
            $fund->sort_order = $input['sort_order'] === null || $input['sort_order'] === ''
                ? 0
                : (int) $input['sort_order'];
        }

        if (array_key_exists('goal_cents', $input)) {
            $fund->goal_cents = $this->nullableInt($input['goal_cents']);
        }

        if (array_key_exists('parent_fund_id', $input)) {
            $fund->parent_fund_id = $this->resolveParent($input['parent_fund_id'], (int) $fund->id);
        }

        $becomesDefault = false;
        if (array_key_exists('is_default', $input)) {
            $next = (bool) $input['is_default'];
            if (! $next && $fund->is_default) {
                throw new InvalidArgumentException(
                    esc_html__('Set another fund as the default rather than clearing this one.', 'gratora')
                );
            }
            if ($next) {
                $this->assertNotReassigning(
                    (int) $fund->id,
                    esc_html__('This fund is being reassigned and will be removed when that finishes, so it cannot be made the default.', 'gratora')
                );
            }
            $becomesDefault = $next && ! $fund->is_default;
            $fund->is_default = $next;
            if ($next) {
                $fund->is_active = true;
            }
        }

        // Refused, never repaired: dropping the window here would discard dates
        // the caller never mentioned, and an API client would have no way to
        // see it happen. Clearing them is the caller's to ask for.
        $this->assertDefaultHasNoWindow($fund);

        $fund->updated_at = $this->clock->now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($fund, $becomesDefault): void {
            $fund->save();
            if ($becomesDefault) {
                $this->demoteOtherDefaults((int) $fund->id);
            }
        });

        do_action('gratora.fund.updated', $fund);
        return $fund;
    }

    /**
     * Deletes a fund, deactivating it if it still has donation/campaign
     * references. With a reassignment target, references are moved in a
     * resumable background job before deletion; without one, the fund is
     * deactivated and kept for reporting.
     *
     * @return array{action:string,donations?:int,campaigns?:int,target_id?:int}
     *
     * @since 1.0.0
     */
    public function delete(Fund $fund, ?int $reassignTo = null): array
    {
        if ($fund->is_default) {
            throw new RuntimeException(
                esc_html__('The default fund cannot be deleted. Set another fund as default first.', 'gratora')
            );
        }
        $hasChildren = $this->hasChildren((int) $fund->id);

        $donations = (int) Donation::query()->where('fund_id', $fund->id)->count();
        $campaigns = (int) Campaign::query()->where('default_fund_id', $fund->id)->count();
        // Forms and plans can designate this fund too. A hard delete would
        // dangle Form.default_fund_id and make every future renewal copy a
        // now-deleted fund_id onto new donations (excluded from all aggregates).
        $forms = (int) Form::query()->where('default_fund_id', $fund->id)->count();
        $plans = (int) RecurringPlan::query()->where('fund_id', $fund->id)->count();

        if ($reassignTo !== null) {
            // Reassignment hard-deletes the source row once it completes, and
            // never touches parent_fund_id, so this is the one outcome that
            // would orphan a sub-fund. Deactivating a parent is supported:
            // FundRepository falls an orphan back to top level.
            if ($hasChildren) {
                throw new RuntimeException(
                    esc_html__('Move the sub-funds under this fund to another parent, or delete them, before reassigning and removing it.', 'gratora')
                );
            }

            $target = $this->funds->findById($reassignTo);
            if (! $target || (int) $target->id === (int) $fund->id) {
                throw new InvalidArgumentException(
                    esc_html__('Choose a different, existing fund to reassign donations to.', 'gratora')
                );
            }
            if (! $target->is_active) {
                throw new InvalidArgumentException(
                    esc_html__('Reassign donations to an active fund.', 'gratora')
                );
            }

            $fund->is_active  = false;
            $fund->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $fund->save();

            FundReassignmentJob::markPending((int) $fund->id, (int) $target->id);
            // Single-value arg: AS passes positionally; job resolves target from pending map.
            $this->async->enqueue(FundReassignmentJob::HOOK, [
                'fund_id' => (int) $fund->id,
            ]);
            do_action('gratora.fund.reassign_queued', $fund, $target);

            return ['action' => 'reassign_queued', 'target_id' => (int) $target->id];
        }

        if ($donations > 0 || $campaigns > 0 || $forms > 0 || $plans > 0 || $hasChildren) {
            $fund->is_active  = false;
            $fund->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $fund->save();
            do_action('gratora.fund.deactivated', $fund);

            return [
                'action'    => 'deactivated',
                'donations' => $donations,
                'campaigns' => $campaigns,
                'forms'     => $forms,
                'plans'     => $plans,
            ];
        }

        Fund::query()->where('id', $fund->id)->delete();
        do_action('gratora.fund.deleted', $fund);

        return ['action' => 'deleted'];
    }

    /**
     * Which of these funds delete() would hard-delete rather than deactivate:
     * not the default, no sub-funds, and zero donation / campaign / form / plan
     * references. Donations of ANY status count, so a fund reached only by test
     * or pending rows still reads as non-deletable, exactly as the delete guard
     * treats it. Batched into a handful of grouped queries so the admin list
     * can flag every row without a per-row fan-out.
     *
     * @param list<int> $fundIds
     * @return array<int,bool>
     *
     * @since 1.0.0
     */
    public function deletableMap(array $fundIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fundIds))));
        if ($ids === []) {
            return [];
        }

        $blocked = [];
        foreach (Fund::query()->whereIn('id', $ids)->where('is_default', 1)->getAll() as $f) {
            $blocked[(int) $f->id] = true;
        }

        $mark = function (string $table, string $column) use ($ids, &$blocked): void {
            $rows = DB::table($table)
                ->whereIn($column, $ids)
                ->selectRaw("DISTINCT {$column} AS ref")
                ->getAll();
            foreach ($rows as $r) {
                $blocked[(int) $r['ref']] = true;
            }
        };
        $mark('gratora_donations', 'fund_id');
        $mark('gratora_campaigns', 'default_fund_id');
        $mark('gratora_forms', 'default_fund_id');
        $mark('gratora_recurring_plans', 'fund_id');
        $mark('gratora_funds', 'parent_fund_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ! isset($blocked[$id]);
        }
        return $out;
    }

    /** @since 1.0.0 */
    public function reconcilePendingReassignments(): void
    {
        FundReassignmentJob::reconcile($this->async);
    }

    /** @since 1.0.0 */
    private function demoteOtherDefaults(int $keepId): void
    {
        foreach (Fund::query()->where('is_default', 1)->getAll() as $other) {
            if ((int) $other->id === $keepId) {
                continue;
            }
            Fund::query()->where('id', $other->id)->update(['is_default' => 0]);
        }
    }

    /** @since 1.0.0 */
    private function hasChildren(int $fundId): bool
    {
        return Fund::query()->where('parent_fund_id', $fundId)->get() !== null;
    }

    /**
     * Which of these funds have sub-funds under them, in one query, so the
     * admin list can say which deletes are on offer without a per-row check.
     *
     * @param int[] $ids
     * @return array<int, bool>
     *
     * @since 1.0.0
     */
    public function childrenMap(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $parents = [];
        $rows = DB::table('gratora_funds')
            ->whereIn('parent_fund_id', $ids)
            ->selectRaw('DISTINCT parent_fund_id AS ref')
            ->getAll();
        foreach ($rows as $r) {
            $parents[(int) $r['ref']] = true;
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = isset($parents[$id]);
        }
        return $out;
    }

    /**
     * @since 1.0.0
     */
    private function assertWindowOrder(Fund $fund): void
    {
        // Both are stored as datetime strings (YYYY-MM-DD from the date input
        // is fine, MySQL accepts it directly), so a lexicographic compare is
        // enough.
        if ($fund->starts_at && $fund->ends_at && $fund->starts_at > $fund->ends_at) {
            throw new InvalidArgumentException(
                esc_html__('The fund start date must be before its end date.', 'gratora')
            );
        }
    }

    /**
     * The default fund is the one a donation lands in when nothing else claims
     * it, so it has to be open whenever the site can take money. Outside its
     * window it is closed, FundResolver skips it, and those donations are filed
     * against whichever other fund happens to sort first.
     *
     * Held on both halves of the pairing: adding a window to the default and
     * promoting a fund that already has one are the same contradiction, and a
     * caller that wants the second has to clear the dates in the same request.
     *
     * @since 1.0.0
     */
    private function assertDefaultHasNoWindow(Fund $fund): void
    {
        if (! $fund->is_default || ($fund->starts_at === null && $fund->ends_at === null)) {
            return;
        }

        throw new InvalidArgumentException(
            esc_html__('The default fund cannot have a schedule. Every donation with no fund chosen goes to the default, so it has to stay open.', 'gratora')
        );
    }

    /**
     * All consumers assume at most one parent level.
     *
     * @since 1.0.0
     */
    private function resolveParent(mixed $value, ?int $selfId): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $parentId = (int) $value;
        if ($selfId !== null && $parentId === $selfId) {
            throw new InvalidArgumentException(esc_html__('A fund cannot be its own parent.', 'gratora'));
        }
        $parent = $this->funds->findById($parentId);
        if (! $parent) {
            throw new InvalidArgumentException(esc_html__('Parent fund not found.', 'gratora'));
        }
        if ($parent->parent_fund_id !== null) {
            throw new InvalidArgumentException(
                esc_html__('Funds nest only one level deep. Pick a top-level fund as the parent.', 'gratora')
            );
        }
        if ($selfId !== null && $this->hasChildren($selfId)) {
            throw new InvalidArgumentException(
                esc_html__('This fund has sub-funds, so it cannot also become a sub-fund.', 'gratora')
            );
        }
        $this->assertNotReassigning(
            $parentId,
            esc_html__('That fund is being reassigned and will be removed when that finishes, so it cannot take sub-funds.', 'gratora')
        );
        return $parentId;
    }

    /**
     * A queued reassignment ends in the source row being hard-deleted, so
     * anything that revives it or hangs a fund off it leaves the site holding
     * a pointer to a row that is about to go. Refused rather than worked
     * around: nothing offers a way to cancel a reassignment, so silently
     * letting the write through would only move the damage.
     *
     * @since 1.0.0
     */
    private function assertNotReassigning(int $fundId, string $message): void
    {
        if (! array_key_exists($fundId, FundReassignmentJob::pending())) {
            return;
        }

        throw new InvalidArgumentException($message);
    }

    /** @since 1.0.0 */
    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    /** @since 1.0.0 */
    /**
     * Reject invalid dates before MySQL coerces them to an ended zero date.
     *
     * @since 1.0.0
     */
    private static function scheduleDate(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $at = strtotime((string) $value);
        if ($at === false) {
            throw new InvalidArgumentException(esc_html(sprintf(
                /* translators: %s: the name of the date field, e.g. "start date". */
                __('That is not a date the fund %s can be set to.', 'gratora'),
                $label
            )));
        }

        return gmdate('Y-m-d H:i:s', $at);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** @since 1.0.0 */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int) $value);
    }
}
