<?php

if( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registers the meta boxes
 *
 * @author 	Gijs Jorissen
 * @since 	0.2
 *
 */
class Cuztom_Meta_Box extends Cuztom_Meta
{
	var $context;
	var $priority;
	var $post_types;
	
	/**
	 * Constructs the meta box
	 *
	 * @param   string 			$id
	 * @param 	string|array	$title
	 * @param 	array|string	$fields
	 * @param 	string 			$post_type_name
	 * @param 	string 			$context
	 * @param 	string 			$priority
	 *
	 * @author 	Gijs Jorissen
	 * @since 	0.2
	 *
	 */
	function __construct( $id, $title, $post_type, $data = array(), $args = array() )
	{
		if( ! empty( $title ) )
		{
			parent::__construct( $title );

			$this->id 			= $id;
			$this->post_types 	= (array) $post_type;

			$this->context		= isset( $args['context'] ) 		? $args['context'] 			: 'normal';
			$this->priority		= isset( $args['priority'] ) 		? $args['priority'] 		: 'default';
			$this->revisions	= isset( $args['revisions'] ) 		? $args['revisions'] 		: $this->revisions;

			// Chack if the class, function or method exist, otherwise use cuztom callback
			if( Cuztom::is_wp_callback( $data ) )
			{
				$this->callback = $data;
			}
			else
			{
				$this->callback = array( &$this, 'callback' );

				// Build the meta box and fields
				$this->data = $this->build( $data );

				foreach( $this->post_types as $post_type )
				{
					add_filter( 'manage_' . $post_type . '_posts_columns', array( &$this, 'add_column' ) );
					add_action( 'manage_' . $post_type . '_posts_custom_column', array( &$this, 'add_column_content' ), 10, 2 );
					add_action( 'manage_edit-' . $post_type . '_sortable_columns', array( &$this, 'add_sortable_column' ), 10, 2 );
				}

				add_action( 'save_post', array( &$this, 'save_post' ) );
				add_action( 'post_edit_form_tag', array( &$this, 'edit_form_tag' ) );
			}
			
			// Add the meta box
			add_action( 'add_meta_boxes', array( &$this, 'add_meta_box' ) );

			if( $this->revisions )
			{
				add_action( 'wp_restore_post_revision', array( &$this, 'restore_revision' ), 10, 2 );
				add_filter( '_wp_post_revision_fields', array( &$this, 'revision_fields' ) );

				foreach( $this->fields as $field )
					add_filter( '_wp_post_revision_field_' . $field->id, array( &$this, 'revision_field' ), 10, 2 );
			}
		}	
	}
	
	/**
	 * Method that calls the add_meta_box function
	 *
	 * @author 	Gijs Jorissen
	 * @since 	0.2
	 *
	 */
	function add_meta_box()
	{
		foreach( $this->post_types as $post_type )
		{
			add_meta_box(
				$this->id,
				$this->title,
				$this->callback,
				$post_type,
				$this->context,
				$this->priority
			);
		}
	}
	
	/**
	 * Hooks into the save hook for the newly registered Post Type
	 *
	 * @author 	Gijs Jorissen
	 * @since 	0.1
	 *
	 */
	function save_post( $post_id )
	{
		// Deny the wordpress autosave function
		if( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) return;

		// Verify nonce
		if( ! ( isset( $_POST['cuztom_nonce'] ) && wp_verify_nonce( $_POST['cuztom_nonce'], plugin_basename( dirname( __FILE__ ) ) ) ) ) return;

		// Is the post from the given post type?
		if( ! in_array( get_post_type( $post_id ), array_merge( $this->post_types, array( 'revision' ) ) ) ) return;

		// Is the current user capable to edit this post
		foreach( $this->post_types as $post_type )
			if( ! current_user_can( get_post_type_object( $post_type )->cap->edit_post, $post_id ) ) return;

		$values = isset( $_POST['cuztom'] ) ? $_POST['cuztom'] : array();

		if( ! empty( $values ) )
			parent::save( $post_id, $values );
	}

	/**
	 * Normal save method to save all the fields in a metabox
	 *
	 * @author 	Gijs Jorissen
	 * @since 	2.6
	 */
	function save( $post_id, $values )
	{
		foreach( $this->fields as $id => $field )
		{
			if( $field->in_bundle ) continue;
			
			$value = isset( $values[$id] ) ? $values[$id] : '';
			$value = apply_filters( "cuztom_post_meta_save_$field->type", apply_filters( 'cuztom_post_meta_save', $value, $field, $post_id ), $field, $post_id );

			$field->save( $post_id, $value );
		}
	}

	/**
	 * Restore revisions and its meta
	 *
	 * @param  	int 	$post_id
	 * @param  	int 	$revision_id
	 * 
	 * @author 	Gijs Jorissen
	 * @since 	2.7
	 */
	function restore_revision( $post_id, $revision_id ) 
	{
		$post     = get_post( $post_id );
		$revision = get_post( $revision_id );

		foreach( $this->fields as $field )
		{
			$value  = get_post_meta( $revision->ID, $field->id, true );

			if ( false !== $value )
				update_post_meta( $post_id, $field->id, $value );
			else
				delete_post_meta( $post_id, $field->id );
		}
	}

	/**
	 * Add fields to revision screen
	 *
	 * @param  	array 	$fields
	 * 
	 * @author 	Gijs Jorissen
	 * @since 	2.7
	 */
	function revision_fields( $fields ) 
	{
		foreach( $this->fields as $field )
		{
			$fields[$field->id] = $field->label;
		}

		return $fields;
	}

	/**
	 * Add meta to field on revision screen
	 *
	 * @param  	string 	$value
	 * @param  	string 	$field
	 * 
	 * @author 	Gijs Jorissen
	 * @since 	2.7
	 */
	function revision_field( $value, $field ) 
	{
		global $revision;

		if( $revision )
			return get_metadata( 'post', $revision->ID, $field, true );
	}
	
	/**
	 * Used to add a column head to the Post Type's List Table
	 *
	 * @param 	array 			$columns
	 * @return 	array
	 *
	 * @author 	Gijs Jorissen
	 * @since 	1.1
	 *
	 */
	function add_column( $columns )
	{
		unset( $columns['date'] );

		foreach( $this->fields as $id_name => $field )
		{
			if( $field->show_admin_column ) $columns[$id_name] = $field->label;
		}

		$columns['date'] = __( 'Date' );
		return $columns;
	}
	
	/**
	 * Used to add the column content to the column head
	 *
	 * @param 	string 			$column
	 * @param 	integer 		$post_id
	 * @return 	mixed
	 *
	 * @author 	Gijs Jorissen
	 * @since 	1.1
	 *
	 */
	function add_column_content( $column, $post_id )
	{
		$meta = get_post_meta( $post_id, $column, true );
		
		foreach( $this->fields as $id_name => $field )
		{
			if( $column == $id_name )
			{
				if( $field->repeatable && $field->_supports_repeatable )
				{
					echo implode( $meta, ', ' );
				}
				else
				{
					if( $field instanceof Cuztom_Field_Image )
						echo wp_get_attachment_image( $meta, array( 100, 100 ) );
					else
						echo $meta;
				}

				break;
			}
		}
	}

	/**
	 * Used to make all columns sortable
	 * 
	 * @param 	array 			$columns
	 * @return  array
	 *
	 * @author  Gijs Jorissen
	 * @since   1.4.8
	 * 
	 */
	function add_sortable_column( $columns )
	{
		foreach( $this->fields as $id_name => $field )
			if( $field->admin_column_sortable ) $columns[$id_name] = $field->label;

		return $columns;
	}
}