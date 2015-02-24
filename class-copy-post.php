<?php

Writing_Helper()->add_helper( 'copy_post', new Writer_Helper_Copy_Post() );

class Writer_Helper_Copy_Post {

	function init() {

		add_action( 'wp_ajax_helper_search_posts', array( $this, 'add_ajax_search_posts_endpoint' ) );
		add_action( 'wp_ajax_helper_get_post', array( $this, 'add_ajax_get_post_endpoint' ) );
		add_action( 'wp_ajax_helper_stick_post', array( $this, 'add_ajax_stick_post_endpoint' ) );
		add_action( 'wp_ajax_helper_record_stat', array( $this, 'add_ajax_record_stat_endpoint' ) );

		// Add "Copy a Post" to the Posts menu.
		add_action( 'admin_menu', array( $this, 'add_submenu_page' ) );
	}

	/**
	 * Add submenu links for each supported post type
	 */
	function add_submenu_page() {
		$post_types = get_post_types();
		foreach( $post_types as $post_type ) {
			if( post_type_supports( $post_type, 'writing-helper' ) ) {
				$post_type_obj = get_post_type_object( $post_type );

				$submenu_page = 'edit.php';
				if ( 'post' != $post_type ) {
					$submenu_page .= '?post_type=' . $post_type;
				}

				if ( $post_type == 'post' ) {
					$submenu_page_label = __( 'Copy a Post', 'writing-helper' );
				} else if ( $post_type == 'page' ) {
					$submenu_page_label = __( 'Copy a Page', 'writing-helper' );
				} else {
					$submenu_page_label = sprintf(
						_x( 'Copy a %s', 'Copy a {post_type}', 'writing-helper' ),
						$post_type_obj->labels->singular_name
					);
				}

				$submenu_page_link = add_query_arg( 'cap#cap', '', str_replace( 'edit.php', '/post-new.php', $submenu_page ) );

				add_submenu_page( $submenu_page, $submenu_page_label, $submenu_page_label, $post_type_obj->cap->edit_posts, $submenu_page_link );
			}
		}
	}

	function add_ajax_search_posts_endpoint() {
		check_ajax_referer( 'writing_helper_nonce_' . get_current_blog_id(), 'nonce' );

		if ( ! is_user_member_of_blog() ) {
			exit;
		}

		$_REQUEST = stripslashes_deep( $_REQUEST );
		$search_terms = trim( $_REQUEST['search'] );

		$post_type = ! empty( $_REQUEST['post_type'] ) ?
			sanitize_key( $_REQUEST['post_type'] ) : 'post';

		Writing_Helper::json_return( self::get_candidate_posts( $post_type, $search_terms ) );
	}

	function get_candidate_posts( $post_type = 'post', $search_terms = '', $sticky = false ) {
		global $wpdb;

		// Constructing a query that will find returned posts
		$query_string =
			"SELECT ID FROM {$wpdb->posts} "
			. "WHERE post_type = %s AND post_status <> %s ";
		$arguments = array( $post_type, 'auto-draft' );

		if ( ! current_user_can( 'edit_others_posts' ) ) {

			// Limiting the author's copying capabilities to own posts and public posts
			$query_string .= "AND ( post_author = %d OR post_status = 'publish' ) ";

			array_push( $arguments, get_current_user_id() );
		}

		$sticky_posts = get_option( 'copy_a_post_sticky_posts' );
		$limit = 20;
		if ( $sticky ) {

			// Including only sticky posts as required
			$query_string .=
				"AND ID IN ( "
				. implode( ',', (array) $sticky_posts )
				. " ) ";

			$limit = 3;

		} elseif ( empty( $search_terms ) ) {

			// Excluding sticky posts from results because they will be shown separately
			$query_string .=
				"AND ID NOT IN ( "
				. implode( ',', (array) $sticky_posts )
				. " ) ";
		} else {
			$query_string .= "AND ( post_title LIKE %s OR post_content LIKE %s ) ";
			array_push( $arguments, "%" . like_escape( $search_terms ) . "%" );

			// Duplicating the last entry
			array_push( $arguments, end( $arguments ) );

			do_action( 'wh_copypost_searched_posts' );
		}
		$query_string .= "ORDER BY post_date DESC LIMIT $limit";

		// Extracting post IDs
		$post_ids = wp_list_pluck(
			$wpdb->get_results( $wpdb->prepare( $query_string, $arguments) ),
			'ID'
		);

		return get_posts( array( 'post__in' => $post_ids ) );
	}

	function add_ajax_get_post_endpoint() {
		global $wpdb, $current_blog;

		check_ajax_referer( 'writing_helper_nonce_' . get_current_blog_id(), 'nonce' );

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			exit;
		}

		$_REQUEST = stripslashes_deep( $_REQUEST );
		$post_id = (int) $_REQUEST['post_id'];

		if ( empty( $post_id ) )
			die( '-1' );

		$post = get_post( $post_id );

		if ( 'post' == $post->post_type ) {
			$post->post_tags = implode( ', ', (array) $wpdb->get_col( $wpdb->prepare( "SELECT slug FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id INNER JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy IN ( 'post_tag' ) AND tr.object_id = %d", $post_id ) ) );
			$post->post_categories = get_the_category( $post_id );
		}

		do_action( 'wh_copypost_copied_post', $post );

		Writing_Helper::json_return( $post );
	}

	function add_ajax_stick_post_endpoint() {
		check_ajax_referer( 'writing_helper_nonce_' . get_current_blog_id(), 'nonce' );

		if ( ! is_user_member_of_blog() ) {
			exit;
		}

		$_REQUEST = stripslashes_deep( $_REQUEST );
		$post_id = (int) $_REQUEST['post_id'];

		if ( empty( $post_id ) )
			die( '-1' );

		// Get sticky posts for the blog.
		$sticky_posts = (array) get_option( 'copy_a_post_sticky_posts' );

		$existing = array_search( $post_id, $sticky_posts );
		if ( false !== $existing ) {
			unset( $sticky_posts[$existing] );
		} else if ( count( $sticky_posts ) > 2 ) {
			array_pop( $sticky_posts );
		}

		array_unshift( $sticky_posts, $post_id );
        update_option( 'copy_a_post_sticky_posts', $sticky_posts );

        die( '1' );
    }

	function add_ajax_record_stat_endpoint() {
		$_REQUEST = stripslashes_deep( $_REQUEST );
		$stat = $_REQUEST['stat'];

		if ( empty( $stat ) )
			die( '-1' );

		do_action( 'wh_copypost_ajax_stat', $stat );

		die( '1' );
	}
}
