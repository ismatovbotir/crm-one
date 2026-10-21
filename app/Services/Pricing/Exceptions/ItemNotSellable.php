<?php

namespace App\Services\Pricing\Exceptions;

use RuntimeException;

/**
 * The item is inactive, archived or deleted in 1C and cannot be priced for new documents (AC-2.7).
 */
class ItemNotSellable extends RuntimeException {}
