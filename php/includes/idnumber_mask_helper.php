<?php

/**
 * Masks an ID number for account-recovery screens.
 * Only the first and last two digits are revealed; separators are preserved.
 */
function mask_idnumber(string $idnumber): string
{
    $digits = preg_replace('/\D/', '', $idnumber);
    $digitCount = strlen($digits);

    if ($digitCount < 4) {
        return str_repeat('*', $digitCount);
    }

    $index = 0;
    $masked = '';
    foreach (str_split($idnumber) as $character) {
        if (!ctype_digit($character)) {
            $masked .= $character;
            continue;
        }

        $masked .= ($index < 2 || $index >= $digitCount - 2) ? $character : '*';
        $index++;
    }

    return $masked;
}
