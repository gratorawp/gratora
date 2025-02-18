<?php

declare(strict_types=1);

namespace Dono\Funds;

use Dono\Async\AsyncDispatcher;
use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Forms\Form;
use Dono\Recurring\RecurringPlan;
use Dono\Foundation\Time\Clock;
use InvalidArgumentException;
use Dono\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Fund lifecycle: create, update, delete/deactivate, reassign.
 *
 * @version 1.0.0
 */
final class FundService
{
    public function __construct(
        private FundRepository $funds,
        private Clock $clock,
        private AsyncDispatcher $async,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function create(array $input): Fund
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $code = $this->normalizeCode((string) ($input['code'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException(__('A fund code is required.', 'dono'));
        }
        if ($this->funds->codeExists($code)) {
            throw new InvalidArgumentException(__('Fund code is already in use.', 'dono'));
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(__('A fund name is required.', 'dono'));
        }

        $fund = Fund::make();
        $fund->code            = $code;
        $fund->name            = $name;
        $fund->description     = $this->nullableString($input['description'] ?? null);
        $fund->is_restricted   = (bool) ($input['is_restricted'] ?? false);
        $fund->is_default      = (bool) ($input['is_default'] ?? false);
        $fund->is_active       = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
        $fund->sort_order      = (int) ($input['sort_order'] ?? 0);
        $fund->parent_fund_id  = $this->resolveParent($input['parent_fund_id'] ?? null, null);
        $fund->goal_cents      = $this->nullableInt($input['goal_cents'] ?? null);
        $fund->starts_at       = $this->nullableString($input['starts_at'] ?? null);
        $fund->ends_at         = $this->nullableString($input['ends_at'] ?? null);
        $fund->accounting_code = $this->nullableString($input['accounting_code'] ?? null);
        $fund->raised_cents    = 0;
        $fund->created_at      = $now;
        $fund->updated_at      = $now;

        DB::transaction(function () use ($fund): void {
            $fund->save();
            if ($fund->is_default) {
                $this->demoteOtherDefaults((int) $fund->id);
            }
        });

        do_action('dono.fund.created', $fund);
        return $fund;
    }

    /** @param array<string,mixed> $input */
    public function update(Fund $fund, array $input): Fund
    {
        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);
            if ($code === '') {
                throw new InvalidArgumentException(__('A fund code is required.', 'dono'));
            }
            if ($code !== $fund->code && $this->funds->codeExists($code, (int) $fund->id)) {
                throw new InvalidArgumentException(__('Fund code is already in use.', 'dono'));
            }
            $fund->code = $code;
        }

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name !== '') {
                $fund->name = $name;
            }
        }

        foreach (['description', 'starts_at', 'ends_at', 'accounting_code'] as $field) {
            if (array_key_exists($field, $input)) {
                $fund->$field = $this->nullableString($input[$field]);
            }
        }

        // Reject a window that starts after it ends. Both are stored as
        // datetime strings (YYYY-MM-DD from the date input is fine, MySQL
        // accepts it directly), so a lexicographic compare is enough.
        if ($fund->starts_at && $fund->ends_at && $fund->starts_at > $fund->ends_at) {
            throw new InvalidArgumentException(
                __('Fund "Active from" date must be before "Active until".', 'dono')
            );
        }

        if (array_key_exists('is_restricted', $input)) {
            $fund->is_restricted = (bool) $input['is_restricted'];
        }

        if (array_key_exists('is_active', $input)) {
            $next = (bool) $input['is_active'];
            if (! $next && $fund->is_default) {
                throw new InvalidArgumentException(
                    __('The default fund cannot be deactivated. Set another fund as default first.', 'dono')
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
                    __('Set another fund as the default rather than clearing this one.', 'dono')
                );
            }
            $becomesDefault = $next && ! $fund->is_default;
            $fund->is_default = $next;
            if ($next) {
                $fund->is_active = true;
            }
        }

        $fund->updated_at = $this->clock->now()->format('Y-m-d H:i:s');

        DB::transaction(function () use ($fund, $becomesDefault): void {
            $fund->save();
            if ($becomesDefault) {
                $this->demoteOtherDefaults((int) $fund->id);
            }
        });

        do_action('dono.fund.updated', $fund);
        return $fund;
    }

    /**
     * Deletes a fund, deactivating it if it still has donation/campaign
     * references. With a reassignment target, references are moved in a
     * resumable background job before deletion; without one, the fund is
     * deactivated and kept for reporting.
     *
     * @return array{action:string,donations?:int,campaigns?:int,target_id?:int}
     */
    public function delete(Fund $fund, ?int $reassignTo = null): array
    {
        if ($fund->is_default) {
            throw new RuntimeException(
                __('The default fund cannot be deleted. Set another fund as default first.', 'dono')
            );
        }
        if ($this->hasChildren((int) $fund->id)) {
            throw new RuntimeException(
                __('Reassign or remove the sub-funds under this fund before deleting it.', 'dono')
            );
        }

        $donations = (int) Donation::query()->where('fund_id', $fund->id)->count();
        $campaigns = (int) Campaign::query()->where('default_fund_id', $fund->id)->count();
        // Forms and plans can designate this fund too. A hard delete would
        // dangle Form.default_fund_id and make every future renewal copy a
        // now-deleted fund_id onto new donations (excluded from all aggregates).
        $forms = (int) Form::query()->where('default_fund_id', $fund->id)->count();
        $plans = (int) RecurringPlan::query()->where('fund_id', $fund->id)->count();

        if ($reassignTo !== null) {
            $target = $this->funds->findById($reassignTo);
            if (! $target || (int) $target->id === (int) $fund->id) {
                throw new InvalidArgumentException(
                    __('Choose a different, existing fund to reassign donations to.', 'dono')
                );
            }
            if (! $target->is_active) {
                throw new InvalidArgumentException(
                    __('Reassign donations to an active fund.', 'dono')
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
            do_action('dono.fund.reassign_queued', $fund, $target);

            return ['action' => 'reassign_queued', 'target_id' => (int) $target->id];
        }

        if ($donations > 0 || $campaigns > 0 || $forms > 0 || $plans > 0) {
            $fund->is_active  = false;
            $fund->updated_at = $this->clock->now()->format('Y-m-d H:i:s');
            $fund->save();
            do_action('dono.fund.deactivated', $fund);

            return [
                'action'    => 'deactivated',
                'donations' => $donations,
                'campaigns' => $campaigns,
                'forms'     => $forms,
                'plans'     => $plans,
            ];
        }

        Fund::query()->where('id', $fund->id)->delete();
        do_action('dono.fund.deleted', $fund);

        return ['action' => 'deleted'];
    }

    /** Re-queue any reassignment whose background job was lost. Idempotent. */
    public function reconcilePendingReassignments(): void
    {
        FundReassignmentJob::reconcile($this->async);
    }

    private function demoteOtherDefaults(int $keepId): void
    {
        foreach (Fund::query()->where('is_default', 1)->getAll() as $other) {
            if ((int) $other->id === $keepId) {
                continue;
            }
            Fund::query()->where('id', $other->id)->update(['is_default' => 0]);
        }
    }

    private function hasChildren(int $fundId): bool
    {
        return Fund::query()->where('parent_fund_id', $fundId)->get() !== null;
    }

    /**
     * Validates and resolves a parent fund id.
     *
     * Funds nest one level deep; every consumer assumes a shallow tree.
     * Raising the depth requires updating all consumers.
     */
    private function resolveParent(mixed $value, ?int $selfId): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }
        $parentId = (int) $value;
        if ($selfId !== null && $parentId === $selfId) {
            throw new InvalidArgumentException(__('A fund cannot be its own parent.', 'dono'));
        }
        $parent = $this->funds->findById($parentId);
        if (! $parent) {
            throw new InvalidArgumentException(__('Parent fund not found.', 'dono'));
        }
        if ($parent->parent_fund_id !== null) {
            throw new InvalidArgumentException(
                __('Funds nest only one level deep. Pick a top-level fund as the parent.', 'dono')
            );
        }
        if ($selfId !== null && $this->hasChildren($selfId)) {
            throw new InvalidArgumentException(
                __('This fund has sub-funds, so it cannot also become a sub-fund.', 'dono')
            );
        }
        return $parentId;
    }

    private function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int) $value);
    }
}
