wp-dj-thumbnails
================

Wordpress plugin for an thumbnail fallback system. Will only display thumbnails when of the required 
thumbnail size.

Filters: 
  * `get_fallback_tree_items`	: runs before the fallback tree is created
  * `get_required_thumbsize`	: runs on the admin page. requires this size

You can use the first filter to add new fallback items. Simply provide a name, a function and a name
after which this item is inserted.

````php
function get_fallback_tree_items( $fallback_items ) {
  		$fallback_items[ ::name:: ] = array('function' => ::function::, 'after' => ::name:: or null );
      return $fallback_items;
}
````

The function header for fallback_items is

````php
function function_name( $post_id, $args = array() ) 
````

And should return an `array` or `NULL`.

You can use the second filter to change the required thumbsize. It should return a `string` or and `array` with sizes. 
This will be used by default when querying for thumbnails. 
