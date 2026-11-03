{**
 * AxiTrace configuration page banners.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{foreach from=$axitrace_errors item=axitrace_error}
  <div class="alert alert-danger" role="alert" data-axitrace-notice="error">{$axitrace_error|escape:'html':'UTF-8'}</div>
{/foreach}
