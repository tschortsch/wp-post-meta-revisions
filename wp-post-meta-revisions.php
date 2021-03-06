<?php
/*
 * Plugin Name: Post Meta Revisions
 * Plugin URI: https://github.com/adamsilverstein/wp-post-meta-revisions
 * Description: Post Meta Revisions
 * Version: 0.3.0
 * Author: Adam Silverstein / Juerg Hunziker - code developed with others
 * at https://core.trac.wordpress.org/ticket/20564
 * License: GPLv2 or later
*/

class WP_Post_Meta_Revisioning {

	/**
	 * Set up the plugin actions
	 */
	public function __construct() {

		// Actions
		//
		// When restoring a revision, also restore that revisions's revisioned meta.
		add_action( 'wp_restore_post_revision', array( $this, '_wp_restore_post_revision_meta' ), 10, 2 );

		// When creating or updating an autosave, save any revisioned meta fields.
		add_action( 'wp_creating_autosave', array( $this, '_wp_autosave_post_revisioned_meta_fields' ) );
		add_action( 'wp_before_creating_autosave', array( $this, '_wp_autosave_post_revisioned_meta_fields' ) );

		// When creating a revision, also save any revisioned meta.
		add_action( '_wp_put_post_revision', array( $this, '_wp_save_revisioned_meta_fields' ) );

		//Filters
		// When revisioned post meta has changed, trigger a revision save.
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, '_wp_check_revisioned_meta_fields_have_changed' ), 10, 3 );

		// Filter the diff ui returned for the revisions screen.
		add_filter( 'wp_get_revision_ui_diff', array( $this, '_wp_filter_revision_ui_diff' ), 10, 3 );
	}

	/**
	 * Filter the revisions ui diff, adding revisioned meta fields.
	 *
	 * @param array   $fields       Revision UI fields. Each item is an array of id, name and diff.
	 * @param WP_Post $compare_from The revision post to compare from.
	 * @param WP_Post $compare_to   The revision post to compare to.
	 *
	 * @return array
	 */
	function _wp_filter_revision_ui_diff( $fields, $compare_from, $compare_to ) {
		$post_type = get_post_type( wp_is_post_revision( $compare_from ) );
		foreach ( $this->_wp_post_revision_meta_keys( $post_type ) as $meta_key => $meta_name ) {
			$meta_from = $compare_from ? apply_filters( "_wp_post_revision_field_{$meta_key}", get_post_meta( $compare_from->ID, $meta_key ), $meta_key, $compare_from, 'from' ) : '';
			$meta_to = apply_filters( "_wp_post_revision_field_{$meta_key}", get_post_meta( $compare_to->ID,   $meta_key ), $meta_key, $compare_to, 'to' );

			$args = array( 'show_split_view' => true );
			$args = apply_filters( 'revision_text_diff_options', $args, $meta_key, $compare_from, $compare_to );
			$diff = wp_text_diff( $this->_prepare_meta_values_for_diff( $meta_from ), $this->_prepare_meta_values_for_diff( $meta_to ), $args );
			// Add this meta field if it has a diff.
			if ( ! empty( $diff ) ) {
				$new_field = array(
					'id'   => $meta_key,
					'name' => $meta_name,
					'diff' => $diff
				);

				/**
				 * Filter revisioned meta fields used for the revisions UI.
				 *
				 * The dynamic portion of the hook name, `$meta_key`, refers to
				 * the revisioned meta key.
				 *
				 * @since 4.6.0
				 *
				 * @param object $new_field     Object with id, name and diff for the UI.
				 * @param WP_Post $compare_from The revision post to compare from.
				 * @param WP_Post $compare_to   The revision post to compare to.
				 */
				$fields[] = apply_filters( "revisioned_meta_ui_field_{$meta_key}", $new_field, $compare_from, $compare_to );
			}
		}
		return $fields;
	}

	/**
	 * Prepares meta values for diff.
	 * If multiple values available -> list all of them.
	 *
	 * @param array $values Values array from get_post_meta().
	 * @return string
	 */
	public function _prepare_meta_values_for_diff( $values ) {
		if ( is_array( $values ) ) {
			if ( count( $values ) > 1 ) {
				$item_count = 1;
				$flattened_values = '';
				foreach ( $values as $value ) {
					// if single meta value is still an array
					if ( is_array( $value ) ) {
						foreach ( $value as $element_key => $element_value ) {
							$flattened_values .= "[" . $element_key . " " . $item_count . "]:\n" . $element_value . "\n";
						}
					} else {
						$flattened_values .= "[" . $item_count . "]:\n" . $value . "\n";
					}
					$flattened_values .= "----------\n";
					$item_count++;
				}
				return $flattened_values;
			} else if ( count( $values ) === 1 ) {
				$value = reset( $values );
				// if single meta value is still an array
				if ( is_array( $value ) ) {
					$flattened_values = '';
					foreach ( $value as $element_key => $element_value ) {
						$flattened_values .= "[" . $element_key . "]:\n" . $element_value . "\n";
					}
					return $flattened_values;
				} else {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Add the revisioned meta to get_post_metadata for preview meta data.
	 *
	 * @since 4.5.0
	 */
	public function _add_metadata_preview_filter() {
		add_filter( 'get_post_metadata', array( $this, '_wp_preview_meta_filter'), 10, 4 );
	}

	/**
	 * Autosave the revisioned meta fields.
	 *
	 * Iterates thru the revisioned meta fields and checks each to see if they are set,
	 * and have a changed value. If so, the meta value is saved and attached to the autosave.
	 *
	 * @since 4.5.0
	 *
	 * @param Post object $new_autosave The new post being autosaved.
	 */
	public function _wp_autosave_post_revisioned_meta_fields( $new_autosave ) {
		$post_type = get_post_type( wp_is_post_revision( $new_autosave ) );

		/**
		 * The post data arrives as either $_POST['data']['wp_autosave'] or the $_POST
		 * itself. This sets $posted_data to the correct variable.
		 */
		$posted_data = isset( $_POST['data'] ) ? $_POST['data']['wp_autosave'] : $_POST;
		/**
		 * Go thru the revisioned meta keys and save them as part of the autosave, if
		 * the meta key is part of the posted data, the meta value is not blank and
		 * the the meta value has changes from the last autosaved value.
		 */
		foreach ( array_keys( $this->_wp_post_revision_meta_keys( $post_type ) ) as $meta_key ) {

			if ( isset( $posted_data[ $meta_key ] )
				&& get_post_meta( $new_autosave['ID'], $meta_key, true ) != wp_unslash( $posted_data[ $meta_key ] ) )
			{
				/*
				 * Use the underlying delete_metadata() and add_metadata() functions
				 * vs delete_post_meta() and add_post_meta() to make sure we're working
				 * with the actual revision meta.
				 */
				delete_metadata( 'post', $new_autosave['ID'], $meta_key );
				/**
				 * One last check to ensure meta value not empty().
				 */
				if ( ! empty( $posted_data[ $meta_key ] ) ) {
					/**
					 * Add the revisions meta data to the autosave.
					 */
					add_metadata( 'post', $new_autosave['ID'], $meta_key, $posted_data[ $meta_key ] );
				}
			}
		}
	}

	/**
	 * Determine which post meta fields should be revisioned.
	 *
	 * @access public
	 * @since 4.5.0
	 *
	 * @param string $post_type Type of current post.
	 *
	 * @return array An array of meta keys to be revisioned.
	 */
	public function _wp_post_revision_meta_keys( $post_type = 'post' ) {
		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		/**
		 * Filter the list of post meta keys to be revisioned.
		 *
		 * @since 4.5.0
		 *
		 * @param array $keys An array of default meta fields to be revisioned.
		 */
		return apply_filters( 'wp_post_revision_meta_keys', array(), $post_type );
	}

	/**
	 * *
	 * Check whether revisioned post meta fields have changed.
	 *
	 * @since 4.5.0
	 *
	 * @param bool    $post_has_changed Has post changed.
	 * @param WP_Post $last_revision Last revision of post.
	 * @param WP_Post $post Current post.
	 * @return bool
	 */
	public function _wp_check_revisioned_meta_fields_have_changed( $post_has_changed, WP_Post $last_revision, WP_Post $post ) {
		foreach ( array_keys( $this->_wp_post_revision_meta_keys( get_post_type( $post ) ) ) as $meta_key ) {
			if ( get_post_meta( $post->ID, $meta_key ) != get_post_meta( $last_revision->ID, $meta_key ) ) {
				$post_has_changed = true;
				break;
			}
		}
		return $post_has_changed;
	}

	/**
	 * Save the revisioned meta fields.
	 *
	 * @since 4.5.0
	 *
	 * @param int $revision_id Id of current revision.
	 */
	public function _wp_save_revisioned_meta_fields( $revision_id ) {
		$revision = get_post( $revision_id );
		$post_id  = $revision->post_parent;
		// Save revisioned meta fields.
		foreach ( array_keys( $this->_wp_post_revision_meta_keys( get_post_type( $post_id ) ) ) as $meta_key ) {
			$meta_values = get_post_meta( $post_id, $meta_key );

			/*
			 * Use the underlying add_metadata() function vs add_post_meta()
			 * to ensure metadata is added to the revision post and not its parent.
			 */
			foreach( $meta_values as $meta_value ) {
				add_metadata( 'post', $revision_id, $meta_key, $meta_value );
			}
		}
	}

	/**
	 * Restores meta fields of a revision.
	 *
	 * @param int $post_id Id of post which is restored.
	 * @param int $revision_id Id of revision to restore.
	 *
	 * Restore the revisioned meta values for a post.
	 *
	 * @since 4.5.0
	 */
	public function _wp_restore_post_revision_meta( $post_id, $revision_id ) {
		// Restore revisioned meta fields.
		$metas_revisioned = array_keys( $this->_wp_post_revision_meta_keys( get_post_type( $post_id ) ) );
		if ( isset( $metas_revisioned ) && 0 !== sizeof( $metas_revisioned ) ) {
			foreach ( $metas_revisioned as $meta_key ) {
				// Clear any existing metas
				delete_post_meta( $post_id, $meta_key );
				// Get the stored meta, not stored === blank
				$meta_values = get_post_meta( $revision_id, $meta_key );
				if ( 0 !== sizeof( $meta_values ) && is_array( $meta_values ) ) {
					foreach ( $meta_values as $meta_value ) {
						add_post_meta( $post_id, $meta_key, $meta_value );
					}
				}
			}
		}
	}

	/**
	 * Filters post meta retrieval to get values from the actual autosave post,
	 * and not its parent.
	 *
	 * Filters revisioned meta keys only.
	 *
	 * @access public
	 * @since 4.5.0
	 *
	 * @param mixed  $value     Meta value to filter.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key to filter a value for.
	 * @param bool   $single    Whether to return a single value. Default false.
	 * @return mixed Original meta value if the meta key isn't revisioned, the object doesn't exist,
	 *               the post type is a revision or the post ID doesn't match the object ID.
	 *               Otherwise, the revisioned meta value is returned for the preview.
	 */
	public function _wp_preview_meta_filter( $value, $object_id, $meta_key, $single ) {

		$post = get_post();
		if ( empty( $post )
			|| $post->ID != $object_id
			|| ! in_array( $meta_key, array_keys( $this->_wp_post_revision_meta_keys( get_post_type( $post ) ) ) )
			|| 'revision' == $post->post_type )
		{
			return $value;
		}

		// Grab the autosave.
		$preview = wp_get_post_autosave( $post->ID );
		if ( ! is_object( $preview ) ) {
			return $value;
		}

		return get_post_meta( $preview->ID, $meta_key, $single );
	}
}

$wp_Post_Meta_Revisioning = new WP_Post_Meta_Revisioning;
