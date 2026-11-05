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
<div class="panel" id="kronixonconnect-status">
  <h3>{l s='Kronixon Connect' mod='kronixonconnect'}</h3>
  <p><strong>{l s='Not paired.' mod='kronixonconnect'}</strong> {l s='Pairing with Kronixon PI arrives in the next module version.' mod='kronixonconnect'}</p>
  <table class="table">
    <tbody>
      <tr><th>{l s='Module version' mod='kronixonconnect'}</th><td>{$kx_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='Protocol' mod='kronixonconnect'}</th><td>{$kx_protocol|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='PrestaShop' mod='kronixonconnect'}</th><td>{$kx_ps_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='PHP' mod='kronixonconnect'}</th><td>{$kx_php_version|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='Compatibility profile' mod='kronixonconnect'}</th><td>{$kx_compat|escape:'htmlall':'UTF-8'}</td></tr>
      <tr><th>{l s='API endpoint' mod='kronixonconnect'}</th><td><code>{$kx_api_url|escape:'htmlall':'UTF-8'}</code></td></tr>
    </tbody>
  </table>
</div>
