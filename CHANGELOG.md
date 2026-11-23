# Changelog

All notable changes to the AxiTrace PrestaShop module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.2] - 2026-11-23

### Fixed
- **Orders lost the Meta browser id (`_fbp`/`_fbc`) when the shop runs Meta's Conversions API Parameter Builder** (for example through a Meta module that uses it). The library appends a 5th part to both cookies (`fb.1.<ts>.<id>.AQEAAQMC`); `SnapshotBuilder` accepted only 4 parts and left fbp/fbc out of the attribution snapshot. Both cookies are now accepted with Meta's appendix (8 base64url characters starting with `AQ`, or a legacy 2-character token) and forwarded unchanged.

## [1.1.1] - 2026-11-22

### Fixed
- The payment method of the add payment info event (the module name of the selected payment option)
  was sent under the key `paymentType`, which AxiTrace does not read, so it was always empty in the
  event. It is now sent as `payment_type`.

## [1.1.0] - 2026-11-22

### Added
- Automatic store domain verification: the module asks AxiTrace to verify the main shop URL of every
  shop (multistore: grouped by workspace keys) when the settings are saved, at install, once a day
  and once right after the upgrade, so the browser events of the store (page views, add to cart,
  checkout) are accepted without adding the domain by hand in AxiTrace. AxiTrace checks it through
  the new proof URL `/index.php?fc=module&module=axitrace&controller=domainproof`, which answers
  for the shop of the requested URL, signed with its secret key when one is saved, also in
  maintenance mode.
- "Store domain" panel on the configuration page: verified, still verifying, or what is wrong in one
  sentence per shop domain, with a "Verify store domain" button.

### Fixed
- The status, cron and proof URLs answered an empty page to a request that accepts JSON: PrestaShop
  runs a controller in ajax mode then and never called their output. The storefront script asks the
  status URL that way, so excluded customer groups were still tracked in the browser (their purchases
  were held back on the server either way).
- Upgrading from 1.0.0 failed and PrestaShop disabled the module: the upgrade script used a class the
  1.0.0 autoloader of the upload request cannot load. It now only marks the domain claim as due.

## [1.0.0] - 2026-11-04

First public release, for PrestaShop 8.1 to 9.x on PHP 8.1 or newer.

### Features
- Browser tracking: page views, product views, add to cart, begin checkout, add payment info and the
  purchase on the confirmation page, through the AxiTrace SDK, with cookie consent modes.
- Server-side purchase from the order status change (paid status, or placed with a cash on delivery
  module), with the same event id as the browser purchase, so it is counted once.
- Product costs and refunds for profit tracking (with the secret key): net unit costs per line, every
  credit slip, line cancellations and Refunded or Canceled orders.
- Retry queue with backoff (5 minutes doubling up to 6 hours, 10 attempts), no system cron needed,
  optional cron URL; refunds refused because of the secret key wait until a valid key is saved.
- Configuration page with Connection health (every recorded failure with its time and count, Dismiss
  failures), delivery log with Retry now, and banners for rejected keys and refused tracking domains.
- Multistore: every order, refund and retry uses the settings of its own shop.
- psgdpr export and erasure of the module's records.

### Fixed since 0.1.0
- JSON stored by the module is no longer cut at a `<` (product names such as "Ring <5mm>").
- A purchase or refund that could not be delivered nor queued is retried instead of lost; one failing
  row no longer blocks a retry batch.
- A failed or refused queue row is never brought back to the queue; a delivered purchase or refund
  whose local state could not be saved is reported as delivered, so it is not sent twice by hand.
- An order or refund without a readable currency, or a status refund without any known total, is
  never sent; the failure is shown in Connection health.
- The settings form is protected by the module's own token (a forged POST could change the settings
  on PrestaShop 9).

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
