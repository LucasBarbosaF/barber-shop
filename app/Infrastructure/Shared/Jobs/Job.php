<?php

namespace App\Infrastructure\Shared\Jobs;

/**
 * Base class for queued jobs (WhatsApp, email, notifications, AI, reports, webhooks).
 *
 * Use Illuminate\Queue\ShouldQueue and set queue connection via config.
 */
abstract class Job {}
