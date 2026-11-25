<?php

function account_input(string $field, bool $trim = true): string
{
    $value = $_POST[$field] ?? '';
    return is_string($value) ? ($trim ? trim($value) : $value) : '';
}

function account_name_is_valid(string $name): bool
{
    return preg_match("/^(?=.*\p{L})[\p{L}\p{M} .’'-]+\z/u", $name) === 1;
}

function account_text_is_valid(string $value, int $maxBytes): bool
{
    return strlen($value) <= $maxBytes && preg_match('/\p{Cc}/u', $value) === 0;
}

function account_password_is_valid(string $password): bool
{
    return preg_match('/\A(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[!-~]{8,15}\z/', $password) === 1;
}

function account_phone_is_valid(string $phone): bool
{
    $digits = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^\+?[0-9 ()-]+\z/', $phone) === 1 && strlen($digits) >= 7 && strlen($digits) <= 15;
}

function account_postal_is_valid(string $postal, string $country): bool
{
    return strcasecmp($country, 'Philippines') === 0
        ? preg_match('/^[0-9]{4}\z/', $postal) === 1
        : preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{0,19}\z/', $postal) === 1;
}

function account_positive_integer(mixed $value, int $max = 2147483647): ?int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*\z/', (string) $value)) {
        return null;
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    return $number === false ? null : $number;
}
