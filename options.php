<?php
if (!defined ('ABSPATH')) die ('No direct access allowed');

/* TODO:

Some of these tasks are obsolete or complete - needs pruning

Test - re-check for any possible leaks

Need to print out the code needed to check for updates on the client end

Search for other TODOs

Not sure if WP_List_Table sanitises HTML for us.

Downloads tracking - how many times each downloaded? (Store in 'zips' array, keyed by day).

Need UI to manually, for any given plugin (and link to this from the "Users" screen as well as from plugins display - so that they can effectively search by user or by plugin)
- Show user entitlements
- Add user entitlement
- Delete user entitlement
- Reset user entitlement
- Grant user entitlement
When doing that, have either unlimited or X-month grants
Those should be stored as user meta.

Need to re-write a user entitlement 

Need to show the download URL for the zip in the shortcode code - optionally (so, must also activate the options page)

With free plugins, usernames are ignored - should note this somewhere in the zip rules stuff (+ prevent use of that field)

*/

class UpdraftManager_Options {

	public function __construct() {
		add_action('admin_head', array($this, 'admin_head'));
		add_action('admin_menu', array($this, 'admin_menu'));
		add_filter('plugin_action_links', array($this, 'action_links'), 10, 2 );
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('wp_ajax_udmanager_ajax', array($this, 'ajax_handler'));
	}

	public static function get_options($uid = false) {
		if (false === $uid) $uid = get_current_user_id();
		return get_user_meta($uid, 'updraftmanager_plugins', true);
		#return get_option('updraftmanager_plugins');
	}

	public static function update_options($opts, $uid = false) {
		if (false === $uid) $uid = get_current_user_id();
		return update_user_meta($uid, 'updraftmanager_plugins', $opts);
		#return update_option('updraftmanager_plugins', $opts);
	}

	public static function get_manager_dir($parent = false, $uid = false, $url = false) {
		$upload_dir = wp_upload_dir();
		$dir = (($url) ? $upload_dir['baseurl'] : $upload_dir['basedir']).'/updraftmanager';
		if ($parent) return $dir;
		return ($uid === false) ? $dir.'/'.get_current_user_id() : $dir.'/'.$uid;
	}

	public static function remove_local_directory($dir, $contents_only = false) {
		// PHP 5.3+ only
// 		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
// 			$path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
// 		}
// 		return ($contents_only) ? true : rmdir($dir);
		$d = dir($dir);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry) {
				if (is_dir($dir.'/'.$entry)) {
					self::remove_local_directory($dir.'/'.$entry, false);
				} else {
					@unlink($dir.'/'.$entry);
				}
			}
		}
		$d->close();
		return ($contents_only) ? true : rmdir($dir);
	}

	public static function manage_permission() {
		return 'manage_options';
	}

	public function ajax_handler() {

		if (!current_user_can($this->manage_permission())) return;

		if (empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'updraftmanager-ajax-nonce') || empty($_REQUEST['subaction'])) die('Security check');
		switch ($_REQUEST['subaction']) {
			case 'reorderrules':
				if (empty($_POST['order']) || empty($_POST['slug'])) break;
				# Relevant keys: slug, order
				$plugins = $this->get_options();
				if (empty($plugins[$_POST['slug']])) break;
				$plugin = $plugins[$_POST['slug']];
				$rules = (empty($plugin['rules'])) ? array() : $plugin['rules'];
				# Sanity checks
				$newrules = array();
				$new_order = explode(',', $_POST['order']);
				foreach ($new_order as $nord) {
					if (!is_numeric($nord)) break(2);
					$ind = $nord-1;
					if ($ind<0) break(2);
					if (empty($rules[$ind])) break(2);
					$newrules[] = $rules[$ind];
				}
				# Sanity checks have been passed
				$plugin['rules'] = $newrules;
				$plugins[$_POST['slug']] = $plugin;
				$this->update_options($plugins);
				echo json_encode(array('r' => 'ok'));
				die;
			break;
		}
		do_action('udmanager_ajax_event');
		echo json_encode(array('r' => 'invalid'));
		die;
	}

	public function admin_head() {
		if (!current_user_can($this->manage_permission())) return;
		echo "<script>var updraftmanager_freeversion = 1;</script>\n";
	}

	public function admin_enqueue_scripts($hook) {
		if (strpos($hook, 'updraftmanager') === false) return;
		wp_enqueue_style('updraftmanager_css', UDMANAGER_URL.'/css/admin.css' );
		wp_enqueue_script('updraftmanager-admin-js', UDMANAGER_URL.'/js/admin.js', array('jquery', 'jquery-ui-sortable', 'jquery-color'));
		wp_enqueue_script('jquery-blockui', UDMANAGER_URL.'/js/jquery.blockui.js', array('jquery'));
		$managerurl = $this->get_manager_dir(false, false, true);
		wp_localize_script('updraftmanager-admin-js', 'updraftmanagerlion', array(
			'areyousureplugin' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'updraftmanager'), __('plugin', 'updraftmanager')),
			'areyousurezip' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'updraftmanager'), __('zip', 'updraftmanager')),
			'areyousurerule' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'updraftmanager'), __('rule', 'updraftmanager')),
			'ajaxnonce' => wp_create_nonce('updraftmanager-ajax-nonce'),
			'rule' => __('Rule', 'updraftmanager'),
			'applyalways' => esc_attr(__('Apply this rule always', 'updraftmanager')),
			'alwaysmatch' => __('Always match', 'updraftmanager'),
			'version' => __('Apply this rule if the site checking already has a specified version installed', 'updraftmanager'),
			'installedversion' => __('Installed plugin version', 'updraftmanager'),
			'equals' => __('equals', 'updraftmanager'),
			'lessthan' => __('is at least (>=)', 'updraftmanager'),
			'greaterthan' => __('is at most (<=)', 'updraftmanager'),
			'range' => __('is between', 'updraftmanager'),
			'rangeexplain' => __('If you have chosen a range (in between), specify the (inclusive) end points using a comma; for example: 1.0,2.1', 'updraftmanager'),
			'ifwp' => esc_attr(__('Apply this rule if the site checking has a particular version of WordPress installed', 'updraftmanager')),
			'wpver' => __('WordPress version', 'updraftmanager'),
			'ifphp' => esc_attr(__('Apply this rule if the site checking has a particular version of PHP installed', 'updraftmanager')),
			'phpver' => __('PHP version', 'updraftmanager'),
			'ifusername' => esc_attr(__('Apply this rule if the site checking belongs to a specified user from this site', 'updraftmanager')),
			'username' => __('Username', 'updraftmanager'),
			'ifsiteurl' => esc_attr(__('Apply this rule if the site checking has a specific site URL', 'updraftmanager')),
			'processing' => __('Processing...', 'updraftmanager'),
			'httpnotblocked' => __('It is possible to access the contents of our the directory which we want to keep private via HTTP. This means that apparently the .htaccess file placed there is not working (perhaps your webserver uses a different mechanism). You should prevent access to this directory; otherwise unauthenticated users will be able to directly download your plugins. The directory is: ', 'updraftmanager').$managerurl,
			'siteurl' => __('Site URL', 'updraftmanager'),
			'delete' => __('Delete', 'updraftmanager'),
			'slug' => (empty($_GET['slug'])) ? '' : $_GET['slug'],
			'managerurl' => $managerurl
		));
	}

	public function show_admin_warning($message, $class = "updated", $escape = true) {
		echo '<div class="'.$class.'">'."<p>".(($escape) ? htmlspecialchars($message) : $message)."</p></div>";
	}

	public function admin_menu() {

		$perm = $this->manage_permission();

		# http://codex.wordpress.org/Function_Reference/add_options_page
		add_menu_page('Simba Plugins Manager', __('Plugins Manager', 'updraftmanager'), $perm, 'updraftmanager', array($this, 'options_printpage'), '', '59.1756344');

		add_submenu_page('updraftmanager', __('Plugins Manager', 'updraftmanager').' - '.__('Add New Plugin', 'updraftmanager'), __('Add New', 'updraftmanager'), $perm, 'updraftmanager_add_new', array($this, 'options_printpage'));

		#add_submenu_page('updraftmanager', __('Plugins Manager', 'updraftmanager').' - '.__('Options', 'updraftmanager'), __('Options', 'updraftmanager'), $perm, 'updraftmanager_options', array($this, 'options_printpage'));
	}

	public function action_links($links, $file) {
		if ( $file == UDMANAGER_SLUG."/udmanager.php" ){
			array_unshift( $links, 
				'<a href="admin.php?page=updraftmanager">'.__('Plugins', 'updraftmanager').'</a>',
				'<a href="http://updraftplus.com">'.__('UpdraftPlus WordPress backups', 'updraftmanager').'</a>'
			);
		}
		return $links;
	}

	# This is the function outputing the HTML for our options page
	public function options_printpage() {
		if (!current_user_can($this->manage_permission())) wp_die( __('You do not have sufficient permissions to access this page.') );

		echo '<div style="clear: left;width:950px; float: left; margin-right:20px;">
		<h1>'.__('Simba Plugins Manager', 'updraftmanager').'</h1>';

		# Warnings go here
		# TODO: Directory fetch warning

		echo '<div class="wrap">';

		$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : '';
		if (empty($action)) {
			global $plugin_page;
			if ('updraftmanager_' == substr($plugin_page, 0, 15)) $action = substr($plugin_page, 15);
		}

		switch ($action) {
			case 'activate':
			case 'deactivate':
				if (!empty($_GET['slug'])) {
					$slug = $_GET['slug'];
					$plugins = $this->get_options();
					if (isset($plugins[$slug])) {
						$plugins[$slug]['active'] = ('activate' == $action) ? true : false;
						$this->update_options($plugins);
						$this->show_admin_warning(('activate' == $action) ? __('Plugin activated.', 'updraftmanager') : __('Plugin de-activated.', 'updraftmanager'));
					}
				}
				$this->show_plugins();
				break;
			case 'delete':
				if (!empty($_GET['slug'])) {
					$slug = $_GET['slug'];
					$plugins = $this->get_options();
					if (isset($plugins[$slug])) {
						$zips = (!empty($plugins[$slug]['zips']) && is_array($plugins[$slug]['zips'])) ? $plugins[$slug]['zips'] : array();
						$manager_dir = $this->get_manager_dir();
						foreach ($zips as $zip) {
							if (!empty($zip['filename']) && is_file($manager_dir.'/'.$zip['filename'])) unlink($manager_dir.'/'.$zip['filename']);
						}
						unset($plugins[$slug]);
						$this->update_options($plugins);
						$this->show_admin_warning(__('Plugin deleted.', 'updraftmanager'));
					}
				}
				$this->show_plugins();
				break;
			case 'managezips':
			case 'add_new_zip':
			case 'add_new_zip_go':
			case 'edit_zip':
			case 'edit_zip_go':
			case 'delete_zip':
			case 'add_new_rule':
			case 'add_new_rule_go':
			case 'edit_rule':
			case 'edit_rule_go':
			case 'delete_rule':
				if (!empty($_REQUEST['slug'])) {
					$slug = $_REQUEST['slug'];
					$plugins = $this->get_options();
					if (isset($plugins[$slug])) {
						require_once(UDMANAGER_DIR.'/classes/updraftmanager-manage-zips.php');
						$udmanager_manage_zips = new UpdraftManager_Manage_Zips($slug, $plugins[$slug]);
						call_user_func(array($udmanager_manage_zips, $action));
					}
				}
				break;
			case 'edit';
				$plugins = $this->get_options();
				if (!empty($_GET['oldslug'])) {
					$slug = $_GET['oldslug'];
					if (isset($plugins[$slug])) {
						$this->add_edit_form($plugins[$slug], true);
					} else {
						$this->show_plugins();
					}
				} else {
					$this->show_plugins();
				}
				break;
			case 'edit_go':
				$this->add_edit_form(stripslashes_deep($_POST), true);
				break;
			case 'add_new':
			case 'add_new_go':
				$this->add_edit_form(stripslashes_deep($_POST));
				break;
			case 'options':
				$this->options_page();
				break;
			default:
				$this->show_plugins();
		}

		echo '</div>';

	}

	private function options_page() {
		# TODO
	}

	protected function set_free_plugin_status($x) {
		return true;
	}

	private function add_edit_form($use_values, $editing = false) {

		if (!empty($use_values['action']) && ('edit_go' == $use_values['action'] || 'add_new_go' == $use_values['action'])) {
			$errors = array();
			if (empty($use_values['name'])) {
				$errors[] = __('The plugin name cannot be empty.', 'updraftmanager');
			}
			$plugins = $this->get_options(); if (!is_array($plugins)) $plugins=array();
			if (empty($use_values['slug'])) {
				$errors[] = __('The plugin slug cannot be empty.', 'updraftmanager');
			} else {
				$slug = $use_values['slug'];
				if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
					$errors[] = __('The plugin slug should contain lower-case letters, numerals and hyphens only.', 'updraftmanager');
				} elseif (!$editing || $use_values['oldslug'] != $slug) {
					if (isset($plugins[$slug])) {
						$errors[] = __('A plugin already exists with that slug.', 'updraftmanager');
					}
				}
			}
			if (0 == count($errors)) {
				# Place in database
				$existing_zips = (!empty($plugins[$slug]['zips'])) ? $plugins[$slug]['zips'] : array();
				$existing_rules = (!empty($plugins[$slug]['rules'])) ? $plugins[$slug]['rules'] : array();
				if (!empty($_REQUEST['oldslug']) && $slug != $_REQUEST['oldslug']) {
					$oldslug = $_REQUEST['oldslug'];
					unset($plugins[$oldslug]);
				}
				$plugins[$slug] = array(
					'slug' => $slug,
					'name' => $use_values['name'],
					'description' => $use_values['description'],
					'author' => $use_values['author'],
					'zips' => $existing_zips,
					'addonsdir' => (isset($use_values['addonsdir'])) ? $use_values['addonsdir'] : '',
					'rules' => $existing_rules,
					'active' => (!empty($use_values['active'])) ? true : false,
					'homepage' => $use_values['homepage'],
					'freeplugin' => $this->set_free_plugin_status(empty($use_values['freeplugin']) ? false : true),
				);
// 					'minwpver' => $use_values['minwpver'],
// 					'testedwpver' => $use_values['testedwpver'],
				$this->update_options($plugins);
				$message = ($editing) ? __('Plugin edited.', 'updraftmanager') : __('Plugin added.', 'updraftmanager');
				if (empty($plugins[$slug]['zips'])) $message .= ' <a href="?page=updraftmanager&amp;action=add_new_zip&amp;slug='.$slug.'">'.__('You should now add some zips for the plugin itself.', 'updraftmanager').'</a>';
				$this->show_admin_warning($message, 'updated', false);
				$this->show_plugins();
				return;
			}
			// Errors
			$html = '<ul style="list-style:disc; padding-left:14px;">';
			foreach ($errors as $message) {
				$html .= '<li>'.htmlspecialchars($message).'</li>';
			}
			$html .= "</ul>";
			$this->show_admin_warning('<strong>'.__('Please correct these errors and try again:', 'updraftmanager').'</strong>'.$html, 'error');
		}

		?><h2><?php echo ($editing) ? __('Edit Plugin', 'updraftmanager') : __('Add New', 'updraftmanager'); ?></h2>

		<?php if (!$editing) { ?>
			<p>
				<strong><?php _e('First you must add the plugin details here; and then you will be able to upload zip files for it afterwards.', 'updraftmanager');?></strong>
			</p>
		<?php } ?>


		<div id="updraftmanager_form">
		<form method="post">

			<input type="hidden" name="action" value="<?php echo (($editing) ? 'edit_go' : 'add_new_go'); ?>">
			<input type="hidden" name="page" value="updraftmanager">
			<input type="hidden" name="oldslug" value="<?php
				if ($editing) {
					echo (isset($use_values['oldslug'])) ? $use_values['oldslug'] : $use_values['slug'];
				}
			?>">

			<label for="udm_newform_text"><?php _e('Plugin name (*):', 'updraftmanager');?></label> <input id="udm_newform_text" type="text" name="name" value="<?php echo (isset($use_values['name'])) ? htmlspecialchars($use_values['name']) : ''; ?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('A short textual name, e.g. "Wurgleflub Super Forms".', 'updraftmanager'));?></em></span>

			<label for="udm_newform_slug"><?php _e('Plugin slug (*):', 'updraftmanager');?></label> <input id="udm_newform_slug" type="text" name="slug" value="<?php echo (isset($use_values['slug'])) ? htmlspecialchars($use_values['slug']) : ''; ?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter the slug used by the plugin zip, i.e. the directory name that the plugin will live in, e.g. "wurgleflub-super-forms".', 'updraftmanager'));?></em></span>

			<label for="udm_newform_author"><?php _e('Plugin author:', 'updraftmanager');?></label> <input id="udm_newform_author" type="text" name="author" value="<?php
				if (!$editing) {
					$user = wp_get_current_user();
					echo htmlspecialchars($user->display_name);
				} else {
					echo (isset($use_values['author'])) ? htmlspecialchars($use_values['author']) : '';
				}
			?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter the author of the plugin.', 'updraftmanager'));?></em></span>

			<label for="udm_newform_description"><?php _e('Description:', 'updraftmanager');?></label> <input id="udm_newform_description" type="text" name="description" value="<?php echo (isset($use_values['description'])) ? htmlspecialchars($use_values['description']) : ''; ?>" size="60">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('This is shown in the WordPress dashboard when showing update information.', 'updraftmanager'));?></em></span>

			<label for="udm_newform_freeplugin"><?php _e('Free plugin:', 'updraftmanager');?></label>

			<?php echo apply_filters('updraftmanager_newplugin_freeplugin', '<span class="udm_description">'.__('Yes', 'updraftmanager').'</span><input type="hidden" name="freeplugin" value="yes">', $use_values); ?>

			<span class="udm_description"><em><?php echo htmlspecialchars(__("If this option is set, then no user account is needed to obtain the plugin and its updates, and no tracking of users' entitlements takes place.", 'updraftmanager'));?></em></span>

			<!--
			<label for="udm_newform_minwpver">Minimum WordPress version required:</label> <input id="udm_newform_minwpver" type="text" name="minwpver" value="<?php echo (isset($use_values['minwpver'])) ? htmlspecialchars($use_values['minwpver']) : ''; ?>" size="8" maxlength="8">
			<span class="udm_description"><em></em></span>

			<label for="udm_newform_testedwpver">Tested up to WordPress version:</label> <input id="udm_newform_testedwpver" type="text" name="testedwpver" value="<?php echo (isset($use_values['testedwpver'])) ? htmlspecialchars($use_values['testedwpver']) : ''; ?>" size="8" maxlength="8">
			<span class="udm_description"><em></em></span>
			-->

			<label for="udm_newform_homepage"><?php _e('Plugin homepage:', 'updraftmanager');?></label> <input id="udm_newform_homepage" type="text" name="homepage" value="<?php echo (isset($use_values['homepage'])) ? htmlspecialchars($use_values['homepage']) : ''; ?>" size="60">
			<span class="udm_description"><em><?php echo htmlspecialchars(__("This is shown in the WordPress dashboard when showing update information. Should be a URL.", 'updraftmanager'));?></em></span>

			<?php
				$hid = (!empty($use_values['freeplugin'])) ? 'style="display:none;" ' : '';
			?>

			<label <?php echo $hid; ?>class="udm_newform_addonsrow" for="udm_newform_addonsdir"><?php _e('Add-ons directory:', 'updraftmanager');?></label> 

			<?php
				echo apply_filters('updraftmanager_newplugin_addonsdir', '<span '.$hid.'class="udm_description"><em>'.__('Not applicable for free plugins', 'updraftmanager'), $use_values, $hid).'</em></span>';
			?>

			<label for="udm_newform_active"><?php _e('Active:', 'updraftmanager');?></label> <input id="udm_newform_active" type="checkbox" name="active" value="yes" <?php if (!empty($use_values['active'])) echo 'checked="checked" '; ?>>
			<span class="udm_description"><em><?php echo htmlspecialchars(__("This plugin will not be live on the system unless you check this box.", 'updraftmanager'));?></em></span>

			<input type="submit" class="button-primary" value="<?php echo (($editing) ? __('Edit Plugin', 'updraftmanager') : __('Add Plugin', 'updraftmanager')); ?>">

		</form>

		</div>


		<?php
	}

	private function show_plugins() {

		echo '<p>'.sprintf(__('Version: %s', 'updraftmanager'), UDMANAGER_VERSION).' - '.__('Authored by', 'updraftplus').' Simba Hosting (<a href="http://updraftplus.com">'.__('UpdraftPlus - Best WordPress Backup', 'updraftmanager').'</a> | <a href="http://www.simbahosting.co.uk">'.__("Simba Hosting - Web Hosting", 'updraftmanager').'</a> | <a href="http://david.dw-perspective.org.uk">'.__("Lead Developer's Homepage", 'updraftmanager').'</a>)</p>';

		?><div id="icon-plugins" class="icon32"><br></div><h2><?php _e('Managed Plugins', 'updraftmanager');?> <a href="?page=<?php echo htmlspecialchars($_REQUEST['page'])?>&action=add_new" class="add-new-h2"><?php _e('Add New', 'updraftmanager');?></a></h2><?php

		if(!class_exists('UpdraftManager_List_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-list-table.php');

		$this->plugs =  array(
			'wonky' => array(
				'active' => false,
				'slug' => 'wonky',
				'name' => 'The Wonky Plugin!',
				'zips' => array(),
				'addons' => 'addons',
				'rules' => array()
			)
		);

		$plug_table = new UpdraftManager_List_Table();

		$plug_table->prepare_items(); 
		?>
		<form method="post">
		<?php
		$plug_table->display(); 
		echo '</form>';
	}

}
