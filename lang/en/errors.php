<?php

/*
| HTTP error pages (resources/views/errors).
*/

return [

    'code' => 'Error :code',
    'home' => 'Go to the start page',
    'back' => 'Go back',
    'retry_after' => 'You can try again in :seconds seconds.',
    '403' => [
        'title' => 'Access denied',
        'message' => 'You do not have permission to open this page. If you need access, ask your manager or the administrator.',
    ],
    '404' => [
        'title' => 'Page not found',
        'message' => 'The page does not exist, was moved, or the link is incomplete.',
    ],
    '419' => [
        'title' => 'Page expired',
        'message' => 'Your session expired for security reasons. Go back, reload the page and try again.',
    ],
    '429' => [
        'title' => 'Too many requests',
        'message' => 'You sent too many requests in a short time. Please wait a moment.',
    ],
    '500' => [
        'title' => 'Something went wrong',
        'message' => 'An unexpected error occurred on our side. It has been logged; please try again later.',
    ],
    '503' => [
        'title' => 'Maintenance in progress',
        'message' => 'The system is being updated and will be back shortly.',
    ],

];
