<?php

/**
 * Default reporting tree — resolved by email after staff import.
 * CEO is apex when present; otherwise CCO is apex.
 */
return [
    'apex_email' => env('ORG_TREE_APEX_EMAIL', 'cco@kimfay.com'),

    'nodes' => [
        ['email' => 'cco@kimfay.com', 'reports_to' => null],
        ['email' => 'moderntrade@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
        ['email' => 'susan@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
        ['email' => 'partnerbrands@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
        ['email' => 'salesstrategy@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
        ['email' => 'hr@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
        ['email' => 'dispatch@kimfay.com', 'reports_to' => 'cco@kimfay.com'],
    ],
];