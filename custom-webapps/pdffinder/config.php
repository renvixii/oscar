<?php
/**
 * PDF Finder — configuration
 *
 * Edit the paths below to match your server. Use absolute paths when possible.
 */

declare(strict_types=1);

// One or more directories to scan for PDF files (recursive).
$PDF_DIRECTORIES = [
    __DIR__ . '/pdf-files',
     'C:\Users\Alo\Documents\KWUCC\WESTMOUNT',
    // '/home/user/patient-pdfs',
];

// Where the generated index is stored (created automatically on rebuild).
$INDEX_FILE = __DIR__ . '/storage/pdf_index.json';
