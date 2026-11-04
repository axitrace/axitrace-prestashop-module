{**
 * AxiTrace delivery log: the newest queued and failed deliveries, and "Retry now".
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<div class="panel" id="axitrace-delivery-log">
  <div class="panel-heading">{l s='Delivery log' d='Modules.Axitrace.Admin'}</div>
  <p>{l s='The last 50 deliveries waiting for a retry, waiting for a valid secret key or failed for good. Delivered events are not listed.' d='Modules.Axitrace.Admin'}</p>
  {if $axitrace_log}
    <div class="table-responsive">
      <table class="table" data-axitrace-log="1">
        <thead>
          <tr>
            <th>{l s='Event id' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Type' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Status' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Attempts' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Next attempt (UTC)' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Last error' d='Modules.Axitrace.Admin'}</th>
            <th>{l s='Queued at (UTC)' d='Modules.Axitrace.Admin'}</th>
          </tr>
        </thead>
        <tbody>
        {foreach from=$axitrace_log item=axitrace_entry}
          <tr{if $axitrace_entry.failed} class="danger"{/if}>
            <td><code>{$axitrace_entry.event_id|escape:'html':'UTF-8'}</code></td>
            <td>{$axitrace_entry.type|escape:'html':'UTF-8'}</td>
            <td>{$axitrace_entry.status|escape:'html':'UTF-8'}</td>
            <td>{$axitrace_entry.attempts|escape:'html':'UTF-8'}</td>
            <td>{$axitrace_entry.next_attempt_at|escape:'html':'UTF-8'}</td>
            <td>{$axitrace_entry.last_error|escape:'html':'UTF-8'}</td>
            <td>{$axitrace_entry.created_at|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <p class="text-success" data-axitrace-log="0">{l s='Nothing is waiting for a retry and nothing failed.' d='Modules.Axitrace.Admin'}</p>
  {/if}
  <form method="post" action="{$axitrace_retry_url|escape:'html':'UTF-8'}">
    <input type="hidden" name="axitrace_retry_token" value="{$axitrace_retry_token|escape:'html':'UTF-8'}">
    <button type="submit" name="submitAxitraceRetryNow" value="1" class="btn btn-default"{if !$axitrace_log} disabled{/if}>{l s='Retry now' d='Modules.Axitrace.Admin'}</button>
    <span class="help-block">{l s='Sends every delivery waiting for a retry or for a valid secret key at once. Failed deliveries are not sent again.' d='Modules.Axitrace.Admin'}</span>
  </form>
</div>
