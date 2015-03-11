<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

if(!class_exists('WP_List_Table')) require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');

# http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617

class UpdraftManager_List_Table extends WP_List_Table {

	public function get_columns(){
	$columns = array(
		'cb' => '<input type="checkbox" />',
		'name' => __('Name', 'updraftmanager'),
		'slug' => __('Slug', 'updraftmanager'),
		'downloads' => __('Downloads', 'updraftmanager'),
		'description' => __('Description', 'updraftmanager')
	);
	return $columns;
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);
		$data = UpdraftManager_Options::get_options();
		if (!is_array($data)) $data = array();
		usort($data, array( &$this, 'usort_reorder' ) );
		$this->items = $data;
	}

	public function usort_reorder( $a, $b ) {
		// If no sort, default to title
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'name';
		// If no order, default to asc
		$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'asc';
		// Determine sort order
		$result = strcmp( $a[$orderby], $b[$orderby] );
		// Send final sort direction to usort
		return ( $order === 'asc' ) ? $result : -$result;
	}

	public function get_sortable_columns() {
		$sortable_columns = array(
			'name'  => array('name',false),
			'slug' => array('slug',false),
			'downloads' => array('downloads', false),
			'description' => array('description',false),
		);
		return $sortable_columns;
	}

	public function column_default( $item, $column_name ) {
		switch( $column_name ) { 
		case 'slug':
		case 'description':
			return $item[ $column_name ];
		case 'downloads':
			if (!isset($this->downloads)) $this->downloads = get_user_meta(get_current_user_id(), 'udmanager_downloads', true);
			if (!is_array($this->downloads) || empty($this->downloads[$item['slug']])) return 0;
			$total = 0;
			foreach ($this->downloads[$item['slug']] as $fn) {
				if (is_array($fn)) {
					foreach ($fn as $dl) {
						$total += $dl;
					}
				}
			}
			return $total;
		default:
			return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
		}
	}

	public function column_name($item) {
		$isactive = (isset($item['active']) && false == $item['active']) ? false : true;
		$actions = array(
				'edit'      => sprintf('<a href="?page=%s&action=%s&oldslug=%s">Edit</a>',$_REQUEST['page'],'edit',$item['slug']),
				'managezips'      => sprintf('<a href="?page=%s&action=%s&slug=%s">'.__('Manage Zips', 'updraftmanager').'</a>',$_REQUEST['page'],'managezips',$item['slug']),
				'delete'    => sprintf('<a class="udmplugin_delete" href="?page=%s&action=%s&slug=%s">'.__('Delete').'</a>',$_REQUEST['page'],'delete',$item['slug']),
				'activation'    => sprintf('<a href="?page=%s&action=%s&slug=%s">%s</a>', $_REQUEST['page'], ($isactive) ? 'deactivate' : 'activate',$item['slug'], ($isactive) ? __('De-activate') : __('Activate')),
			);
		$name = $item['name'].(($isactive) ? '' : ' <strong>(inactive)</strong>');
		
		$name .= (empty($item['zips'])) ? ' <strong>'.__('(No zips)', 'updraftmanager').'</strong>' : ' '.sprintf(__('(%d zips)', 'updraftmanager'), count($item['zips']));

		if (!empty($item['zips']) && empty($item['rules'])) $name .= ' <strong>'.__('(No rules)', 'updraftmanager').'</strong>';

		return sprintf('%1$s %2$s', $name, $this->row_actions($actions) );
	}

	public function get_bulk_actions() {
		$actions = array(
			'delete' => __('Delete')
		);
		return $actions;
	}

	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="slug[]" value="%s" />', $item['slug']
		);    
	}

}
