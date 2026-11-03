# Changelog

All notable changes to the AxiTrace PrestaShop module are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
