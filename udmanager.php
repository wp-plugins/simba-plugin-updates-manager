<?php
/*
Plugin Name: Simba Plugins Manager
Plugin URI: https://www.simbahosting.co.uk
Description: Management of plugin updates + licences
Author: David Anderson
Version: 1.4.3
License: GPLv2+ / MIT
Text Domain: updraftmanager
Author URI: http://david.dw-perspective.org.uk
*/ 

if (!defined('ABSPATH')) die('No direct access.');

define('UDMANAGER_VERSION', '1.4.3');
define('UDMANAGER_DIR', dirname(realpath(__FILE__)));
define('UDMANAGER_URL', plugins_url('', realpath(__FILE__)));
define('UDMANAGER_SLUG', basename(UDMANAGER_DIR));

/* TODO:

Some of these are out of date/no longer issues - needs pruning

Lots of PHP warnings when visiting: http://localhost/ud/wp-admin/admin.php?page=updraftmanager_add_new (maybe somethign not copied correctly from web-22may14/wp-content/plugins/simba-plugin-manager/ ?)

POST-LAUNCH:

We need a reaper that will delete entitlements that expired > ?12? months ago (to prevent spurious/annoying 'has expired!' notices). Or perhaps the get_user_addons routines should have a flag that causes them to ignore stuff that old that will be used by the updates checker (but other consumers will continue to use it)

Reset uninstalled licences

Check that the weekly cache-cleaning code is working.

Allow re-claiming of an entitlement on the same URL / different sid (i.e. handle re-installs). Test this.

Admin's management interface for entitlements: make more sophisticated. Switch alert for jQuery, and provide precise dates, not just by month.

Future/post-selling (stuff that is nice to have):

Provide stats on how many active sites there are (can use last-checkin stats - just note that those behind firewalls or non-activated won't be included)

Display last check-in time too (or perhaps, only if admin)

Need a link to allow the user to extend his entitlement (i.e. allow site owner to specify where the link goes to)

Provide a manual button to purge the cache.

Provide statistics on how many actively accessing sites (using the updraftmanager_plugins usermeta data) + the spread of PHP/WP/plugin versions they are using

Should go in Connector: Add option, for each user, to add their plugin license info to the My Account WC page. Useful snippet:
add_action('woocommerce_after_available_downloads', 'my_woocommerce_after_available_downloads');
function my_woocommerce_after_available_downloads() {
        echo "<div style=\"padding: 16px 0;\"><h2>Plugin Licences</h2>";
        echo do_shortcode('[udmanager userid="1"]');
        echo '</div>';
}


*/

require_once(UDMANAGER_DIR.'/options.php');
require_once(UDMANAGER_DIR.'/classes/updraftmanager.php');
if (!defined('UDMANAGER_DISABLEPREMIUM') || !UDMANAGER_DISABLEPREMIUM) @include_once(UDMANAGER_DIR.'/premium/load.php');

if (empty($updraftmanager_options) || !is_object($updraftmanager_options) || !is_a($updraftmanager_options, 'UpdraftManager_Options')) $updraftmanager_options = new UpdraftManager_Options;

$updraft_manager = new Updraft_Manager;

register_activation_hook(__FILE__, array($updraft_manager, 'activation'));
register_deactivation_hook(__FILE__, array($updraft_manager, 'deactivation'));
