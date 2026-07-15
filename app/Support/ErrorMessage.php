<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns raw exceptions into something an admin or customer can actually read.
 *
 * The API used to hand `$e->getMessage()` straight back to the UI, which meant
 * a constraint violation surfaced as
 * "SQLSTATE[23000]: ... Column 'provider_id' cannot be null (SQL: insert into ...)"
 * — leaking the schema and the query, and telling the user nothing useful.
 *
 * Callers should log the original exception and show humanize() instead.
 */
class ErrorMessage
{
    /** Fallback when we cannot say anything specific without leaking internals. */
    public const GENERIC = 'Something went wrong while completing that request. Please try again.';

    /**
     * A human-readable message for an exception. Messages that are already
     * user-facing (validation, aborts, our own domain exceptions) are passed
     * through untouched; technical ones are translated or generalised.
     */
    public static function humanize(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return $e->getMessage();
        }

        if ($e instanceof QueryException) {
            return self::fromQueryException($e);
        }

        // Aborts/HTTP exceptions carry an intentional, already-friendly message
        // (e.g. abort(403, 'You cannot edit this plan.')). Keep it unless empty.
        if ($e instanceof HttpExceptionInterface) {
            $message = trim($e->getMessage());

            return ($message !== '' && !self::isTechnical($message)) ? $message : self::GENERIC;
        }

        $message = trim($e->getMessage());

        if ($message === '' || self::isTechnical($message)) {
            return self::GENERIC;
        }

        return $message;
    }

    /**
     * HTTP status that best fits an exception. Constraint/data violations are
     * things the caller can fix (422); anything else is a server fault (500).
     */
    public static function statusFor(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof ValidationException) {
            return 422;
        }

        if ($e instanceof QueryException) {
            return in_array(self::driverCode($e), [1048, 1062, 1264, 1406, 1451, 1452], true) ? 422 : 500;
        }

        return 500;
    }

    /**
     * True when a message looks like internals (SQL, PDO, stack paths) rather
     * than something written for a person.
     */
    public static function isTechnical(string $message): bool
    {
        return (bool) preg_match(
            '/SQLSTATE|\bSQL:|\bPDO|Integrity constraint|Base table|syntax error|Call to (a member function|undefined)|Undefined (variable|index|property)|Class .* not found|Connection refused|Allowed memory size|Trying to access array offset|::class|\.php\b|#\d+ \//i',
            $message,
        );
    }

    private static function fromQueryException(QueryException $e): string
    {
        return match (self::driverCode($e)) {
            // Column 'x' cannot be null
            1048 => self::missingFieldMessage($e),
            // Duplicate entry 'x' for key 'y'
            1062 => self::duplicateMessage($e),
            // Out of range / incorrect value for a column
            1264 => 'One of the values is outside the range this field allows.',
            // Data too long for column 'x'
            1406 => self::tooLongMessage($e),
            // Cannot delete or update a parent row (FK constraint)
            1451 => 'This record is still linked to other data, so it cannot be changed or deleted. Remove or reassign the linked records first.',
            // Cannot add or update a child row (FK constraint)
            1452 => 'A record this refers to does not exist. Pick an existing option and try again.',
            // Unknown column
            1054 => 'One of the fields sent does not exist on this record.',
            // Table doesn't exist
            1146 => 'This feature is not fully set up yet. Please contact support.',
            // Connection failures
            2002, 2003, 2006, 2013 => 'We could not reach the database just now. Please try again in a moment.',
            // Lock wait / deadlock — genuinely retryable
            1205, 1213 => 'The system was busy handling another change. Please try again.',
            default => 'The database rejected that change. Please check the values and try again.',
        };
    }

    private static function missingFieldMessage(QueryException $e): string
    {
        if (preg_match("/Column '([^']+)' cannot be null/i", $e->getMessage(), $m)) {
            return sprintf('%s is required.', self::fieldLabel($m[1]));
        }

        return 'A required field was left empty.';
    }

    private static function duplicateMessage(QueryException $e): string
    {
        if (preg_match("/Duplicate entry '(.*)' for key '([^']+)'/i", $e->getMessage(), $m)) {
            $field = self::fieldFromIndexName($m[2]);

            return $field !== null
                ? sprintf('That %s is already taken. Please use a different one.', mb_strtolower(self::fieldLabel($field)))
                : 'That value already exists. Please use a different one.';
        }

        return 'That value already exists. Please use a different one.';
    }

    private static function tooLongMessage(QueryException $e): string
    {
        if (preg_match("/Data too long for column '([^']+)'/i", $e->getMessage(), $m)) {
            return sprintf('%s is too long.', self::fieldLabel($m[1]));
        }

        return 'One of the values is too long.';
    }

    /** "provider_id" => "Provider", "accountNumber" => "Account number". */
    private static function fieldLabel(string $column): string
    {
        $label = preg_replace('/_id$/', '', $column) ?? $column;
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', $label) ?? $label;
        $label = str_replace('_', ' ', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);

        return $label === '' ? 'This field' : ucfirst(mb_strtolower($label));
    }

    /**
     * Pull the column out of a conventional index name, e.g.
     * "users_email_unique" => "email". Null when it isn't that shape.
     */
    private static function fieldFromIndexName(string $index): ?string
    {
        if ($index === 'PRIMARY') {
            return null;
        }

        $trimmed = preg_replace('/_(unique|index|foreign|idx|key)$/i', '', $index) ?? $index;
        $parts = explode('_', $trimmed);

        // Conventional names are "<table>_<column>"; drop the table segment.
        if (count($parts) < 2) {
            return null;
        }

        array_shift($parts);

        return implode('_', $parts) ?: null;
    }

    private static function driverCode(QueryException $e): ?int
    {
        $code = $e->errorInfo[1] ?? null;

        return $code !== null ? (int) $code : null;
    }
}
