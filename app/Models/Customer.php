<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * A partner in the customer role (Spec 03 section 24): the `partners` table restricted to
 * `is_customer = true` by a global scope. Customer screens, documents and the 1C customer exchange
 * use it; organization-wide work (tax-ID uniqueness, roles, supplier profile) uses `Partner`.
 * Morph alias, factory and policies are shared with `Partner`.
 */
class Customer extends Partner
{
    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('customer_role', fn (Builder $query) => $query->where($query->qualifyColumn('is_customer'), true));
    }
}
