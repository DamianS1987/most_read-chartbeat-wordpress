<?php
/* Check chartbeat for most read articles 
* inside of category you are in */
class MostReadChartbeat {
	public $host;
	public $chartbeat_api_key;
	public $cache_time;
	//main object where the posts from different sections/categories will be stored
	public $cats_posts = array();

	function __construct( $instance ) {
		$this->host = $instance['host'];
		$this->chartbeat_api_key = $instance['key'];
		$this->cache_reset_time = $instance['time_value'];
	}

	/* prepare section name string for query */
	private function get_section ( $section ) {
		return strtolower( preg_replace( '/ / ', '+', $section) );
	}

	//naming convention - category (wp), section (Chartbeats)
	private function query_posts_from_category( $section ) {
		$chartbeat_query = 'http://api.chartbeat.com/live/toppages/v3/?apikey=' . $this->chartbeat_api_key . '&host=' . $this->host . '&section=' . $this->get_section( $section );
		/* Query Chartbeat */
		$chart_resp = json_decode( file( $chartbeat_query )[0] );
		//this obj is storing posts from a certain category for caching purposes
		$this_cat_posts = array();
		//loop through posts result from single category
		foreach( $chart_resp->pages as $post ) {
			//add post to "cats_posts" only if the post doesn't exist in the array already
			if( $this->check_if_post_exists( $post ) ) {
				array_push($this->cats_posts, $post);
				array_push($this_cat_posts, $post);
			}
		}
		//cache the results for specified category
		//to prevent flooding chartbeat with requests
		if (sizeof($this->cats_posts) > 0 ) {
			set_transient( $this->get_section( $section ), $this_cat_posts, $this->cache_reset_time );
		} else {
			set_transient( $this->get_section( $section ), "no_posts", $this->cache_reset_time );
		}
	}

	private function check_if_post_exists( $the_post ) {
		//return type Boolean
		//true if the object doesnt exists in an array, false if exists
		$i = 0;
		foreach( $this->cats_posts as $post_obj ) {
			if( $post_obj->path === $the_post->path ) $i++;
		}
		return ($i > 0) ? false : true; 
	}

	private function get_posts_from_cached_category ( $posts ) {
		//add posts to the main category posts object
		foreach( $posts as $post ) {
			//check if the posts is aleady include in the "cats_posts" array
			if( $this->check_if_post_exists( $post ) ) {
				array_push($this->cats_posts, $post);
			}
		}
	}

	/* main method initiated after page load
	* we pass array of categories as one post may contain several categories
	*/
	public function get_main_posts( $cats ) {
		foreach( $cats as $category ) {
			//query chartbeat
			$this->add_posts_from_category(  $category );
		}
	}

	public function get_init_posts( $cats ) {
		foreach( $cats as $category ) {
			//query chartbeat
			$this->add_posts_from_category(  $category->name );
		}
	}

	/* method initiated in two cases:
	* 1. on page load by "get_main_posts()"
	* 2. for other single categories query to meet the aim of geting 10 most read posts
	*/
	public function add_posts_from_category( $category ) {
		/* Check if we have any data about the categories in cache
		* check cache for the category key
		*/
		$cached_category_posts = get_transient( $this->get_section( $category ) );
		if ( false === $cached_category_posts ) {
			$this->query_posts_from_category( $category );
		
		} elseif ( gettype( $cached_category_posts ) === "array" ) {
			$this->get_posts_from_cached_category( $cached_category_posts );
		}
	}
}

/* The aim of this class is to detect category hirarchy - which level the current category is in
* and how to behave in each category
*/
class Categories_extractor {
	//status for each category
	public $category_status = array();
	//list of categories to query - level 1
	public $cats_to_query_lv1 = array();
	//list of categories to query - level 2
	public $cats_to_query_lv2 = array();
	//list of categories to query - level 3
	public $cats_to_query_lv3 = array();

	/* We will recognize categories nested in 3 levels only
	* refer to them from top to bottom
	* - top level category    - "parent category"
	* - middle level category - "child category"
	* - bottom level category - "grandchild category"
	*/

	/* Option One - from bottom to top
	*
	* check if we are dealing with grandchild category
	* if true -> fun starts:
	* 1. go to it's parent category "child category" and search chartbeat for posts
	* 2. go to it's parent's child categories - it's siblings and search for posts 
	*
	* Option Two - from top to bottom
	*
	* if we are dealing with "child" or "parent" category
	* get me all child and grandchildren categories
	*
	*/

	//loop through categories
	/* Please note that this class handles all of the categories for a post - in array format */
	function __construct( $cats ) {

		foreach ( $cats as $cat) {
			array_push( $this->cats_to_query_lv1, $cat->name );
			//category position check
			$first_parent_check = $this->check_categories_position( $cat );
		}
	}

	private function add_cat_to_level2 ( $name ) {
		//don't add categories already in the group
		if ($name !== null && !in_array( $name, $this->cats_to_query_lv2)) {
			array_push( $this->cats_to_query_lv2, $name);
		}
	}

	private function add_cat_to_level3 ( $name ) {
		//don't add categories already in the group
		if ($name !== null && !in_array( $name, $this->cats_to_query_lv3)) {
			array_push( $this->cats_to_query_lv3, $name);
		}
	}

	public function extraction_two () {
		foreach ( $this->category_status as $key=>$value) {
			switch ( $value['status'] ) {
				case "grandchild":
					//add grandchild's parent name to categories lv2
					$this->add_cat_to_level2( $value['parent']->name );
				break;
				case "child":
					//search for child categories
					foreach( $value['children'] as $child_cat ) {
						$this->add_cat_to_level2( $child_cat->name );
					}
				break;
				case "parent":
					//search for child categories
					foreach( $value['children'] as $child_cat ) {
						$this->add_cat_to_level2( $child_cat );
					}
				break;
			}
		}
	}

	public function extraction_three () {
		foreach ( $this->category_status as $key=>$value) {
			switch ($value['status']) {
				case "grandchild":
					//search for grandchild parent's child categories (siblings)
					$ar = $this->get_child_categories( $value['parent']->name );
					foreach( $ar as $cat ) {
						$this->add_cat_to_level3( $cat->name );
					}
				break;
				case "child":
				//don't perform any action as you searched the children already
				break;
				case "parent":
					foreach( $value['grandchildren'] as $grandchild ) {
						$this->add_cat_to_level3( $grandchild);
					}
				break;
			}
		}
	}

	//top to bottom check - applicable for "child category" as it's getting all lower level categories
	private function get_child_categories ( $single_cat ) {
		$this_cat = get_category_by_slug( $single_cat );
		$child_cat_args = array(
			'type' => 'post',
			'hierarchical' => false,
			'taxonomy' => 'category',
		    'child_of' => $this_cat->term_id
		);
		$categories_ret = (!!$this_cat->term_id ) ? get_categories( $child_cat_args ) : array();
		return $categories_ret;
	}

	//bottom to top check - only applicable for "grandchild category"
	private function get_parent_category ( $cat ) {
		$cat_id = $cat->term_id;
		$this_category = get_category($cat_id);
		//return parent category name or 0 if there's no parent category
		return ($this_category->parent === 0) ? false : get_category( $this_category->parent);
	}

	//divide children in 2 groups for the "parent category"
	private function divide_children ( $group, $parent_name ) {
		//immediate child
		$this->immediate_child = array();
		//grandchild
		$this->grandchild = array();

		foreach( $group as $child) {
			$parent_cat_of_child = get_category( intval( $child->parent ) );

			//immediate child
			if ( $parent_cat_of_child->name === $parent_name ) {
				array_push( $this->immediate_child, $child->name );
			} else {
				//grandchild
				array_push( $this->grandchild, $child->name );
			}
		}
	}

	private function check_categories_position ( $cat ) {
		$parent_name = $this->get_parent_category( $cat );

		//if we have a parent...
		if ( gettype( $parent_name ) === "object" ) {
			if ($parent_name->parent === 0) {
				//that's the child category
				$this->category_status[$cat->name] = array(
					"status" => "child",
					"children" => $this->get_child_categories( $cat->name )/* function checking the children */
					);
			} else {
				//that's grandchild category
				$this->category_status[$cat->name] = array(
					"status"=>"grandchild",
					"parent"=>$parent_name
					);
			}
		} else {
			$children = $this->get_child_categories( $cat->name );
			//init division
			$this->divide_children( $children, $cat->name );

			//that was the "parent category"
			$this->category_status[$cat->name] = array(
				"status"=>"parent",
				"children" => $this->immediate_child,
				"grandchildren" => $this->grandchild
				);
		}
	}
}

// Most read widget
class most_read_widget extends WP_Widget {

	function __construct() {
		parent::__construct(
			// Base ID of your widget
			'most_read_widget',

			// Widget name will appear in UI
			__('Most Read Widget', 'most_read_widget'), 

			// Widget description
			array( 'description' => __( 'Most Read Article - real time with Chartbeat', 'most_read_widget' ), ) 
		);
	}

	public $cats = array();
	public $ten_most_read_posts = array();
	private $list = array();
	private $full_template = '';

	private function template_wrapper( $list, $display) {
		$html = '<li class="widget widget_most_read_chartbeat" style="display: ' . $display . '"><h3 class="widget-title">' . $this->title . '</h3><ul class="pictured">' . $list . '</ul></li>';
		return $html;
	}

	private function template_list_element( $title, $link,  $img, $root_cat_number, $root_cat_name ) {
		$html = '<li><div class="feature-image">' . $img . '<a class="category_' . $root_cat_number . '_color_hero_link category"><span>' . $root_cat_name . '</span></a></div><a href="' . $link . '">' . $title . '</a><div class="clear-fix"></div></li>';

		return $html;
	}

	private function displayWidget() {
		/* Custom dev settings */
		/* We are using chartbeat data from another domain (production website) while working on development environment. 
		* Due to the fact that we have old database on dev most of the posts won't diplay in the widget -
		* Not untill we create artificial traffic on the old posts on the live site. To display those posts we have to change 
		* post's domain name taken from the chartbeats. 
		*/
		$env = $_SERVER['ENVIRONMENT'];
		$dev_name = $_SERVER['SERVER_NAME'];

		foreach( $this->ten_most_read_posts as $single_post ) {
			if ( $env === "development" ) {
				$url = str_replace($this->mr->host, $dev_name, $single_post->path);
				$post_id = url_to_postid( $url );
			} else {
				$post_id = url_to_postid( $single_post->path );
			}
			if ($post_id !== 0) {

				$img = get_the_post_thumbnail( $post_id, array(125, 100) );

				//if img is empty string
				if ( !$img ) {
					$img = '<img width="125" height="94" src="' . $this->image . '" class="attachment-125x100 wp-post-image" alt="Runners make their way through the rain during the Edinburgh Marathon">';
				}

				$chartbeat_post = get_post( $post_id );
				
				$this_post_category = get_the_category( $post_id );
				$link = $single_post->path;

				$post_category = get_the_category($post_id);
				$ancestors = get_ancestors($post_category[0]->term_id, 'category');
				$root_category = end($ancestors);

				if ( !!$root_category ) {
					$root_name = get_the_category_by_ID( $root_category );
					$element = $this->template_list_element( $chartbeat_post->post_title, $link, $img, $root_category, $root_name);
					array_push( $this->list, $element );
				}
			}
		}
		//combine list elements
		$ar = implode( '', $this->list );
		$this->display_widget( $ar );
	}

	private function display_widget( $ar ) {
		/* Display the widget only if the number of posts is more 
		* than the "hide widget" number defined in the widget  
		*/
		if ( sizeof( $this->ten_most_read_posts) > (int) $this->hide ) {
			$display = "block";
		} else {
			$display = "none";
		}
		//join the wrapper and the list elements and display it
		$this->full_template = $this->template_wrapper( $ar, $display );
		/*
		* display the widget
		*/
		echo($this->full_template );
	}

	// Creating widget front-end
	// This is where the action happens
	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title', $instance['title'] );
		$time  = apply_filters( 'widget_title', $instance['time_value'] );
		$hide  = apply_filters( 'widget_title', $instance['hide_value'] );
		$host  = apply_filters( 'widget_title', $instance['host'] );
		$key   = apply_filters( 'widget_title', $instance['key'] );
		$image = apply_filters( 'widget_title', $instance['image'] );

		$this->title = $title;
		$this->time  = $time;
		$this->hide  = $hide;
		$this->host  = $host;
		$this->key   = $key;
		$this->image = $image;

		/* get the categories for the post we are currently in */
		global $wp_query;
		$post_obj = $wp_query->get_queried_object();

		// Use the post's categories as sections
		//this is the exact way chartbeat is fetching a post categories to create their sections
		//chartbeat.php line 285

		//in the main category newsstreams $post_obj->ID is null
		//this means that the widget was placed somewhere outside of article page
		if ( is_null($post_obj->ID) ) {
		//	$obj = (object) ['name'=> $post_obj];
			$this->cats_lv1 = array('0'=> $post_obj);
		} else {
			$this->cats_lv1 = get_the_terms( $post_obj->ID, 'category' );
		}
		
		//create an array of categories to query	
		foreach( $this->cats_lv1 as $cat_lv1 ) {
			array_push( $this->cats, $cat_lv1->name );
		}

		/* Init Chartbeat query */
		$this->chartbeat_initialize( $instance );

		if ( !$this->is_preview() ) {
			$cache[ $args['widget_id'] ] = ob_get_flush();
			wp_cache_set( 'widget_category_posts', $cache, 'widget' );
		} else {
			ob_flush();
		}
	}

	private function chartbeat_initialize( $instance ) {
		$this->mr = new MostReadChartbeat( $instance );
		//Query Level 1
		//get posts from the main post's categories (the posts widget sits in) categories
		$this->mr->get_init_posts( $this->cats_lv1 );

		//logic for the results
		if ( sizeof( $this->mr->cats_posts ) > 10) {
			$this->ten_most_read_posts = array_slice( $this->mr->cats_posts, 0, 10 );
			//we've got our posts, now let's handle display
			$this->displayWidget();
		} elseif ( sizeof( $this->mr->cats_posts ) < 10 ) {
			//Query level 2
			$this->call_level_two_init();
			//after query level 2...
			if ( sizeof( $this->mr->cats_posts ) < 10 ) {
				//Query level 3
				$this->call_level_three_init();
				if ( sizeof( $this->mr->cats_posts ) < 10 ) {
					$this->ten_most_read_posts = array_slice( $this->mr->cats_posts, 0, 10 );				 	
				} else {
					$this->ten_most_read_posts = $this->mr->cats_posts;
				}
				//we've got our posts, now let's handle display
				$this->displayWidget();	
			} elseif ( sizeof( $this->mr->cats_posts ) > 10 ) {
				//there is enough posts or the exact number so display the posts
				$this->ten_most_read_posts = array_slice( $this->mr->cats_posts, 0, 10 );
				//we've got our posts, now let's handle display
				$this->displayWidget();				

			} else {
				$this->ten_most_read_posts = $this->mr->cats_posts;
				//we've got our 10 posts, now let's handle display
				$this->displayWidget();
			}
		} else {
			$this->ten_most_read_posts = $this->mr->cats_posts;
			//we've got our 10 posts, now let's handle display
			$this->displayWidget();
		}
	}

	private function call_level_two_init() {
		/* if the widget is going to sit in newsstream panel check the newsstream main category only (and children if necessary) e.g. "Business" */
		$this->extract = new Categories_extractor( $this->cats_lv1 );

		//call for more posts level 2
		$this->extract->extraction_two();
		//create an array of categories to query chartbeat
		$cats_to_query = $this->extract->cats_to_query_lv2;

		foreach( $cats_to_query as $categ ) {
			//don't add categories already in the group
			if (!in_array( $categ, $this->cats)) {
				array_push( $this->cats, $categ );
			}
		}
		//Query level 2
		$this->mr->get_main_posts( $this->cats );
	}

	private function call_level_three_init() {

		//call for more posts level 3
		$this->extract->extraction_three();
		//create an array of categories to query chartbeat
		$cats_to_query2 = $this->extract->cats_to_query_lv3;

		foreach( $cats_to_query2 as $categ ) {
			//don't add categories already in the group
			if (!in_array( $categ, $this->cats)) {
				array_push( $this->cats, $categ );
			}
		}
		//Query level 3
		$this->mr->get_main_posts( $this->cats );	
	}
			
	// Widget Backend 
	public function form( $instance ) {
		$title      = ( isset( $instance[ 'title' ] ) ) ?  $instance[ 'title' ] : "title";
		$hide_value = ( isset( $instance['hide_value'] ) ) ? $instance['hide_value'] : 2;
		$time_value = ( isset( $instance['time_value'] ) ) ? $instance['time_value'] : 60;
		$host       = ( isset( $instance['host'] ) ) ? $instance['host'] : "yourhost.co.uk";
		$key        = ( isset( $instance['key'] ) ) ? $instance['key'] : "your_key";
		$image      = ( isset( $instance['image'] ) ) ? $instance['image'] : "image_url";

		?>
		<div class="widget_form">
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><b><?php _e( 'Title:' ); ?></b></label> 
				<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
			</p>
			<p><b>Hide the widget if less than (posts):</b></p>

			<span>set to:  </span><span><?php echo esc_attr($hide_value); ?></span> <span style="float: right;"><span> change to: </span><input class="rangeValue1" type="text" size="2"></span>
			<input type="range" min="2" max="10" class="widefat hide_value" value="<?php echo esc_attr($hide_value); ?>" name="<?php echo $this->get_field_name( 'hide_value' ); ?>">
			
			<br>
			<p><b>Refresh cache every (seconds):</b></p>
			<span>set to:  </span><span><?php echo esc_attr($time_value); ?></span> <span style="float: right;"><span> change to: </span><input class="rangeValue2" type="text" size="2"></span>
			
			<input type="range" min="10" max="600" class="widefat time_value" value="<?php echo esc_attr($time_value); ?>" name="<?php echo $this->get_field_name( 'time_value' ); ?>">
			<br>
			<p>
				<label for="key"><b>Host</b> (website which uses Chartbeat service, use domain only e.g.: pressandjournal.co.uk):</label><br><br>
				<input type="text" class="host widefat" name="<?php echo $this->get_field_name( 'host' ); ?>" placeholder="host e.g.: pressandjournal.co.uk" value="<?php echo esc_attr($host); ?>">
			</p>

			<br>
			<p>
				<label for="key"><b>Chartbeat API key</b> (you can get if from Chartbeat.com API panel):</label><br><br>
				<input type="text" class="key widefat" name="<?php echo $this->get_field_name( 'key' ); ?>" placeholder="your chartbeat api key" value="<?php echo esc_attr($key); ?>">
			</p>

			<br>
			<p>
				<label for="image"><b>Default Image</b> (image url for articles with no featured image):</label><br><br>
				<input type="text" class="image widefat" name="<?php echo $this->get_field_name( 'image' ); ?>" placeholder="your chartbeat api image" value="<?php echo esc_attr($image); ?>">
			</p>

			<br />
		</div>
		<script>
			(function () {
				var $ = jQuery.noConflict();
				$('.hide_value').change(function () {
					var v = $(this).attr('value');
					$('.rangeValue1').val(v);
				});
				$('.time_value').change(function () {
					var v = $(this).attr('value');
					$('.rangeValue2').val(v);
				});
			})();
		</script>
		<?php 
	}
		
	// Updating widget replacing old instances with new
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		$instance['hide_value'] = $new_instance['hide_value'];
		$instance['time_value'] = $new_instance['time_value'];
		$instance['host'] = $new_instance['host'];
		$instance['key'] = $new_instance['key'];
		$instance['image'] = $new_instance['image'];

		return $instance;
	}
} // Class wpb_widget ends here

// Register and load the widget
function wpb_load_widget() {
	register_widget( 'most_read_widget' );
}
add_action( 'widgets_init', 'wpb_load_widget' );