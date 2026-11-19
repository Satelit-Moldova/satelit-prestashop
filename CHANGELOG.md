# Changelog

## 1.0.0 (2026-11-19)

- BNM exchange rates for PrestaShop currencies from `/v1/rates`, any default currency, BNM `nominal` handled: "Update rates now" and a cron URL with a secret token.
- "Market price" on product pages (`displayProductAdditionalInfo`) with min and median price and the cheapest offer per shop.
- Search term per product: product page Modules tab (legacy product page) and the module configuration (every product page).
- Configuration form with Test connection, TTLs, data language. Translations en, ro, ru.
- Own cache table, stale copy and a 10-minute pause when the API fails.
