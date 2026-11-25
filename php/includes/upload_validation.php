<?php

function upload_contains_allowed_file(string $path, string $extension): bool
{
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    $expected = $types[$extension] ?? null;
    if ($expected === null || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== $expected) {
        return false;
    }
    if ($extension === 'pdf') {
        return file_get_contents($path, false, null, 0, 5) === '%PDF-';
    }
    $image = @getimagesize($path);
    return $image !== false && $image['mime'] === $expected && $image[0] > 0 && $image[1] > 0;
}

function validated_upload_extension(array $file, array $allowed, int $maxBytes): ?string
{
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > $maxBytes) {
        return null;
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    return in_array($extension, $allowed, true) && upload_contains_allowed_file($file['tmp_name'], $extension) ? $extension : null;
}
