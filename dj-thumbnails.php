<?php
/*
Plugin Name: Derk-Jan's Fallback Thumbs
Plugin URI: http://sg.tudelft.nl/
Description: Fallback thumbnail system to display thumbs if not available for a post.
Author: Derk-Jan Karrenbeld
Version: 1.2
Author URI: http://derk-jan.com

Filters: 
	- get_fallback_tree_items	: runs before the fallback tree is created
	- get_required_thumbsize	: runs on the admin page. requires this size
*/
 
if (!class_exists("DerkJan_Thumbnails")) {
	
	define('DERKJAN_THUMBNAILS_VERSION', '1.2');
	define('DERKJAN_THUMBNAILS_DIR', plugin_dir_url( __FILE__ ));

	class DerkJan_Thumbnails {
		
		private static $singleton;
		protected $fallback_tree;
		
		/**
		 * Gets a singleton of this class
		 *
		 * DerkJan_Thumbnails::singleton() will always return the same instance during a
		 * PHP processing stack. This way actions will not be queued duplicately and 
		 * caching of processed values is not neccesary.
		 *
		 * @returns the singleton instance
		 */
		public static function singleton() {
			if ( empty( DerkJan_Thumbnails::$singleton ) )
				DerkJan_Thumbnails::$singleton = new DerkJan_Thumbnails();
			return DerkJan_Thumbnails::$singleton;
		}
		
		/**
		 * Creates a new instance of DerkJan_Thumbnails
		 *
		 * @remarks use DerkJan_Thumbnails::singleton() outside the class hieracrchy
		 */
		protected function __construct() {	
							
			// Admin interface
			add_action( 'admin_menu', array( $this, 'add_pages' ) );
			add_action( 'admin_enqueue_scripts', array(& $this, 'admin_scripts' ), 1000 ); 
			
			// Add the fallbacks
			add_filter( 'get_required_thumbsize', array( $this, 'get_required_thumbsize' ), 10, 1);
			add_filter( 'get_fallback_tree_items', array( $this, 'get_fallback_tree_items' ), 10, 1);
			add_action( 'init', array( $this, 'create_fallback_tree' ) );
			
			// i18n
			add_action( 'plugins_loaded', array( $this, 'textdomain_init' ) );
		}
		
		/**
		 * Creates the table
		 *
		 * Upgrades the database to include the recordings table
		 */ 
		public function onactivate() {
			
			global $wp_roles;
			$wp_roles->add_cap( 'administrator', $this->all_capability() );
			
			$table_name = $this->table_name();

			global $wpdb;
			//$wpdb->query("DROP TABLE $table_name; ");
			
			// add if not exists
			$sql = "
			CREATE TABLE `$table_name` (
			   `thumbnail_id` int(11) NOT NULL AUTO_INCREMENT,
  			   `attachment_id` int(11) NOT NULL,
			   `slug` varchar(200) NOT NULL,
			   PRIMARY KEY (`thumbnail_id`),
			   UNIQUE KEY `slug`(`slug`)
			   );";
			   
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}
		
		/**
		 * Gets the table name
		 */
		public function table_name() {
			global $wpdb;
			return $wpdb->prefix . "dj_thumbnails";	
		}
	
		/**
		 * Gets the capbility need for all user events
		 */ 
		public function all_capability() {
			return 'manage_dj_thumbnails';
		}

		/**
		 * Gets the options page slug
		 */ 
		public function page_slug() {
			return 'dj_thumbnails';
		}
		
		/**
		 * Admin scripts
		 */
		public function admin_scripts( $hook ) {
			if ( 'media_page_' . $this->page_slug() !== $hook )
				return;
				
			// Only load on media page
			wp_enqueue_script( 'thickbox' );
			wp_enqueue_script( 'media-upload' );
			wp_enqueue_script( 'dj-thumbnails-admin' , DERKJAN_THUMBNAILS_DIR . '/js/dj-thumbnails.admin.js', array( 'jquery', 'thickbox' ) ); 
			
			// Styles
			wp_enqueue_style( 'thickbox' );
			
			$dj_thumbs_thumbnail_i18n = array(
				'use_image' => __( 'Use as thumbnail image', 'dj-thumbnails' ),
			);	
			wp_localize_script( 'dj-thumbnails-admin', 'dj_thumbnails_i18n', $dj_thumbs_thumbnail_i18n );	
		}
		
		/**
		 * Gets the required thumbnail size for this to display
		 * @size string of the thumbnail size
		 */
		public function get_required_thumbsize( $size = NULL ) {
			$size = "thumbnail"; // minumum size
			return $size;
		}

		/**
		 * Gets the fallback tree items
		 */
		public function get_fallback_tree_items( $fallback_items ) {
			$fallback_items['post'] = array('function' => array( $this, "get_post_thumbnail" ), 'after' => NULL);
			$fallback_items['category'] = array('function' => array( $this, "get_category_thumbnail" ), 'after' => 'post' );
			$fallback_items['tag'] = array('function' => array( $this, "get_taxonomy_thumbnail" ), 'after' =>'category', 'args' => array('taxonomy' => 'post_tag') );
			$fallback_items['site'] = array('function' => array( $this, "get_site_thumbnail" ), 'after' =>'tag' );
			
			return $fallback_items;
		}

		/**
		 * Creates the fallback tree
		 */
		public function create_fallback_tree() {
			$this->fallback_tree = array();
			$fallback_items = array();
			$fallback_items = apply_filters( 'get_fallback_tree_items', $fallback_items );
			
			
			foreach($fallback_items as $key => $data)
				$this->add_fallback( $key, $data["function"], 
					isset($data["after"]) ? $data["after"] : NULL, 
					isset($data["args"]) ? $data["args"] : array()
				);
		}
		
		/**
		 * Adds a fallback function
		 */
		public function add_fallback($key, $function, $after = NULL, $args = array() ) {
			
			$stay_behind = array();	
			
			// Find insertion position
			for ( $i = 0; $i < count( $this->fallback_tree ); $i++ )
				if ( $this->fallback_tree[$i]["key"] == $after ) :
					$i++; // insert here
					break;
				endif;
				
			// If we could not find our entry point, 
			if ( $i >= count( $this->fallback_tree ))
				for ( $i = 0; $i < count( $this->fallback_tree ); $i++ )
					// find another entry point
					if ( $this->fallback_tree[$i]["after"] == $key ) :
						// insert here
						break;
					endif;
			
			// Inserted item
			$item = array( 
				'key' => $key, 
				'after' => $after,
				'function' => $function, 
				'args' => $args
			);
			
			// Append to
			if ( $i >= count( $this->fallback_tree ) ) :
				array_push( $this->fallback_tree, $item );
				return;
			endif;
			
			// Insert before
			if ( $i == 0 ) :
				array_unshift( $this->fallback_tree, $item );
				return;
			endif;
			
			// Insert between
			array_splice( $this->fallback_tree, $i, 0, array( $item ) );		
		}
		
		/* Gets the thumbnail following the fallback_tree */
		public function get_thumbnail( $post_id = NULL, $size = NULL ) 
		{
			$size = $size?: apply_filters( 'get_required_thumbsize', $size );
			$post_id = $post_id ?: get_the_ID();
			$tree_pointer = 0;
			while( !( $thumbnail = $this->get_thumbnail_for( $post_id, $this->fallback_tree[$tree_pointer], $size ) ) ) :
				$tree_pointer++;
				if (! isset($this->fallback_tree[$tree_pointer]))
					return NULL;
			endwhile;
			
			return $thumbnail;
		}
		
		/**
		 * Checks if a attachment's thumbnail is of the defined size
		 *
		 * @attachment_id attachement to check
		 * @size size to check for
		 * @returns true if correct size, false if non existant or too small
		 */
		protected function is_thumbnail_of_size( $attachment_id, $size ) {
			
			$meta_size = get_thumbnail_base_size( $size );
			$image_attributes = wp_get_attachment_image_src( $attachment_id, $size );
			return isset($image_attributes) && (
					// When cropping, sizes needs to exactly match
					($meta_size['crop'] && ( $image_attributes[1] == $meta_size['width'] && $image_attributes[2] == $meta_size['height'] ) ) ||
					// When not cropping, one of the sizes needs to exactly match
					(!$meta_size['crop'] && ( $image_attributes[1] == $meta_size['width'] || $image_attributes[2] == $meta_size['height'] ) )			
				);
		}

		/**
		 * Gets a thumbnail for a post on a fallback level 
		 */
		protected function get_thumbnail_for( $post_id, $fallback_item, $size ) 
		{	
			// Call this fallback function
			$args = array( 'size' => $size ) + ( is_array( $fallback_item["args"] ) ? $fallback_item["args"]  : array() ) ;
			$items = call_user_func( $fallback_item["function"], $post_id, $args );
		
			if (!$items)
				return NULL;
			
			// Get the thumbnail
			$meta_size = $this->get_thumbnail_base_size( $size );
			
			while($item = array_shift( $items )) :
				$image_attributes = wp_get_attachment_image_src( $item, $size );
				if ( !isset($image_attributes) || (
						// When cropping, sizes needs to exactly match
						($meta_size['crop'] && !( $image_attributes[1] == $meta_size['width'] && $image_attributes[2] == $meta_size['height'] ) ) ||
						// When not cropping, one of the sizes needs to exactly match
						(!$meta_size['crop'] && !( $image_attributes[1] == $meta_size['width'] || $image_attributes[2] == $meta_size['height'] ) )			
					)
				)
					continue;
					
				return $item; //$image_attributes_2[0];
			endwhile;
					
			// Nothing for this item
			return NULL;
		}
		
		/**
		 * Gets an arbitrairy thumbnail
		 */
		public function get_slug_thumbnail( $slug ) {
			global $wpdb;
			$row = $wpdb->get_var(	
				$wpdb->prepare(
					"SELECT attachment_id
					FROM `".$this->table_name()."`
					WHERE `slug` = %s",
					$slug
				)
			);
			
			if (!$row)
				return NULL;
			return $row;
		}
		
		/*
		 * Gets a thumbnail for a post
		 */
		public function get_post_thumbnail( $post_id, $args = array() ) 
		{	

			if ( !has_post_thumbnail( $post_id) )
				return NULL;
			$result = get_post_thumbnail_id( $post_id );
			
			return array( $result );
		}
				
		/*
		 * Gets a thumbnail for a category
		 */
		public function get_category_thumbnail( $post_id, $args = array() ) 
		{
			$results = array();
			
			// Get categories for this post
			$categories = get_the_category( $post_id );
			if (!$categories)
				return NULL;
				
			// Retrieve thumbs for each category slug
			foreach( $categories as $category )
				if ( $result = $this->get_slug_thumbnail( $category->slug ) )
					array_push( $results, $result );
				
			// Return results
			if (!count($results))
				return NULL;
			return $results;
		}
		
		/*
		 * Gets a thumbnail for a taxonomy
		 */
		public function get_taxonomy_thumbnail( $post_id, $args = array() ) 
		{
			$results = array();
					
			// Get taxonomy
			$taxonomy = isset($args['taxonomy']) ? $args['taxonomy'] : 'post_tag';
			
			// Get the post
			$post_data = get_post( $post_id );
			if (!$post_data)
				return NULL;
			
			// Get the terms
			$terms = get_the_terms( $post_data, $taxonomy );
			if (!$terms)
				return NULL;
				
			// Retrieve thumbs for each term slug
			foreach( $terms as $term )
				if ( $result = $this->get_slug_thumbnail( $term->slug ) )
					array_push( $results, $result );
				
			// Return results
			if (!count($results))
				return NULL;
			return $results;
		}

		/*
		 * Gets a thumbnail for the site
		 */
		public function get_site_thumbnail( $post_id , $args = array() ) {
			$result = $this->get_slug_thumbnail( '~site' );
			if (!$result)
				return NULL;
				
			return array( $result );
		}
		
		/**
		 *	Gets all the registered thumbnails
		 */
		protected function get_thumbnails() {
			global $wpdb;	
			$rows = $wpdb->get_results(	
				"SELECT `attachment_id`, `slug`, `thumbnail_id` as `id`
				FROM `".$this->table_name()."`"
			);
			return $rows;
		}
		
		/**
		 *	Adds a new thumbnail
		 */
		protected function add_thumbnail( $slug, $attachment ) {
			global $wpdb;	
			$rows = $wpdb->insert(	
				$this->table_name(),
				array( 
					'attachment_id' => $attachment, 
					'slug' => $slug 
				),
				array( 
					'%d', 
					'%s' 
				)
			);	
		}
		
		/**
		 *	Updates an existing thumbnail
		 */
		protected function update_thumbnail( $id, $attachment ) {
			global $wpdb;	
			$rows = $wpdb->update(	
				$this->table_name(),
				array( 'attachment_id' => $attachment ),
				array( 'thumbnail_id' => $id ),
				'%d',
				'%s'
			);	
		}
		
		/**
		 *	Deletes an existing thumbnail
		 */
		protected function delete_thumbnail( $id ) {
			global $wpdb;	
			$rows = $wpdb->query(	
				$wpdb->prepare(
					"DELETE FROM `".$this->table_name()."`
					WHERE `thumbnail_id` = %d",
					$id
				)
			);
		}
		
		/**
		 * Adds menu admin/user pages
		 */ 
		public function add_pages() {
			
			// This is the admin page 
			add_submenu_page( 
				'upload.php', 
				__('Fallback Thumbnails', 'dj-thumbnails'), 
				__('Fallback Thumbnails', 'dj-thumbnails'), 
				$this->all_capability(),
				$this->page_slug(), 
				array( $this, 'manager' )
			);
		}
		
		/**
		 * Admin event page
		 */ 
		public function manager() { 
		
			if ( isset($_POST['submit']) || isset($_POST['thumbnail_delete']) )
				$post_results = $this->manager_submitted();
				
			$thumbnails = $this->get_thumbnails();
			
			// Get size
			$size = apply_filters( 'post_thumbnail_size', apply_filters( 'get_required_thumbsize', NULL ) ); 
			$display_size = isset( $_REQUEST['display_size'] ) ? $_REQUEST['display_size'] : 'thumbnail';
			
			$image_meta_size = $this->get_thumbnail_base_size( $size );
			$display_meta_size = $this->get_thumbnail_base_size( $display_size );
			
			echo '<input type="hidden" id="thumb_display_width" value="' . esc_attr( $display_meta_size[ 'width' ] ) . '"/>';
			echo '<input type="hidden" id="thumb_display_height" value="' . esc_attr( $display_meta_size[ 'height' ] ) . '"/>';
		
			// Base Url placeholders
			$thumb_base_placeholder = sprintf(
					'http://placehold.it/%dx%d.png&text=%s', 
					$display_meta_size['width'], 
					$display_meta_size['height'],
					'%s'
				);
				
			// Too small
			$thumb_too_small_url = sprintf( 
					$thumb_base_placeholder,
					urlencode( _x( "Too small!", 'thumbnail image', "dj-thumbnails" ) ) 
				);
				
			// Missing
			$thumb_missing_url = sprintf( 
					$thumb_base_placeholder,
					urlencode( __( "Thumbnail missing!", "dj-thumbnails" ) )
				);
				
			// Url to get a new image
			$thumb_new_url = sprintf( 
					sprintf(
						'http://placehold.it/%dx%d.png&text=%s', 
						$image_meta_size['width'], 
						$image_meta_size['height'],
						'%s'
					),
					urlencode( 
						sprintf( 
							__( "%dx%d or bigger", "dj-thumbnails" ), 
							$image_meta_size["width"], 
							$image_meta_size["height"] 
						) 
					) 
				);
			
			?>
			<div class="wrap" id="wrap-recording-manager">
                <h2><?php _e('Fallback Thumbnails', 'dj-thumbnails'); ?></h2>
                
                <!-- new -->
				<form action="<?php echo $this->get_current_url(); ?>" method="post">
                	
					<?php if ( !empty( $thumbnails ) ) : ?>
						<?php
                            
                            foreach( $thumbnails as $thumbnail ) :
								
								$title = sprintf( __("Thumbnail for %s", "dj-thumbnails"), $thumbnail->slug );
                                $display_attributes = wp_get_attachment_image_src( $thumbnail->attachment_id, $display_size ); // TODO remove max size body	
								$image_attributes = wp_get_attachment_image_src( $thumbnail->attachment_id, $size );
								
								// Not found
								if ( !isset($image_attributes) ) :
									$title =  sprintf( __("Thumbnail for %s does not exist.", "dj-thumbnails"), $thumbnail->slug );
									$display_attributes[0] = $thumb_missing_url;
									$display_attributes[1] = $display_meta_size['width'];
									$display_attributes[2] = $display_meta_size['height'];
								
								// Size missmatch
								elseif (
										// When cropping, sizes needs to exactly match
										($image_meta_size['crop'] && !( $image_attributes[1] == $image_meta_size['width'] && $image_attributes[2] == $image_meta_size['height'] ) ) ||
										// When not cropping, one of the sizes needs to exactly match
										(!$image_meta_size['crop'] && !( $image_attributes[1] == $image_meta_size['width'] || $image_attributes[2] == $image_meta_size['height'] ) )
									) :
									
									$title =  sprintf( __("Thumbnail for %s is too small.", "dj-thumbnails"), $thumbnail->slug );
									$display_attributes[0] = $thumb_too_small_url;
									$display_attributes[1] = $display_meta_size['width'];
									$display_attributes[2] = $display_meta_size['height'];
									//$image_attributes[1] = $display_meta_size['width'];
									//$image_attributes[2] = $display_meta_size['height'];
								endif;
								
                                printf(' 
								
									<div style="display: inline-block; margin-right:16px;">
										<input id="thumbnail_%1$d_upload_image" type="button" name="thumbnail_%1$d" 
											class="button thumb-upload" value="'.__('Update image', 'dj-thumbnails').'"  style="margin-bottom: 3px"/>
										<input type="submit" class="button" name="thumbnail_delete[%1$d]" value="'._x('X', 'delete thumbnail button', 'dj-thumbnails').'"/>
               
										<label for="thumbnail_%1$d_upload_image">	
											<div id="thumbnail_%1$d_icon" style="width:%8$s; height:%9$s; overflow: hidden; border:2px #f5f5f5 solid;">
												<img src="%2$s" width="%3$d" height="%4$d" title="%7$s" alt="%7$s"/>
											</div>
											
											<span style="position: relative; padding: 5px; top: -21px; margin-bottom: -16px; background: none repeat scroll 0px 0px rgba(255, 255, 255, 0.8);">%5$s</span>
										</label>
										<input id="thumbnail_%1$d_image_id" type="hidden" name="thumbnail_image_id[%1$d]" />
									</div>
                                    ', 
                                    
                                    $thumbnail->id,
                                    esc_attr( esc_url( $display_attributes[0] ) ),
                                    esc_attr( $display_attributes[1] ),
                                    esc_attr( $display_attributes[2] ),
                                    $thumbnail->slug,
									esc_url( $this->get_current_url() . '&thumbnail_delete=' . $thumbnail->id ),
									esc_attr( $title ),
									esc_attr( $display_meta_size['width'] . 'px' ),
                                    esc_attr( $display_meta_size['height'] . 'px' )
									
                                );
                            endforeach;
                        ?>
					
                    <?php endif; ?>
                             	
                    <h3><?php _e("Add new thumbnail", "dj-thumbnails"); ?></h3>
                	<div id="thumbnail_new">
                   		<?php _e("slug", "dj-thumbnails"); ?> <input type="text" name="thumbnail_new_slug" size="19" maxlength="200" style="margin-bottom: 3px"/>
                            
                        <label for="thumbnail_new_upload_image" >
                        	<div id="thumbnail_new_icon" style="width:<?php echo esc_attr( $image_meta_size['width'] . 'px' ); ?>; height:<?php echo esc_attr( $image_meta_size['height'] . 'px' ); ?>; overflow: hidden; border:2px #f5f5f5 solid;">
                            	<img src="<?php echo esc_attr( esc_url ( $thumb_new_url ) ); ?>" 
                                	alt="<?php _e("New thumbnail image.", "dj-thumbnails"); ?>" 
                                	title="<?php _e("New thumbnail image.", "dj-thumbnails"); ?>"/>
                            </div>
                            <p>
                            	<input id="thumbnail_new_upload_image" type="button" name="thumbnail_new" class="button thumb-upload" value="<?php _e('Select image', 'dj-thumbnails'); ?>"/>
                            	<input id="thumbnail_new_image_id" type="hidden" name="thumbnail_new_image_id" /> 
								<?php _e('Select an image for this thumbnail', 'dj-thumbnails'); ?>
                            </p>
                        </label>
                    </div>
                   	
                    <input id="thumbnail_submit" class="button button-primary" type="submit" name="submit" value="<?php _e('Submit changes', 'dj-thumbnails'); ?>" disabled />
                </form>
                
                <h2 style="margin-top: 32px"><?php _e("Fallback Information", "dj-thumbnails"); ?></h2>
                <h3><?php _e("Fallback tree", "dj-thumbnails"); ?></h3>
                <?php 
                $this->create_fallback_tree();
                $keys = array();
                foreach( $this->fallback_tree as $data )
                    array_push( $keys, $data["key"] );
                ?>
                <p><?php echo implode( ' > ', $keys ); ?></p>
                
                <h3><?php _e("Fallback properties", "dj-thumbnails"); ?></h3>
                <?php printf(
                        '<dl>
                            <dt>%8$s <code>%10$s</code></dt>
                            <dd>
                                <dl>
                                    <dt>%1$s</dt>
                                    <dd>%3$s</dd>
                                    <dt>%2$s</dt>
                                    <dd>%4$s</dd>
                                </dl>
                            </dd>
                            <dt>%9$s <code>%5$s</code></dt>
                            <dd>
                                <dl>
                                    <dt>%1$s</dt>
                                    <dd>%6$s</dd>
                                    <dt>%2$s</dt>
                                    <dd>%7$s</dd>
                                </dl>
                            </dd>
                        </dl>',
                        
                        _x( 'width',  'thumbnail image', 'dj-thumbnails' ),
                        _x( 'height', 'thumbnail image', 'dj-thumbnails' ),
                        $display_meta_size['width'] . ' ' . _x( 'pixels', 'image size', 'dj-thumbnails'),
                        $display_meta_size['height'] . ' ' . _x( 'pixels', 'image size', 'dj-thumbnails'),
                        $size,
                        $image_meta_size['width'] . ' ' . _x( 'pixels', 'image size', 'dj-thumbnails'),
                        $image_meta_size['height'] .' ' .  _x( 'pixels', 'image size', 'dj-thumbnails'),
                        _x( 'displayed:', 'thumbnail size name on display', 'dj-thumbnails' ),
                        _x( 'minimum size:', 'thumbnail size name required', 'dj-thumbnails' ),
						$display_size
                    );
					
					$links = array();
					foreach (get_intermediate_image_sizes() as $size)
						array_push( $links, sprintf( '<a href="%s" title="%s">%s</a>',
								esc_attr( esc_url( $this->get_current_url( 'display_size' ).'&display_size='.urlencode( $size ) ) ),
								sprintf( _x( 'Change display size to %s.', 'title for link', 'dj-thumbnails' ), $size ),
								$size
							)
						);
						
					printf('<p>'._x( 'Change display size to %s.', 'label for links', 'dj-thumbnails').'</p>', implode( _x(', ', 'seperator for urls, space after comma', 'dj-thumbnails'), $links ) );

                ?>
                
                

			</div>
            <?php
		}
		
		/**
		 * Manager process submissions
		 */
		public function manager_submitted() {
			
			if ( !current_user_can( $this->all_capability() ) )
				return;
			
			// Delete
			if ( isset( $_POST['thumbnail_delete'] ) ) :
				$this->delete_thumbnail( array_pop( array_keys ( $_POST['thumbnail_delete'] ) ) );
				return;
			endif;
			
			// Update
			if ( isset( $_POST["thumbnail_image_id"] ) ) :
				foreach( $_POST["thumbnail_image_id"] as $thumbnail_id => $value ) :
					if ( !empty($value) ) :
						$this->update_thumbnail( $thumbnail_id , $value );
					endif;
				endforeach;
			endif;
				
			// Add new
			if ( isset( $_POST['thumbnail_new_image_id'] ) && strlen( $_POST['thumbnail_new_image_id'] ) ) :
				$slug = isset( $_POST['thumbnail_new_slug'] ) && strlen( $_POST['thumbnail_new_slug'] ) ? $_POST['thumbnail_new_slug'] : 'test_' . rand();
				$this->add_thumbnail( $slug, $_POST['thumbnail_new_image_id'] );
			endif;
		}
		
		/**
		 *	Gets the thumbnail resize sizes. 
		 */
		public function get_thumbnail_base_size( $size ) {
			global $_wp_additional_image_sizes;
			
			if ( is_array( $size ) )
				return array( 'width' => intval( $size[0] ), 'height' => intval( $size[1] ), 'crop' => false );
			
			// Custom size
			if (isset($_wp_additional_image_sizes[ $size ]))
				return $_wp_additional_image_sizes[ $size ];
			
			// Rename size
			if ( in_array( $size, array( 'post-thumbnail', 'thumb' ) ) )
				$size = 'thumbnail';
				
			// Get from media settings
			$width = get_option( $size.'_size_w' );
			$height = get_option( $size.'_size_h' );
			$crop = get_option( $size.'_crop' );
			
			// If couldn't get this
			if ( empty( $width ) && $size != 'thumbnail' )
				// Get default
				return $this->get_thumbnail_base_size( 'thumbnail' );
			
			// Return sizes
			return array( 'width' => intval( $width ), 'height' => intval( $height ), 'crop' => !empty( $crop ) );
		}
		
		/**
		 * Gets the current url
		 */
		protected function get_current_url( $strip = NULL ) {
			$url  = isset($_SERVER["HTTPS"]) && $_SERVER['HTTPS'] == "on" ? 'https://' : 'http://';
			$url .= (strlen($_SERVER["SERVER_NAME"]) ? $_SERVER["SERVER_NAME"] :$_SERVER['HTTP_HOST']);
			$url .= (strval($_SERVER["SERVER_PORT"]) != "80") ? $_SERVER["SERVER_PORT"] : '';
		
			if ( !is_null($strip) ) :
				$strip = is_array( $strip ) ? $strip : array( $strip );
		  		$url .= preg_replace("/[&\?](".implode( "|", $strip ).")=?[^&]*/", "", $_SERVER["REQUEST_URI"]);
			else :
				$url .= $_SERVER["REQUEST_URI"];
			endif;
			return $url;	
		}
		
		/**
		 * Initializes the textdomain for this plugin
		 */
		function textdomain_init() {
			$plugin_dir = basename( DERKJAN_THUMBNAILS_DIR );
			load_plugin_textdomain( 'dj-thumbnails' , false , $plugin_dir . '/languages' );
		}

	}
	
	$DerkJan_Thumbnails = DerkJan_Thumbnails::singleton();
	register_activation_hook(__FILE__, array( $DerkJan_Thumbnails, 'onactivate') );
	
	/**
	 * Echos the thumbnail for the current post in the loop
	 *
	 * @args can either be a thumbnail size or an array of options
	 * @attr see the_post_thumbnail
	 */
	function the_dj_fallback_thumbnail ( $args = array(), $attr = '') {
		echo get_dj_fallback_thumbnail( NULL, $args, $attr );
	}
	
	/**
	 *	Get the thumbnail
	 *
	 *	@post_id the post id to get it for or NULL for the current in the loop
	 *  @args can either be a thumbnail size or an array of options
	 *  @attr see the_post_thumbnail	
	 */
	function get_dj_fallback_thumbnail($post_id = NULL, $args = array(), $attr = '') {
		global $DerkJan_Thumbnails;
						
			// What thumbnail size?
			$default_size = apply_filters( 'get_required_thumbsize', NULL ); 
			$size = apply_filters( 'post_thumbnail_size', 
				is_array( $args ) ? 
					( 
						// Is just two numbers, then it's a size
						( count( $args ) == 2 && isset( $args[0] ) && isset( $args[1] ) ) ? $args : 
						// Else we have an argument array
						( isset( $args['size'] ) ? $args['size'] : $default_size )
						// Just a string
					) : ( is_string( $args ) ? $args : $default_size )
			); 
			
			$post_id = ( NULL === $post_id ) ? get_the_ID() : $post_id;
			$post_thumbnail_id = $DerkJan_Thumbnails->get_thumbnail( $post_id, $size );
			
	        if ( $post_thumbnail_id ) {
	        	do_action( 'begin_fetch_post_thumbnail_html', $post_id, $post_thumbnail_id, $size ); 
	            if ( in_the_loop() )
	            	update_post_thumbnail_cache();
	            $html = wp_get_attachment_image( $post_thumbnail_id, $size, false, $attr );
	            do_action( 'end_fetch_post_thumbnail_html', $post_id, $post_thumbnail_id, $size );
	        } else {
	            $html = '';
	        }
	        return apply_filters( 'post_thumbnail_html', $html, $post_id, $post_thumbnail_id, $size, $attr );
		
		return $thumbnail;
	}
}
?>
