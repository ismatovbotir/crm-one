<?php

namespace App\Services\Leads;

use App\Authorization\PermissionMap;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Owner for leads that arrive without a person (Spec 04 section 15.2): when `leads.round_robin` is
 * on, the next active sales user (global role sales or a sales department member who may create
 * leads) after the last one picked, by user id; otherwise unassigned (null) for a head to assign.
 */
class LeadRoundRobin
{
    public const LAST_KEY = 'leads.round_robin_last_user_id';

    public function next(): ?User
    {
        if (! BusinessSettings::bool('leads.round_robin')) {
            return null;
        }

        return Cache::lock('leads-round-robin', 5)->block(5, function (): ?User {
            $candidates = User::query()->where('is_active', true)->orderBy('id')->get()
                ->filter(fn (User $user): bool => $user->isInternal()
                    && $user->can('create', Lead::class)
                    && (PermissionMap::matchesGrant($user, 'role:sales') || PermissionMap::matchesGrant($user, 'dept:sales:specialist')))
                ->values();

            if ($candidates->isEmpty()) {
                return null;
            }

            $last = (int) Setting::get(self::LAST_KEY, '0');
            $next = $candidates->first(fn (User $user): bool => $user->id > $last) ?? $candidates->first();
            Setting::putMany([self::LAST_KEY => (string) $next->id]);

            return $next;
        });
    }
}
