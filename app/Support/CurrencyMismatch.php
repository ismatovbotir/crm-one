<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Thrown when amounts in different currencies are combined (no FX conversion, Spec 01 D-12).
 */
class CurrencyMismatch extends InvalidArgumentException {}
