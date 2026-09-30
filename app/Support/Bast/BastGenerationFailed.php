<?php

namespace App\Support\Bast;

use RuntimeException;

/**
 * The BAST PDF could not be generated. Thrown inside the transition, it
 * rolls the status change back and leaves no file behind.
 */
class BastGenerationFailed extends RuntimeException {}
