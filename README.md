# AxiTrace for PrestaShop

Server-side conversion tracking module for PrestaShop 8.1 and 9.x. It installs the AxiTrace web SDK
on your storefront and sends each purchase from your server to AxiTrace, which forwards it to Meta,
Google Ads, GA4, TikTok and the other platforms connected to your workspace, with a deterministic
event id that deduplicates against the browser purchase.

The module is **free** under the Academic Free License 3.0. AxiTrace bills the service that processes
the events on [axitrace.com](https://axitrace.com/pricing); the module has no licence check.

Install guide: <https://axitrace.com/docs/integrations/prestashop>

---

## Requirements

| Component | Version |
|-----------|---------|
| PrestaShop | 8.1.x, 8.2.x, 9.0.x, 9.1.x, 9.2.x |
| PHP | 8.1 or newer |
| Server | Outbound HTTPS (port 443) to your tracking domain |

PrestaShop 1.7 and 8.0 are not supported: the installer refuses them.

## Installation

1. Download `axitrace-prestashop-module-latest.zip` from
   <https://axitrace.com/downloads/axitrace-prestashop-module-latest.zip>.
2. In the back office open **Modules > Module Manager**, click **Upload a module** and drop the ZIP.
3. Click **Configure**, turn on **Enable tracking**, paste your workspace **Public key** and click
   **Save**.

## Settings

| Field | Notes |
|-------|-------|
| Enable tracking | Master switch. Off, or no public key: nothing is rendered or sent. |
| Public key | `pk_live_` followed by 32 characters, from Tracking & domains in your workspace. |
| Secret key | Optional, for profit tracking (costs and refunds). Stored encrypted with the shop key; the field stays empty, leaving it empty keeps the saved key, **Clear the secret key** removes it. |
| Tracking domain | Default `stat.axitrace.com`, or your own subdomain set up in AxiTrace. Host name only. |
| Conversion value | Which order amount is reported as the purchase value. |
| Cookie consent mode | Load immediately, or wait for consent (browser only, or browser and server-side order events). |
| Consent cookie name | The cookie your consent tool sets on accept. |
| Cash on delivery modules | Payment modules whose orders are sent when placed. Default `ps_cashondelivery`. |
| Customer groups excluded from tracking | Customers whose default group is selected are not tracked. |
| Debug mode | Verbose SDK console output. |

With multistore, every setting follows the shop context selected in the back office.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
scripts/build-zip.sh /tmp/axitrace.zip   # ZIP with the single root folder axitrace/
```

Classes that call PrestaShop live in `src/Adapter/`; everything else is plain PHP covered by unit
tests. For local end-to-end tests only, a shop running with `_PS_MODE_DEV_` set to `true` honours the
environment variables `AXITRACE_DEV_API_BASE_URL` and `AXITRACE_DEV_SDK_BASE_URL`; the configuration
page then shows a red "Developer endpoint override active" banner. Production shops never read them.

## License

[Academic Free License 3.0](LICENSE)
