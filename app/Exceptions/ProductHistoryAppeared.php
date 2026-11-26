<?php

namespace App\Exceptions;

/**
 * Raised when a product gained inventory history between the deletion check and
 * the delete itself.
 *
 * Its only job is to roll the transaction back and be recognised by the caller,
 * so the race answers with the same refusal a product that already had history
 * would have received.
 */
class ProductHistoryAppeared extends \RuntimeException
{
}
