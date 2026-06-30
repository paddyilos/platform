<?php

/**
 * Liberia custom routes — iReport Liberia PBO
 *
 * Added on top of standard Ushahidi V5 API.
 * Kept in a separate file so upstream merges to api.php stay clean.
 */

// Geographic alert subscriptions (public)
$router->post('v3/get-alerts', 'AlertController@store');
$router->get('v3/get-alerts/unsubscribe-email/{hash}', 'AlertController@unsubscribe');

// Public contact form
$router->post('v3/contact-us', 'ContactUsController@store');

// LERN data import (admin only)
$router->post('v3/lern-import', 'LernImportController@store')->middleware('auth:api');

// About Us has no dedicated controller/route here — it reuses the stock
// config group mechanism instead (group "about_us", key "content"), via
// the existing GET/PUT /api/v3/config/{group}/{key} routes in api.php.
// See Models/Config.php and Policies/ConfigPolicy.php for the group
// registration.
