<?php
declare(strict_types=1);

/**
 * UUID v4 (RFC 4122), например "3f6b0f5e-2c1a-4b7d-9c8e-1a2b3c4d5e6f".
 */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-'
         . substr($hex, 8, 4) . '-'
         . substr($hex, 12, 4) . '-'
         . substr($hex, 16, 4) . '-'
         . substr($hex, 20);
}
