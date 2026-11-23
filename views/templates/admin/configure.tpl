{**
 * Kronixon Connect for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available at https://opensource.org/licenses/AFL-3.0
 *
 * @author    Kronixon
 * @copyright 2026 Kronixon
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{if $kx_notice}<div class="alert {if $kx_notice_error}alert-danger{else}alert-info{/if}" id="kx-notice">{$kx_notice|escape:'htmlall':'UTF-8'}</div>{/if}
{if $kx_error}<div class="alert alert-danger" id="kx-error">{$kx_error|escape:'htmlall':'UTF-8'}</div>{/if}
{if $kx_warning}<div class="alert alert-warning" id="kx-warning">{$kx_warning|escape:'htmlall':'UTF-8'}</div>{/if}
{if !$kx_sodium}<div class="alert alert-danger">{l s='The PHP extension sodium is required for pairing. Ask your hosting provider to enable it.' mod='kronixonconnect'}</div>{/if}

<div class="panel" id="kronixonconnect-status">
  <h3>{l s='Kronixon Connect' mod='kronixonconnect'}</h3>
  {if $kx_paired}
    <p id="kx-status" data-status="paired"><span class="badge badge-success label label-success">{l s='Paired' mod='kronixonconnect'}</span>
      {if $kx_tenant}{l s='Workspace:' mod='kronixonconnect'} <strong>{$kx_tenant|escape:'htmlall':'UTF-8'}</strong>{/if}</p>
    <table class="table">
      <tbody>
        <tr><th>{l s='Store ID' mod='kronixonconnect'}</th><td><code>{$kx_store_id|escape:'htmlall':'UTF-8'}</code></td></tr>
        <tr><th>{l s='Paired at' mod='kronixonconnect'}</th><td>{$kx_paired_at|escape:'htmlall':'UTF-8'}</td></tr>
        <tr><th>{l s='Last signed request from Kronixon PI' mod='kronixonconnect'}</th><td>{if $kx_last_request}{$kx_last_request|escape:'htmlall':'UTF-8'}{else}{l s='none yet' mod='kronixonconnect'}{/if}</td></tr>
        <tr><th>{l s='Platform URL' mod='kronixonconnect'}</th><td><code>{$kx_platform_url|escape:'htmlall':'UTF-8'}</code></td></tr>
      </tbody>
    </table>
    <form method="post" action="{$kx_action|escape:'htmlall':'UTF-8'}" class="form-inline">
      <button type="submit" name="submitKxPing" class="btn btn-default">{l s='Test the connection' mod='kronixonconnect'}</button>
      <button type="submit" name="submitKxUnpair" class="btn btn-danger" onclick="return confirm('{l s='Unpair this shop from Kronixon PI?' mod='kronixonconnect' js=1}');">{l s='Unpair' mod='kronixonconnect'}</button>
    </form>
  {else}
    <p id="kx-status" data-status="unpaired"><span class="badge label label-default">{l s='Not paired' mod='kronixonconnect'}</span>
      {l s='In Kronixon PI, open Stores, choose "Connect a store" and enter the code it shows here.' mod='kronixonconnect'}</p>
    <form method="post" action="{$kx_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-3" for="kx_code">{l s='Pairing code' mod='kronixonconnect'}</label>
        <div class="col-lg-3">
          <input type="text" id="kx_code" name="kx_code" maxlength="12" autocomplete="off" placeholder="XXXX-XXXX" class="form-control" required>
        </div>
        <div class="col-lg-3">
          <button type="submit" name="submitKxPair" class="btn btn-primary"{if !$kx_sodium} disabled{/if}>{l s='Pair' mod='kronixonconnect'}</button>
        </div>
      </div>
    </form>
    <form method="post" action="{$kx_action|escape:'htmlall':'UTF-8'}" class="form-horizontal">
      <div class="form-group">
        <label class="control-label col-lg-3" for="kx_platform_url">{l s='Platform URL' mod='kronixonconnect'}</label>
        <div class="col-lg-5">
          <input type="url" id="kx_platform_url" name="kx_platform_url" value="{$kx_platform_url|escape:'htmlall':'UTF-8'}" class="form-control">
        </div>
        <div class="col-lg-2">
          <button type="submit" name="submitKxPlatformUrl" class="btn btn-default">{l s='Save' mod='kronixonconnect'}</button>
        </div>
      </div>
    </form>
  {/if}
</div>

{if $kx_paired}
<div class="panel" id="kronixonconnect-events">
  <h3>{l s='Store events' mod='kronixonconnect'}</h3>
  {if $kx_delivery == 'pull_only'}<div class="alert alert-warning" id="kx-delivery-warning">{l s='Set up the cron URL below. No cron calls it, and this server cannot send changes after a page view, so store events reach Kronixon PI only when it pulls them.' mod='kronixonconnect'}</div>{/if}
  <table class="table">
    <tbody>
      <tr><th>{l s='Waiting to be sent' mod='kronixonconnect'}</th><td id="kx-outbox-pending">{$kx_outbox_pending|intval}</td></tr>
      <tr><th>{l s='Failed' mod='kronixonconnect'}</th><td id="kx-outbox-failed">{$kx_outbox_failed|intval}</td></tr>
      <tr><th>{l s='Last delivery' mod='kronixonconnect'}</th><td>{if $kx_outbox_last_sent}{$kx_outbox_last_sent|escape:'htmlall':'UTF-8'}{else}{l s='none yet' mod='kronixonconnect'}{/if}</td></tr>
      {if $kx_outbox_error}<tr><th>{l s='Last error' mod='kronixonconnect'}</th><td><code>{$kx_outbox_error|escape:'htmlall':'UTF-8'}</code></td></tr>{/if}
      {if $kx_outbox_retry}<tr><th>{l s='Next attempt' mod='kronixonconnect'}</th><td>{$kx_outbox_retry|escape:'htmlall':'UTF-8'}</td></tr>{/if}
      <tr><th>{l s='Cron URL' mod='kronixonconnect'}</th><td><code>{$kx_cron_url|escape:'htmlall':'UTF-8'}</code></td></tr>
    </tbody>
  </table>
  <p class="help-block">{l s='Call this URL every minute from the cron of your hosting. Without a cron, changes are sent after page views only under PHP-FPM or LiteSpeed; otherwise only when Kronixon PI pulls them.' mod='kronixonconnect'}</p>
  <p class="help-block">{l s='The token is part of the URL, so it is written to the access log of your web server. If it leaked, make a new one.' mod='kronixonconnect'}</p>
  <form method="post" action="{$kx_action|escape:'htmlall':'UTF-8'}" class="form-inline">
    <button type="submit" name="submitKxRotateCron" class="btn btn-default" onclick="return confirm('{l s='Make a new cron token? The old cron URL stops working.' mod='kronixonconnect' js=1}');">{l s='New cron token' mod='kronixonconnect'}</button>
  </form>
</div>
{/if}

<div class="panel" id="kronixonconnect-about">
  <h3>{l s='About this module' mod='kronixonconnect'}</h3>
  <table class="table">
    <tbody>
      <tr><th>{l s='Module version' mod='kronixonconnect'}</th><td>{$kx_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='Protocol' mod='kronixonconnect'}</th><td>{$kx_protocol|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='PrestaShop' mod='kronixonconnect'}</th><td>{$kx_ps_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='PHP' mod='kronixonconnect'}</th><td>{$kx_php_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='Compatibility profile' mod='kronixonconnect'}</th><td>{$kx_compat|escape:'htmlall':'UTF-8'}</td></tr>
    </tbody>
  </table>
</div>
