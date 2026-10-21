<?php

namespace App\Services\Leads;

/**
 * Lead score 0–100 and stage suggestion (Spec 04 section 16, default weights):
 * budget, authority, need and timeline confirmed 15 each (60); customer linked 10; a way to reach
 * the contact 5; need described 5; estimated value 5; expected decision within 90 days 5; at least
 * one product of interest 5; high priority +5 (capped at 100).
 */
class LeadScore
{
    /**
     * @param  array{budget_confirmed?: bool, authority_confirmed?: bool, need_confirmed?: bool, timeline_confirmed?: bool, customer?: bool, reachable?: bool, need?: string|null, est_value_minor?: int|null, expected_close_date?: string|null, items?: int, priority?: string}  $facts
     */
    public function calculate(array $facts): int
    {
        $score = 0;

        foreach (['budget_confirmed', 'authority_confirmed', 'need_confirmed', 'timeline_confirmed'] as $flag) {
            $score += ! empty($facts[$flag]) ? 15 : 0;
        }

        $score += ! empty($facts['customer']) ? 10 : 0;
        $score += ! empty($facts['reachable']) ? 5 : 0;
        $score += filled($facts['need'] ?? null) ? 5 : 0;
        $score += ($facts['est_value_minor'] ?? 0) > 0 ? 5 : 0;

        if (filled($facts['expected_close_date'] ?? null)) {
            $date = strtotime((string) $facts['expected_close_date']);
            $score += $date !== false && $date <= strtotime('+90 days') ? 5 : 0;
        }

        $score += ($facts['items'] ?? 0) > 0 ? 5 : 0;
        $score += ($facts['priority'] ?? null) === 'high' ? 5 : 0;

        return min(100, $score);
    }

    /** low | medium | high band for the badge (text, never colour alone). */
    public function band(int $score): string
    {
        return match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }

    /**
     * Stage suggestion key under leads.suggestions.*.
     *
     * @param  list<string>  $gaps
     */
    public function suggestion(int $score, array $gaps, bool $hasCustomer): string
    {
        return match (true) {
            $gaps === [] => 'qualify',
            ! $hasCustomer => 'link_customer',
            $score >= 40 => 'confirm_bant',
            default => 'contact',
        };
    }
}
