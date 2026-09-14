<?php

namespace App\Exceptions;

use RuntimeException;

/** An SMS code could not be sent or was not accepted; the message is shown to the person (Phase 7). */
class OtpException extends RuntimeException {}
