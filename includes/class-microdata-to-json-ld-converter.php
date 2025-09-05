<?php
/**
 * Main plugin class.
 * @version 1.6
 *
 * v1.6 - Resolved an unintended consequence of the previous update in which attributes with content of "0" was voided. Implemented a smarter parsing logic.
 * v1.5.6 - corrected logic for to address Object vs. Array Confusion with some attributes.
 * v1.5.5 - Added a warning message for the "Remove Inline Microdata from HTML" setting.
 * - "Enabling this may conflict with server-side caching systems (e.g., Varnish). If you use a managed host with advanced caching, please test this feature carefully."  
 * - This option, while effective, may prevent some server-side caching systems from serving cached pages. 
 * - If you are on a managed WordPress host that uses this type of caching, test this feature to ensure there are no conflicts or keep it disabled if you notice issues with your site's cache.
 * v1.5.4 - Security and WordPress Standards Update.
 * - Refactored to use admin_enqueue_scripts for all CSS/JS.
 * - Added sanitization for nonce verification.
 * - Implemented recursive sanitization for all incoming JSON data.
 * - Replaced direct PHP variables in JS with wp_localize_script for improved security.
 * v1.5.3 - Fixed a bug where the scheduler would not process Media (attachments) due to incorrect post_status.
 * v1.5.2 - Added a log to display the results of the last completed scheduled rebuild.
 * v1.5.1 - Improved scheduler status feedback to prevent "false negative" on save.
 * v1.5.0 - Added a WP-Cron based scheduler for automatic background rebuilding of JSON-LD.
 */
class Microdata_To_JSON_LD_Converter {

	protected static $_instance = null;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_post_meta' ) );
		add_action( 'wp_head', array( $this, 'output_json_ld' ) );
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		
		add_action( 'wp_ajax_mdtj_generate_json', array( $this, 'ajax_generate_json_ld' ) );
		add_action( 'wp_ajax_mdtj_bulk_rebuild', array( $this, 'ajax_bulk_rebuild' ) );
		add_action( 'wp_ajax_mdtj_validate_schema', array( $this, 'ajax_validate_schema' ) );

		if ( get_option( 'mdtj_remove_microdata' ) ) {
			add_action('template_redirect', array($this, 'start_buffer'));
		}
		
		// --- SCHEDULER HOOKS ---
		add_action( 'mdtj_cron_rebuild_initiator', array( $this, 'run_scheduled_rebuild' ) );
		add_action( 'mdtj_cron_rebuild_worker', array( $this, 'run_rebuild_batch' ) );
		add_action( 'update_option_mdtj_scheduler_settings', array( $this, 'handle_schedule_update' ), 10, 2 );

	}

	public function start_buffer() {
		$mdtj_preview = isset( $_GET['mdtj_preview'] ) ? sanitize_key( $_GET['mdtj_preview'] ) : '';

		if ( is_singular() && get_option('mdtj_remove_microdata') && 'true' !== $mdtj_preview ) {
			ob_start(array($this, 'process_html_buffer'));
		}
	}

	public function save_post_meta( $post_id ) {
		// UPDATED: Added sanitization to nonce verification.
		if ( ! isset( $_POST['mdtj_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mdtj_meta_box_nonce'] ) ), 'mdtj_save_meta_box_data' ) ) {
			return;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( wp_is_post_revision( $post_id ) ) return;

		if ( get_option( 'mdtj_regenerate_on_update' ) ) {
			remove_action( 'save_post', array( $this, 'save_post_meta' ), 10 );
			$this->generate_for_post( $post_id, false );
			add_action( 'save_post', array( $this, 'save_post_meta' ), 10 );
			return;
		}

		if ( isset( $_POST['mdtj_json_ld'] ) ) {
			$json_string = wp_unslash( $_POST['mdtj_json_ld'] );
			if ( empty( trim( $json_string ) ) ) {
				delete_post_meta($post_id, '_mdtj_json_ld');
				return;
			}
			
			$decoded_json = json_decode( $json_string );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				// UPDATED: Sanitize the decoded JSON data before saving.
				$sanitized_data = $this->sanitize_json_recursively( $decoded_json );
				$clean_json = wp_json_encode( $sanitized_data, JSON_UNESCAPED_UNICODE );
				update_post_meta( $post_id, '_mdtj_json_ld', $clean_json );
			}
		}
	}

	private function generate_for_post($post_id, $send_json_response = false) {
		$url = get_permalink($post_id);
		if ( !$url ) {
			$result = array('success' => false, 'message' => 'Could not get permalink.');
			if ($send_json_response) wp_send_json_error( array( 'message' => $result['message'] ) );
			return $result;
		}

		$fetch_url = add_query_arg('mdtj_preview', 'true', $url);

		$response = wp_remote_get($fetch_url, array('sslverify' => false, 'timeout' => 30));
		if( is_wp_error( $response ) ) {
			$result = array('success' => false, 'message' => 'Failed to fetch page: ' . $response->get_error_message());
			if ($send_json_response) wp_send_json_error( array( 'message' => $result['message'] ) );
			return $result;
		}

		$html = wp_remote_retrieve_body($response);
		if ( empty($html) ) {
			$result = array('success' => false, 'message' => 'Fetched page content is empty.');
			if ($send_json_response) wp_send_json_error( array( 'message' => $result['message'] ) );
			return $result;
		}

		$json_array = $this->generate_json_from_html( $html );
		if ( !empty($json_array) ) {
			$json_string = wp_json_encode($json_array, JSON_UNESCAPED_UNICODE);
			update_post_meta($post_id, '_mdtj_json_ld', $json_string);
			$pretty_json = wp_json_encode($json_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$result = array('success' => true, 'message' => 'Success');
			if ($send_json_response) wp_send_json_success( $pretty_json );
			return $result;
		} else {
			delete_post_meta($post_id, '_mdtj_json_ld');
			$result = array('success' => false, 'message' => 'No microdata found.');
			if ($send_json_response) wp_send_json_error( array( 'message' => $result['message'] ) );
			return $result;
		}
	}

	public function ajax_generate_json_ld() {
		check_ajax_referer( 'mdtj_generate_json_nonce' );
		$post_id = isset($_POST['post_id']) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied or invalid post.' ) );
		}
		$this->generate_for_post($post_id, true);
	}

	public function ajax_validate_schema() {
		check_ajax_referer('mdtj_validate_schema_nonce');
		if (!current_user_can('edit_posts')) {
			wp_send_json_error(['message' => 'Permission denied.']);
		}
		
		$json_string = isset($_POST['json_ld']) ? wp_unslash($_POST['json_ld']) : '';
		$data = json_decode($json_string, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			wp_send_json_error(['message' => 'Invalid JSON syntax: ' . json_last_error_msg()]);
		}
		
		// UPDATED: Sanitize data before sending it to the validator.
		$sanitized_data = $this->sanitize_json_recursively( $data );
		$validator = new MDTJ_Schema_Validator( $sanitized_data );
		$results = $validator->validate();

		$html = '<h4>' . esc_html__('Validation Results', 'microdata-to-json-ld-converter') . '</h4>';
		if (empty($results)) {
			$html .= '<p style="color:green;">' . esc_html__('No issues found based on built-in best practices!', 'microdata-to-json-ld-converter') . '</p>';
		} else {
			$html .= '<ul>';
			foreach ($results as $result) {
				$color = $result['level'] === 'warning' ? 'orange' : 'blue';
				$html .= "<li><strong style='color:{$color};'>" . ucfirst($result['level']) . ":</strong> " . esc_html($result['message']) . "</li>";
			}
			$html .= '</ul>';
		}
		$html .= '<p><em>' . esc_html__('This is a basic check. For a complete analysis, use the "Test on Google" button.', 'microdata-to-json-ld-converter') . '</em></p>';
		wp_send_json_success(['html' => $html]);
	}
	
	public function ajax_bulk_rebuild() {
		check_ajax_referer( 'mdtj_bulk_rebuild_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		
		$post_types_raw = isset($_POST['post_types']) ? (array) wp_unslash( $_POST['post_types'] ) : array();
		$post_types = array_map( 'sanitize_text_field', $post_types_raw );
		
		$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
		$batch_size = 10;
		$args = array( 'post_type' => $post_types, 'posts_per_page' => $batch_size, 'offset' => $offset, 'post_status' => 'publish', );
		$query = new WP_Query($args);
		if ( !$query->have_posts() ) {
			wp_send_json_success( array( 'done' => true, 'message' => '<strong>' . esc_html__('Bulk rebuild complete!', 'microdata-to-json-ld-converter') . '</strong>' ) );
		}
		
		$processed_posts = array();
		foreach ($query->posts as $post) {
			$result = $this->generate_for_post($post->ID, false);
			$processed_posts[] = "<li>" . esc_html($post->post_title) . ": {$result['message']}</li>";
		}
		wp_send_json_success( array( 'done' => false, 'offset' => $offset + $query->post_count, 'total' => $query->found_posts, 'message' => '<ul>' . implode('', $processed_posts) . '</ul>' ));
	}

	public function add_options_page() { add_options_page( __( 'Microdata to JSON-LD Settings', 'microdata-to-json-ld-converter' ), __( 'Microdata to JSON-LD', 'microdata-to-json-ld-converter' ), 'manage_options', 'mdtj-settings', array( $this, 'render_options_page' ) ); }

	// UPDATED: Removed inline style and script tags. They are now in enqueue_admin_assets().
	public function render_options_page() { 
		?> 
		<div class="wrap mdtj-settings-wrap"> 
			<h1><?php esc_html_e( 'Microdata to JSON-LD Settings', 'microdata-to-json-ld-converter' ); ?></h1> 
			<h2 class="nav-tab-wrapper"> 
				<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General Settings', 'microdata-to-json-ld-converter' ); ?></a> 
				<a href="#bulk-rebuild" class="nav-tab"><?php esc_html_e( 'Bulk Rebuild Tools', 'microdata-to-json-ld-converter' ); ?></a> 
				<a href="#scheduler" class="nav-tab"><?php esc_html_e( 'Scheduler', 'microdata-to-json-ld-converter' ); ?></a> 
			</h2> 
			<form action="options.php" method="post"> 
				<?php settings_fields( 'mdtj_options_group' ); ?> 
				<div id="general" class="tab-content active"> <?php do_settings_sections( 'mdtj_general_settings' ); ?> </div> 
				<div id="bulk-rebuild" class="tab-content"> <?php do_settings_sections( 'mdtj_bulk_rebuild_settings' ); ?> </div> 
				<div id="scheduler" class="tab-content"> <?php do_settings_sections( 'mdtj_scheduler_settings' ); ?> </div> 
				<?php submit_button(); ?> 
			</form> 
		</div> 
		<?php 
	}
	
    public function register_settings() { 
		register_setting( 'mdtj_options_group', 'mdtj_create_json', 'boolval' ); 
		register_setting( 'mdtj_options_group', 'mdtj_remove_microdata', 'boolval' ); 
		register_setting( 'mdtj_options_group', 'mdtj_regenerate_on_update', 'boolval' );
		
		// General Settings
		add_settings_section('mdtj_general_section', null, null, 'mdtj_general_settings');
		add_settings_field('mdtj_create_json', __( 'Enable JSON-LD Output', 'microdata-to-json-ld-converter' ), array( $this, 'render_toggle_field' ), 'mdtj_general_settings', 'mdtj_general_section', [ 'option_name' => 'mdtj_create_json', 'desc' => __( 'When enabled, the plugin will output the saved JSON-LD in the <head> of your published posts and pages.', 'microdata-to-json-ld-converter' ) ]);
		add_settings_field('mdtj_remove_microdata', __( 'Remove Inline Microdata from HTML', 'microdata-to-json-ld-converter' ), array( $this, 'render_toggle_field' ), 'mdtj_general_settings', 'mdtj_general_section', [ 
			'option_name' => 'mdtj_remove_microdata', 
			'desc'        => __( 'When enabled, the plugin will remove the inline Microdata attributes from the final public-facing HTML.', 'microdata-to-json-ld-converter' ),
			'warning'     => __( 'Enabling this may conflict with server-side caching systems (e.g., Varnish). If you use a managed host with advanced caching, please test this feature carefully.', 'microdata-to-json-ld-converter' )
		]);
		add_settings_field('mdtj_regenerate_on_update', __( 'Keep JSON-LD up to date', 'microdata-to-json-ld-converter' ), array( $this, 'render_toggle_field' ), 'mdtj_general_settings', 'mdtj_general_section', [ 'option_name' => 'mdtj_regenerate_on_update', 'desc' => __( 'When enabled, the JSON-LD will be automatically regenerated every time a post is saved/updated. <strong>Warning:</strong> This will overwrite any manual edits in the meta box.', 'microdata-to-json-ld-converter' ) ]);
		
		// Bulk Rebuild Settings
		add_settings_section('mdtj_bulk_rebuild_section', __( 'Bulk Rebuild Tools', 'microdata-to-json-ld-converter' ), array($this, 'render_bulk_rebuild_section_text'), 'mdtj_bulk_rebuild_settings');
		add_settings_field('mdtj_bulk_rebuild_post_types', __( 'Select Post Types to Rebuild', 'microdata-to-json-ld-converter' ), array( $this, 'render_bulk_rebuild_field' ), 'mdtj_bulk_rebuild_settings', 'mdtj_bulk_rebuild_section');
		
		// Scheduler Settings
		register_setting( 'mdtj_options_group', 'mdtj_scheduler_settings', array( $this, 'sanitize_scheduler_settings' ) );
		add_settings_section('mdtj_scheduler_section', null, null, 'mdtj_scheduler_settings');
		add_settings_field('mdtj_scheduler_fields', __( 'Scheduled Rebuild Settings', 'microdata-to-json-ld-converter' ), array( $this, 'render_scheduler_fields' ), 'mdtj_scheduler_settings', 'mdtj_scheduler_section');
	}
	public function render_toggle_field($args) { 
		$option_name = $args['option_name']; 
		$description = $args['desc'] ?? ''; 
		$warning     = $args['warning'] ?? '';
		$option      = get_option($option_name); 

		echo '<label class="mdtj-toggle-switch">'; 
		echo '<input type="checkbox" name="' . esc_attr($option_name) . '" value="1" ' . checked(1, $option, false) . ' />'; 
		echo '<span class="mdtj-toggle-slider"></span>'; 
		echo '</label>'; 
		
		if ($description) { 
			echo '<p class="description">' . wp_kses_post($description) . '</p>'; 
		}
		if ($warning) {
			echo '<p class="description" style="color: #c83333;"><strong>' . esc_html__('Warning:', 'microdata-to-json-ld-converter') . '</strong> ' . wp_kses_post($warning) . '</p>';
		}
	}
	
    public function process_html_buffer($buffer) {
        if (get_option('mdtj_remove_microdata')) {
            $buffer = preg_replace('/<meta[^>]*\sitemprop\s*=\s*(["\'])(?:(?!\1).)*\1[^>]*\/?>/i', '', $buffer);
            $buffer = preg_replace( '/\s(itemprop|itemscope|itemtype)="[^"]*"/i', '', $buffer );
            $buffer = preg_replace( '/\s(itemprop|itemscope|itemtype)=\'[^\']*\'/i', '', $buffer );
            $buffer = str_replace( ' itemscope', '', $buffer );
        }
        return $buffer;
    }

	public function output_json_ld() { if ( is_singular() && get_option( 'mdtj_create_json' ) ) { $post_id = get_queried_object_id(); $json_ld_string = get_post_meta( $post_id, '_mdtj_json_ld', true ); if ( ! empty( $json_ld_string ) ) { $json_ld_data = json_decode( $json_ld_string, true ); if ( json_last_error() === JSON_ERROR_NONE ) { if ( ! isset( $json_ld_data['@context'] ) ) { $json_ld_data = array('@context' => 'https://schema.org') + $json_ld_data; } echo '<script type="application/ld+json">' . wp_json_encode($json_ld_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"; } } } }
	private function parse_item( $element ) {
		$item = array('@type' => preg_replace( '#https?://schema.org/?#', '', $element->getAttribute( 'itemtype' )));
		$properties = $this->get_child_properties( $element );
		foreach ( $properties as $prop_element ) {
			$prop_names_string = $prop_element->getAttribute( 'itemprop' );
			$prop_names = preg_split('/\s+/', $prop_names_string, -1, PREG_SPLIT_NO_EMPTY);
			$prop_value = $this->get_property_value( $prop_element );
			foreach ($prop_names as $prop_name) {
				// This is the final corrected condition.
				if ( ! empty( $prop_name ) && (is_array($prop_value) || trim((string)$prop_value) !== '') ) {
					if ( isset( $item[ $prop_name ] ) ) {
						// This is the robust check for creating a list.
						if ( ! is_array( $item[ $prop_name ] ) || ! isset( $item[ $prop_name ][0] ) ) {
							// If it's not a list, wrap the existing value in an array to create a new list.
							$item[ $prop_name ] = array( $item[ $prop_name ] );
						}
						// Now that we are certain $item[$prop_name] is a list, append the new value.
						$item[ $prop_name ][] = $prop_value;
					} else {
						// If the property doesn't exist yet, just assign the value.
						$item[ $prop_name ] = $prop_value;
					}
				}
			}
		}
		return $item;
	}
	public function add_meta_box() { add_meta_box( 'mdtj-json-ld-meta-box', __( 'Schema.org JSON-LD', 'microdata-to-json-ld-converter' ), array( $this, 'render_meta_box' ), null, 'advanced', 'high' ); }
	
	public function render_meta_box( $post ) { 
		wp_nonce_field( 'mdtj_save_meta_box_data', 'mdtj_meta_box_nonce' ); 
		$json_ld = get_post_meta( $post->ID, '_mdtj_json_ld', true ); 
		$decoded = json_decode($json_ld); 
		if (json_last_error() === JSON_ERROR_NONE) { 
			$json_ld = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); 
		} 
		echo '<p>' . esc_html__( 'The JSON-LD generated from the Microdata on this page. You can manually edit it here.', 'microdata-to-json-ld-converter' ) . '</p>'; 
		echo '<textarea style="width:100%; min-height: 250px; font-family: monospace;" id="mdtj_json_ld" name="mdtj_json_ld">' . esc_textarea( $json_ld ) . '</textarea>'; 
		echo '<div style="margin-top: 10px; display: flex; align-items: center; gap: 10px;">'; 
		echo '<button type="button" class="button" id="mdtj-regenerate-json">' . esc_html__( 'Regenerate', 'microdata-to-json-ld-converter' ) . '</button>'; 
		echo '<button type="button" class="button button-secondary" id="mdtj-validate-json">' . esc_html__( 'Validate', 'microdata-to-json-ld-converter' ) . '</button>'; 
		$rich_results_url = 'https://search.google.com/test/rich-results?url=' . urlencode(get_permalink($post->ID)); 
		echo '<a href="' . esc_url($rich_results_url) . '" target="_blank" class="button button-secondary">' . esc_html__( 'Test on Google', 'microdata-to-json-ld-converter' ) . '</a>'; 
		echo '</div>'; 
		echo '<div id="mdtj-validation-results" style="display:none; padding: 10px; border: 1px solid #ccc; margin-top: 10px; background-color: #fafafa; font-family: monospace; font-size: 12px;"></div>'; 
	}
	public function generate_json_from_html( $html ) { if ( ! class_exists( 'DOMDocument' ) ) return array(); libxml_use_internal_errors(true); $dom = new DOMDocument(); $dom->loadHTML( mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOWARNING | LIBXML_NOERROR ); libxml_clear_errors(); $xpath = new DOMXPath( $dom ); $items = array(); $item_nodes = $xpath->query( '//*[@itemscope and not(@itemprop)]' ); foreach ( $item_nodes as $item_node ) $items[] = $this->parse_item( $item_node ); if(empty($items)) return array(); if(count($items) === 1) return array_merge(array('@context' => 'https://schema.org'), $items[0]); return array('@context' => 'https://schema.org', '@graph' => $items); }
	private function get_child_properties( $element ) { $properties = array(); $queue = new SplQueue(); foreach ($element->childNodes as $child) $queue->enqueue($child); while (!$queue->isEmpty()) { $node = $queue->dequeue(); if ($node instanceof DOMElement) { if ($node->hasAttribute('itemprop')) $properties[] = $node; if (!$node->hasAttribute('itemscope')) { foreach ($node->childNodes as $child) $queue->enqueue($child); } } } return $properties; }
	private function get_property_value( $element ) { if ( $element->hasAttribute( 'itemscope' ) ) return $this->parse_item( $element ); $tag = strtoupper( $element->tagName ); if ( in_array($tag, array('A', 'LINK')) ) return $element->getAttribute( 'href' ); if ( in_array($tag, array('IMG', 'VIDEO', 'AUDIO', 'SOURCE')) ) return $element->getAttribute( 'src' ); if ( $tag == 'META' ) return $element->getAttribute( 'content' ); if ( $tag == 'TIME' ) return $element->getAttribute( 'datetime' ); if ( in_array($tag, array('DATA', 'INPUT')) ) return $element->getAttribute( 'value' ); return trim($element->textContent); }
	public function render_bulk_rebuild_section_text() { echo '<p>' . esc_html__( 'Use this tool to generate JSON-LD for all published items of the selected post types.', 'microdata-to-json-ld-converter' ) . '</p>'; }
	
	// UPDATED: Removed inline script tag. It is now in enqueue_admin_assets().
	public function render_bulk_rebuild_field() { 
		$post_types = get_post_types( array( 'public' => true ), 'objects' ); 
		foreach ( $post_types as $post_type ) { 
			echo '<label><input type="checkbox" class="mdtj_bulk_post_types" value="' . esc_attr( $post_type->name ) . '"> ' . esc_html( $post_type->label ) . '</label><br>'; 
		} 
		echo '<p><button type="button" class="button button-primary" id="mdtj-start-bulk-rebuild">' . esc_html__( 'Start Bulk Rebuild', 'microdata-to-json-ld-converter' ) . '</button></p>'; 
		echo '<div id="mdtj-bulk-progress-bar" style="display:none; width: 100%; background-color: #ddd;"><div id="mdtj-bulk-progress-bar-inner" style="width: 0%; height: 20px; background-color: #4CAF50; text-align: center; color: white;"></div></div>'; 
		echo '<div id="mdtj-bulk-rebuild-log" style="display:none; padding: 10px; border: 1px solid #ccc; margin-top: 10px; max-height: 300px; overflow-y: auto; background-color: #fafafa; font-family: monospace; font-size: 12px;"></div>'; 
	}

	// --- NEW METHODS ---
	
	/**
	 * NEW: Enqueues admin scripts and styles.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;
	
		// Only load on our settings page or on post edit screens.
		if ( 'settings_page_mdtj-settings' !== $hook && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
	
		// Styles for the settings page toggle switches.
		if ( 'settings_page_mdtj-settings' === $hook ) {
			$inline_styles = "
				.mdtj-settings-wrap .tab-content { display: none; padding-top: 1rem; }
				.mdtj-settings-wrap .tab-content.active { display: block; }
				.mdtj-settings-wrap .form-table th { width: 250px; }
				.mdtj-toggle-switch { position: relative; display: inline-block; width: 60px; height: 34px; }
				.mdtj-toggle-switch input { opacity: 0; width: 0; height: 0; }
				.mdtj-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
				.mdtj-toggle-slider:before { position: absolute; content: ''; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
				input:checked + .mdtj-toggle-slider { background-color: #2196F3; }
				input:checked + .mdtj-toggle-slider:before { transform: translateX(26px); }
			";
			wp_add_inline_style( 'wp-admin', $inline_styles );
	
			// Script for settings page tabs.
			$settings_script = "
				jQuery(document).ready(function($) {
					if (window.location.hash) {
						$('.nav-tab-wrapper a').removeClass('nav-tab-active');
						$('.nav-tab-wrapper a[href=\"' + window.location.hash + '\"]').addClass('nav-tab-active');
						$('.tab-content').removeClass('active');
						$(window.location.hash).addClass('active');
					}
					$('.nav-tab-wrapper a').on('click', function(e) {
						e.preventDefault();
						var target = $(this).attr('href');
						window.location.hash = target;
						$('.nav-tab-wrapper a').removeClass('nav-tab-active');
						$(this).addClass('nav-tab-active');
						$('.tab-content').removeClass('active');
						$(target).addClass('active');
					});
				});
			";
			wp_add_inline_script('jquery', $settings_script);
		}
	
		// Scripts for the Meta Box on post edit screens.
		if ( ('post.php' === $hook || 'post-new.php' === $hook) && is_object($post) ) {
			$meta_box_params = array(
				'post_id'       => $post->ID,
				'is_published'  => ($post->post_status === 'publish'),
				'generate_nonce' => wp_create_nonce('mdtj_generate_json_nonce'),
				'validate_nonce' => wp_create_nonce('mdtj_validate_schema_nonce'),
				'i18n'          => array(
					'confirm_regenerate' => __('Are you sure? This fetches the live URL and overwrites the text above.', 'microdata-to-json-ld-converter'),
					'generating'         => __('Generating...', 'microdata-to-json-ld-converter'),
					'regenerate'         => __('Regenerate', 'microdata-to-json-ld-converter'),
					'error'              => __('Error: ', 'microdata-to-json-ld-converter'),
					'nothing_to_validate' => __('Nothing to validate.', 'microdata-to-json-ld-converter'),
					'validating'         => __('Validating...', 'microdata-to-json-ld-converter'),
					'validate'           => __('Validate', 'microdata-to-json-ld-converter'),
					'checking'           => __('Checking...', 'microdata-to-json-ld-converter'),
				)
			);
			wp_localize_script('jquery', 'mdtj_meta_box', $meta_box_params);
			
			$meta_box_script = "
				jQuery(document).ready(function($) {
					function regenerateJson(isAuto) {
						if (!isAuto && !confirm(mdtj_meta_box.i18n.confirm_regenerate)) { return; }
						var btn = $('#mdtj-regenerate-json');
						btn.prop('disabled', true).text(mdtj_meta_box.i18n.generating);
						$.post(ajaxurl, {
							action: 'mdtj_generate_json',
							post_id: mdtj_meta_box.post_id,
							_ajax_nonce: mdtj_meta_box.generate_nonce
						}).done(function(res) {
							if (res.success) {
								$('#mdtj_json_ld').val(res.data);
								$('#mdtj-validation-results').hide().empty();
							} else {
								if (!isAuto) { alert(mdtj_meta_box.i18n.error + res.data.message); }
							}
						}).always(function() {
							btn.prop('disabled', false).text(mdtj_meta_box.i18n.regenerate);
						});
					}
		
					$('#mdtj-regenerate-json').on('click', function() { regenerateJson(false); });
		
					var jsonContent = $('#mdtj_json_ld').val().trim();
					if (jsonContent === '' && mdtj_meta_box.is_published) { regenerateJson(true); }
		
					$('#mdtj-validate-json').on('click', function() {
						var btn = $(this);
						var jsonContent = $('#mdtj_json_ld').val();
						if (!jsonContent.trim()) { alert(mdtj_meta_box.i18n.nothing_to_validate); return; }
						btn.prop('disabled', true).text(mdtj_meta_box.i18n.validating);
						$('#mdtj-validation-results').show().html('<em>' + mdtj_meta_box.i18n.checking + '</em>');
						$.post(ajaxurl, {
							action: 'mdtj_validate_schema',
							json_ld: jsonContent,
							_ajax_nonce: mdtj_meta_box.validate_nonce
						}).done(function(res) {
							if (res.success) { $('#mdtj-validation-results').html(res.data.html); } 
							else { $('#mdtj-validation-results').html('<p style=\"color:red;\">' + res.data.message + '</p>'); }
						}).always(function() {
							btn.prop('disabled', false).text(mdtj_meta_box.i18n.validate);
						});
					});
				});
			";
			wp_add_inline_script('jquery', $meta_box_script);
		}
		
		// Script for bulk rebuild on settings page.
		if ('settings_page_mdtj-settings' === $hook) {
			$bulk_rebuild_params = array(
				'rebuild_nonce' => wp_create_nonce('mdtj_bulk_rebuild_nonce'),
				'i18n'          => array(
					'select_post_type' => __('Please select at least one post type to rebuild.', 'microdata-to-json-ld-converter'),
					'confirm_rebuild'  => __('Are you sure you want to rebuild JSON-LD for all items in the selected post types? This will overwrite existing data and can take a long time.', 'microdata-to-json-ld-converter'),
					'complete'         => __('Complete!', 'microdata-to-json-ld-converter'),
					'ajax_error'       => __('A critical AJAX error occurred.', 'microdata-to-json-ld-converter'),
				)
			);
			wp_localize_script('jquery', 'mdtj_bulk_rebuild', $bulk_rebuild_params);
	
			$bulk_rebuild_script = "
				jQuery(document).ready(function($) {
					$('#mdtj-start-bulk-rebuild').on('click', function() {
						var button = $(this);
						var selectedPostTypes = $('.mdtj_bulk_post_types:checked').map(function() { return this.value; }).get();
						if (selectedPostTypes.length === 0) {
							alert(mdtj_bulk_rebuild.i18n.select_post_type);
							return;
						}
						if (!confirm(mdtj_bulk_rebuild.i18n.confirm_rebuild)) {
							return;
						}
						button.prop('disabled', true);
						$('#mdtj-bulk-rebuild-log').html('').show();
						$('#mdtj-bulk-progress-bar').show();
						$('#mdtj-bulk-progress-bar-inner').css('width', '0%').text('');
						processBatch(selectedPostTypes, 0);
					});
				
					function processBatch(postTypes, offset) {
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'mdtj_bulk_rebuild',
								_ajax_nonce: mdtj_bulk_rebuild.rebuild_nonce,
								post_types: postTypes,
								offset: offset
							},
							success: function(response) {
								if (response.success) {
									$('#mdtj-bulk-rebuild-log').append(response.data.message);
									if (response.data.done) {
										$('#mdtj-start-bulk-rebuild').prop('disabled', false);
										$('#mdtj-bulk-progress-bar-inner').css('width', '100%').text(mdtj_bulk_rebuild.i18n.complete);
									} else {
										var percent = (response.data.offset / response.data.total) * 100;
										$('#mdtj-bulk-progress-bar-inner').css('width', percent + '%').text(Math.round(percent) + '%');
										processBatch(postTypes, response.data.offset);
									}
								} else {
									$('#mdtj-bulk-rebuild-log').append('<p style=\"color:red;\">Error: ' + response.data.message + '</p>');
									$('#mdtj-start-bulk-rebuild').prop('disabled', false);
								}
							},
							error: function() {
								$('#mdtj-bulk-rebuild-log').append('<p style=\"color:red;\">' + mdtj_bulk_rebuild.i18n.ajax_error + '</p>');
								$('#mdtj-start-bulk-rebuild').prop('disabled', false);
							}
						});
					}
				});
			";
			wp_add_inline_script('jquery', $bulk_rebuild_script);
		}
	}
	
	/**
	 * NEW: Recursively sanitizes decoded JSON data.
	 */
	private function sanitize_json_recursively( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->sanitize_json_recursively( $value );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->sanitize_json_recursively( $value );
			}
		} elseif ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}
		return $data;
	}

	// --- SCHEDULER ---

	public function render_scheduler_fields() {
		$options = get_option('mdtj_scheduler_settings', array(
			'enabled' => false,
			'frequency' => 'daily',
			'time' => '02:00',
			'post_types' => array()
		));
		?>
		<p><?php esc_html_e( 'Use this tool to automatically rebuild JSON-LD for your content on a recurring schedule. This is useful for keeping schema up-to-date with site changes.', 'microdata-to-json-ld-converter' ); ?></p>
		
		<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Scheduler', 'microdata-to-json-ld-converter' ); ?></th>
					<td>
						<label class="mdtj-toggle-switch">
							<input type="checkbox" name="mdtj_scheduler_settings[enabled]" value="1" <?php checked( !empty($options['enabled']) ); ?> />
							<span class="mdtj-toggle-slider"></span>
						</label>
						<p class="description"><?php esc_html_e('Enable or disable the automatic rebuild scheduler.', 'microdata-to-json-ld-converter'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Frequency', 'microdata-to-json-ld-converter' ); ?></th>
					<td>
						<select name="mdtj_scheduler_settings[frequency]">
							<option value="daily" <?php selected( $options['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'microdata-to-json-ld-converter' ); ?></option>
							<option value="weekly" <?php selected( $options['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'microdata-to-json-ld-converter' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e('How often should the rebuild run?', 'microdata-to-json-ld-converter'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Time of Day', 'microdata-to-json-ld-converter' ); ?></th>
					<td>
						<select name="mdtj_scheduler_settings[time]">
							<?php for ( $i = 0; $i < 24; $i++ ) : 
								$time = sprintf( '%02d:00', $i );
							?>
								<option value="<?php echo esc_attr( $time ); ?>" <?php selected( $options['time'], $time ); ?>><?php echo esc_html( $time ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description"><?php esc_html_e('Select a low-traffic time to run the rebuild (uses your site\'s timezone).', 'microdata-to-json-ld-converter'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types to Rebuild', 'microdata-to-json-ld-converter' ); ?></th>
					<td>
						<?php
						$post_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $post_types as $post_type ) {
							$checked = in_array( $post_type->name, (array) $options['post_types'] );
							echo '<label><input type="checkbox" name="mdtj_scheduler_settings[post_types][]" value="' . esc_attr( $post_type->name ) . '" ' . checked( $checked, true, false ) . '> ' . esc_html( $post_type->label ) . '</label><br>';
						}
						?>
						<p class="description"><?php esc_html_e('Select the post types to be included in the scheduled rebuild.', 'microdata-to-json-ld-converter'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Scheduler Status', 'microdata-to-json-ld-converter' ); ?></th>
					<td>
						<?php
						$timestamp = wp_next_scheduled( 'mdtj_cron_rebuild_initiator' );
						if ( $timestamp ) {
							$gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
							$local_time = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp + $gmt_offset );
							// translators: %s: The formatted date and time of the next scheduled run.
							$message = sprintf( esc_html__( 'A rebuild is scheduled. Next run: %s', 'microdata-to-json-ld-converter' ), '<strong>' . esc_html( $local_time ) . '</strong>' );
							echo '<p style="color: green;">' . wp_kses( $message, array( 'strong' => array() ) ) . '</p>';
						} else {
                            if ( ! empty( $options['enabled'] ) ) {
                                echo '<p><em>' . esc_html__( 'Scheduling in process... Please save again or refresh to confirm.', 'microdata-to-json-ld-converter' ) . '</em></p>';
                            } else {
                                echo '<p style="color: red;">' . esc_html__( 'No rebuild is currently scheduled.', 'microdata-to-json-ld-converter' ) . '</p>';
                            }
						}

						$queue = get_transient( 'mdtj_rebuild_queue' );
						if ( $queue !== false ) {
							// translators: %d: The number of items remaining in the rebuild queue.
							echo '<p style="color: orange;">' . sprintf( esc_html__( 'A rebuild is currently in progress with %d items remaining.', 'microdata-to-json-ld-converter' ), count( $queue['ids'] ) ) . '</p>';
						}
						?>
					</td>
				</tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Last Run Details', 'microdata-to-json-ld-converter' ); ?></th>
                    <td>
                        <?php
                        $last_run_log = get_option('mdtj_last_scheduled_rebuild_log');
                        if ( ! empty( $last_run_log ) && is_array( $last_run_log ) ) {
                            $gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
                            $completion_time = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run_log['completion_time'] + $gmt_offset );
                            
                            $summary_parts = array();
                            $total_posts = 0;
                            if ( ! empty( $last_run_log['counts'] ) ) {
                                foreach( $last_run_log['counts'] as $pt_slug => $count ) {
                                    $post_type_obj = get_post_type_object($pt_slug);
                                    $pt_label = $post_type_obj ? $post_type_obj->label : $pt_slug;
                                    $summary_parts[] = sprintf( '%d %s', $count, esc_html($pt_label) );
                                    $total_posts += $count;
                                }
                            }
                            // translators: %s: The formatted date and time of the last completed rebuild.
							$message = sprintf( esc_html__( 'The last rebuild completed at %s.', 'microdata-to-json-ld-converter' ), '<strong>' . esc_html( $completion_time ) . '</strong>' );
							echo '<p>' . wp_kses( $message, array( 'strong' => array() ) ) . '</p>';

                            if ( ! empty( $summary_parts ) ) {
								echo '<p>' . sprintf(
									// translators: %s: A summary of the number of posts processed for each post type (e.g., "150 Posts, 34 Pages").
                                    esc_html__( 'Successfully rebuilt JSON-LD for %s.', 'microdata-to-json-ld-converter' ),
                                    esc_html( implode(', ', $summary_parts) )
                                ) . '</p>';
                            }

                        } else {
                            echo '<p><em>' . esc_html__('No scheduled rebuild has completed yet.', 'microdata-to-json-ld-converter') . '</em></p>';
                        }
                        ?>
                    </td>
                </tr>
			</tbody>
		</table>
		<?php
	}

	public function sanitize_scheduler_settings( $input ) {
		$sanitized_input = array();
		$sanitized_input['enabled'] = !empty( $input['enabled'] ) ? true : false;
		$sanitized_input['frequency'] = in_array( $input['frequency'], array( 'daily', 'weekly' ) ) ? $input['frequency'] : 'daily';
		$sanitized_input['time'] = preg_match( '/^([01]?[0-9]|2[0-3]):00$/', $input['time'] ) ? $input['time'] : '02:00';
		
		if ( !empty($input['post_types']) && is_array($input['post_types']) ) {
			$sanitized_input['post_types'] = array_map( 'sanitize_text_field', $input['post_types'] );
		} else {
			$sanitized_input['post_types'] = array();
		}

		return $sanitized_input;
	}

	public function handle_schedule_update( $old_value, $value ) {
		wp_clear_scheduled_hook( 'mdtj_cron_rebuild_initiator' );
		
		if ( !empty( $value['enabled'] ) ) {
			try {
				$time_string = $value['time'];
				$frequency   = $value['frequency'];
				
				$datetime = new DateTime( "today {$time_string}", wp_timezone() );
				
				if ( $datetime->getTimestamp() < time() ) {
					$datetime->modify('+1 day');
				}
				
				wp_schedule_event( $datetime->getTimestamp(), $frequency, 'mdtj_cron_rebuild_initiator' );
			} catch (Exception $e) {
				// Handle potential DateTime exceptions
				return;
			}
		}
	}

	public function run_scheduled_rebuild() {
		$options = get_option( 'mdtj_scheduler_settings' );

		if ( empty( $options['enabled'] ) || empty( $options['post_types'] ) ) {
			return;
		}

        $post_type_counts = array();
        $all_post_ids = array();

        foreach( $options['post_types'] as $post_type ) {
            $args = array(
                'post_type'      => $post_type,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            );
            $post_ids = get_posts( $args );
            if ( ! empty( $post_ids ) ) {
                $post_type_counts[$post_type] = count( $post_ids );
                $all_post_ids = array_merge( $all_post_ids, $post_ids );
            }
        }

		if ( ! empty( $all_post_ids ) ) {
            $queue_data = array(
                'ids' => $all_post_ids,
                'counts' => $post_type_counts,
            );
			set_transient( 'mdtj_rebuild_queue', $queue_data, DAY_IN_SECONDS );
			wp_schedule_single_event( time(), 'mdtj_cron_rebuild_worker' );
		}
	}
	
	public function run_rebuild_batch() {
		$queue_data = get_transient( 'mdtj_rebuild_queue' );

		if ( empty( $queue_data ) || !is_array( $queue_data ) || empty( $queue_data['ids'] ) ) {
			delete_transient( 'mdtj_rebuild_queue' );
			return;
		}

        $ids = $queue_data['ids'];
        $counts = $queue_data['counts'];

		$batch_size = 25;
		$batch = array_slice( $ids, 0, $batch_size );
		$remaining = array_slice( $ids, $batch_size );

		foreach( $batch as $post_id ) {
			$this->generate_for_post( $post_id, false );
		}

		if ( ! empty( $remaining ) ) {
            $queue_data['ids'] = $remaining;
			set_transient( 'mdtj_rebuild_queue', $queue_data, DAY_IN_SECONDS );
			wp_schedule_single_event( time() + 60, 'mdtj_cron_rebuild_worker' );
		} else {
			delete_transient( 'mdtj_rebuild_queue' );
            // Log the completion details
            $log_data = array(
                'completion_time' => time(),
                'counts' => $counts,
            );
            update_option( 'mdtj_last_scheduled_rebuild_log', $log_data );
		}
	}
}