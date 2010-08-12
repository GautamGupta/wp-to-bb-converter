<?php
/**
 * WordPress to bbPress Converter Script (W2bC)
 *
 * Requires WordPress 3.0 (or above) and bbPress 1.1 (or above)
 * 
 * @author Gautam <admin@gaut.am>
 * @version 0.1-beta1
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt GPL 2.0
 * 
 * @todo Category -> Forum (Preserving hierarchy) (#1)
 * @todo Migration of users and usermeta - Currenly requires WP and bb users table to be integrated (#2)
 * @todo Convert WP Images Shortcodes to Image URLs (#3)
 */

/*
 * You must edit the below 2 options to suitable values. Here is an example:
 * Suppose your WordPress directory (which contains wp-load.php) is one directory below this script.
 * Then you would edit:
 *
 * define( 'W2BC_WP_PATH', '' );
 *
 * to:
 *
 * define( 'W2BC_WP_PATH', '../' );
 *
 * If you have wp-load.php in the same directory as you installed the script, then you leave it blank.
 * If you enter a directory structure, then you must also include a trailing slash (/).
 *
 * 3rd value is an option to convert pingbacks or not. Don't put apostrophe (') before or after true/false.
 */

/* Required */
define( 'W2BC_WP_PATH', '' ); /** With Trailing Slash, if required */
define( 'W2BC_BB_PATH', 'forum/' ); /** With Trailing Slash, if required */

/* Optional */
/**
 * Convert from a particular date. Format should be yearmodahomise (year month
 * day hour minute second). Eg. 20101007132320 (Year 2010, 10 month (October),
 * 07 day, 13 hours (1 PM), 23 minutes and 20 seconds or 2010-10-07 13:23:20).
 * You can exclude parts from the last (eg. it can be year month day but not
 * month day hour or month hour second). Make this to false if not needed. The
 * post date you set is also included (ie. >= (greater than or equal to)
 * comparison is used). This should not be GMT time, but the timezone of your
 * blog (set in WP settings). Currently, there maybe some problems if you
 * supply minutes and seconds, so better don't.
 */
define( 'W2BC_CONVERT_FROM_TIME', false );
/** Convert pingacks too? true or false */
define( 'W2BC_CONVERT_PINGBACKS', true );

/******************************************************************************
 ***************************** Stop Editing Here!! ****************************
 *****************************************************************************/

/* For Developers */
define( 'W2BC_VER', '0.1-beta1' );		/** Version */
define( 'W2BC_DEBUG', false );			/** Debug? */
define( 'W2BC_DISABLE_SCRIPT', false );		/** Disable script? Lets you disable any functionality from this script without the need of delete this. */
define( 'SAVEQUERIES', true );			/** Save DB Queries */
define( 'W2BC_MEMORY_LIMIT', '256M' );		/** Memory limit - a blog with around 800 posts requires 128M on localhost */
define( 'W2BC_ALLOW_SYNC', false );		/** Show Sync options? Use the W2BC_CONVERT_FROM_TIME contsant above to set time */
if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC != true ) /** There may be plugins active that may cause fatal errors, as most plugins use deprecated stuff */
	define( 'BB_LOAD_DEPRECATED', false );	/** Don't load deprecated stuff */

/******************************************************************************
 ************************* Really Stop Editing Here!! *************************
 *****************************************************************************/

// -> Developers Continue :P

/** Die if W2BC_DISABLE_SCRIPT is set to true */
if ( defined( 'W2BC_DISABLE_SCRIPT' ) && W2BC_DISABLE_SCRIPT == true )
	die( 'The script has been disabled. Please edit this script and set W2BC_DISABLE_SCRIPT constant to true.' );

set_time_limit( 0 ); /** Set time limit to 0 to avoid time out errors */

/** Increase memory limit to avoid memory exhausted errors */
if ( (int) @ini_get( 'memory_limit' ) < abs( intval( W2BC_MEMORY_LIMIT ) ) )
	@ini_set( 'memory_limit', W2BC_MEMORY_LIMIT );

/** Load WordPress Bootstrap */
if ( file_exists( W2BC_WP_PATH . 'wp-load.php' ) )
	require_once( W2BC_WP_PATH . 'wp-load.php' );
else
	die( 'WordPress loader file (wp-load.php) doesn\'t exist in the path specified!' );

/** Check if the user is logged in as an administrator, else redirect to login page */
if ( !is_user_logged_in() || !current_user_can( 'administrator' ) ) {
	auth_redirect();
	exit();
}

/** Load bb Environment */
if ( file_exists( W2BC_BB_PATH . 'bb-load.php' ) )
	require_once( W2BC_BB_PATH . 'bb-load.php' );
else
	die( 'bbPress loader file (bb-load.php) doesn\'t exist in the path specified!' );

if ( file_exists( W2BC_BB_PATH . 'bb-admin/includes/functions.bb-admin.php' ) )
	require_once( W2BC_BB_PATH . 'bb-admin/includes/functions.bb-admin.php' ); /** Required for categories -> forums */
else
	die( 'bbPress admin functions file (bb-admin/includes/functions.bb-admin.php) doesn\'t exist in the path specified!' );

/** Some functions before we start */

/**
 * Echoes out title, stylesheet and some HTML
 *
 * @return void
 */
function w2bc_after_title() {
	global $title;
	
	$title .= ' &raquo; W2bC</title>
		<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.8.1/build/reset-fonts-grids/reset-fonts-grids.css">
		<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.8.1/build/base/base-min.css">
		<style>#hd{text-align:center;}p.small{font-size:11px;margin-top:20px;text-align:center;}</style>

	</head>
	
	<body id="doc4">
		
		<div id="hd">
			<h2>' . $title . '</h2>
		</div>
		
		<div id="bd">
		';
	
	echo $title;
}

/**
 * Custom insert post function so that we could do what we need
 *
 * All counting functions have been removed from here, recount should be done
 * after running this script.
 *
 * @param mixed $args
 *
 * @return int|bool New post ID if post was created, otherwise false
 */
function w2bc_insert_post( $args = null ) {
	global $bbdb, $bb_current_user, $bb;

	if ( !$args = wp_parse_args( $args ) )
		return false;
	
	$fields = array_keys( $args );
	
	$defaults = array(
		'topic_id' => 0,
		'post_text' => '',
		'post_time' => bb_current_time( 'mysql' ),
		'poster_id' => bb_get_current_user_info( 'id' ), // accepts ids or names
		'poster_ip' => $_SERVER['REMOTE_ADDR'],
		'post_status' => 0, // use bb_delete_post() instead
		'post_position' => false
	);

	// Insert all args
	$fields = array_keys( $defaults );
	$fields[] = 'forum_id';
	
	extract( wp_parse_args( $args, $defaults ) );

	if ( !$topic = get_topic( $topic_id ) )
		return false;

	$topic_id = (int) $topic->topic_id;
	$forum_id = (int) $topic->forum_id;

	if ( false === $post_position )
		$post_position = $topic_posts = intval( ( 0 == $post_status ) ? $topic->topic_posts + 1 : $topic->topic_posts );

	$bbdb->insert( $bbdb->posts, compact( $fields ) );
	$post_id = $topic_last_post_id = (int) $bbdb->insert_id;

	// if user not logged in, save user data as meta data
	if ( !$user ) {
		if ( $post_author )	bb_update_meta( $post_id, 'post_author', $post_author, 'post' ); // Atleast this should be there
		if ( $post_email )	bb_update_meta( $post_id, 'post_email', $post_email, 'post' );
		if ( $post_url )	bb_update_meta( $post_id, 'post_url', $post_url, 'post' );
	}
	
	$topic_time = $post_time;
	$topic_last_poster = ( ! bb_is_user_logged_in() && ! bb_is_login_required() ) ? -1 : $poster_id;
	$topic_last_poster_name = ( ! bb_is_user_logged_in() && ! bb_is_login_required() ) ? $post_author : $user->user_login;
	
	$bbdb->update(
		$bbdb->topics,
		compact( 'topic_time', 'topic_last_poster', 'topic_last_poster_name', 'topic_last_post_id', 'topic_posts' ),
		compact ( 'topic_id' )
	);

	wp_cache_delete( $topic_id, 'bb_topic' );
	wp_cache_delete( $topic_id, 'bb_thread' );
	wp_cache_delete( $forum_id, 'bb_forum' );
	wp_cache_flush( 'bb_forums' );
	wp_cache_flush( 'bb_query' );
	wp_cache_flush( 'bb_cache_posts_post_ids' );

	if ( bb_get_option( 'enable_pingback' ) ) {
		bb_update_postmeta( $post_id, 'pingback_queued', '' );
		wp_schedule_single_event( time(), 'do_pingbacks' );
	}

	return $post_id;
}

/**
 * Retrieve a list of comments.
 *
 * The comment list can be for the blog as a whole or for an individual post.
 *
 * The list of comment arguments are 'status', 'orderby', 'comment_date_gmt',
 * 'order', 'number', 'offset', and 'post_id'.
 *
 * @since 0.1-alpha3
 * @global $wpdb
 *
 * @param mixed $args Optional. Array or string of options to override defaults.
 * @return array List of comments.
 */
function w2bc_get_comments( $args = '' ) {
	global $wpdb;

	$defaults = array(
		'author_email'	=> '',
		'ID'		=> '',
		'karma'		=> '',
		'number'	=> '',
		'offset'	=> '',
		'orderby'	=> '',
		'order'		=> 'ASC',
		'parent'	=> '',
		'post_ID'	=> '',
		'post_id'	=> 0,
		'status'	=> '',
		'type'		=> '',
		'user_id'	=> '',
		'comment_date'	=> '',
		'not_of_posts'	=> ''
	);

	$args = wp_parse_args( $args, $defaults );
	extract( $args, EXTR_SKIP );

	$post_id = absint($post_id);

	if ( 'hold' == $status )
		$approved = "comment_approved = '0'";
	elseif ( 'approve' == $status )
		$approved = "comment_approved = '1'";
	elseif ( 'spam' == $status )
		$approved = "comment_approved = 'spam'";
	elseif ( 'trash' == $status )
		$approved = "comment_approved = 'trash'";
	else
		$approved = "( comment_approved = '0' OR comment_approved = '1' )";

	$order = ( 'ASC' == $order ) ? 'ASC' : 'DESC';

	if ( ! empty( $orderby ) ) {
		$ordersby = is_array($orderby) ? $orderby : preg_split('/[,\s]/', $orderby);
		$ordersby = array_intersect(
			$ordersby,
			array(
				'comment_agent',
				'comment_approved',
				'comment_author',
				'comment_author_email',
				'comment_author_IP',
				'comment_author_url',
				'comment_content',
				'comment_date',
				'comment_date_gmt',
				'comment_ID',
				'comment_karma',
				'comment_parent',
				'comment_post_ID',
				'comment_type',
				'user_id',
			)
		);
		$orderby = empty( $ordersby ) ? 'comment_date_gmt' : implode( ', ', $ordersby );
	} else {
		$orderby = 'comment_date_gmt';
	}

	$number = absint( $number );
	$offset = absint( $offset );

	if ( !empty( $number ) )
		if ( $offset )
			$number = 'LIMIT ' . $offset . ',' . $number;
		else
			$number = 'LIMIT ' . $number;
	else
		$number = '';

	$post_where = '';
	
	if ( $comment_date ) {
		$post_where .= "YEAR(comment_date)>=" . substr( $comment_date, 0, 4 ) . " AND ";
		if ( strlen( $comment_date ) > 5 )
			$post_where .= "MONTH(comment_date)>=" . substr( $comment_date, 4, 2 ) . " AND ";
		if ( strlen( $comment_date ) > 7 )
			$post_where .= "DAYOFMONTH(comment_date)>=" . substr( $comment_date, 6, 2 ) . " AND ";
		if ( strlen( $comment_date ) > 9 )
			$post_where .= "HOUR(comment_date)>=" . substr( $comment_date, 8, 2 ) . " AND ";
		if ( strlen( $comment_date ) > 11 )
			$post_where .= "MINUTE(comment_date)>=" . substr( $comment_date, 10, 2 ) . " AND ";
		if ( strlen( $comment_date ) > 13 )
			$post_where .= "SECOND(comment_date)>=" . substr( $comment_date, 12, 2 ) . " AND ";
	}
	
	if ( $not_of_posts )
		$post_where .= 'comment_post_ID NOT IN(' . join( ',', $not_of_posts ) . ') AND ';

	if ( ! empty($post_id) )
		$post_where .= $wpdb->prepare( 'comment_post_ID = %d AND ', $post_id );
	if ( '' !== $author_email )
		$post_where .= $wpdb->prepare( 'comment_author_email = %s AND ', $author_email );
	if ( '' !== $karma )
		$post_where .= $wpdb->prepare( 'comment_karma = %d AND ', $karma );
	if ( 'comment' == $type )
		$post_where .= "comment_type = '' AND ";
	elseif ( ! empty( $type ) )
		$post_where .= $wpdb->prepare( 'comment_type = %s AND ', $type );
	if ( '' !== $parent )
		$post_where .= $wpdb->prepare( 'comment_parent = %d AND ', $parent );
	if ( '' !== $user_id )
		$post_where .= $wpdb->prepare( 'user_id = %d AND ', $user_id );
	
	$sql = "SELECT * FROM $wpdb->comments WHERE $post_where $approved ORDER BY $orderby $order $number";
	//echo $sql . "<br /><hr>";
	
	$comments = $wpdb->get_results( $sql );
	wp_cache_add( $cache_key, $comments, 'comment' );

	return $comments;
}

/**
 * Changes = compare to >= for post time
 *
 * Only performs when W2BC_CONVERT_FROM_TIME is defined and it isn't set to false
 */
function w2bc_change_posttime_compare( $where = '' ) {
	if ( !defined( 'W2BC_CONVERT_FROM_TIME' ) || W2BC_CONVERT_FROM_TIME == false || strpos( $where, 'post_date)=' ) === false )
		return $where;
	
	return str_replace( 'post_date)=', 'post_date)>=', $where );
	
}
add_filter( 'posts_where_paged', 'w2bc_change_posttime_compare', -10, 1 );

/** Get on with our work */

$mode = $_GET['mode']; /** Get the page */

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>
<?php

switch ( $mode ) { /** Load the page according to mode */
	case 'topics':
		$title = 'Converting Posts to Topics';
		w2bc_after_title();
		
		echo "<ol>\n";
		
		$postargs = array( 'numberposts' => '-1', 'order' => 'ASC' );
		if ( defined( 'W2BC_CONVERT_FROM_TIME' ) && W2BC_CONVERT_FROM_TIME !== false ) {
			$postargs['m'] = W2BC_CONVERT_FROM_TIME;
			$postargs['suppress_filters'] = false;
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Posts from date/time " . W2BC_CONVERT_FROM_TIME . " will only be converted.</li>\n";
		}
		
		if ( !$posts = get_posts( $postargs ) ) {
			echo "<li><strong>No posts were found!</strong></li></ol>\n";
			break;
		}
		//$bbtopics = new BB_Query( 'topic', array( 'post_status' => '0', 'per_page' => '-1', 'topic_status' => '0' ) );
		//echo '<pre>'; print_r( $posts ); echo '</pre><hr>';
		
		if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
			echo "<li>Total number of posts - " . count( $posts ) . "</li>\n";
		
		if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true && $all_mem_size = @ini_get( 'memory_limit' ) )
			echo "<li>Allocated memory is $all_mem_size</li>\n";
		
		if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true )
			$notposts = array();
		
		foreach ( (array) $posts as $post ) {
			echo "<li>Processing post #$post->ID (<a href=\"$post->guid\">$post->post_title</a>)\n<ul>\n";
			
			if ( defined( 'W2BC_CONVERT_FROM_TIME' ) && W2BC_CONVERT_FROM_TIME !== false && defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Post date/time is $post->post_date (GMT - $post->post_date_gmt)</li>\n";
			
			/* Category <-> Forum */
			$cats = get_the_category( $post->ID );
			$cat = $cats[0];
			//echo '<pre>'; print_r( $cat ); echo '</pre><hr>';
			if ( !$forum = bb_get_forum( bb_slug_sanitize( $cat->name ) ) ){
				if ( $forum_id = bb_new_forum( array( 'forum_name' => $cat->name, 'forum_desc' => $cat->description ) ) ) {
					echo "<li>Added category #$cat->term_id ($cat->name) as forum #$new_id</li>\n";
				} else {
					echo "<li><em>There was a problem in adding category #$cat->term_id ($cat->name) as a forum and thus post #$post->ID couldn't be added as a topic.</em></li>\n";
					continue;
				}
			} else {
				$forum_id = $forum->forum_id;
			}
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Topic's forum is <a href=\"" . bb_get_uri( 'forum.php', array( 'id' => $forum_id ) ) . "\">#$forum_id</a> ($cat->name)</li>\n";
			
			/* Post Tag <-> Topic Tag */
			$tags = '';
			if ( $posttags = get_the_tags() )
				foreach( $posttags as $tag )
					if ( $tag != end( $posttags ) )
						$tags .= $tag->name . ', ';
					else
						$tags .= $tag->name;
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				if ( $tags )
					echo "<li>Tags for topic are <em>$tags</em></li>\n";
				else
					echo "<li>There are no tags for this topic</li>\n";
			
			/* Are comments/replies open */
			$open = $post->comment_status == 'closed' ? '0' : '1';
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				if ( $open == '0' )
					echo "<li>Topic is closed to new replies</li>\n";
				else
					echo "<li>Topic is open to new replies</li>\n";
			
			/* Finally add the topic */
			if ( $new_topic_id = bb_new_topic( $post->post_title, $forum_id, $tags, array( 'topic_poster' => $post->post_author, 'topic_last_poster' => $post->post_author, 'topic_start_time' => $post->post_date_gmt, 'topic_time' => $post->post_date_gmt, 'topic_open' => $open ) ) ) {
				echo "<li>Added the post as topic <a href=\"" . bb_get_uri( 'topic.php', array( 'id' => $new_topic_id ) ) . "\">#$new_topic_id</a></li>\n";
				if ( $new_post_id = w2bc_insert_post( array( 'post_text' => stripslashes( $post->post_content ), 'topic_id' => $new_topic_id, 'poster_id' => $post->post_author, 'post_time' => $post->post_date_gmt, 'post_position' => 1 ) ) )
					echo "<li>Added first reply - #$new_post_id</li>\n";
				else
					echo "<li>There was a problem adding first reply</li>\n";
				
				$comments = get_comments( array( 'post_id' => $post->ID, 'status' => 'approve', 'order' => 'ASC' ) );
				//echo '<pre>'; print_r( $comments ); echo '</pre><hr>';
				$position = 2;
				
				/* Add Comments as Posts */
				foreach ( (array) $comments as $comment ) {
					//echo '<pre>'; print_r( $comment ); echo '</pre><hr>';
					
					/* We don't need blank comments */
					if ( !$comment->comment_content = stripslashes( $comment->comment_content ) )
						continue;
					
					/* Comments shouldn't be posted before original post */
					if ( strtotime( $comment->comment_date_gmt ) < strtotime( $post->post_date_gmt ) )
						$comment->comment_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->post_date_gmt ) +  10 );
					
					if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true )
						$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $new_topic_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position, 'poster_id' => false );
					elseif ( $comment->comment_type == 'pingback' && ( !defined( 'W2BC_CONVERT_PINGBACKS' ) || W2BC_CONVERT_PINGBACKS != true ) )
						continue;
					elseif ( $comment->user_id )
						$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $new_topic_id, 'poster_id' => $comment->user_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
					else
						if ( $user = get_user_by_email( $comment->comment_author_email ) )
							$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $new_topic_id, 'poster_id' => $user->ID, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
						else
							$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $new_topic_id, 'poster_id' => false, 'post_time' => $comment->comment_date_gmt, 'post_author' => $comment->comment_author, 'post_email' => $comment->comment_author_email, 'post_url' => $comment->comment_author_url, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
					
					//echo '<pre>'; print_r( $post_data ); echo '</pre><hr>';
					
					if ( $new_post_id = w2bc_insert_post( $post_data ) ) {
						echo "<li>Added another reply - #$new_post_id\n";
						
						if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true ) {
							bb_update_postmeta( $new_post_id, 'pingback_uri', $comment->comment_author_url );
							bb_update_postmeta( $new_post_id, 'pingback_title', $comment->comment_author );
							if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
								echo "<li>This post is a pingback</li>\n";
						}
						
						if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
							echo "<li>Reply's position is $position</li>\n";
						echo "</li>\n";
						$position++;
						
						if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true && is_array( $notposts ) )
							$notposts[] = $post->ID;
					} else {
						echo "<li>There was a problem adding another reply</li>\n";
					}
				}
				
				//bb_update_post_positions( $new_topic_id );
				//bb_topic_set_last_post( $new_topic_id );
				
			} else {
				echo "<li>There was a problem adding the post.</li>\n";
			}
			
			//unset( $post, $cats, $cat, $forum, $forum_id, $tags, $posttags, $tag, $open, $new_topic_id, $comments, $comment, $post_data, $user, $position, $new_post_id );
			
			echo "</ul>\n</li>\n";
			
		}
		
		if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true )
			echo "<li>The post date for last post was $post->post_date (" . (string) abs( intval( str_replace( array( '-', ':', ' ' ), '', $post->post_date ) ) ) . ").</li>";
		
		echo "</ol>\n\n";
		
		if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true && is_array( $notposts ) ) {
			$notposts = join( ',', $notposts );
			echo "<p><strong>Proceed to <a href=\"w2bc.php?mode=replies&notposts=$notposts\">convert comments to replies</a>.</strong></p>";
		}
		break;
	
	case 'replies':
		if ( !defined( 'W2BC_ALLOW_SYNC' ) || W2BC_ALLOW_SYNC != true )
			die( 'Syncing is not enabled, and comments are converted while converting posts. If you want to sync, then set W2BC_ALLOW_SYNC to true in the script.' );
		
		$title = 'Converting Comments to Replies';
		w2bc_after_title();
		
		echo "<ol>\n";
		
		$commentargs = array( 'status' => 'approve', 'order' => 'ASC' );
		if ( defined( 'W2BC_CONVERT_FROM_TIME' ) && W2BC_CONVERT_FROM_TIME !== false ) {
			$commentargs['comment_date'] = W2BC_CONVERT_FROM_TIME;
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Comments from date/time " . W2BC_CONVERT_FROM_TIME . " will only be converted.</li>\n";
		}
		
		if ( isset( $_GET['notposts'] ) && $_GET['notposts'] != null ) {
			$commentargs['not_of_posts'] = array_filter( array_map( 'intval', explode( ',', stripslashes( $_GET['notposts'] ) ) ) );
			if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Comments from post IDs " . join( ', ', $commentargs['not_of_posts'] ) . " will not be converted.</li>\n";
		}
		
		if ( !$comments = w2bc_get_comments( $commentargs ) ) {
			echo "<li><strong>No comments were found (which match the criteria)</strong></li></ol>\n";
			break;
		}
		//echo '<pre>'; print_r( $comments ); echo '</pre><hr>';
		
		if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
			echo "<li>Total number of comments - " . count( $comments ) . "</li>\n";
		
		if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true && $all_mem_size = @ini_get( 'memory_limit' ) )
			echo "<li>Allocated memory is $all_mem_size</li>\n";
		
		/* Add Comments as Posts */
		foreach ( (array) $comments as $comment ) {
			//echo '<pre>'; print_r( $comment ); echo '</pre><hr>';
			
			/* We don't need blank comments */
			if ( !$comment->comment_content = stripslashes( $comment->comment_content ) )
				continue;
			
			if ( !$post = get_post( $comment->comment_post_ID ) )
				continue;
			//echo '<pre>'; print_r( $post ); echo '</pre><hr>';
			
			$slug = bb_slug_sanitize( wp_specialchars_decode( $post->post_title, ENT_QUOTES ) );
			if ( !$topic = get_topic( $slug ) )
				continue;
			//echo $slug . '<br /><pre>'; print_r( $topic ); echo '</pre><hr>';
			
			echo "<li>Processing comment #$comment->comment_ID of post #$post->ID ($post->post_title) which will go into topic #$topic->topic_id\n<ul>\n";
			
			if ( defined( 'W2BC_CONVERT_FROM_TIME' ) && W2BC_CONVERT_FROM_TIME !== false && defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
				echo "<li>Comment date/time is $comment->comment_date (GMT - $comment->comment_date_gmt)</li>\n";
			
			/* Comments shouldn't be posted before original post */
			if ( strtotime( $comment->comment_date_gmt ) < strtotime( $post->post_date_gmt ) )
				$comment->comment_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->post_date_gmt ) +  10 );
			
			$position = $topic->topic_posts ? $topic->topic_posts + 1 : 2;
			
			if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true )
				$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $topic->topic_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position, 'poster_id' => false );
			elseif ( $comment->comment_type == 'pingback' && ( !defined( 'W2BC_CONVERT_PINGBACKS' ) || W2BC_CONVERT_PINGBACKS != true ) )
				continue;
			elseif ( $comment->user_id )
				$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $topic->topic_id, 'poster_id' => $comment->user_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
			else
				if ( $user = get_user_by_email( $comment->comment_author_email ) )
					$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $topic->topic_id, 'poster_id' => $user->ID, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
				else
					$post_data = array( 'post_text' => $comment->comment_content, 'topic_id' => $topic->topic_id, 'poster_id' => false, 'post_time' => $comment->comment_date_gmt, 'post_author' => $comment->comment_author, 'post_email' => $comment->comment_author_email, 'post_url' => $comment->comment_author_url, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
			
			//echo '<pre>'; print_r( $post_data ); echo '</pre><hr>';
			
			if ( $new_post_id = w2bc_insert_post( $post_data ) ) {
				echo "<li>Added another reply - #$new_post_id\n";
				
				if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true ) {
					bb_update_postmeta( $new_post_id, 'pingback_uri', $comment->comment_author_url );
					bb_update_postmeta( $new_post_id, 'pingback_title', $comment->comment_author );
					if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
						echo "<li>This post is a pingback</li>\n";
				}
				
				if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
					echo "<li>Reply's position is $position</li>\n";
				echo "</li>\n";
				$position++;
			} else {
				echo "<li>There was a problem adding another reply</li>\n";
			}
			
			echo "</ul>\n</li>\n";
			
		}
		echo "</ol>\n\n";
		
		break;
	
	case 'start':
	default:
		$title = 'WordPress to bbPress Converter! - Instructions';
		w2bc_after_title();
		
		echo '<ul>';
			if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true ) {
				echo '<li>Before Syncing:<ul>';
					echo '<li>Make sure that you\'ve created a <a href="http://codex.wordpress.org/WordPress_Backups" title="Creating Backups">backup</a> of your database and files just in case something went wrong.</li>';
					echo '<li>A recount (by going to admin section -> tools, check all options and press recount button) is <em>highly</em> recommended.</li>';
					echo '<li>You may leave the plugins active but you must note that they may <em>still</em> interfere in the process.</li>';
					echo '<li>This converter is heavy and consumes too much CPU, so it is suggested that you do the process on your local machine (if you are not in a hurry).</li>';
				echo '</ul></li>';
			} else {
				echo '<li>Before Converting:<ul>';
					echo '<li>Make sure that you\'ve created a <a href="http://codex.wordpress.org/WordPress_Backups" title="Creating Backups">backup</a> of your database and files just in case something went wrong.</li>';
					echo '<li>Your forum atleast has a single forum and a topic (you may delete that later).</li>';
					echo '<li>Deactivate each and every plugin and switch to the default theme (on WordPress and bbPress both). You may just let bbPress Integration WordPress plugin remain activated if that\'s installed. You might also want to install <a href="http://bbpress.org/plugins/topic/admin-can-post-anything/">Admin Can Post Anything plugin</a> and <a href="http://bbpress.org/plugins/topic/allow-images/">Allow Images</a> as bbPress only allows some HTML to be posted.</li>';
					echo '<li>This converter is heavy and consumes too much CPU, so it is suggested that you do the process on your local machine.</li>';
				echo '</ul></li>';
			}
			
			echo '<li>After Converting:<ul>';
				echo '<li>Do a recount (by going to admin section -> tools, check all options and press recount button) to get the posts/topics/etc counts in sync.</li>';
				echo '<li>Delete this script if not needed or set W2BC_DISABLE_SCRIPT constant to true in the script.</li>';
			echo '</ul></li>';
			
			echo '<li>If every thing is OK, proceed to <a href="w2bc.php?mode=topics">converting posts to topics</a> (their categories to forums, tags, and comments to replies)!';
				if ( defined( 'W2BC_ALLOW_SYNC' ) && W2BC_ALLOW_SYNC == true )
					echo ' Or you may straight away go to <a href="w2bc.php?mode=replies">syncing comments to replies</a>.';
				echo '</li>';
			
		echo '</ul>';
		
		break;

}

?>
			<p class="small"><?php
				printf(
				'This page generated in %s seconds, using %d/%d queries (WP/bb). Memory - %s MB used out of allocated %s MB.',
				bb_timer_stop(),
				bb_number_format_i18n( $wpdb->num_queries ),
				bb_number_format_i18n( $bbdb->num_queries ),
				round( memory_get_peak_usage( true ) / ( 1024 * 1024 ), 2 ),
				abs( intval( @ini_get( 'memory_limit' ) ) )
				);
				?>
				WP to bb Converter (W2bC) made by <a href="http://gaut.am/">Gautam</a> (<a href="http://twitter.com/_GautamGupta_">twitter</a>) - ver. <?php echo W2BC_VER; ?>.
			</p>
		</div>

	</body>

</html>