# Changelog

All notable changes to the AxiTrace PrestaShop module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- JSON stored by the module (retry queue payloads, attribution snapshots, sent purchase details) is
  no longer cut at a `<` (a product named "Ring <5mm>" broke the retry and refund records).
- A refund refused because of the secret key waits in the delivery log ("waiting for a valid secret
  key") and is sent by "Retry now" once the key is fixed, instead of being dropped.
- A purchase that could not be delivered nor queued is sent again at the next status change.
- An order or refund without a readable currency, or a status refund without any known total, is
  never sent; the failure is shown in Connection health.
- One failing row no longer stops a retry batch; retry run failures are shown in Connection health.
- Connection health lists every recorded failure, not only the newest, with a "Dismiss failures"
  button; a missing attribution snapshot under consent mode "browser and server-side" is shown as a
  failure.
- The settings form is protected by the module's own token (a forged POST could change the settings
  on PrestaShop 9).
- An invalid shop time zone is logged as a warning.

## [0.1.0] - 2026-11-04

### Added
- Server-side purchase: sent once per order from the order status change, when the order reaches a
  paid status or is placed with a cash on delivery module (Cash on delivery modules setting), to
  `/v1/plugin/pixel` with the order date in UTC and the same event id as the browser purchase.
  Payment errors, awaiting payment and repeated statuses never send; an order cancelled or refunded
  before it was sent is recorded as void. Customers of an excluded group and orders without consent
  in mode "browser and server-side" are not sent.
- Browser purchase on the order confirmation page, only for paid and cash on delivery orders, once
  per order per browser tab.
- Short timeouts (1 s connect, 2 s total); a rejected secret key resends the purchase without costs;
  rejected public or secret keys are shown on the configuration page.
- Retry queue for purchases and refunds that could not be delivered: same event id,
  retries after 5 minutes, doubling up to 6 hours, at most 10 attempts, failed entries kept 30 days;
  the secret key is never stored in the queue. Retries run at the end of ordinary back-office
  requests, and of storefront requests on PHP-FPM, after the response is sent; no system cron needed.
- Optional cron URL (`/module/axitrace/cron?token=...`, 403 on a wrong token) with a copy button on
  the configuration page.
- Product costs (with the secret key): each purchase line carries its net unit cost in the order
  currency, from the order line's supplier price, else its cost price, else the product's or
  combination's current cost price, converted with the order's conversion rate. A line without a
  cost is sent without one.
- Refunds for profit (with the secret key), gross in the order currency, sent to `/v1/refund` with
  a deterministic refund id: every credit slip (products and shipping), lines cancelled on an order
  already sent (priced from the purchase as it was sent), and an order set to Refunded or Canceled
  without a credit slip (the rest of the order). Each refund is sent once; undelivered refunds go to
  the retry queue.
- psgdpr (PrestaShop's GDPR module): the module is listed among the modules processing personal data;
  data export includes the order records, cart snapshots and queued deliveries of the customer;
  erasure deletes the cart snapshots and queued deliveries and blanks the IP address, user agent,
  cookie and click ids of the customer's orders, keeping the order id and the sent flags.
- Multistore: every order, refund and retry is sent with the settings of the shop the order was
  placed in; a value emptied for one shop stays empty for it.
- Connection health on the configuration page: where events are sent, the last successful delivery,
  the last failure, whether product costs are accepted, the retry queue and the module, PrestaShop
  and PHP versions.
- Delivery log: the last 50 deliveries waiting for a retry or failed, with event id, type, attempts
  and last error, and a Retry now button.
- Every back-office string goes through the PrestaShop translation system
  (`Modules.Axitrace.Admin`), English source.

### Changed
- Upgrade script for 0.1.0 adds the `purchase_snapshot` column to an existing `axitrace_order` table.

## [0.0.1] - 2026-11-03

### Added
- Installable module skeleton for PrestaShop 8.1 to 9.x on PHP 8.1 or newer; older versions are
  refused at install with a clear message.
- Configuration page: Enable tracking, Public key, Secret key (stored encrypted, with Clear the secret
  key), Tracking domain, Conversion value, Cookie consent mode, Consent cookie name, Cash on delivery
  modules, Customer groups excluded from tracking, Debug mode. Multistore aware.
- Tracking domain safety checks: HTTPS only, no IP addresses, no hosts resolving to private networks;
  a refused domain falls back to stat.axitrace.com with an error on the configuration page.
- Module tables, cron token and hook registrations created at install and removed at uninstall.
