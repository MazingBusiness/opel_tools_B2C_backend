<?php

namespace App\Modules\Catalog\Services;

use RuntimeException;

/** B2B returned HTTP success with a body that is not a product page. */
final class UnexpectedCatalogPayload extends RuntimeException {}
