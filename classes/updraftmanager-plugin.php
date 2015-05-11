<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

// PclZip only calls functions; hence this lives out here
function udmmanager_pclzip_name_as($p_event, &$p_header) {
	global $updraft_manager;
	$p_header['stored_filename'] = $updraft_manager->plugin->slug.'/'.$updraft_manager->plugin->pluginfile;
	return 1;
}

class Updraft_Manager_Plugin {

	public $slug;
	public $author;
	public $uid;
	public $version;
	public $homepage;
	public $free;

	public $pluginfile;

	public $plugin_name;
	public $plugin_descrip;

	public function __construct($slug, $uid) {

		$this->uid = $uid;

		$plugins = UpdraftManager_Options::get_options($uid);
		if (empty($plugins[$slug]) || !is_array($plugins[$slug])) { throw new Exception("No such slug ($slug/$uid)"); }
		$plugin = $plugins[$slug];
		$this->plugin = $plugin;

		$this->slug = $slug;

		$this->plugin_name = $plugin['name'];
		$this->plugin_descrip = $plugin['description'];
		$this->author = (empty($this->plugin['author'])) ? '' : $this->plugin['author'];
		$this->addonsdir = (empty($this->plugin['addonsdir'])) ? '' : $this->plugin['addonsdir'];

		// If not on Premium, all plugins are 'free' (i.e. no licensing stuff)
		$this->free = true;
		$this->homepage = $plugin['homepage'];

		$this->entitlement_meta_addons_prefix = apply_filters('updraftmanager_entitlement_meta_addons_prefix', 'updraftmanager_addons_'.$uid.'_', $slug, $uid);
		$this->entitlement_meta_support_prefix = apply_filters('updraftmanager_entitlement_meta_support_prefix', 'updraftmanager_support_'.$uid.'_', $slug, $uid);

	}

	// Returns false, or the (array)information about the zip
	public function calculate_download($info = array()) {
		// Potential keys in info: wp, php, installed, username, siteurl
		$manager_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid);
		$rules = (!empty($this->plugin['rules']) && is_array($this->plugin['rules'])) ? $this->plugin['rules'] : array();
		ksort($rules);
		foreach ($rules as $rule) {
			$combination = (!empty($rule['combination'])) ? $rule['combination'] : 'and';
			$ruleset = (!empty($rule['rules']) && is_array($rule['rules'])) ? $rule['rules'] : array();
			ksort($ruleset);
			$matches = false;
			$mismatches = false;
			foreach ($ruleset as $r) {
				$criteria = (!empty($r['criteria'])) ? $r['criteria'] : '';
				$relationship = (!empty($r['relationship'])) ? $r['relationship'] : '';
				if ($criteria != 'always' && (!isset($info[$criteria]) || !isset($r['value']) || '' == $relationship)) continue;
				if ('always' == $criteria) {
					$matches = true;
				} elseif ('eq' == $relationship && $info[$criteria] == $r['value']) {
					$matches = true;
				} elseif ('lt' == $relationship && version_compare($info[$criteria], $r['value'], '<=')) {
					$matches = true;
				} elseif (version_compare($info[$criteria], $r['value'], '>=') && 'gt' == $relationship) {
					$matches = true;
				} elseif ('range' == $relationship && preg_match('/^([^,]+),(.*)$/', $r['value'], $matches) && version_compare($info[$criteria], $matches[1], '>=') && version_compare($info[$criteria], $matches[2], '<=')) {
					$matches = true;
				} else {
					$mismatches = true;
				}
			}

			# Debug : rules matched - was the file correctly found?
// 			if ((('and' == $combination && $matches && !$mismatches) || ('or' == $combination && $matches))) {
// 				error_log("FN: ".$rule['filename']);
// 				error_log("PATH: ".$manager_dir.'/'.$rule['filename']);
// 				error_log("ZIP: ".serialize($this->plugin['zips'][$rule['filename']]));
// 			}

			if ((('and' == $combination && $matches && !$mismatches) || ('or' == $combination && $matches)) && !empty($rule['filename']) && is_file($manager_dir.'/'.$rule['filename']) && isset($this->plugin['zips'][$rule['filename']])) return $this->plugin['zips'][$rule['filename']];
		}
		return false;
	}


	public function get_available_addons($unpacked_dir, $download = null) {
		$udmanager_addons = apply_filters('updraftmanager_defaultaddons', array(), $this, $download);
		$scan_addons = $this->scan_addons($unpacked_dir);
		return array_merge($udmanager_addons, $scan_addons);
	}

	// Returns an array of addons found in the managed plugin's 'addons' sub-directory
	public function scan_addons($unpacked_dir) {

		if (empty($this->addonsdir)) return array();

		$usedir = $unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir;

		$stat = stat($usedir);

		// Use transient if it exists, and if the directory was not modified in the last hour
		if ($stat['mtime'] < time()-3600) {
			$tmp = get_transient('udmanager_scanaddons_'.$this->uid.'_'.$this->slug);
			if ($tmp != false) return $tmp;
		}

		$scan_addons = array();
		if (is_dir($usedir) && $dir_handle = opendir($usedir)) {
			while ($e = readdir($dir_handle)) {
				if (is_file("$usedir/$e") && preg_match('/^(.*)\.php$/i', $e, $matches)) {
					$potential_addon = $this->get_addon_info("$usedir/$e");
					if (is_array($potential_addon) && isset($potential_addon['key'])) {
						$key = $potential_addon['key'];
						$scan_addons[$key] = $potential_addon;
					}
				}
			}
		}

		set_transient('udmanager_scanaddons_'.$this->uid.'_'.$this->slug, $scan_addons, 3600);

		return $scan_addons;
	}

	// This function, if ever changed, should be kept in sync with the same function in updraftplus-addons.php
	// Returns either false or an array
	protected function get_addon_info($file) {
		if ($f = fopen($file, 'r')) {
			$key = "";
			$name = "";
			$description = "";
			$version = "";
			$shopurl = "";
			$latestchange = null;
			$lines_read = 0;
			$include = "";
			while (!feof($f) && $lines_read<10) {
				$line = @fgets($f);
				if ($key == "" && preg_match('/Addon: ([^:]+):(.*)$/i', $line, $lmatch)) {
					$key = $lmatch[1]; $name = $lmatch[2];
				} elseif ($description == "" && preg_match('/Description: (.*)$/i', $line, $lmatch)) {
					$description = $lmatch[1];
				} elseif ($version == "" && preg_match('/Version: (.*)$/i', $line, $lmatch)) {
					$version = $lmatch[1];
				} elseif ($shopurl == "" && preg_match('/Shop: (.*)$/i', $line, $lmatch)) {
					$shopurl = $lmatch[1];
				} elseif ("" == $latestchange && preg_match('/Latest Change: (.*)$/i', $line, $lmatch)) {
					$latestchange = $lmatch[1];
				} elseif ("" == $include && preg_match('/Include: (.*)$/i', $line, $lmatch)) {
					$include = $lmatch[1];
				}
				$lines_read++;
			}
			fclose($f);
			if ($key && $name && $description && $version) {
				return array('key' => $key, 'name' => $name, 'description' => $description, 'latestversion' => $version, 'shopurl' => $shopurl, 'latestchange' => $latestchange, 'include' => $include);
			}
		}
		return false;
	}

	// Find out if there is any unused purchase matching that key
	// If so, then claim it. Remember that some grants are unlimited, and should generate a fresh entitlement if granted.
	public function claim_addon_entitlement($user_id, $key, $sid, $site_name, $site_url) {

		$addon_entitlements = $this->get_user_addon_entitlements($user_id, false, true);
		if (!is_array($addon_entitlements)) return false;

		$expire_date = -1;

		// First parse - if this site already has this entitlement, then do nothing (except updating the details)
		foreach ($addon_entitlements as $ekey => $titlement) {
			// Keys: site (sid, unclaimed, unlimited), sitedescription, key, status

			if ($titlement['key'] == $key && ($titlement['site'] == $sid || $site_url === $titlement['sitedescription'] || 0 === strpos($titlement['sitedescription'], $site_url.' - '))) {
				// Update site details
				$this->grant_user_addon_entitlement($ekey, $key, $sid, "$site_url - $site_name", $user_id);
				return true;
			} elseif ($titlement['key'] == $key && ( $titlement['site'] == 'unclaimed' || $titlement['site'] == 'unlimited')) {
				if ($titlement['site'] == 'unlimited') {
					if (isset($titlement['expires'])) $expire_date = $titlement['expires'];
					$i = 1;
					while (isset($addon_entitlements[$ekey.sprintf("%03d", $i)])) {
						$i++;
					}
					$slot_available = $ekey.sprintf("%03d", $i);
				} else {
					$slot_available = $ekey;
				}
			}
		}

		// Grant entitlement
		if (isset($slot_available)) {
			// Try to minimise character set issues
			#$site_description = htmlentities("$site_url - $site_name");
			$site_description = htmlentities($site_url);
			$titlement = $this->grant_user_addon_entitlement($slot_available, $key, $sid, $site_description, $user_id, $expire_date);
			do_action('updraftmanager_claimed_entitlement', $key, $titlement, $slot_available, $addon_entitlements, $user_id);
			return true;
		}

		return false;

	}

	// Valid types: OK, ERR, BADAUTH, INVALID
	public function send_response($type, $data = null, $msg = null) {
		switch ($type) {
			case 'OK':
			case 'ERR':
			case 'INVALID':
			case 'BADAUTH':
			$rcode = $type;
			break;
			default:
			throw new Exception('Unknown response type: '.$type);
			break;
		}
		$response = array(
			'version' => 1,
			'code' => $rcode
		);
		if ($data !== null) $response['data'] = $data;
		if ($msg !== null) $response['msg'] = $msg;
		// Allow legacy installations to send the data in a different format
		echo apply_filters('updraftmanager_send_response', json_encode($response), $type, $data, $msg);
	}

	public function pinfo_claimaddon() {
		echo $this->send_response('OK');
		return;
	}

	public function pinfo_releaseaddon() {
		echo $this->send_response('OK');
		return;
	}

	// Further parameters: token
	public function pinfo_download() {
		if (empty($_GET['token'])) die('Invalid token');
		$download_info = get_transient('uddownld_'.$_GET['token']);

		if (!is_array($download_info) || empty($download_info['download'])) die("Invalid token");
		if ($this->uid != $download_info['uid'] || $this->slug != $download_info['slug']) die("Invalid token");

		if (!empty($download_info['mustbeloggedin']) && !is_user_logged_in()) die("Invalid token");

		// Find out what they are entitled to
		$entitlements = $this->get_user_addon_entitlements($download_info['id'], $download_info['sid'], true);
		if (!is_array($entitlements)) die;

		$ent_keys = array();

		foreach ($entitlements as $titlement) {
			if (isset($titlement['key'])) {
				if (!in_array($titlement['key'], $ent_keys)) $ent_keys[] = $titlement['key'];
			}
		}

		$deliver = $this->deliver_zip($download_info['download'], $ent_keys, $download_info['pluginfile']);

		if (is_wp_error($deliver)) {
			error_log("Zip delivery failed: ".$deliver->get_error_message());
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		} elseif (!$deliver) {
			error_log("Zip delivery failed");
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		}

		// TODO: Check this out, see if it's still true. The number of transients being stored can get high, so if we can get rid of them, it would be good.
		// We don't delete or change the expiration time of the transient, because the tokens are not unique (it may be re-used)
		// delete_transient('uddownld_'.$_GET['token']);

		die;

	}

	// Create the zip (or find a pre-made cached one) from their entitlement
	// Feed the zip to them
	public function deliver_zip($filename, $keys = array(), $pluginfile = '') {

		$this->pluginfile = $pluginfile;

		global $updraft_manager;

		$manager_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid);
		if (false === $manager_dir || !$updraft_manager->manager_dir_exists($manager_dir)) return false;

		$version = empty($this->plugin['zips'][$filename]['version']) ? '' : $this->plugin['zips'][$filename]['version'];
		$versionsuffix = $version;

		$cache_file = apply_filters('updraftmanager_plugin_deliverzip_cachefile', $manager_dir.'/'.$filename, $keys, $manager_dir, $version, $versionsuffix);

		if (is_wp_error($cache_file)) return $cache_file;

		if (!file_exists($cache_file)) return false;

		# Increment download counter for this zip
		$nowtime = time();
		$date_key = $nowtime-($nowtime%86400);
		$downloads = get_user_meta($this->uid, 'udmanager_downloads', true);
		if (!is_array($downloads)) $downloads=array();
		if (empty($downloads[$this->slug][$filename][$date_key])) $downloads[$this->slug][$filename][$date_key] = 0;
		$downloads[$this->slug][$filename][$date_key]++;
		update_user_meta($this->uid, 'udmanager_downloads', $downloads);

		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename='.$this->slug.'.'.$versionsuffix.'.zip');
		header('Content-Length: '.filesize($cache_file));
		readfile($cache_file);
		
		return true;
	}

	public function pinfo_listaddons() {

		$download = $this->calculate_download();
		// Send back an array of available add-ons
		$addons = (is_array($download)) ? $this->get_available_addons(UpdraftManager_Options::get_manager_dir(false, $this->uid.'/_up_'.$download['filename']), $download) : array();
		// Set this header to prevent content compressors mangling the contents (e.g. assuming it is HTML and stripping double spaces)
		@header('Content-Type: application/octet-stream');
		// We wrap inside another array to allow for future changes
		print serialize(array('addons' => $addons));
	}

	// Send false to get the current user
	// Returns the stored arrays
	// Values for $prune_expired: true = both, 1 = addons only, 2 = support only, false = neither
	public function get_user_entitlements($id = false, $sid = false, $prune_expired = false) {
		return array(array(), array());
	}

	public function renew_user_support_entitlement($support_type, $response_time, $renewal_items, $months = 12, $id = false) {
		return false;
	}


	public function get_user_addon_entitlements($id = false, $sid = false, $prune_expired = false) {
		return array();
	}

	public function get_user_support_entitlements($id = false, $prune_expired = false) {
		return array();
	}

	public function parse_id($id = false) {
		if ($id) return $id;
		if (!is_user_logged_in()) return false;
		get_currentuserinfo();
		global $user_ID;
		return $user_ID;
	}

	// This is similar, but far from identical, to the procedure in updraftplus-addons.php
	private function addonbox($name, $shopurl, $description, $latestversion, $in_use_on_sites, $key = false, $user_id = false) {

		$urlbase = UDMANAGER_URL;

		# TODO: Get real URL
		$mother = home_url('');
		$full_url = (0 === strpos($shopurl, 'http:/') || 0 === strpos($shopurl, 'https:/')) ? $shopurl : $mother.$shopurl;
		$blurb = '';

		if ($in_use_on_sites) {
			$preblurb="<img style=\"float:right; margin-left: 24px;\" src=\"$urlbase/images/yes.png\" width=\"85\" height=\"98\" alt=\"".__("You've got it", 'updraftmanager')."\">";

			$blurb .= "<p>$in_use_on_sites</p>";

		} else {
			$preblurb='<span style="float:right; margin-left: 24px;">';
			if ($shopurl) $preblurb .= '<a href="'.$full_url.'">';
			$preblurb .= '<img src="'.$urlbase.'/images/shopcart.png" width="120" height="98" alt="'.esc_attr(__('Buy It', 'updraftmanager')).'">';
			if ($shopurl) $preblurb .= '</a>';
			$preblurb .= '</span>';
			$blurb = '<p><em>'.__('No purchases.', 'updraftmanager').'</em></p>';
		}

		$blurb.='';

		if ($shopurl) {
			$blurb .= apply_filters('updraftmanager_plugin_addonbox_shopurl', '<p><a href="'.$full_url.'">'.__("Free plugin: Go to the plugin's homepage", 'updraftmanager').'</a></p>', $shopurl);
		}

		if ($this->plugin_name != $name) { $name = $this->plugin_name.' : '.$name; }

		$ret = '<div class="udmanager-addonbox"'.((!empty($user_id)) ? ' data-userid="'.$user_id.'"' : '').' style="border: 1px solid; border-radius: 4px; padding: 0px 12px 0px; min-height: 164px; width: 95%; margin-bottom: 16px;"';
		$ret .= ' data-entitlementslug="'.esc_attr($this->slug).'"';
		if (is_string($key)) $ret .= ' data-addonkey="'.esc_attr($key).'"';
		$ret .=">";
		$ret .= <<<ENDHERE
			<div style="width: 100%;">
			<h2 style="margin: 4px;">$name</h2>
			$preblurb
			$description<br>
			$blurb
ENDHERE;

		$ret = apply_filters('updraftmanager_inuseonsites_final', $ret, $this->free, $this->user_can_manage);

		return $ret.'</div></div>';
	}

	// Further parameters: slug
	public function pinfo_updateinfo() {

		// The plugin that we are managing
		// Other parameters: site, u, siteid. No longer used: p

		// If it passes, then store a short-lived transient (as long-lived as the checkupdate interval)

		$email = isset($_GET['e']) ? strtolower($_GET['e']) : '';
		#$pass = isset($_GET['p']) ? @base64_decode($_GET['p']) : '';
		$sid = isset($_GET['sid']) ? $_GET['sid'] : '';
		$ssl = ((isset($_GET['ssl']) && $_GET['ssl']) || (!isset($_GET['ssl']) && is_ssl())) ? true : false;
		$site_url = isset($_GET['su']) ? @base64_decode($_GET['su']) : '';
		$si = isset($_GET['si']) ? maybe_unserialize(@base64_decode($_GET['si'])) : '';

		$plugin_info = $this->get_plugin_info($sid, $email, $ssl, $site_url, $si);

		// Set this header to prevent content compressors mangling the contents (e.g. assuming it is HTML and stripping double spaces)
		@header('Content-Type: application/octet-stream');
		echo json_encode($plugin_info);
	}

	public function get_plugin_info($sid = false, $email, $ssl = false, $site_url = '', $si = array()) {
		$description = $this->plugin_descrip;

		$plugin_info = array(
			'name' => $this->plugin_name,
			'slug' => $this->slug,
			'author' => $this->author,
			'homepage' => $this->homepage,
			'sections' => array()
		);

		# No need to authenticate them - the SID is sufficient authentication. Also, if they changed their p/w, then authentication will fail
		#if (!empty($sid) && $sid != 'all' && $sid != 'unclaimed' && $user = $this->authenticate($email, $pass)) {

		if ($this->free || (!empty($sid) && $sid != 'all' && $sid != 'unclaimed' && !empty($email) && $user = get_user_by('email', $email))) {

			if ($this->free) { $user = new stdClass; $user->ID = false; }

			// What is the user entitled to? Calculate entitlements now
			list($user_addons, $user_support) = $this->get_user_entitlements($user->ID, $sid, 2);

			#file_put_contents('/tmp/ent.log', "UID: ".$user->ID." SID=$sid: ".print_r($user_addons, true));

			if (is_array($user_addons) && !empty($sid) && $user->ID != false) {
				# Update list of last check-ins
				$last_checkins = get_user_meta($user->ID, 'udmanager_lastcheckins', true);
				if (!is_array($last_checkins)) $last_checkins = array();
				if (empty($last_checkins[$this->uid][$this->slug][$sid])) $last_checkins[$this->uid][$this->slug][$sid] = array();
				$last_checkins[$this->uid][$this->slug][$sid]['time'] = time();
				if ($site_url) $last_checkins[$this->uid][$this->slug][$sid]['site_url'] = $site_url;
				update_user_meta($user->ID, 'udmanager_lastcheckins', $last_checkins);
			}

			if (!$this->free && !empty($this->addonsdir)) $pver = (int)count($user_addons);
			$keyhash = '';

			$extra_descrip = '';

			$dinfo = array();
			if (!$this->free) $dinfo['username'] = $email;
			if (!empty($_GET['installed_version'])) $dinfo['installed'] = $_GET['installed_version'];
			if (!empty($si['wp'])) $dinfo['wp'] = $si['wp'];
			if (!empty($site_url)) $dinfo['siteurl'] = $site_url;

			# First, prune the expired ones
			$pre_prune_num = count($user_addons);
			foreach ($user_addons as $ind => $addon) {
				if (is_array($addon) && !empty($addon['expires']) && time() >= $addon['expires'] && $addon['expires'] > 0) unset($user_addons[$ind]);
			}
			if ($pre_prune_num > 0 && 0 == count($user_addons)) {
				$user_addons = 'expired';
			} elseif ($pre_prune_num > 0 && count($user_addons) < $pre_prune_num) {
				$plugin_info['x-spm-expiry'] = 'expired_'.($pre_prune_num - count($user_addons));
			}

			# Now we have only the un-expired addons
			if (is_string($user_addons) && 'expired' == $user_addons) {
				$download = array();
				$plugin_info['x-spm-expiry'] = 'expired';
			} elseif (0 == count($user_addons) && !$this->free) {
				$download = array();
				$plugin_info['x-spm-expiry'] = 'expired';
			} else {

				$how_many_expiring_soon = 0;
				$have_all = false;

				# First parse - do we have 'all' ?
				foreach ($user_addons as $ind => $addon) {
					if (!empty($addon['key']) && 'all' == $addon['key']) {
						$have_all = true;
						# If there are expired add-ons, then the user will be considering those irrelevant
						unset($plugin_info['x-spm-expiry']);
					}
				}

				foreach ($user_addons as $ind => $addon) {
					if (!empty($addon['key']) && $have_all && 'all' != $addon['key']) {
						# This add-on is irrelevant - they have got all
						continue;
					}
					if (is_array($addon) && !empty($addon['expires']) && $addon['expires'] > 0) {
						if (time()+28*86400 >= $addon['expires']) {
							$how_many_expiring_soon++;
						}
					}
				}

				if ($how_many_expiring_soon >0) {
					$plugin_info['x-spm-expiry'] = (empty($plugin_info['x-spm-expiry'])) ? '' : $plugin_info['x-spm-expiry'].',';
					if ($how_many_expiring_soon == count($user_addons)) {
						$plugin_info['x-spm-expiry'] .= 'soon';
					} else {
						$plugin_info['x-spm-expiry'] .= 'soonpartial_'.$how_many_expiring_soon.'_'.count($user_addons);
					}
				}

				$download = $this->calculate_download($dinfo);
			}

			if (is_string($user_support) && 'expired' == $user_support) {
				$plugin_info['x-spm-support-expiry'] = 'expired';
			} else {
				$support_expires_soon = false;
				foreach ($user_support as $ind => $support) {
					if (is_array($support) && !empty($support['expire_date']) && $support['expire_date'] > 0) {
						if (time()+28*86400 >= $support['expire_date']) {
							if (false === $support_expires_soon) $support_expires_soon = true;
						} else {
							$support_expires_soon = 0;
						}
					}
				}
				if ($support_expires_soon) $plugin_info['x-spm-support-expiry'] = 'soon';
			}

			if (!empty($download['filename'])) $unpacked_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid).'/_up_'.$download['filename'];

			$transient_name = 'spm_readme_secs_'.substr(md5($unpacked_dir), 0, 12);
			$sections_from_readme = get_transient($transient_name);

			if (is_array($sections_from_readme) && !empty($sections_from_readme)) {

				$plugin_info['sections'] = $sections_from_readme;

			} else {

	// 			$sections_grokked = array('changelog' => '', 'frequently asked questions' => '', 'installation' => '', 'screenshots' => '');
				$sections_grokked = array('changelog' => '', 'frequently asked questions' => '');
				if (isset($unpacked_dir)) {
					if (is_readable($unpacked_dir.'/'.$this->slug.'/readme.txt')) {
						$readme_lines = file($unpacked_dir.'/'.$this->slug.'/readme.txt');
						$current_section = false;
						$how_many_divisions = 0;
						foreach ($readme_lines as $cl) {
							if (preg_match('/^==(.*)==\s+$/', $cl, $matches)) {
								$current_section = strtolower(trim($matches[1]));~
								$how_many_divisions = 0;
							} elseif (isset($sections_grokked[$current_section])) {
								if ('changelog' == $current_section && ($how_many_divisions > 4 || $sections_grokked[$current_section] > 10240)) continue;
								if (preg_match('/^=(.*)=\s+$/', $cl, $matches)) {
									$how_many_divisions++;
									$sections_grokked[$current_section] .= '<h4><strong>'.$matches[1].'</strong></h4>';
									if ($how_many_divisions > 4 && 'changelog' == $current_section) $sections_grokked[$current_section] .= "\n...";
								} else {
									$sections_grokked[$current_section] .= $cl;
								}
							}
						}
					}
					if (empty($sections_grokked['changelog']) && is_readable($unpacked_dir.'/'.$this->slug.'/changelog.txt')) {
						$sections_grokked['changelog'] = file_get_contents($unpacked_dir.'/'.$this->slug.'/changelog.txt');
					}
					//$plugin_info['sections']['changelog'] = ...
				}

				$sections_grokked['faq'] = $sections_grokked['frequently asked questions'];
				unset($sections_grokked['frequently asked questions']);

				foreach ($sections_grokked as $section => $content) {
					if (empty($content)) continue;
					if (!function_exists('Markdown')) require_once(UDMANAGER_DIR.'/vendor/php-markdown-extra/markdown.php');
					$plugin_info['sections'][$section] = Markdown($content);
				}

				$sections_from_readme = $plugin_info['sections'];

				set_transient($transient_name, $sections_from_readme, 86400);

			}
			

			if (!$this->free && !empty($this->addonsdir) && isset($unpacked_dir) && !empty($download['version'])) {
				$all_addons = false;
				foreach ($user_addons as $addon) {
					if (isset($addon['key'])) {
						$keyhash .= ($keyhash == '') ? $addon['key'] : ','.$addon['key'];
						if ('all' == $addon['key']) {
							$all_addons = true;
							$extra_descrip .= "<li>".'All add-ons'."</li>";
							$scan_addons = $this->scan_addons($unpacked_dir);
							// Force upgrade: one higher than all available add-ons
							$pver = count($scan_addons)+1;
						}
					}
				}
			}

			/* To prevent the accumulation of squillions of transients, we issue a token which is:
			
			- Broadly deterministic: the same token will always be generated if the variables giving rise to it are the same
			- Time-based: based on day (since transient lasts 24 hours)
			- Based on user ID (can drop this later if needed - they provide us some verification for present
			- Based on what is in their version (can vary between sites)
			- Also based on $pass, to prevent valid tokens being externally deterministic
			- The token is hashed, so there's no way to reverse engineer it into its components
			*/

			// Only if entitled do we bother passing a non-default version
			// Authorise for 7 days (needs to match the maximum update checking interval)
			$now_time = time();
			$token_time = $now_time - ($now_time % (7*86400));

			if (is_array($download) && isset($download['filename'])) {

				$plugin_info['requires'] = $download['minwpver'];
				$plugin_info['tested'] = $download['testedwpver'];

				$token = md5($user->ID.'-'.$this->uid.'-'.$this->slug.'-'.$sid.'-'.$token_time.'-'.$keyhash);

				set_transient('uddownld_'.$token, array(
					'id' => $user->ID,
					'sid' => $sid,
					'download' => $download['filename'],
					'uid' => $this->uid,
					'slug' => $this->slug,
					'pluginfile' => $download['pluginfile']
				), 7*86400);

				# The final parameter is because WordPress uses the URL to construct a temporary directory using basename($url), which can be long and risk overflowing 256-character limits (e.g. on WAMP)
				$plugin_download_url = apply_filters('updraftmanager_downloadbase', home_url('', ($ssl) ? 'https' : 'http'), $ssl).'/?udm_action=download&slug='.$this->slug.'&muid='.$this->uid.'&token='.$token.'&ig=/'.substr(md5(time()),0,8);

				if (!$this->free && !empty($this->addonsdir)) {
					$plugin_version = isset($pver) ? $download['version'].'.'.$pver : $download['version'];

					foreach ($user_addons as $addon) {
						$key = $addon['key'];

						$file = $unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir.'/'.$key.'.php';

						if (is_file($file)) {
							$info = $this->get_addon_info($file);
							if ($info) $extra_descrip .= "<li>".htmlspecialchars($info['name'])." - ".htmlspecialchars($info['description']).'</li>';
						}
					}
				} else {
					$plugin_version = $download['version'];
				}
				if ($extra_descrip) $description.='<p><strong>'.__('Add-ons for this site:', 'updraftmanager').'</strong></p><ul>'.$extra_descrip.'</ul>';

			}

		}

		$plugin_info['sections']['description'] = $description;

		if (empty($plugin_version) && !empty($_REQUEST['installed_version'])) $plugin_version = $_REQUEST['installed_version'];

		if (isset($plugin_version)) $plugin_info['version'] = $plugin_version;

		if (isset($plugin_download_url)) $plugin_info['download_url'] = $plugin_download_url;

		/* Finally - send back info on what WP versions their *current* install has been tested on */
		if (!empty($_REQUEST['installed_version']) && is_array($this->plugin['zips'])) {
			$installed_version = $_REQUEST['installed_version'];
			if (!empty($this->addonsdir) && preg_match('/^(.+)\.(\d+)$/', $installed_version, $matches)) $installed_version = $matches[1];
			$yourversion_tested = false;
			foreach ($this->plugin['zips'] as $zip) {
				if (!empty($zip['version']) && $zip['version'] == $installed_version) {
					if (empty($zip['testedwpver'])) continue;
					if ($yourversion_tested === false) {
						$yourversion_tested = $zip['testedwpver'];
					} elseif ($yourversion_tested != $zip['testedwpver']) {
						$yourversion_tested = -1;
					}
				}
			}
			if ($yourversion_tested > 0) $plugin_info['x-spm-yourversion-tested'] = $yourversion_tested;
		}

		return $plugin_info;
	}

	// Show the logged-in user's support entitlements
	public function home_support() {

		$ret="";

		if (!is_user_logged_in()) return __("You need to be logged in to see this information", 'updraftmanager');

		$user_support = $this->get_user_support_entitlements();

		$ret .= '<ul>';
		if (count($user_support) == 0) {
			$ret .= "<li><em>".__('None yet purchased', 'updraftmanager')."</em></li>";
		}
		foreach ($user_support as $support) {
		// keys: status (unused / used / expired), purchasedate, expire_date, response time, method

			if ($support['expire_date'] && $support['expire_date'] < time()) {
				$statm = __('Has expired', 'updraftmanager');
			} else {
				if (empty($support['status'])) continue;
				switch ($support['status']) {
					case 'used':
						$statm = __('Has been used', 'updraftmanager');
						break;
					case 'unused':
						$statm = __('Not yet used', 'updraftmanager');
						break;
					case 'active':
						$statm = __('Active ongoing subscription', 'updraftmanager');
						if (is_numeric($support['expire_date'])) $statm .= ' '.sprintf(__("(expires: %s)", 'updraftmanager'), date("Y-m-d", $support['expire_date']));
						break;
					default:
						$statm = __("Unrecognised status code", 'updraftmanager')." (".$support['status'].")";
						break;
				}
			}

			global $wpdb;

			// support_type values:
			$support_type = $wpdb->get_row("SELECT name FROM $wpdb->terms WHERE slug='".esc_sql($support['support_type'])."'");
			if (isset($support_type->name)) $statm .= " (".$support_type->name.")";

			// Response time
			$response_time = $wpdb->get_row("SELECT name FROM $wpdb->terms WHERE slug='".esc_sql($support['response_time'])."'");
			if (isset($response_time->name)) $statm .= " (".$response_time->name.")";

			$pdate = date('d F Y', $support['purchase_date']);
			$ret .= "<li><strong>".__('Purchased', 'updraftmanager')." $pdate:</strong> $statm</li>\n";

		}

		$ret .= '</ul>';
		
		return $ret;

	}

	// Show the logged-in user's add-on entitlements; also used for the admin's management of user entitlements
	// Valid values for $showunpurchased : all, free, none
	public function home_addons($show_link, $showunpurchased, $showaddons, $user_id = false) {

		$ret="";

		if (false === $user_id) $user_id = get_current_user_id();

		$this->user_can_manage = current_user_can(UpdraftManager_Options::manage_permission());

		$user_addons = $this->get_user_addon_entitlements($user_id);

		# Get the default download
		$download = $this->calculate_download();

		// Returns an array of arrays
		// Keys: name (short), description (longer), shopurl (stub)

		$addons = array();
		if ($showaddons>0) $addons = (is_array($download)) ? $this->get_available_addons(UpdraftManager_Options::get_manager_dir(false, $this->uid.'/_up_'.$download['filename']), $download) : array();
		# For plugins with no add-ons, or if not showing addons
		if ($this->free || empty($addons)) {
			$addons = array(array('key' => 'all', 'name' => $this->plugin_name, 'description' => $this->plugin_descrip, 'latestversion' => ((!empty($download['version'])) ? $download['version'] : ''), 'shopurl' => $this->homepage, 'latestchange' => null, 'include' => null));
		} elseif ((2 == $showaddons || 0 == $showaddons) && !isset($addons['all'])) {
			array_unshift($addons, array('key' => 'all', 'name' => $this->plugin_name, 'description' => $this->plugin_descrip, 'latestversion' => ((!empty($download['version'])) ? $download['version'] : ''), 'shopurl' => $this->homepage, 'latestchange' => null, 'include' => null));
		}

		$ret.= '<div class="udmanager-addonstable">';

		$last_checkins = get_user_meta($user_id, 'udmanager_lastcheckins', true);

		$index = 0;

		foreach ($addons as $key => $addon) {
			$index++;

			// Keys: name, description, shopurl

			// Has the user bought it? Inspect user_addons to find out.
			// We then need to pass in the information on those purchases - remember, there may be multiple.

			// TODO: Automatically release unused licences (e.g. unaccessed for >= 1 month, or the oldest of duplicate URLs unaccessed for >1 day)

			$showit = false;
			$can_download = false;

			$in_use_on_sites = "";

			foreach ($user_addons as $uid => $useradd) {
				// keys are site, sitedescription, key, status, expires
				# If we expired more than a month ago, then show nothing
				#if (isset($useradd['expires']) && $useradd['expires'] > 0 && time() > $useradd['expires'] + 86400*30) continue;
				if (!empty($useradd['expires'])) {
					if ($useradd['expires'] < 0) {
						$expires = __('never expires', 'updraftmanager');
					} else {
						$expires = date_i18n('Y-m-d', $useradd['expires']);
					}
				} else {
					$expires = '';
				}

				$last_checkin = false;

				if (($addon['key'] == $useradd['key']) && $useradd['status'] == 'active') {
					# If possible, access the more up-to-date array of siteURLs, rather than the original one.
					if (!empty($last_checkins) && is_array($last_checkins) && !empty($last_checkins[$this->uid]) && is_array($last_checkins[$this->uid]) && !empty($last_checkins[$this->uid][$this->slug]) && !empty($useradd['site']) && !empty($last_checkins[$this->uid][$this->slug][$useradd['site']]['site_url'])) {
						$sitedescription = $last_checkins[$this->uid][$this->slug][$useradd['site']]['site_url'];
						if (!empty($last_checkins[$this->uid][$this->slug][$useradd['site']]['time'])) {
							$last_checkin = $last_checkins[$this->uid][$this->slug][$useradd['site']]['time'];
						}
					} else {
						$sitedescription = (empty($useradd['sitedescription'])) ? '' : $useradd['sitedescription'];
					}
					
					if (false == apply_filters('updraftmanager_showaddon', true, $useradd, $last_checkin)) continue;

					$showit = true;
					$can_download = true;
					$expired = false;
					if (!empty($useradd['expires']) && time() >= $useradd['expires'] && $useradd['expires'] >0 ) {
						$expired = true;
						if ($sitedescription) {
							$in_use_on_sites .= "<strong>".sprintf(__('Expired updates subscription (%s) on:', 'updraftmanager'), $expires)."</strong> ".htmlspecialchars($sitedescription);
						} else {
							$in_use_on_sites .= "<strong>".sprintf(__('Expired updates subscription (%s)', 'updraftmanager'), $expires)."</strong>";
						}
					} elseif ('unclaimed' == $useradd['site']) {
						$in_use_on_sites .= apply_filters('updraftmanager_unactivatedpurchase', "<strong>".__('You have an available licence', 'updraftmanager')."</strong>");
					} elseif ('unlimited' == $useradd['site']) {
						$in_use_on_sites .= "<strong>".__('Active:', 'updraftmanager').'</strong> '.__('unlimited entitlement.','updraftmanager');
					} else {
						$in_use_on_sites .= "<strong>".__('Assigned:', 'updraftmanager')."</strong> ".htmlspecialchars($sitedescription);
					}
					if ($expires && !$expired) $in_use_on_sites .= '<br><span style="font-size:85%"><em>'.sprintf(__('Update subscription expires: %s', 'updraftmanager'), $expires).'</em></span>';
					if ('<br>' != substr($in_use_on_sites, -4)) $in_use_on_sites .= '<br>';

					$in_use_on_sites = apply_filters('updraftmanager_inuseonsites', $in_use_on_sites, $this->free, $this->user_can_manage, $useradd, $uid);

				}
			}
			if ('all' == $showunpurchased || ($this->free && 'free' == $showunpurchased)) $showit = true;
			if ($this->free) $can_download = true;

			# Not yet supported
			if ($this->addonsdir) $can_download = false;

			if ($showit) {

				if ($show_link && $can_download && !empty($download['filename']) && !empty($download['version'])) {

					$user_id = get_current_user_id();

					$now_time = time();
					$token_time = $now_time - ($now_time % (7*86400));

					$token = md5($user_id.'-'.$this->uid.'-'.$this->slug.'-0-'.$token_time.'-'.rand());

					set_transient('uddownld_'.$token, array(
						'id' => $user_id,
						'sid' => false,
						'download' => $download['filename'],
						'uid' => $this->uid,
						'slug' => $this->slug,
						'pluginfile' => $download['pluginfile'],
						'mustbeloggedin' => ($this->free) ? false : true
					), 7*86400);

					$ssl = is_ssl();

					# The final parameter is because WordPress uses the URL to construct a temporary directory using basename($url), which can be long and risk overflowing 256-character limits (e.g. on WAMP)
					$plugin_download_url = apply_filters('updraftmanager_downloadbase', home_url('', ($ssl) ? 'https' : 'http'), $ssl).'/?udm_action=download&slug='.$this->slug.'&muid='.$this->uid.'&token='.$token.'&ig=/'.substr(md5(time()),0,8);
					
					$in_use_on_sites .= '<p><a href="'.esc_attr($plugin_download_url).'">'.sprintf(__('Download %s version %s', 'updraftmanager'), $this->plugin_name, $download['version']).'</a></p>';
				}

				$ret .= $this->addonbox($addon['name'], apply_filters('updraftmanager_shopurl', $addon['shopurl']), $addon['description'], $addon['latestversion'], $in_use_on_sites, $key, $user_id);

			}

		}

		$ret.= '</div>';

		return $ret;

	}

}
