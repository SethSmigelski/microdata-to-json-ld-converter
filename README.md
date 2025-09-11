# Microdata to JSON-LD Converter
A WordPress plugin to convert existing Schema.org Microdata into the preferred JSON-LD format, clean up your HTML, and maintain structured data.

![Microdata to JSON-LD Converter WordPress Plugin Icon](https://ps.w.org/microdata-to-json-ld-converter/assets/icon-256x256.png)

* **Contributors:** sethsm
* **Tags:** schema.org, Microdata, json-ld, seo, structured data
* **Requires at least:** 5.5
* **Tested up to:** 6.8
* **Stable tag:** 1.6.3
* **Requires PHP:** 7.2
* **License:** GPLv2 or later
* **License URI:** https://www.gnu.org/licenses/gpl-2.0.html
* **Donate link:** https://www.paypal.com/donate/?hosted_button_id=M3B2Q94PGVVWL
* **Plugin URI:** https://www.sethcreates.com/plugins-for-wordpress/microdata-to-json-ld-converter/
* **Author URI:** https://www.sethcreates.com/plugins-for-wordpress/

---

## Description

Is your WordPress theme or website built with inline Schema.org Microdata? As search engines increasingly prefer the JSON-LD format for structured data, updating your site can be a significant challenge. Manually removing old Microdata and creating new JSON-LD scripts for every page is tedious and prone to errors.

The **Microdata to JSON-LD Converter** solves this exact problem. This plugin seamlessly automates the entire conversion process:

1.  **Scans Your Pages:** It fetches the full HTML of your published posts and pages, ensuring it captures all Microdata, whether it’s in your post content or your theme files (like `header.php`).
2.  **Converts to JSON-LD:** It intelligently parses the `itemscope`, `itemtype`, and `itemprop` attributes and converts them into a well-structured JSON-LD script.
3.  **Removes Inline Microdata:** Optionally, it can clean up your public-facing HTML by removing the now-redundant Microdata attributes, leaving only the clean JSON-LD script in the `<head>`.
4.  **Keeps It Fresh:** With the "Keep up to date" option, the plugin can automatically regenerate the JSON-LD every time you update a post, ensuring your structured data always reflects your latest content.

### Key Features:

* **Full Page Parsing:** Accurately reads Microdata from the entire webpage, not just post content.
* **Automatic Generation:** Automatically creates the initial JSON-LD when you open a published post for the first time.
* **Manual Control:** View and edit the generated JSON-LD directly in a meta box on each post’s edit screen.
* **Built-in Validator:** Get instant feedback on your schema with a built-in validator that checks for common required and recommended properties.
* **Bulk Rebuild Tool:** Process your entire site at once with a batch-processing tool that works through all your posts and pages.
* **"Set It and Forget It" Mode:** Enable the "Keep JSON-LD up to date" option to ensure your structured data remains current.
* **Clean & Modern UI:** A simple, intuitive settings page with toggle switches and tabs makes configuration a breeze.

This plugin offers a seamless migration path for modernizing your site’s SEO and structured data implementation, eliminating the need to edit your content, plugins, and theme files.

---

![Microdata to JSON-LD Converter WordPress Plugin Banner](https://ps.w.org/microdata-to-json-ld-converter/assets/banner-1544x500.png)

## Installation

### From the WordPress Plugin Directory File
1.  Log in to your WordPress Admin Dashboard.
2.  Navigate to Plugins > Add Plugin in the left-hand menu.
3.  Search for the plugin: Microdata to JSON-LD Converter.
4.  Install the plugin: Once you locate the [correct plugin](https://wordpress.org/plugins/microdata-to-json-ld-converter/), click the "Install Now" button next to it.
5.  Activate the plugin: After the installation is complete, click the "Activate Plugin" button that appears.

### From a Zip File
1.  Download a copy of the plugin, available in the WordPress Plugin Directory [Microdata to JSON-LD Converter](https://wordpress.org/plugins/microdata-to-json-ld-converter/) webpage. 
2.  Upload the `microdata-to-json-ld-converter` folder to the `/wp-content/plugins/` directory.
3.  Activate the plugin through the 'Plugins' menu in WordPress.
4.  Navigate to `Settings > Microdata to JSON-LD` to configure the plugin.
5.  Enable the desired options, such as "Enable JSON-LD Output" and "Remove Inline Microdata from HTML".
6.  Optionally, use the "Bulk Rebuild Tools" tab to process all your existing content.

---

## Frequently Asked Questions

### How do I generate the JSON-LD for my posts?

You have two main options:
1.  **Automatically:** For a single post, simply open the post editor. If the post is published and no JSON-LD exists, the plugin will automatically generate it. For all posts, use the "Bulk Rebuild Tools" on the plugin’s settings page.
2.  **Manually:** In the post editor, you can click the "Regenerate" button inside the "Schema.org JSON-LD" meta box at any time.

### What does the "Keep JSON-LD up to date" option do?

When this option is enabled, the plugin will automatically regenerate the JSON-LD every single time you click “Update” on a post. This ensures your structured data always matches your content, but it will overwrite any manual changes you’ve made in the JSON-LD text box. If you plan to manually edit your JSON-LD, you should leave this option turned off.

### Will this remove Microdata from my theme files?

The "Remove Inline Microdata from HTML" option removes the Microdata attributes from the final HTML that is sent to the user’s browser. It does **not** edit or delete your actual PHP theme files. The original Microdata will still be in your files, but it will be invisible on the front end.

### Does the 'Remove Inline Microdata' feature work with caching?

The "Remove Inline Microdata from HTML" option works by capturing the entire page output after it has been generated and before it is sent to the browser. This method, while effective, may prevent some server-side caching systems (like Varnish or NGINX FastCGI Cache) from serving cached pages. This is because the output buffer signals that the content is being dynamically modified. If you are on a managed WordPress host that utilizes this type of caching, you should test this feature to ensure there are no conflicts. Alternatively, you can keep it disabled if you notice issues with your site’s cache.

### Can I check if my generated JSON-LD is valid?

Yes. In the "Schema.org JSON-LD" meta box, you have two options. The "Validate" button runs a check against a built-in list of best practices for common schema types. For a complete, official analysis, use the "Test on Google" button to open the page in Google’s Rich Results Test.

### How is this plugin connected to the Microdata Refinement Department at LUMON?

This plugin bears no relation whatsoever to LUMON or the MDR Department on the Severed Floor. The "Microdata" processed by this plugin is a structured data markup outlined by schema.org, which has fallen out of favor and has largely been replaced by the JSON-LD format (hence, why this converter exists).

### What is the recommended implementation of this plugin?

Use the Microdata to JSON-LD Converter in 5 easy steps.

1.  Open the post editor, scroll down to the box titled “Schema.org JSON-LD.” Verify that the created JSON-LD matches your existing schema.org content.
2.  After you have reviewed the “Schema.org JSON-LD” meta boxes on multiple posts and pages, use the Bulk Rebuild Tools to create JSON-LD meta for each post and page on your website. This build process will take several minutes for larger sites.
3.  Within the General Settings of the Microdata to JSON-LD Settings, toggle on “Enable JSON-LD Output.” Doing this will add the JSON-LD to the head element of your webpages. The content within the “Schema.org JSON-LD” meta boxes is now being added to your published posts and pages.
4.  Within the General Settings of the Microdata to JSON-LD Settings, toggle on “Remove Inline Microdata from HTML.” Doing this will strip the Microdata from the HTML output of your webpages. Although this plugin removes the inline Microdata attributes from the final public-facing HTML, it will not affect your backend theme files, plugins, and content where the Microdata markups originate.

    *Note: To ensure that the inline Microdata does not serve as a duplicate of the new JSON-LD, both “Enable JSON-LD Output” and “Remove Inline Microdata from HTML” should be enabled in unison.*

5.  To keep your JSON-LD updated going forward, toggle the option “keep JSON-LD up to date.” This will ensure that your post’s JSON-LD information is updated when you make changes to your posts and pages. If you have, or plan to, make manual edits within the JSON-LD meta boxes, keep this option turned off. However, in most cases, it will be helpful to automate keeping the JSON-LD up to date.

---

## Screenshots

![Microdata to JSON-LD Converter WordPress Plugin Settings Page](https://ps.w.org/microdata-to-json-ld-converter/assets/screenshot-1.png)
The clean, tabbed settings page for Microdata to JSON-LD Converter showing the modern toggle switches for the main options.

![Microdata to JSON-LD Converter WordPress Plugin Bulk Rebuild Tools and progress bar.](https://ps.w.org/microdata-to-json-ld-converter/assets/screenshot-2.png)
The "Bulk Rebuild Tools" tab, showing the post type selection and progress bar.

![Microdata to JSON-LD Converter WordPress Plugin generated JSON-LD in post editor meta box](https://ps.w.org/microdata-to-json-ld-converter/assets/screenshot-3.png)
The meta box in the post editor, showing the generated JSON-LD.

![Microdata to JSON-LD Converter WordPress Plugin validation results](https://ps.w.org/microdata-to-json-ld-converter/assets/screenshot-4.png)
The meta box’s validation results after clicking the "Validate" button.

---

## Changelog

### 1.6.3
* **Fix:** Added package.json file and simplified deploy.yml file in GitHub for WordPress.org Plugin Deploy Action.

### 1.6
* **Fix:** Resolved an unintended consequence of the previous update in which attributes with content of "0" was voided. Implemented a smarter parsing logic.

### 1.5.6
* **Fix:** Corrected logic for to address Object vs. Array Confusion with some attributes

### 1.5.5 
* **Improvement:** Added a settings page warning message for the "Remove Inline Microdata from HTML" option. The warning message, along with an added readme FAQ question, describes how this option may conflict with server-side caching systems. Users, especially those with managed hosting and advanced caching, are advised to test this feature carefully to ensure there are no conflicts. The recommendation is for users to keep this feature disabled if it creates caching issues.

### 1.5.4 
* **Security:** Performed a Security and WordPress Standards Update. Refactored to use admin_enqueue_scripts for all CSS/JS. Added sanitization for nonce verification. Implemented recursive sanitization for all incoming JSON data.

### 1.5.3
* **Fix:** Fixed a bug where the scheduler would not process Media (attachments) due to incorrect post_status.

### 1.5.2
* **Improvement:** Added a log to display the results of the last completed scheduled rebuild.

### 1.5.1 
* **Improvement:** Scheduler status provides better feedback to prevent "false negatives" on save.

### 1.5.0 
* **Improvement:** Added a WP-Cron-based scheduler for automatic background rebuilding of JSON-LD to better handle dynamic content.

### 1.4.8 
* **Improvement:** Modified process_html_buffer() to completely remove meta tags containing itemprop attributes.

### 1.4.7
* **Security:** Updated Direct Nonce Verification in the save_post_meta. Added explanatory comments for the warnings about sanitizing $_POST variables in the AJAX functions

### 1.4.6
* **Security:** Updated Sanitization Flow to address InputNotSanitized security warnings and added explanatory comments to start_buffer explaining why sanitize_key() is the appropriate and secure method for this specific, low-risk check.

### 1.4.5
* **Security:** Made additional security updates via Explicit Nonce Escaping and Sanitization Best Practices

### 1.4.4
* **Security:** Applied Nonce Escaping, Nonce Verification, and Input Sanitization for best-practice fixes and security hardening

### 1.4.3
* **Fix:**  Addressed text domain declaration issue within plugin files, changing the text domain inside the code from 'mdtj' to 'microdata-to-json-ld-converter'.

= 1.4.2 =
* **Fix:** Add query parameter to URLs when the plugin fetches pages for regeneration, avoiding a recursive problem discovered while fetching Microdata when "Remove Inline Microdata" is active.

### 1.4.1
* **Improvement:** Refined the settings page UI with clearer descriptions for each option and a more intuitive title for the auto-update feature.

### 1.4.0
* **Feature:** Redesigned the settings page with a modern, tabbed UI and interactive toggle switches.
* **Feature:** Significantly expanded the built-in schema validator to include rules for Event, FAQPage, VideoObject, and more detailed checks for Offer properties.

### 1.3.5
* **Fix:** The "Remove Inline Microdata" function now correctly strips leftover standalone `itemscope` attributes for cleaner HTML output.

### 1.3.4
* **Feature:** Added a new "Keep JSON-LD up to date" option to automatically regenerate the JSON-LD every time a post is saved.

### 1.3.3
* **Feature:** The JSON-LD is now automatically generated the first time a user opens the editor for a published post if the field is empty. Improves workflow.

### 1.3.2
* **Fix:** Corrected a double-encoding issue with special characters. Unicode characters like `→` and `·` are now saved correctly to the database and render properly in the final front-end script.

### 1.3.1
* **Fix:** The parser now correctly handles space-separated `itemprop` attributes (e.g., `itemprop="caption description"`) by splitting them into two distinct properties.
* **Fix:** Final JSON-LD script now uses `JSON_UNESCAPED_UNICODE` to ensure special characters display correctly in all validators.

### 1.3.0
* **Feature:** Added a "Validate" button to the meta box for on-demand checks against schema best practices.
* **Feature:** Added a "Test on Google" button to the meta box for easy one-click validation in the Rich Results Test.
* **Refactor:** Moved main plugin class and new validator class into an `/includes` directory for better organization.

### 1.2.0
* **Feature:** Implemented a Bulk Rebuild tool on the settings page with a progress bar to process all posts.
* **Improvement:** The regeneration process now fetches the full, live HTML of a page, ensuring Microdata from theme files is parsed.

### 1.1.0
* **Improvement:** The "Remove Microdata" option now uses an output buffer to process the entire page, not just `the_content`, for more comprehensive removal.
* **Improvement:** Added JSON validation and pretty-printing when saving data from the meta box.
* **Fix:** Ensured `@context` is always present in the generated JSON-LD.

### 1.0.0
* Initial release.

## Upgrade Notice

### 1.4.1
This version includes UI text improvements for better clarity on the settings page.

### 1.4.0
This version introduces a redesigned settings page and a more powerful schema validator.
