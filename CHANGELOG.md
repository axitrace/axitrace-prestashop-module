# Changelog

All notable changes to the AxiTrace PrestaShop module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
