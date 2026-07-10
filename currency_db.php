<?php
/*
 * currency_db.php
 *
 * Shared helpers for the currency conversion feature. Included by
 * convert_currency.php and get_conversions.php.
 *
 * The account balance is ALWAYS stored in EUR — converting only changes which
 * currency the app displays, but the exchange FEE is real: 0.5% of the balance
 * is charged in EUR (house transaction "Currency Exchange Fee - X→Y") and every
 * conversion is recorded in the currency_conversions table with the rate, the
 * fee and the exact amount the user receives in the target currency.
 *
 * IMPORTANT: the rates here must stay in sync with CURRENCIES in the app's
 * src/currency/CurrencyContext.js (the client shows the quote, the server is
 * the source of truth when the conversion is executed).
 *
 * Requires config.php (provides $conn) to have been included.
 */

// Fee charged on every balance conversion, in percent.
if (!defined("CURRENCY_FEE_PCT")) {
    define("CURRENCY_FEE_PCT", 0.5);
}

/* Static exchange rates relative to EUR: 1 EUR = rate units of the currency. */
function currencyRates() {
    return [
        "EUR" => 1.0,
        "USD" => 1.17,
        "GBP" => 0.86,
        "CHF" => 0.94,
    ];
}

/* Create the conversions table once (cheap + idempotent on every request). */
function ensureCurrencySchema($conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS currency_conversions (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            from_code VARCHAR(8) NOT NULL,
            to_code VARCHAR(8) NOT NULL,
            rate DECIMAL(14,6) NOT NULL,
            fee_percent DECIMAL(6,3) NOT NULL,
            amount_eur DECIMAL(12,2) NOT NULL,
            amount_from DECIMAL(12,2) NOT NULL,
            fee_eur DECIMAL(12,2) NOT NULL,
            fee_from DECIMAL(12,2) NOT NULL,
            amount_received DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_currency_conversions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}
