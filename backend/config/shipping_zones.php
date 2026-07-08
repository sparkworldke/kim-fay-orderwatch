<?php

/**
 * Known shipping zone metadata when the Acumatica Zone entity is not exposed
 * on the IpayV2 endpoint (404). Populated automatically from Customer.ShippingZoneID
 * during customer sync; entries here drive display labels and delivery SLA metro matching.
 *
 * Keys are uppercase zone IDs.
 */
return [
    'known_zones' => [
        'Z001' => ['name' => 'Westlands', 'region' => 'Nairobi'],
        'Z002' => ['name' => 'CBD', 'region' => 'Nairobi'],
        'Z003' => ['name' => 'Ngong', 'region' => 'Nairobi'],
        'Z004' => ['name' => 'Thika', 'region' => 'Nairobi'],
        'Z005' => ['name' => 'Mombasa Rd', 'region' => 'Nairobi'],
        'Z012' => ['name' => 'Mombasa', 'region' => 'Coast'],
    ],
];