<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HotelPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) return;
        if (strlen($value) > 72) $fail('Kata sandi terlalu panjang. Gunakan kata sandi yang lebih singkat.');
        if (str_contains($value, "\0")) $fail('Kata sandi mengandung karakter yang tidak didukung.');
    }
}
