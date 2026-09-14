<?php

namespace App\Exceptions;

use RuntimeException;

/** The SMS gateway did not accept a text (Phase 7). */
class SmsException extends RuntimeException {}
