<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

if(!class_exists('WP_List_Table')) require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');

# http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617

class UpdraftManager_Zips_Table extends WP_List_Table {

	private $plug_slug;

	function __construct($slug) {
		// Not entirely sure why this double-save is needed; seems like somewhere after WP 4.1, $this->slug started getting over-written
		$this->plug_slug = $slug;
		$this->slug = $slug;
		parent::__construct();
	}

	function get_columns() {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'filename' => __('Filename', 'updraftmanager'),
			'version' => __('Version', 'updraftmanager'),
			'downloads' => __('Downloads', 'updraftmanager'),
			'minwpver' => __('Minimum WP Version', 'updraftmanager'),
			'testedwpver' => __('Tested WP Version', 'updraftmanager'),
		);
		return $columns;
	}

	function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);
		$data = UpdraftManager_Options::get_options();
		$data = (empty($this->plug_slug) || empty($data[$this->plug_slug]['zips']) || !is_array($data[$this->plug_slug]['zips'])) ? array() : $data[$this->plug_slug]['zips'];
		usort($data, array( &$this, 'usort_reorder' ) );
		$this->items = $data;
	}

	function no_items() {
		_e( 'No zips found.' );
		echo ' '.__('Users will not be able to obtain any updates.', 'updraftmanager');
	}

	function usort_reorder( $a, $b ) {
		// If no sort, default to title
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'filename';
		// If no order, default to asc
		$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'asc';
		if (empty($a[$orderby]) && empty($b[$orderby])) return $a;
		// Determine sort order
		$result = strcmp( $a[$orderby], $b[$orderby] );
		// Send final sort direction to usort
		return ( $order === 'asc' ) ? $result : -$result;
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			'filename'  => array('filename', false),
			'version' => array('version', false),
			'downloads' => array('downloads',false),
			'minwpver' => array('minwpver',false),
			'testedwpver' => array('testedwpver',false)
		);
		return $sortable_columns;
	}

	function column_default($item, $column_name) {
		switch( $column_name ) { 
		case 'filename':
		case 'minwpver':
		case 'testedwpver':
		case 'version':
			return $item[$column_name];
		case 'downloads':
			if (!isset($this->downloads)) $this->downloads = get_user_meta(get_current_user_id(), 'udmanager_downloads', true);
			if (!is_array($this->downloads) || empty($this->downloads[$this->plug_slug][$item['filename']])) return 0;
			$total = 0;
			foreach ($this->downloads[$this->plug_slug][$item['filename']] as $dl) {
				$total += $dl;
			}
			return $total;
		default:
			return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
		}
	}

	function column_filename($item) {
		$actions = array(
				'edit'      => sprintf('<a href="?page=%s&action=%s&oldfilename=%s&slug=%s">'.__('Edit', 'updraftmanager').'</a>', $_REQUEST['page'], 'edit_zip', $item['filename'], $this->plug_slug),
				'delete'    => sprintf('<a class="udmzip_delete" href="?page=%s&action=%s&filename=%s&slug=%s">'.__('Delete (and associated rules)', 'updraftmanager').'</a>', $_REQUEST['page'],'delete_zip', $item['filename'], $this->plug_slug)
			);
		return sprintf('%1$s %2$s', $item['filename'], $this->row_actions($actions) );
	}

	function get_bulk_actions() {
		$actions = array( 'delete_zip' => 'Delete' );
// 		$actions = array();
		return $actions;
	}

	function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="filename[]" value="%s" />', $item['filename']
		);    
	}

}
