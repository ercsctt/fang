<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Freshness Monitoring
    |--------------------------------------------------------------------------
    |
    | Configure thresholds for monitoring data freshness and system health.
    | These values determine when alerts are triggered.
    |
    */

    'stale_product_threshold_days' => env('MONITORING_STALE_PRODUCT_DAYS', 2),

    'retailer_crawl_threshold_hours' => env('MONITORING_RETAILER_CRAWL_HOURS', 24),

    'high_failure_rate_threshold' => env('MONITORING_HIGH_FAILURE_RATE', 0.2),

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how and where alerts are sent when critical issues are detected.
    |
    */

    'alert_email' => env('MONITORING_ALERT_EMAIL', env('MAIL_FROM_ADDRESS')),

    'notification_channels' => array_filter([
        env('SLACK_BOT_USER_OAUTH_TOKEN') ? 'slack' : null,
        env('MONITORING_ALERT_EMAIL') ? 'mail' : null,
    ]),

];
