<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * A user's phone number must be unique — compared the way it is stored.
 *
 * Every save path runs the number through PhoneNumber::format() first, so
 * "+212 711 04 11 34", "0711 041 134" and "0711041134" all land in the
 * column as the same value. A plain `unique` rule compares the text as
 * typed, lets two of those through, and the insert then dies on the
 * users_phone_unique index as a 500 instead of a message on the field.
 */
class UniqueUserPhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $normalized = PhoneNumber::format($value);

        // Too short to be a number: the save path stores null for it, and
        // null never collides.
        if ($normalized === null) {
            return;
        }

        // Straight at the table, not through the model: the unique index
        // spans every row, including any a model scope would hide.
        $taken = DB::table('users')
            ->whereIn('phone', array_unique([$normalized, $value]))
            ->when($this->ignoreUserId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail(__('This phone number is already used by another account.'));
        }
    }
}
