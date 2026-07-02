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
$router->get('v3/get-alerts/lookup-location', 'AlertController@lookupLocation')->middleware('throttle:30,1');

// Public contact form — throttled since it has no auth and no other spam
// protection beyond the frontend's client-side captcha
$router->post('v3/contact-us', 'ContactUsController@store')->middleware('throttle:5,1');

// LERN data import (admin only)
$router->post('v3/lern-import', 'LernImportController@store')->middleware('auth:api');

// About Us has no dedicated controller/route here — it reuses the stock
// config group mechanism instead (group "about_us", key "content"), via
// the existing GET/PUT /api/v3/config/{group}/{key} routes in api.php.
// See Models/Config.php and Policies/ConfigPolicy.php for the group
// registration.

// Analysis dashboard + saved report templates — gated server-side by
// AnalysisTemplateController::requireAccessAnalysis() (Permission::ACCESS_ANALYSIS).
// Uses v5/ (unlike the get-alerts/contact-us routes above) to match the
// stock API's actual version convention.
$router->get('v5/analysis-templates', 'AnalysisTemplateController@index')->middleware('auth:api');
$router->get('v5/analysis-templates/{id}', 'AnalysisTemplateController@show')->middleware('auth:api');
$router->post('v5/analysis-templates', 'AnalysisTemplateController@store')->middleware('auth:api');
$router->put('v5/analysis-templates/{id}', 'AnalysisTemplateController@update')->middleware('auth:api');
$router->delete('v5/analysis-templates/{id}', 'AnalysisTemplateController@destroy')->middleware('auth:api');

// Lean per-post attribute-value rows for the Report Builder's WebDataRocks
// pivot table — see PivotDataController for why this isn't just
// `GET /posts?only=...,post_content`.
$router->get('v5/analysis-pivot-data', 'PivotDataController@index')->middleware('auth:api');
