<?php

namespace App\Services;

use RuntimeException;

/** The offer was withdrawn before the note could be written (the check under the row lock). */
class OfferNotEditableException extends RuntimeException {}
