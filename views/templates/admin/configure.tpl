{**
 * AxiTrace configuration page banners and the optional cron URL.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{foreach from=$axitrace_errors item=axitrace_error}
  <div class="alert alert-danger" role="alert" data-axitrace-notice="error">{$axitrace_error|escape:'html':'UTF-8'}</div>
{/foreach}
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
