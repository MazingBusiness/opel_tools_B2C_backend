<?php

namespace App\Modules\Catalog\Services;

use RuntimeException;

/** B2B returned a body that is not the product list. */
final class UnexpectedCatalogPayload extends RuntimeException {}
