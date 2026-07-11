<?php

return [
    // Master switch. Off = SimVending::canServe() is always false, so every
    // purchase falls straight through to the configured API providers.
    'enabled' => env('SIM_VENDING_ENABLED', true),

    // Seconds since last signed device call before a device counts offline.
    'online_window' => (int) env('SIM_VENDING_ONLINE_WINDOW', 180),

    // How long a claimed job is leased to one device before the sweeper
    // treats it as lost (plus grace for a late ack in flight).
    'lease_seconds' => (int) env('SIM_VENDING_LEASE_SECONDS', 300),
    'lease_grace' => (int) env('SIM_VENDING_LEASE_GRACE', 120),

    // A job nobody claimed within this window is refunded — the device
    // that was online at purchase time evidently went away.
    'pending_ttl' => (int) env('SIM_VENDING_PENDING_TTL', 600),

    // Advisory poll interval handed to agents in heartbeat responses.
    'poll_interval' => (int) env('SIM_VENDING_POLL_INTERVAL', 15),

    // Airtime headroom (naira) a SIM must keep after a vend — transfer
    // services charge fees and reject transfers that empty the SIM.
    'airtime_reserve' => (float) env('SIM_VENDING_AIRTIME_RESERVE', 100),

    // Minimum seconds between repeated low-balance alerts for one SIM.
    'low_balance_alert_interval' => (int) env('SIM_VENDING_LOW_BALANCE_ALERT_INTERVAL', 21600),
];
