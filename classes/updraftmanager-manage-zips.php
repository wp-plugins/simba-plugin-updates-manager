<?php

if (!defined('UDMANAGER_DIR')) die('Direct access not allowed');

class UpdraftManager_Manage_Zips {

	public function __construct($slug, $plugin) {
		$this->slug = $slug;
		$this->plugin = $plugin;
		$this->manager_dir = UpdraftManager_Options::get_manager_dir();
		global $updraftmanager_options;
		$this->options = $updraftmanager_options;
	}

	function managezips() {
		$this->show_zips();
		echo '<hr style="margin: 34px 0;">';
		$this->show_zip_rules();
	}

	function delete_zip() {
		$zips = (empty($this->plugin['zips'])) ? array() : $this->plugin['zips'];

		if (empty($_REQUEST['filename'])) {
			$this->options->show_admin_warning(__('No zip file specified.', 'updraftmanager'));
			return;
		}

		$filenames = $_REQUEST['filename'];
		if (!is_array($filenames)) $filenames = array($filenames);

		foreach ($filenames as $filename) {
			if (empty($zips[$filename]) || !preg_match('/^[^\/\\\]+\.zip$/', $filename)) {
				$this->options->show_admin_warning(sprintf(__('The zip %s was not found.', 'updraftmanager'), $filename));
				continue;
			}

			# Update option
			unset($zips[$filename]);
			# Remove file
			@unlink($this->manager_dir.'/'.$filename);
			# Remove unpacked directory
			if (is_dir($this->manager_dir.'/_up_'.$filename)) UpdraftManager_Options::remove_local_directory($this->manager_dir.'/_up_'.$filename);
			$this->delete_zip_rules($filename);
		}

		$this->plugin['zips'] = $zips;
		$this->update_plugins();

		$this->options->show_admin_warning(__('The zip(s) were successfully deleted.', 'updraftmanager'));

		$this->managezips();

	}

	function delete_zip_rules($filename) {
		$rules = (empty($this->plugin['rules'])) ? array() : $this->plugin['rules'];
		foreach ($rules as $ind => $rule) {
			if (!empty($rule['filename']) && $rule['filename'] == $filename) unset($rules[$ind]);
		}
		$this->plugin['rules'] = $rules;
		$this->update_plugins();
	}

	function update_plugins() {
		$plugins = UpdraftManager_Options::get_options();
		$plugins[$this->slug] = $this->plugin;
		 UpdraftManager_Options::update_options($plugins);
	}

	function edit_zip() {
		$plugins = UpdraftManager_Options::get_options();
		if (!isset($_GET['oldfilename']) || empty($plugins[$this->slug]['zips'][$_GET['oldfilename']])) return;
		$values = $plugins[$this->slug]['zips'][$_GET['oldfilename']];
		if (!is_array($values)) $values = array();
		$this->upload_form($values);
	}

	function edit_zip_go() {

		$plugins = UpdraftManager_Options::get_options();
		if (!isset($_POST['filename']) || empty($this->plugin['zips'][$_POST['filename']])) return;

		$zips = (empty($this->plugin['zips'])) ? array() : $this->plugin['zips'];

		$data = $zips[$_POST['filename']];
		$data['minwpver'] = (empty($_POST['minwpver'])) ? '' : $_POST['minwpver'];
		$data['testedwpver'] = (empty($_POST['minwpver'])) ? '' : $_POST['testedwpver'];

		$zips[$_POST['filename']] = $data;
		$this->plugin['zips'] = $zips;

		if (!empty($_POST['addrule'])) $this->add_default_rule($_POST['filename'], false);

		$this->update_plugins();

		# Success.
		$this->options->show_admin_warning(sprintf(__('The zip file %s was edited successfully.', 'updraftmanager'), $_POST['filename']));
		//$this->options->show_plugins();
		$this->managezips();

	}

	function add_new_zip_go() {
		if (empty($_FILES['filename'])) {
			$this->add_new_zip();
			return;
		}
		$file = $_FILES['filename'];
		if (!empty($file['error'])) {
			$this->add_new_zip($file['error']);
			return;
		}
		# Is it a zip?
		if (!preg_match('/\.zip$/i', $file['name'])) {
			$this->add_new_zip(__('Only .zip files are accepted.', 'updraftmanager'));
			return;
		}
		# No invalid elements in the file name
		if (false !== strpos($file['name'], '/')) {
			$this->add_new_zip(__('The filename contains an invalid character.', 'updraftmanager'));
			return;
		}
		# Does a zip with the same name already exist?
		if ($this->filename_exists($file['name'])) {
			$this->add_new_zip(__('A .zip with this filename already exists. Please use a unique filename.', 'updraftmanager'));
			return;
		}

		# Is the zip valid?
		$unpacked = $this->zip_valid($file['tmp_name']);
		if (!is_string($unpacked)) {
			if (is_wp_error($unpacked)) {
				foreach ($unpacked->get_error_messages() as $err) {
					$msg = (empty($msg)) ? htmlspecialchars($err) : ', '.htmlspecialchars($err);
				}
			} else {
				$msg = serialize($unpacked);
			}
			$this->add_new_zip(sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'updraftmanager').' '.$msg, $this->slug));
			return;
		}
		# Check the zip format
		$found_slug = false;
		#$found_a_file = false;
		$d = dir($unpacked);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry && strtolower($this->slug) !== $entry) {
				$this->add_new_zip(sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'updraftmanager'), $this->slug).' '.sprintf(__('The additional entry encountered was: %s', 'updraftmanager'), htmlspecialchars($entry), $this->slug));
				return;
			}
			if (strtolower($this->slug) === strtolower($entry) && is_dir($unpacked.'/'.$entry)) $found_slug = true;
		}
		$d->close();
		if (!$found_slug) {
			$this->add_new_zip(sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'updraftmanager'), $this->slug).' '.sprintf(__('The top-level directory was not found.', 'updraftmanager'), htmlspecialchars($entry), $this->slug));
			return;
		}

		$d = dir($unpacked.'/'.$this->slug);
		$found_plugin = false;
		if (!function_exists('get_plugin_data')) require(ABSPATH.'wp-admin/includes/plugin.php');
		while (false !== ($entry = $d->read())) {
			if (is_file($unpacked.'/'.$this->slug.'/'.$entry) && '.php' == substr($entry, -4)) {
				$plugdata = get_plugin_data($unpacked.'/'.$this->slug.'/'.$entry, false, false ); //Do not apply markup/translate as it'll be cached.
				if (!empty($plugdata['Name']) && !empty($plugdata['Version'])) $found_plugin = array('file' => $entry, 'data' => $plugdata);
			}
		}
		$d->close();

		if (false === $found_plugin) {
			$this->add_new_zip(sprintf(__('The zip file was not valid; it needs to contain a valid .php plugin file (see: %s)', 'updraftmanager'), '<a href="http://codex.wordpress.org/File_Header#Plugin_File_Header_Example">http://codex.wordpress.org/File_Header#Plugin_File_Header_Example</a>'));
			return;
		}

// 		if (!$found_a_file) {
// 			$this->add_new_zip(__('The zip file was not valid; it contains no files.', 'updraftmanager'));
// 			return;
// 		}

		global $updraft_manager;
		if (!$updraft_manager->manager_dir_exists($this->manager_dir)) {
			$this->add_new_zip(sprintf(__('Could not receive this zip file: the internal storage directory (%s) does not exist (probably WordPress lacks file permissions to create this directory).', 'updraftmanager'), $this->manager_dir));
			return;
		}

		if (file_exists($this->manager_dir.'/'.$file['name'])) {
			$this->add_new_zip(__('A .zip with this filename already exists. Please use a unique filename.', 'updraftmanager'));
			return;
		}

		$unpacked_dir = $this->manager_dir.'/_up_'.$file['name'];
		if (file_exists($unpacked_dir)) {
			$this->add_new_zip(sprintf(__('A directory in our cache matching this zip name already exists (%s) (internal inconsistency - consider resolving this via deleting it)', 'updraftmanager'), $unpacked_dir));
			return;
		}

		if (!rename($unpacked, $unpacked_dir)) {
			$this->add_new_zip(sprintf(__('The unpacked zip file could not be moved (to %s) - probably the file permissions in your WordPress install are incorrect.', 'updraftmanager'), $unpacked_dir));
			return;
		}

		if (!move_uploaded_file($file['tmp_name'], $this->manager_dir.'/'.$file['name'])) {
			$this->add_new_zip(sprintf(__('The zip file could not be moved (to %s) - probably the file permissions in your WordPress install are incorrect.', 'updraftmanager'), $this->manager_dir.'/'.$file['name']));
			return;
		}

		$zips = (empty($this->plugin['zips'])) ? array() : $this->plugin['zips'];
		$zips[$file['name']] = array (
			'filename' => $file['name'],
			'version' => $found_plugin['data']['Version'],
			'pluginfile' => $found_plugin['file'],
			'minwpver' => (empty($_POST['minwpver'])) ? '' : $_POST['minwpver'],
			'testedwpver' => (empty($_POST['testedwpver'])) ? '' : $_POST['testedwpver']
		);
		$this->plugin['zips'] = $zips;

		if (!empty($_POST['addrule'])) $this->add_default_rule($file['name'], false);

		$this->update_plugins();

		# Success.
		$this->options->show_admin_warning(sprintf(__('The zip file %s was added successfully.', 'updraftmanager'), $file['name']));

		//$this->options->show_plugins();
		$this->managezips();

	}

	function delete_rule() {
		if (empty($_REQUEST['ruleno'])) return $this->managezips();

		$rulenos = (!is_array($_REQUEST['ruleno'])) ? array($_REQUEST['ruleno']) : $_REQUEST['ruleno'];

		$rules = (empty($this->plugin['rules']) || !is_array($this->plugin['rules'])) ? array() : $this->plugin['rules'];

		foreach ($rulenos as $ruleno) {
			if (!is_numeric($ruleno)) continue;
			$rule_index = $ruleno-1;
			unset($rules[$rule_index]);
		}

		$this->plugin['rules'] = $rules;

		$this->update_plugins();
		$this->options->show_admin_warning(__('The rule was successfully deleted.', 'updraftmanager'));

		$this->managezips();

	}

	function add_default_rule($filename, $update_plugins = true) {

		$rules = (empty($this->plugin['rules']) || !is_array($this->plugin['rules'])) ? array() : $this->plugin['rules'];

		$first_rule = array_shift($rules);
		// Then put it back
		if (is_array($first_rule)) array_unshift($rules, $first_rule);

		if (!is_array($first_rule) || empty($first_rule)) {
			$add_rule = true;
		} else {
			$first_sub_rule = array_shift($rules);
			if (empty($first_rule['filename']) || $first_rule['filename'] != $filename || empty($first_sub_rule['criteria']) || $first_sub_rule['criteria'] != 'always') {
				$add_rule = true;
			}
		}

		if (!empty($add_rule)) array_unshift($rules, array('combination' => 'and', 'filename' => $filename, 'rules' => array(array('criteria' => 'always'))));

		$this->plugin['rules'] = $rules;

		if ($update_plugins) $this->update_plugins();
	}

	function zip_valid($file) {
		if (!class_exists('PclZip')) require_once(ABSPATH.'wp-admin/includes/class-pclzip.php');

		$pclzip = new PclZip($file);

		$wpcd = WP_CONTENT_DIR.'/upgrade';
		if (!is_dir($wpcd) && !mkdir($wpcd)) return new WP_Error('mkdir_failed', 'Could not mkdir: '.WP_CONTENT_DIR.'/upgrade');

		$rand = substr(md5(time().$file.rand()), 0, 12);
		while (file_exists($wpcd.'/'.$rand)) {
			$rand = substr(md5(time().$file.rand()), 0, 12);
			if (!mkdir($wpcd.'/'.$rand)) return new WP_Error('mkdir_failed', 'Could not mkdir: '.$wpcd.'/'.$rand);
		}

		$extract = $pclzip->extract(PCLZIP_OPT_PATH, $wpcd.'/'.$rand);
		#if (is_wp_error($unpacked)) return $unpacked;
		if (!is_array($extract)) return new WP_Error('unpack_failed', 'Unpack failed: '.serialize($extract));
		#return $unpacked;
		return $wpcd.'/'.$rand;
	}

	function filename_exists($filename) {
		$plugins = UpdraftManager_Options::get_options();
		if (!array($plugins)) return false;
		foreach ($plugins as $plug) {
			if (!empty($plug['zips']) && is_array($plug['zips'])) {
				foreach ($plug['zips'] as $zip) {
					if (!empty($zip['filename']) && strtolower($zip['filename']) == strtolower($filename)) return true;
				}
			}
		}
		return false;
	}

	function add_new_zip($error = false, $info = false) {
		echo '<h2>'.sprintf(__('%s: Upload a new zip', 'updraftmanager'), $this->plugin['name']).'</h2><p>';
		printf(__('Use this form to upload a new zip file for this plugin. The plugin must be in the expected format - i.e. it must contain a single directory at the top level, with the name matching the plugin slug (%s).', 'updraftmanager'), $this->slug);
		echo '</p>';

		if (false !== $error) {
			echo '<p><strong>';
			printf(__('The file could not be uploaded; the error code was: %s', 'updraftmanager'), htmlspecialchars($error));
			echo '</strong> ';
			if (is_numeric($error)) echo __('See:', 'updraftmanager').' <a href="http://www.php.net/manual/en/features.file-upload.errors.php">http://www.php.net/manual/en/features.file-upload.errors.php</a>';
			echo '</p>';
		}

		if (false !== $info) {
			echo '<div class="updated" style="padding:6px;">'.htmlspecialchars($info).'</div>';
		}

		$this->upload_form();
	}


	function edit_rule_go() {
		if (empty($_POST['oldruleno']) || !is_numeric($_POST['oldruleno'])) return $this->rule_form($_POST);
		$this->add_new_rule_go();
	}

	# This function also handles editing
	function add_new_rule_go() {
		if (!isset($_POST['ud_rule_type']) || !isset($_POST['relationship']) || !isset($_POST['combination']) || empty($_POST['filename'])) return $this->rule_form($_POST);

		$filename = $_POST['filename'];

		if (empty($this->plugin['zips'][$filename])) return $this->rule_form($_POST);

		if (!is_array($_POST['filename'])) $_POST['filename'] = array();
		if (!is_array($_POST['relationship'])) $_POST['relationship'] = array();

		$rules = (!empty($this->plugin) && isset($this->plugin['rules']) && is_array($this->plugin['rules'])) ? $this->plugin['rules'] : array();

		$newrule_rules = array();

		ksort($_POST['ud_rule_type']);
		foreach ($_POST['ud_rule_type'] as $ind => $nrule) {
			if ('always' == $_POST['ud_rule_type'] || !empty($_POST['relationship'])) {
				$criteria = $_POST['ud_rule_type'][$ind];
				$ourrule = array(
					'criteria' => $criteria
				);
				if ('always' != $_POST['ud_rule_type']) {
					$use = $_POST['relationship'][$ind];
					if (('siteurl' == $criteria || 'username' == $criteria) || ('gt' != $use && 'lt' != $use && 'range' != $use)) $use = 'eq';
					$ourrule['relationship'] = $use;
					$ourrule['value'] = $_POST['ud_rule_value'][$ind];
				}
				$newrule_rules[] = $ourrule;
			}
		}

		if (count($newrule_rules) > 0) {
			$newrule = array(
				'filename' => $filename,
				'combination' => ('or' == $_POST['combination']) ? 'or' : 'and',
				'rules' => $newrule_rules,
			);


			if (isset($_POST['oldruleno']) && is_numeric($_POST['oldruleno'])) {
				$ruleno = $_POST['oldruleno'] - 1;
				$rules[$ruleno] = $newrule;
			} elseif (empty($_POST['ud_rule_firstlast']) || 'last' == $_POST['ud_rule_firstlast']) {
				$rules[] = $newrule;
			} else {
				array_unshift($rules, $newrule);
			}

			$this->plugin['rules'] = $rules;
			$this->update_plugins();


			$this->show_zips();
			echo '<hr style="margin: 34px 0;">';
			if (isset($_POST['oldruleno']) && is_numeric($_POST['oldruleno'])) $this->options->show_admin_warning(sprintf(__('The zip rule %s was edited successfully.', 'updraftmanager'), $_POST['oldruleno']));
			$this->show_zip_rules();

		}

	}

	function edit_rule() {

		if (empty($_GET['oldruleno']) || !is_numeric($_GET['oldruleno'])) return $this->managezips();

		echo '<h2>'.sprintf(__('%s: Edit download rule (number: %s)', 'updraftmanager'), $this->plugin['name'], $_GET['oldruleno']).'</h2><p> </p>';

		$rindex = $_GET['oldruleno'] - 1;

		$rule = (!empty($this->plugin['rules'][$rindex])) ? $this->plugin['rules'][$rindex] : array();

		$this->rule_form($rule);
	}

	function add_new_rule($error = false, $info = false) {
		echo '<h2>'.sprintf(__('%s: Add a new download rule', 'updraftmanager'), $this->plugin['name']).'</h2><p>';
		printf(__('Use this form to add a new rule for determining which zip to offer for download to any particular WordPress site that is checking for updates.', 'updraftmanager'), $this->slug);
		echo '</p>';

		if (false !== $error) {
			echo '<p><strong>';
			printf(__('The entry could not be processed; the error code was: %s', 'updraftmanager'), htmlspecialchars($error));
			echo '</strong> ';
			echo '</p>';
		}

		if (false !== $info) {
			echo '<div class="updated" style="padding:6px;">'.htmlspecialchars($info).'</div>';
		}

		$this->rule_form();
	}

	protected function rule_form($use_values = array()) {

		?>
		<div id="updraftmanager_form">
		<form onsubmit="return updraftmanager_rule_submit();" method="POST">
		<input type="hidden" name="page" value="<?php echo htmlspecialchars($_REQUEST['page']); ?>">
		<input type="hidden" name="action" value="<?php echo (empty($use_values)) ? 'add_new_rule_go' : 'edit_rule_go'; ?>">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		if (!empty($use_values) && isset($_GET['oldruleno'])) {
			?>
			<input type="hidden" name="oldruleno" value="<?php echo (int)$_GET['oldruleno'];?>">
			<?php
		} else {
			?>

		<div class="ud_rule_option_label"><?php _e('Where to add the rule:', 'updraftmanager');?></div>

		<div class="ud_rulebox">

		<input class="ud_rule_firstlast" id="ud_rule_firstlast_first" type="radio" name="ud_rule_firstlast" value="first" <?php if (empty($use_values['ud_rule_firstlast']) || 'first' == $use_values['ud_rule_firstlast']) echo 'checked="checked"'; ?>>
		<label class="ud_rule_firstlast ud_rule_firstlast_innerlabel" for="ud_rule_firstlast_first"><?php _e('Add this rule as the first rule for this zip', 'updraftmanager');?></label>

		<input class="ud_rule_firstlast" id="ud_rule_firstlast_last" type="radio" name="ud_rule_firstlast" value="last" <?php if (!empty($use_values['ud_rule_firstlast']) && 'last' == $use_values['ud_rule_firstlast']) echo 'checked="checked"'; ?>>
		<label class="ud_rule_firstlast ud_rule_firstlast_innerlabel" for="ud_rule_firstlast_last"><?php _e('Add this rule as the last rule for this zip', 'updraftmanager');?></label>
		</div>

		<?php } ?>

		<div class="ud_rule_option_label"><?php _e('Multiple rules:', 'updraftmanager');?></div>

		<div class="ud_rulebox">

		<input class="ud_rule_combination" id="ud_rule_combination_and" type="radio" name="combination" value="and" <?php if (empty($use_values['combination']) || 'and' == $use_values['combination']) echo 'checked="checked"'; ?>>
		<label title="<?php echo esc_attr(__('Logical AND match', 'updraftmanager'));?>" class="ud_rule_combination ud_rule_combination_innerlabel" for="ud_rule_combination_and"><?php _e('Require all of the rules below (if there are more than one) to match', 'updraftmanager');?></label>

		<input class="ud_rule_combination" id="ud_rule_combination_or" type="radio" name="combination" value="or" <?php if (!empty($use_values['combination']) && 'or' == $use_values['combination']) echo 'checked="checked"'; ?>>
		<label title="<?php echo esc_attr(__('Logical OR match', 'updraftmanager'));?>" class="ud_rule_combination ud_rule_combination_innerlabel" for="ud_rule_combination_or"><?php _e('Require any of the rules below (if there are more than one) to match', 'updraftmanager');?></label>
		</div>

		<div id="updraftmanager_rules">
		<?php
			$this->footer_js = '';
			if (!empty($use_values['rules']) && is_array($use_values['rules'])) {
				foreach ($use_values['rules'] as $ind => $rule) {
					if (!is_array($rule)) continue;
					$relationship = (empty($rule['relationship'])) ? '' : $rule['relationship'];
					$value = (empty($rule['value'])) ? '' : $rule['value'];
					$this->footer_js .= "updraftmanager_newline($ind, '".$rule['criteria']."', '$relationship', '".esc_js($value)."');\n";
				}
			}
			if (empty($this->footer_js)) $this->footer_js = "updraftmanager_newline(0);\n";
			#$this->newrule_line(0);
		?>
		</div>

		<div id="updraftmanager_newrule_div" class="ud_rulebox ud_leftgap">
		<a href="#" id="updraftmanager_newrule"><?php _e('Add another rule...', 'updraftmanager');?></a>
		</div>

		<label class="ud_rule_filename" for="ud_rule_filename"><?php _e('Target zip:', 'updraftmanager');?></label>

		<div class="ud_rulebox">

			<select name="filename" id="ud_rule_filename">
				<?php
				$zips = (!empty($this->plugin['zips'])) ? $this->plugin['zips'] : array();
				foreach ($zips as $zip) {
					if (!empty($zip['filename'])) echo '<option value="'.esc_attr($zip['filename']).'" '.((!empty($use_values['filename']) && $use_values['filename'] == $zip['filename']) ? 'selected="selected"': '').'>'.htmlspecialchars($zip['filename']).'</option>';
				}
				?>
			</select>

		</div>

		<input type="submit" class="button" value="<?php echo (empty($use_values)) ? __('Create', 'updraftmanager') : __('Edit', 'updraftmanager'); ?>">
		</form>
		</div>
		<?php
		add_action('admin_footer', array($this, 'admin_footer'));
	}

	public function admin_footer() {
		?>
		<script>
			jQuery(document).ready(function($){
				<?php echo $this->footer_js; ?>
			});
		</script>
		<?php
	}

	# NOT USED
	function newrule_line($ind) {
		$ind = (int)$ind;
		?>
		<div id="ud_rule_<?php echo $ind;?>">

			<label for="ud_rule_type[<?php echo $ind;?>]"><?php _e('Rule:', 'updraftmanager');?></label>
			<select class="ud_rule_type" name="ud_rule_type[<?php echo $ind;?>]" id="ud_rule_<?php echo $ind;?>">
				<option value="always" title="<?php echo esc_attr(__('Apply this rule always', 'updraftmanager'));?>"><?php echo __('Always match', 'updraftmanager'); ?></option>
				<option value="installed" title="<?php echo esc_attr(__('Apply this rule if the site checking already has a specified version installed', 'updraftmanager'));?>"><?php echo __('Installed plugin version', 'updraftmanager'); ?></option>
				<option value="wp" title="<?php echo esc_attr(__('Apply this rule if the site checking has a particular version of WordPress installed', 'updraftmanager'));?>"><?php echo __('WordPress version', 'updraftmanager'); ?></option>
				<option value="php" title="<?php echo esc_attr(__('Apply this rule if the site checking has a particular version of PHP installed', 'updraftmanager'));?>"><?php echo __('PHP version', 'updraftmanager'); ?></option>
				<option value="username" title="<?php echo esc_attr(__('Apply this rule if the site checking belongs to a specified user from this site', 'updraftmanager'));?>"><?php echo __('Username', 'updraftmanager'); ?></option>
				<option value="siteurl" title="<?php echo esc_attr(__('Apply this rule if the site checking has a specific site URL', 'updraftmanager'));?>"><?php echo __('Site URL', 'updraftmanager'); ?></option>
			</select>

			<select class="ud_rule_relationship" name="relationship[<?php echo $ind;?>]" id="ud_rule_relationship<?php echo $ind;?>">
				<option value="eq"><?php _e('equals', 'updraftmanager'); ?></option>
				<option value="lt"><?php _e('is at most', 'updraftmanager'); ?></option>
				<option value="gt"><?php _e('is at least', 'updraftmanager'); ?></option>
				<option value="range"><?php _e('is between', 'updraftmanager'); ?></option>
			</select>

			<input type="text" class="ud_rule_value" name="ud_rule_value[<?php echo $ind;?>]" id="ud_rule_value_<?php echo $ind;?>" value="" title="<?php _e('If you are entering a range, specify the (inclusive) end points using a comma; for example: 1.0,2.1', 'updraftmanager');?>">
		
		</div>
		<?php
	}

	function upload_form($use_values = array()) {

		?>
		<div id="updraftmanager_form">
		<form enctype="multipart/form-data" method="POST">
		<input type="hidden" name="page" value="<?php echo htmlspecialchars($_REQUEST['page']); ?>">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">

		<?php if (empty($use_values['filename'])) { ?>
			<input type="hidden" name="action" value="add_new_zip_go">
			<input type="hidden" name="MAX_FILE_SIZE" value="419430400" />

			<label for="ud_filename"><?php _e('Zip file to upload:', 'updraftmanager');?></label>
			<input type="file" id="ud_filename" name="filename" accept="application/zip">
		<?php }  else {
			?>
			<input type="hidden" name="action" value="edit_zip_go">
			<label for="ud_filename"><?php _e('Zip file:', 'updraftmanager');?></label>
			<input type="hidden" id="ud_filename" name="filename" value="<?php echo $use_values['filename'];?>">
			<?php
			echo '<div class="infodiv">'.htmlspecialchars($use_values['filename']).'</div>';
		}
		?>

		<label for="minwpver"><?php _e('Minimum WordPress version required:', 'updraftmanager');?></label> <input id="minwpver" type="text" name="minwpver" value="<?php echo (isset($use_values['minwpver'])) ? htmlspecialchars($use_values['minwpver']) : ''; ?>" size="8" maxlength="8">
		<span class="udm_description"><em></em></span>

		<label for="testedwpver"><?php _e('Tested up to WordPress version:', 'updraftmanager');?></label> <input id="testedwpver" type="text" name="testedwpver" value="<?php echo (isset($use_values['testedwpver'])) ? htmlspecialchars($use_values['testedwpver']) : ''; ?>" size="8" maxlength="8">
		<span class="udm_description"><em></em></span>

		<label for="addrule"><?php _e('Make this the default download for all users', 'updraftmanager');?></label>
		<input id="addrule" type="checkbox" name="addrule" value="yes" checked="checked">
		<span class="udm_description"><em><?php _e('This will (if needed) create a new download rule.', 'updraftmanager'); ?></em></span>

		<input type="submit" class="button" value="<?php echo (empty($use_values)) ? __('Upload', 'updraftmanager') : __('Edit', 'updraftmanager'); ?>">
		</form>
		</div>
		<?php
	}

	function show_zips() {

		echo '<p><em>'.__('Use this screen to upload zips for this plugin, and to define which one a particular user will be sent.', 'updraftmanager').'</em></p>';

		$plugin = $this->plugin;

		?><div id="icon-plugins" class="icon32"><br></div><h2><?php echo sprintf(__('%s: Manage zips', 'updraftmanager'), htmlspecialchars($plugin['name'])); ?> <a href="?page=<?php echo htmlspecialchars($_REQUEST['page'])?>&action=add_new_zip&slug=<?php echo htmlspecialchars($this->slug);?>" class="add-new-h2"><?php _e('Add New', 'updraftmanager');?></a></h2><?php

		if(!class_exists('UpdraftManager_Zips_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-zips-table.php');

		$zips_table = new UpdraftManager_Zips_Table($this->slug);
		$zips_table->prepare_items(); 
		?>
		<form method="post" class="updraftmanager_ziptable">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		$zips_table->display(); 
		?>
		</form>
		<p><?php echo __('Updates URL for this plugin (used inside the plugin):', 'updraftmanager');?> <code><?php echo sprintf('%s/?udm_action=%s&slug=%s&muid=%s', home_url(), 'updateinfo', $this->slug, get_current_user_id());?></code></p>

		<?php
	}

	function show_zip_rules() {

		$plugin = $this->plugin;

		$add_new = (!empty($this->plugin['zips']) && count($this->plugin['zips']) >0);

		?><div id="icon-tools" class="icon32"><br></div><h2><?php echo sprintf(__('%s: Manage download rules', 'updraftmanager'), htmlspecialchars($plugin['name'])); ?> <?php
			
		if ($add_new) {

			?><a href="?page=<?php echo htmlspecialchars($_REQUEST['page'])?>&action=add_new_rule&slug=<?php echo htmlspecialchars($this->slug);?>" class="add-new-h2"><?php _e('Add New', 'updraftmanager');?></a><?php

		} else {
			?><span class="add-new-h2"><?php _e('There are no zips yet - cannot add any rules', 'updraftmanager');?></a><?php
		}

		?></h2>

		<p><em><?php echo htmlspecialchars(__('Rules are applied in order, and the processing stops after the first matching rule. If no matching rule is found, then no updates will be offered to the user.', 'updraftmanager').' '.__('To re-order rules, drag and drop them.', 'updraftmanager')); ?></em></p>

		<?php

		if(!class_exists('UpdraftManager_ZipRules_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-ziprules-table.php');

		$zip_rules_table = new UpdraftManager_ZipRules_Table($this->slug);
		$zip_rules_table->prepare_items(); 
		?>
		<form method="post" class="updraftmanager_ruletable">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		$zip_rules_table->display();
		echo '</form>';

	}

}

// if (!class_exists('WP_Upgrader_Skin')) require_once(ABSPATH.'wp-admin/includes/class-wp-upgrader.php');
// class UpdraftManager_Upgrader_Skin extends WP_Upgrader_Skin {
// 
// 	function header() {}
// 	function footer() {}
// 	function bulk_header() {}
// 	function bulk_footer() {}
// 
// 	function error($error) {
// 		if (!$error) return;
// 		var_dump($error);
// 	}
// 
// 	function feedback($string) {
// 		# echo serialize($string)."<br>";
// 	}
// }

