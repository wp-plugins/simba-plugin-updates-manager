<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

class Updraft_Manager {

	# The only thing requiring this to be public is udmmanager_pclzip_name_as()
	public $plugin;

	public function __construct() {
		add_action('plugins_loaded', array($this, 'load_translations'));
		if (!empty($_GET['udm_action'])) add_action('init', array($this, 'action_init'));
		add_shortcode('udmanager', array($this, 'udmanager_shortcode'));
		add_action('updraftmanager_weeklycron', array($this, 'weeklycron'));
	}

	public function load_translations() {
		load_plugin_textdomain('updraftmanager', false, UDMANAGER_DIR.'/languages');
	}

	public function activation() {
		if (false === wp_next_scheduled('updraftmanager_weeklycron')) wp_schedule_event(time()+86400*7, 'daily', 'updraftmanager_weeklycron');
	}

	public function deactivation() {
		wp_clear_scheduled_hook('updraftmanager_weeklycron');
	}

	public function weeklycron() {
		$manager_dir = UpdraftManager_Options::get_manager_dir(true);
		$d = dir($manager_dir);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry && is_dir($manager_dir.'/'.$entry.'/cache')) {
				UpdraftManager_Options::remove_local_directory($manager_dir.'/'.$entry.'/cache', true);
			}
		}
		$d->close();
	}

	public function manager_dir_exists($dir) {
		if (is_dir($dir) && is_dir($dir).'/cache' && is_file($dir.'/.htaccess') && is_file($dir.'/index.php')) return true;
		if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
		if (!is_dir($dir.'/cache') && !mkdir($dir.'/cache', 0775, true)) return false;
		if (!is_file($dir.'/index.php') && !file_put_contents($dir.'/index.php',"<html><body>No access.</body></html>")) return false;
		if (!is_file($dir.'/.htaccess') && !file_put_contents($dir.'/.htaccess','deny from all')) return false;
		return true;
	}

	public function get_plugin($slug, $user_id = false) {
		if (empty($user_id)) $user_id = apply_filters('updraftmanager_pluginuserid', false);
		if (empty($user_id) || !is_numeric($user_id)) return false;
		require_once(UDMANAGER_DIR.'/classes/updraftmanager-plugin.php');
		$plugin_object_class = apply_filters('updraftmanager_pluginobjectclass', 'Updraft_Manager_Plugin');
		$this->plugin = new $plugin_object_class($slug, $user_id);
	}

	public function action_init() {
		// No magic URL is required; the presence of the GET parameters is sufficient to indicate intent
		# Slug is not sent on all commands the legacy installs (e.g. connect)
		if (empty($_GET['udm_action'])) return;
		$action = $_GET['udm_action'];
		$slug = isset($_REQUEST['slug']) ? $_REQUEST['slug'] : apply_filters('updraftmanager_defaultslug', false);
		if (empty($slug)) return;
		try {
			$user_id = isset($_GET['muid']) ? $_GET['muid'] : apply_filters('updraftmanager_pluginuserid', false);
			if (empty($user_id) || !is_numeric($user_id)) die();
			$this->get_plugin($slug, $user_id);
		} catch (Exception $e) {
			error_log($e->getMessage());
			die();
		}
		do_action('updraftmanager_pinfo_'.$action, $slug);
		if (method_exists($this->plugin, 'pinfo_'.$action)) call_user_func(array($this->plugin, 'pinfo_'.$action));

		die();

	}

	public function udmanager_shortcode($atts) {
		extract(shortcode_atts(array(
			'action' => 'addons',
			'slug' => '',
			'showaddons' => false,
			'showunpurchased' => "none",
			'userid' => apply_filters('updraftmanager_pluginuserid', false),
			'showlink' => apply_filters('updraftmanager_showlinkdefault', true)
		), $atts));
#			'slug' => apply_filters('updraftmanager_defaultslug', false),

#		if (false === $slug) return '';
		# TODO: When going fully multi-user... which userid to show? All of them?
		if (false === $userid) return '';

		if (!is_user_logged_in()) return __("You need to be logged in to see this information", 'updraftmanager');

		if (empty($slug)) {
			$plugins = UpdraftManager_Options::get_options($userid);
			$slugs = array();
			if (is_array($plugins)) {
				foreach ($plugins as $slug => $plug) {
					$slugs[] = $slug;
				}
			}
		} else {
			$slugs = array($slug);
		}

		$ret = '';
		foreach ($slugs as $slug) {

			$this->get_plugin($slug, $userid);
			switch ($action) {
				case 'addons':
					$ret .= $this->plugin->home_addons(($showlink === true) ? apply_filters('updraftmanager_showlinkdefault', true, $slug) : $showlink, $showunpurchased, ($showaddons === false) ? apply_filters('updraftmanager_account_showaddons', false, $slug) : $showaddons);
					break;
				case 'support':
					$ret .= $this->plugin->home_support();
					break;
			}

		}

		return $ret;

	}

}
