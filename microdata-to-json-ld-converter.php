<?php
/**
 * Plugin Name: Microdata to JSON-LD Converter
 * Plugin URI:  https://www.sethcreates.com/plugins-for-wordpress/microdata-to-json-ld-converter/
 * Description: Converts Microdata to JSON-LD, validates it against best practices, and optionally removes the original markup. Includes a bulk rebuild tool.
 * Version:     1.7.1
 * Author:      Seth Smigelski
 * Author URI:  https://www.sethcreates.com/plugins-for-wordpress/
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: microdata-to-json-ld-converter
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// 1. Define plugin constants
define( 'MDTJ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
//define( 'MDTJ_PLUGIN_VERSION', '1.7' );

// 2. Require the class files
require_once MDTJ_PLUGIN_DIR . 'includes/class-microdata-to-json-ld-converter.php';
require_once MDTJ_PLUGIN_DIR . 'includes/class-mdtj-schema-validator.php';

/**
 * Begins execution of the plugin.
 */
function mdtj_run() {
	return Microdata_To_JSON_LD_Converter::instance();
}
// Run the plugin
mdtj_run();
