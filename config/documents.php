<?php

/*
 * Document Storage settings.
 */
return [
    // Largest single file, in kilobytes. PHP's upload_max_filesize must allow it too.
    'max_upload_kilobytes' => 10240,

    // Most files in one upload.
    'max_files_per_upload' => 20,

    // File types opened in the browser; everything else is downloaded.
    'viewable_types' => [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'text/plain',
        'text/csv',
    ],
];
