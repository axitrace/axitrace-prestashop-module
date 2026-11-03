{**
 * AxiTrace storefront configuration (shop-level settings only, never visitor data) and the
 * consent API stub: a consent tool may call window.axitraceConsent.grant()/revoke() before the
 * module script has loaded; the calls are queued and replayed by views/js/axitrace-storefront.js.
 *
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<script type="application/json" id="axitrace-prestashop-config" data-axitrace-prestashop="1">{$axitrace_json nofilter}</script>
<script data-axitrace-prestashop="1">{literal}window.axitraceConsent=window.axitraceConsent||(function(){var q=[];return{_queue:q,grant:function(){q.push('grant');},revoke:function(){q.push('revoke');},isGranted:function(){return false;}};})();{/literal}</script>
