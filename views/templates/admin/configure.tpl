{**
 * AxiTrace configuration page: error banners, connection health, store domain status and the optional cron URL.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{foreach from=$axitrace_errors item=axitrace_error}
  <div class="alert alert-danger" role="alert" data-axitrace-notice="error">{$axitrace_error|escape:'html':'UTF-8'}</div>
{/foreach}
<div class="panel" id="axitrace-health">
  <div class="panel-heading">{l s='Connection health' d='Modules.Axitrace.Admin'}</div>
  <table class="table">
    <tbody>
    {foreach from=$axitrace_health item=axitrace_row}
      <tr data-axitrace-health="{$axitrace_row.state|escape:'html':'UTF-8'}">
        <th scope="row" style="width: 30%;">{$axitrace_row.label|escape:'html':'UTF-8'}</th>
        <td class="{if $axitrace_row.state == 'error'}text-danger{elseif $axitrace_row.state == 'warning'}text-warning{else}text-success{/if}">{$axitrace_row.value|escape:'html':'UTF-8'}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <p class="help-block">{l s='Shown for the shop selected in the shop context selector. Errors are also written to Advanced Parameters > Logs; search for AxiTrace.' d='Modules.Axitrace.Admin'}</p>
  {if $axitrace_has_failures}
    <form method="post" action="{$axitrace_retry_url|escape:'html':'UTF-8'}">
      <input type="hidden" name="axitrace_dismiss_token" value="{$axitrace_dismiss_token|escape:'html':'UTF-8'}">
      <button type="submit" name="submitAxitraceDismissFailures" value="1" class="btn btn-default">{l s='Dismiss failures' d='Modules.Axitrace.Admin'}</button>
      <span class="help-block">{l s='Clears the failures listed above once you have fixed their cause. A failure that happens again is shown again.' d='Modules.Axitrace.Admin'}</span>
    </form>
  {/if}
</div>
<div class="panel" id="axitrace-store-domain">
  <div class="panel-heading">{l s='Store domain' d='Modules.Axitrace.Admin'}</div>
  <p>{l s='AxiTrace accepts the browser events of your store (page views, add to cart, checkout) only from a verified store domain. The module verifies it automatically when you save the settings and once a day.' d='Modules.Axitrace.Admin'}</p>
  <table class="table">
    <tbody>
    {foreach from=$axitrace_domain item=axitrace_row}
      <tr data-axitrace-domain="{$axitrace_row.state|escape:'html':'UTF-8'}">
        <th scope="row" style="width: 30%;">{$axitrace_row.host|escape:'html':'UTF-8'}</th>
        <td class="{if $axitrace_row.state == 'error'}text-danger{elseif $axitrace_row.state == 'warning'}text-warning{else}text-success{/if}">{$axitrace_row.message|escape:'html':'UTF-8'}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <form method="post" action="{$axitrace_retry_url|escape:'html':'UTF-8'}">
    <input type="hidden" name="axitrace_verify_domain_token" value="{$axitrace_verify_domain_token|escape:'html':'UTF-8'}">
    <button type="submit" name="submitAxitraceVerifyDomain" value="1" class="btn btn-default">{l s='Verify store domain' d='Modules.Axitrace.Admin'}</button>
    <span class="help-block">{l s='Asks AxiTrace to check the domains of all your shops again now.' d='Modules.Axitrace.Admin'}</span>
  </form>
</div>
{if $axitrace_cron_url}
  <div class="panel" id="axitrace-cron">
    <div class="panel-heading">{l s='Cron URL (optional)' d='Modules.Axitrace.Admin'}</div>
    <p>{l s='Failed deliveries are retried automatically at the end of ordinary shop and back-office requests. On a quiet shop, or a server that cannot finish requests in the background, call this URL every 5 to 15 minutes from your hosting cron or an uptime monitor.' d='Modules.Axitrace.Admin'}</p>
    <div class="input-group">
      <input type="text" class="form-control" id="axitrace-cron-url" readonly value="{$axitrace_cron_url|escape:'html':'UTF-8'}">
      <span class="input-group-btn">
        <button type="button" class="btn btn-default" data-axitrace-copy="axitrace-cron-url">{l s='Copy' d='Modules.Axitrace.Admin'}</button>
      </span>
    </div>
  </div>
  <script>
    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-axitrace-copy]') : null;
      if (!button) {
        return;
      }
      var input = document.getElementById(button.getAttribute('data-axitrace-copy'));
      if (!input) {
        return;
      }
      input.select();
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value);
      } else {
        document.execCommand('copy');
      }
    });
  </script>
{/if}
