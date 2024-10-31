<?php
/**
 * @package Pushconnect
 * @version 1
 */
/*
Plugin Name: PushConnect Web push messaging
Plugin URI: http://pushconnect.tech
Description: Enable push messaging subscription using pushconnect.tech
Author: PushConnect
Version: 1.0
Text Domain: pushconnect-web-push-messaging
Domain Path: /languages
Author URI: http://pushconnect.tech
*/

class PushConnect {

    private $endpoint = 'https://push.jwdev.tech/api/';
    private $api_key;
    private $subscription;
    
    public function init() {
        if(isset($_COOKIE['pc_push_subscription'])) {
            $this->subscription = json_decode(stripslashes($_COOKIE['pc_push_subscription']));
        }
		if(/*current_user_can( 'subscriber' )*/ get_current_user_id() && $this->subscription && $this->subscription->endpoint) {
			$this->setCustomerId();
		}
    }
    
    public function setCustomerId() {
		global $wpdb;
        if(get_current_user_id()) {
			
			$data = $wpdb->query( 
				$wpdb->prepare( 
					"SELECT `key` from "  . $wpdb->prefix . "pushconnect_tracking 
					 WHERE endpoint = %s
					",
						$this->subscription->endpoint
					)
			);
						
            if($data) {
				$wpdb->update($wpdb->prefix . "pushconnect_tracking", array( 
					'value' => get_current_user_id(),
					'timestamp' => current_time('mysql', 1)
					),
					array( 'endpoint' => $this->subscription->endpoint,  'key' => 'customer_id')
				); 
            } else {	
				$wpdb->insert($wpdb->prefix . "pushconnect_tracking", array( 
						'endpoint' => $this->subscription->endpoint, 
						'key' => 'customer_id',
						'value' => get_current_user_id()
					)
				);
            }
        }
    }
    
    public function getEndpointForCustomerId($customer_id) {
		global $wpdb;
		
        if(!is_array($customer_id)) {
            $customers = [$customer_id];
        } else {
            $customers = $customer_id;
        }
        $customer_ids = array_map(function($customer) {
           return (int)$customer; 
        }, $customers);
       
		$placeholders = array_fill(0, count($customer_ids), '%d');
		$format = implode(', ', $placeholders);
		$query = "SELECT endpoint, value as customer_id FROM " . $wpdb->prefix. "pushconnect_tracking WHERE `key` = 'customer_id' AND value IN($format)";
		$results = $wpdb->get_results( $wpdb->prepare($query, $customer_id) );
		
		if($results) {
			return array_map(function($customer){
                return $customer->endpoint;
            }, $results);
		} else {
			return null;
		}
    }
    
    public function getAllEndpoints() {
        global $wpdb;
        $query = "SELECT endpoint, value as customer_id FROM " . $wpdb->prefix. "pushconnect_tracking";
		$results = $wpdb->get_results($query);
        if($results) {
			return array_map(function($customer){
                return $customer->endpoint;
            }, $results);
		} else {
			return null;
		}
    }

    public function setApiKey($api_key) {
        $this->api_key = $api_key;
        return $this;
    }

    /**
     * Assign generic data to an endpoint
     * @param array $data [['endpoint' => '', 'key' => '', 'value' => '']]
     */
    public function setEndpointData($data) {
        if (isset($data['endpoint']))
            $data = [$data]; //put singles into an array to enable multiple key/values in one request
        return $this->post('testapi', ['endpoint' => $data]);
    }

    public function filterEndpoints($filters) {
        if (isset($filters['key']))
            $filters = [$filters]; //put singles into an array to enable multiple key/values in one request
        return $this->post('endpoint/filter', ['filters' => $filters]);
    }
    
    public function sendNotification($endpoints, $message) {
        if(!is_array($endpoints)) {
            $endpoints = [$endpoints];
        }
        return $this->post('endpoint/sendpush', ['message' => $message, 'endpoints' => $endpoints]);
    }

    public function post($route, $data) {
        try {
			$response = wp_remote_post($this->endpoint . $route, array( 
					'body' 		=> $data,
					'headers' 	=> array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'x-authorization' => $this->api_key
					)
				) 
			);          
			return json_decode($response['body']);
        } catch(Exception $e) {
            return ['api_success' => false, 'message' => $e->getMessage()];
        }
        
    }

}




function pushconnect_install() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'pushconnect_tracking';
	
	$charset_collate = $wpdb->get_charset_collate();
	
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		`endpoint` text,
		`key` varchar(255) DEFAULT NULL,
		`value` text,
		`timestamp` timestamp NULL DEFAULT NULL
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
    
    copy(plugin_dir_path(__FILE__) . 'pushconnect-sw.js', get_home_path() . 'pushconnect-sw.js');
    
    add_image_size( 'pushconnect-thumb', 400 );
}



function add_action_links ( $links ) {
    $mylinks = array(
    '<a href="' . admin_url( 'options-general.php?page=puschonnect_settings' ) . '">Settings</a>',
    );
    return array_merge( $links, $mylinks );
}


function create_plugin_settings_page() {
    // Add the menu item and page
    $page_title = 'PushConnect settings page';
    $menu_title = 'PushConnect';
    $capability = 'manage_options';
    $slug = 'puschonnect_settings';
    $callback = 'pushconnect_plugin_settings_page';
    add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $slug, $callback );
}



function pushconnect_init() {
    if(get_option('pushconnect_status') && get_option('pushconnect_api_key')) {
        $pushconnect_plugin = new PushConnect();
        $pushconnect_plugin->init();        
    }
}

//called when a post status changes
function pushconnect_status_transitions($new_status, $old_status, $post) {
	if($new_status == 'publish' && $old_status != 'publish' && $post->post_type == 'post' && get_option('pushconnect_status') && get_option('pushconnect_api_key') && get_option('pushconnect_notify_posts')) {
        
        $pushconnect_plugin = new PushConnect();
        $endpoints = $pushconnect_plugin->getAllEndpoints();
        
        if (has_post_thumbnail( $post->ID )) {
            $image = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'thumbnail-size', true);
        }
        
        $message =  array('title'   => $post->post_title, 
                         'body'     => $post->post_excerpt,
                         'image'    => $image ? str_replace( "http:", "https:", $image[0]) : null,
                         'url'      => get_permalink($post->ID)
                    );
        
        
        $pushconnect_plugin->setApiKey(get_option('pushconnect_api_key'))->sendNotification($endpoints, $message);
	}	
    
}




function pushconnect_add_script() {
	wp_enqueue_script( 'pc-script', '//push.jwdev.tech/subscribejs/15c3756f452d18.js');
}


function pushconnect_plugin_settings() {
	//register our settings
	register_setting( 'pushconnect-settings-group', 'pushconnect_api_key' );
	register_setting( 'pushconnect-settings-group', 'pushconnect_javascript_location' );
    register_setting( 'pushconnect-settings-group', 'pushconnect_status' );
    register_setting( 'pushconnect-settings-group', 'pushconnect_notify_posts' );
}

function pushconnect_plugin_settings_page() {
?>
    <div class="wrap">
    <h1>PushConnect <?php _e( 'Settings', 'pushconnect-web-push-messaging' );?></h1>
    <p>
		<?php			
			printf(
				__( 'Please register for an account at %s and set up a new site. Enter your API key and javascript location below', 'pushconnect-web-push-messaging' ),
				'<a target="_blank" href="https://pushconnect.tech">PushConnect.tech</a>'
			);
		?>
    </p>
    <form method="post" action="options.php">
        <?php settings_fields( 'pushconnect-settings-group' ); ?>
        <?php do_settings_sections( 'pushconnect-settings-group' ); ?>
        <table class="form-table">
            
            <tr valign="top">
                <th scope="row"><?php _e( 'Status', 'pushconnect-web-push-messaging' );?></th>
                <td>
                    <select name="pushconnect_status">
                        <option value="" <?php selected(get_option('pushconnect_status'), ""); ?> disabled><?php _e( '--Please Select--', 'pushconnect-web-push-messaging' );?></option>
                        <option value="1" <?php selected(get_option('pushconnect_status'), "1"); ?>><?php _e( 'Enabled', 'pushconnect-web-push-messaging' );?></option>
                        <option value="0" <?php selected(get_option('pushconnect_status'), "0"); ?>><?php _e( 'Disabled', 'pushconnect-web-push-messaging' );?></option>
                    </select>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row"><?php _e( 'Notify users of new posts', 'pushconnect-web-push-messaging' );?></th>
                <td>
                    <select name="pushconnect_notify_posts">
                        <option value="" <?php selected(get_option('pushconnect_notify_posts'), ""); ?> disabled><?php _e( '--Please Select--', 'pushconnect-web-push-messaging' );?></option>
                        <option value="1" <?php selected(get_option('pushconnect_notify_posts'), "1"); ?>><?php _e( 'Enabled', 'pushconnect-web-push-messaging' );?></option>
                        <option value="0" <?php selected(get_option('pushconnect_notify_posts'), "0"); ?>><?php _e( 'Disabled', 'pushconnect-web-push-messaging' );?></option>
                    </select>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row"><?php _e( 'API Key', 'pushconnect-web-push-messaging' );?></th>
                <td><input type="text" name="pushconnect_api_key" value="<?php echo esc_attr( get_option('pushconnect_api_key') ); ?>" /></td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php _e( 'Javascript Location', 'pushconnect-web-push-messaging' );?></th>
                <td><input type="text" name="pushconnect_javascript_location" value="<?php echo esc_attr( get_option('pushconnect_javascript_location') ); ?>" /></td>
            </tr>
        </table>

        <?php submit_button(); ?>

    </form>
    </div>
<?php } 

register_activation_hook( __FILE__, 'pushconnect_install' );

add_action( 'wp_loaded', 'pushconnect_init' );
add_action( 'transition_post_status',  'pushconnect_status_transitions', 10, 3 );
add_action( 'wp_enqueue_scripts', 'pushconnect_add_script' );

if ( is_admin() ) { 
  add_action( 'admin_menu', 'create_plugin_settings_page');
  add_action( 'admin_init', 'pushconnect_plugin_settings');
  add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'add_action_links' );
} else {    
  
  
}

function pushconnect_load_plugin_textdomain() {
    load_plugin_textdomain( 'pushconnect-web-push-messaging', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
}

add_action( 'plugins_loaded', 'pushconnect_load_plugin_textdomain' );

?>