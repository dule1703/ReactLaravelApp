<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * The cell (or group) changed in the database since the browser loaded the matrix.
 */
class MatrixConflictException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('The matrix has changed in the meantime; refresh it.')], 409);
    }
}
