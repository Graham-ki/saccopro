<?php
declare(strict_types=1);

/**
 * Load all settings from the DB into a global $SETTINGS array.
 * Called once per request, cached in a static.
 */
function load_settings(PDO $pdo): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $defaults = [
        'org_name'          => 'My SACCO',
        'org_short_name'    => 'SCMS',
        'currency_symbol'   => 'UGX',
        'currency_position' => 'before',
        'receipt_footer'    => 'Thank you for banking with us.',
        'default_interest'  => '3',
        'default_term'      => '6',
        'default_method'    => 'declining',
        'timezone'          => 'Africa/Kampala',
    ];

    try {
        $rows = $pdo->query("SELECT key_name, value FROM settings")->fetchAll();
        $fromDb = [];
        foreach ($rows as $r) {
            $fromDb[$r['key_name']] = $r['value'];
        }
        $cached = array_merge($defaults, $fromDb);
    } catch (Throwable $e) {
        // Table doesn't exist yet — fall back to defaults
        $cached = $defaults;
    }

    return $cached;
}

/** Get a single setting value. */
function setting(string $key, ?string $default = null): ?string {
    global $SETTINGS;
    if (!is_array($SETTINGS)) return $default;
    return $SETTINGS[$key] ?? $default;
}

/** Save one or more settings. */
function save_settings(PDO $pdo, array $pairs): void {
    $stmt = $pdo->prepare("
        INSERT INTO settings (key_name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)
    ");
    foreach ($pairs as $k => $v) {
        $stmt->execute([(string)$k, (string)$v]);
    }
}