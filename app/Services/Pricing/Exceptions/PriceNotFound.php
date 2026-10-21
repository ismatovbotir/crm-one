<?php

namespace App\Services\Pricing\Exceptions;

use RuntimeException;

/**
 * Neither the customer's price list nor the default list for the currency has a price
 * for the item at that quantity and date. There is no FX conversion (Spec 01 D-12).
 */
class PriceNotFound extends RuntimeException {}
