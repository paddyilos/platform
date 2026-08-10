<?php

namespace Ushahidi\Addons\LiberiaSms;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * Liberia custom — registers HttpSmsStructuredPostListener against the
 * stock 'message.receive' event without touching any vendored file.
 * Packaged as an addon (same auto-discovery mechanism as Addons/HttpSMS)
 * specifically so this survives future ushahidi-api upstream bumps: addon
 * providers are discovered via composer.json's existing merge-plugin glob
 * (see root composer.json, extra.merge-plugin.include) and boot before
 * config/app.php's listed providers, so this listener is guaranteed to
 * run before Modules\V3\Listener\CreatePostFromMessage.
 */
class ServiceProvider extends BaseServiceProvider
{
    public function boot()
    {
        Event::listen('message.receive', HttpSmsStructuredPostListener::class);
    }
}
