<?php

require_once __DIR__ . '/account_input_validation.php';

function book_form_input(): array
{
    foreach (['title', 'author', 'isbn', 'description', 'price', 'stock_quantity', 'is_active'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) {
            throw new InvalidArgumentException('Enter one value per book field.');
        }
    }
    $title = account_input('title');
    $author = account_input('author');
    $isbn = account_input('isbn');
    $description = account_input('description');
    $price = account_input('price');
    $stock = account_input('stock_quantity');
    $active = account_input('is_active');

    if ($title === '' || $author === '' || !account_text_is_valid($title, 255) || !account_text_is_valid($author, 255)) {
        throw new InvalidArgumentException('Enter a title and author up to 255 bytes each.');
    }
    if (!account_text_is_valid($isbn, 17) || !account_text_is_valid(str_replace(["\r", "\n", "\t"], ' ', $description), 1000)) {
        throw new InvalidArgumentException('Use up to 17 characters for ISBN and 1,000 bytes for the description.');
    }
    if (!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $price)) {
        throw new InvalidArgumentException('Enter a nonnegative price with up to two decimal places.');
    }
    $validatedStock = filter_var($stock, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
    if (!preg_match('/^[0-9]+\z/', $stock) || $validatedStock === false) {
        throw new InvalidArgumentException('Enter a valid nonnegative stock quantity.');
    }
    if (!in_array($active, ['0', '1'], true)) {
        throw new InvalidArgumentException('Choose a valid availability status.');
    }
    return [$title, $author, $isbn === '' ? null : $isbn, $description, (float) $price, $validatedStock, (int) $active];
}
