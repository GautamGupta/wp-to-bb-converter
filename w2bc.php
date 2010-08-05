<?php
/**
 * WordPress to bbPress Converter Script (W2bC)
 * 
 * @author Gautam <admin@gaut.am>
 *
 * @version 0.1-aplha1
 * 
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.txt GPL 2.0
 */

/**
 * @todo Category -> Forum (Preserving hierarchy)
 * @todo Migration of users and usermeta - Currenly requires WP and bb users table to be integrated 
 * @todo Convert WP Images Shortcodes to Image URLs
 */

define( 'W2BC_WP_PATH', '' ); /** With Trailing Slash, if required */
define( 'W2BC_BB_PATH', '../forum/' ); /** With Trailing Slash, if required */
define( 'W2BC_CONVERT_PINGBACKS', true ); /** Convert pingacks too? true or false */

/*******************************************************************************
 ***************************** Stop Editing Here!! *****************************
 ******************************************************************************/

define( 'W2BC_VER', '0.1-alpha1' ); /** Version */
define( 'W2BC_DEBUG', true ); /** Debug? */
define( 'SAVEQUERIES', true ); /** Save DB Queries */
define( 'BB_LOAD_DEPRECATED', false ); /* Don't load deprecated stuff */

set_time_limit( 0 ); /** Set time limit to 0 to avoid time out errors */

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

/** Some functions before we start */

// None for now

/** Load bb Environment */
if ( file_exists( W2BC_BB_PATH . 'bb-load.php' ) )
	require_once( W2BC_BB_PATH . 'bb-load.php' );
else
	die( 'bbPress loader file (bb-load.php) doesn\'t exist in the path specified!' );

if ( file_exists( W2BC_BB_PATH . 'bb-admin/includes/functions.bb-admin.php' ) )
	require_once( W2BC_BB_PATH . 'bb-admin/includes/functions.bb-admin.php' ); /** Required for categories -> forums */
else
	die( 'bbPress admin functions file (bb-admin/includes/functions.bb-admin.php) doesn\'t exist in the path specified!' );

/** Get on with our work */

$mode = $_GET['mode']; /** Get the page */
$mode = ( in_array( $mode, array( 'start', 'main' ) ) ) ? $mode : 'start'; /** Verify */

switch ( $mode ) { /** Load the page according to mode */
	case 'start':
			$title = 'Before You Start Converting!';
			
			$text = '<ul>';
			$text .= '<li>Make sure that you\'ve created a <a href="http://codex.wordpress.org/WordPress_Backups" title="Creating Backups">backup</a> of your database and files just in case something went wrong.</li>';
			$text .= '<li>Deactivate each and every plugin and switch to the default theme (on WordPress and bbPress both). You may just let bbPress Integration WordPress plugin remain activated if that\'s installed.</li>';
			$text .= '<li>This converter is heavy and consumes too much CPU, so it is suggested that you do the process on your local machine.</li>';
			$text .= '<li>After doing the conversion, you should do a recount (by going to admin section -> tools, check all options and press recount button) to get the posts/topics/etc counts in sync.</li>';
			$text .= '<li>You might also want to install <a href="http://bbpress.org/plugins/topic/admin-can-post-anything/">Admin Can Post Anything plugin</a> by <a href="http://bbshowcase.org/forums/">ck</a> after the conversion as bbPress only allows some HTML to be posted.</li>';
			$text .= '<li>If every thing is OK, proceed to <a href="w2bc.php?mode=main">converting</a>!</li>';
			$text .= '</ul>';
		break;

	case 'main':
			$title = 'Converting Posts to Topics';
			$text = "<ol>\n";
			
			$posts = get_posts( array( 'numberposts' => '-1' ) );
			//$bbtopics = new BB_Query( 'topic', array( 'post_status' => '0', 'per_page' => '-1', 'topic_status' => '0' ) );
			//echo '<pre>'; print_r( $posts ); echo '</pre><hr>';
			
			foreach ( $posts as $post ) {
				$text .= "<li>Processing post #$post->ID (<a href=\"$post->guid\">$post->post_title</a>)\n<ul>\n";
				
				/* Category <-> Forum */
				$cats = get_the_category( $post->ID );
				$cat = $cats[0];
				//echo '<pre>'; print_r( $cat ); echo '</pre><hr>';
				if ( !$forum = bb_get_forum( bb_slug_sanitize( $cat->name ) ) ){
					if ( $forum_id = bb_new_forum( array( 'forum_name' => $cat->name, 'forum_desc' => $cat->description ) ) ) {
						$text .= "<li>Added category #$cat->term_id ($cat->name) as forum #$new_id</li>\n";
					} else {
						$text .= "<li><em>There was a problem in adding category #$cat->term_id ($cat->name) as a forum and thus post #$post->ID couldn't be added as a topic.</em></li>\n";
						continue;
					}
				} else {
					$forum_id = $forum->forum_id;
				}
				if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
					$text .= "<li>Topic's forum is #$forum_id ($cat->name)</li>\n";
				
				/* Post Tag <-> Topic Tag */
				$tags = '';
				if ( $posttags = get_the_tags() )
					foreach( $posttags as $tag )
						if ( $tag != end( $posttags ) )
							$tags .= $tag->name . ', ';
						else
							$tags .= $tag->name;
				if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
					$text .= "<li>Tags for topic are <em>$tags</em></li>\n";
				
				/* Are comments/replies open */
				$open = $post->comment_status == 'closed' ? '0' : '1';
				if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
					if ( $open == '0' )
						$text .= "<li>Topic is closed to new replies</li>\n";
					else
						$text .= "<li>Topic is open to new replies</li>\n";
				
				/* Finally add the topic */
				if ( $new_topic_id = bb_new_topic( $post->post_title, $forum_id, $tags, array( 'topic_poster' => $post->post_author, 'topic_last_poster' => $post->post_author, 'topic_start_time' => $post->post_date_gmt, 'topic_time' => $post->post_date_gmt, 'topic_open' => $open ) ) ) {
					$text .= "<li>Added the post as topic #$new_topic_id</li>\n";
					if ( $new_post_id = bb_insert_post( array( 'post_text' => stripslashes( $post->post_content ), 'topic_id' => $new_topic_id, 'poster_id' => $post->post_author, 'post_time' => $post->post_date_gmt, 'post_position' => 1 ) ) )
						$text .= "<li>Added first reply - #$new_post_id</li>\n";
					else
						$text .= "<li>There was a problem adding first reply</li>\n";
					
					$comments = get_comments( array( 'post_id' => $post->ID, 'status' => 'approve' ) );
					//echo '<pre>'; print_r( $comments ); echo '</pre><hr>';
					$position = 2;
					
					foreach ( $comments as $comment ) {
						//echo '<pre>'; print_r( $comment ); echo '</pre><hr>';
						if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true )
							$post_data = array( 'post_text' => stripslashes( $comment->comment_content ), 'topic_id' => $new_topic_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position, 'poster_id' => false );
						elseif ( $comment->comment_type == 'pingback' && ( !defined( 'W2BC_CONVERT_PINGBACKS' ) || W2BC_CONVERT_PINGBACKS != true ) )
							continue;
						elseif ( $comment->user_id )
							$post_data = array( 'post_text' => stripslashes( $comment->comment_content ), 'topic_id' => $new_topic_id, 'poster_id' => $comment->user_id, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
						else
							if ( $user = get_user_by_email( $comment->comment_author_email ) )
								$post_data = array( 'post_text' => stripslashes( $comment->comment_content ), 'topic_id' => $new_topic_id, 'poster_id' => $user->ID, 'post_time' => $comment->comment_date_gmt, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
							else
								$post_data = array( 'post_text' => stripslashes( $comment->comment_content ), 'topic_id' => $new_topic_id, 'poster_id' => false, 'post_time' => $comment->comment_date_gmt, 'post_author' => $comment->comment_author, 'post_email' => $comment->comment_author_email, 'post_url' => $comment->comment_author_url, 'poster_ip' => $comment->comment_author_IP, 'post_position' => $position );
						
						//echo '<pre>'; print_r( $post_data ); echo '</pre><hr>';
						
						if ( $new_post_id = bb_insert_post( $post_data ) ) {
							$text .= "<li>Added another reply - #$new_post_id\n";
							
							if ( $comment->comment_type == 'pingback' && defined( 'W2BC_CONVERT_PINGBACKS' ) && W2BC_CONVERT_PINGBACKS == true ) {
								bb_update_postmeta( $new_post_id, 'pingback_uri', $comment->comment_author_url );
								bb_update_postmeta( $new_post_id, 'pingback_title', $comment->comment_author );
								if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
									$text .= "<li>This post is a pingback</li>\n";
							}
							
							if ( defined( 'W2BC_DEBUG' ) && W2BC_DEBUG == true )
								$text .= "<li>Reply's position is $position</li>\n";
							$text .= "</li>\n";
							$position++;
						} else {
							$text .= "<li>There was a problem adding another reply</li>\n";
						}
					}
					
					//bb_update_post_positions( $new_topic_id );
					bb_topic_set_last_post( $new_topic_id );
					
				} else {
					$text .= "<li>There was a problem adding the post.</li>\n";
				}
				
				//unset( $post, $cats, $cat, $forum, $forum_id, $tags, $posttags, $tag, $open, $new_topic_id, $comments, $comment, $post_data, $user, $position, $new_post_id );
				
				$text .= "</ul>\n</li>\n";
				
			}
			$text .= "</ol>\n\n";
			
		break;

}

add_filter( 'wp_title', 'w2bc_title' );
function w2bc_title( $t = null ) {
	global $title;
	
	return $title . ' &raquo; W2bC';
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title><?php wp_title(); ?></title>
		<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.8.1/build/reset-fonts-grids/reset-fonts-grids.css">
		<link rel="stylesheet" type="text/css" href="http://yui.yahooapis.com/2.8.1/build/base/base-min.css">
		<style>
		#hd{text-align:center;}
		p.small{font-size:11px;margin-top:20px;text-align:center;} /* 12px */
		</style>

	</head>
	
	<body id="doc4">
		
		<div id="hd">
			<h2><?php echo $title; ?></h2>
		</div>
		
		<div id="bd">
			<?php echo $text; ?>
		
			<p class="small"><?php
				printf(
				'This page generated in %s seconds, using %d/%d queries (WP/bb).',
				bb_timer_stop(),
				bb_number_format_i18n( $wpdb->num_queries ),
				bb_number_format_i18n( $bbdb->num_queries )
				);
				?>
				Page Generated by WP to bb Converter (W2bC) made by <a href="http://gaut.am/">Gautam Gupta</a> (<a href="http://twitter.com/_GautamGupta_">twitter</a>) - ver. <?php echo W2BC_VER; ?>.
			</p>
		</div>

	</body>

</html>