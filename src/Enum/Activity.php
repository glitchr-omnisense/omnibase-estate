<?php

namespace Base\Estate\Enum;

/**
 * What a property business does, combinable (estate.activities): ASSETS
 * manages its own property (a property company, several companies),
 * OWNERSHIP a shared ownership (indivision: co-owners' shares, shared
 * invoices, calls for funds), MANAGEMENT property management for third
 * parties (mandates, owners' statements, fees), TRANSACTION sales (sale
 * mandates, buyers, offers). A site sets its own: [assets] by default, an
 * agency [management, transaction].
 */
enum Activity: string
{
    case ASSETS = 'assets';
    case OWNERSHIP = 'ownership';
    case MANAGEMENT = 'management';
    case TRANSACTION = 'transaction';

    /** The activities that let homes: tenants, leases, rents, works and contractors are theirs. */
    public const RENTAL = [self::ASSETS, self::OWNERSHIP, self::MANAGEMENT];
}
