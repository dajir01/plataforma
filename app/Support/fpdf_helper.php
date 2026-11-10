<?php

if (! function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime(): bool
    {
        return false;
    }
}

if (! class_exists('FPDF')) {
    require_once __DIR__ . '/../../vendor/setasign/fpdf/fpdf.php';
}
