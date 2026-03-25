<?php
/**
 * Plugin Name: Jesuspended AI Alt Text
 * Plugin URI:  https://github.com/aliameenco-creator/wordpress-pro-plugin
 * Description: Automatically generate alt text for images using Google's Gemini API with a single click. Supports custom niche/industry context for better SEO-optimized alt text.
 * Version:     1.3.0
 * Author:      Ali Ameen
 * Author URI:  https://github.com/aliameenco-creator
 * License:     GPL-2.0+
 * Text Domain: jesuspended-alt-text-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AI_ALT_TEXT_VERSION', '1.3.0' );
define( 'AI_ALT_TEXT_FILE', __FILE__ );
define( 'AI_ALT_TEXT_DIR', plugin_dir_path( __FILE__ ) );

// Load the GitHub updater
require_once AI_ALT_TEXT_DIR . 'includes/class-github-updater.php';

class AI_Alt_Text_Generator {

    private $option_api_key   = 'ai_alt_text_api_key';
    private $option_niche     = 'ai_alt_text_niche';
    private $option_custom_prompt = 'ai_alt_text_custom_prompt';
    private $option_language   = 'ai_alt_text_language';
    private $option_tone       = 'ai_alt_text_tone';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Add generate button to attachment edit fields
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_generate_button_to_attachment' ), 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_ai_generate_alt_text', array( $this, 'ajax_generate_alt_text' ) );
        add_action( 'wp_ajax_ai_generate_alt_text_bulk', array( $this, 'ajax_generate_alt_text_bulk' ) );

        // Initialize GitHub updater
        new AI_Alt_Text_GitHub_Updater(
            AI_ALT_TEXT_FILE,
            'aliameenco-creator',
            'wordpress-pro-plugin'
        );
    }

    /**
     * Add settings page under Settings menu.
     */
    public function add_settings_page() {
        add_options_page(
            __( 'AI Alt Text Generator', 'jesuspended-alt-text-ai' ),
            __( 'AI Alt Text', 'jesuspended-alt-text-ai' ),
            'manage_options',
            'jesuspended-alt-text-ai',
            array( $this, 'render_settings_page' )
        );

        add_media_page(
            __( 'Bulk Generate Alt Text', 'jesuspended-alt-text-ai' ),
            __( 'Bulk Alt Text AI', 'jesuspended-alt-text-ai' ),
            'upload_files',
            'ai-alt-text-bulk',
            array( $this, 'render_bulk_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting( 'ai_alt_text_settings', $this->option_api_key, array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'ai_alt_text_settings', $this->option_niche, array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'ai_alt_text_settings', $this->option_custom_prompt, array(
            'sanitize_callback' => 'sanitize_textarea_field',
        ) );
        register_setting( 'ai_alt_text_settings', $this->option_language, array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
        register_setting( 'ai_alt_text_settings', $this->option_tone, array(
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    /**
     * Enqueue admin scripts and styles.
     */
    public function enqueue_admin_assets( $hook ) {
        $allowed = array( 'post.php', 'post-new.php', 'upload.php', 'media_page_ai-alt-text-bulk', 'settings_page_ai-alt-text-generator' );
        if ( ! in_array( $hook, $allowed, true ) && strpos( $hook, 'ai-alt-text' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'ai-alt-text-admin',
            plugin_dir_url( __FILE__ ) . 'admin.js',
            array( 'jquery' ),
            AI_ALT_TEXT_VERSION,
            true
        );

        wp_localize_script( 'ai-alt-text-admin', 'aiAltText', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ai_alt_text_nonce' ),
        ) );

        wp_enqueue_style(
            'ai-alt-text-admin-css',
            plugin_dir_url( __FILE__ ) . 'admin.css',
            array(),
            AI_ALT_TEXT_VERSION
        );
    }

    /**
     * Add a "Generate Alt Text" button in the media edit screen.
     */
    public function add_generate_button_to_attachment( $form_fields, $post ) {
        if ( ! wp_attachment_is_image( $post->ID ) ) {
            return $form_fields;
        }

        $form_fields['ai_generate_alt'] = array(
            'label' => '',
            'input' => 'html',
            'html'  => sprintf(
                '<button type="button" class="button ai-generate-alt-btn" data-attachment-id="%d">%s</button>
                 <span class="ai-alt-spinner spinner" style="float:none;"></span>',
                esc_attr( $post->ID ),
                esc_html__( '🤖 Generate Alt Text with AI', 'jesuspended-alt-text-ai' )
            ),
        );

        return $form_fields;
    }

    /**
     * AJAX: Generate alt text for a single image.
     */
    public function ajax_generate_alt_text() {
        check_ajax_referer( 'ai_alt_text_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'jesuspended-alt-text-ai' ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            wp_send_json_error( __( 'Invalid image.', 'jesuspended-alt-text-ai' ) );
        }

        $result = $this->generate_alt_text_for_image( $attachment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'alt_text'      => $result,
            'attachment_id' => $attachment_id,
        ) );
    }

    /**
     * AJAX: Bulk generate alt text.
     */
    public function ajax_generate_alt_text_bulk() {
        check_ajax_referer( 'ai_alt_text_nonce', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'jesuspended-alt-text-ai' ) );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();
        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( __( 'No images selected.', 'jesuspended-alt-text-ai' ) );
        }

        $results = array();
        foreach ( $attachment_ids as $id ) {
            if ( ! wp_attachment_is_image( $id ) ) {
                $results[] = array( 'id' => $id, 'success' => false, 'message' => 'Not an image' );
                continue;
            }

            $alt = $this->generate_alt_text_for_image( $id );
            if ( is_wp_error( $alt ) ) {
                $results[] = array( 'id' => $id, 'success' => false, 'message' => $alt->get_error_message() );
            } else {
                $results[] = array( 'id' => $id, 'success' => true, 'alt_text' => $alt );
            }
        }

        wp_send_json_success( $results );
    }

    /**
     * Build the AI prompt with niche context and custom instructions.
     */
    private function build_prompt() {
        $niche         = get_option( $this->option_niche, '' );
        $custom_prompt = get_option( $this->option_custom_prompt, '' );
        $language      = get_option( $this->option_language, 'English' );
        $tone          = get_option( $this->option_tone, 'professional' );

        $prompt = 'You are an expert SEO and web accessibility specialist. ';
        $prompt .= 'Analyze this image and generate a concise, descriptive alt text for web accessibility and SEO. ';
        $prompt .= 'The alt text should describe what is visually present in the image in one or two sentences. ';

        if ( ! empty( $niche ) ) {
            $prompt .= 'IMPORTANT CONTEXT: This image is from a website in the "' . $niche . '" niche/industry. ';
            $prompt .= 'Use relevant terminology, keywords, and phrasing that are commonly used in the ' . $niche . ' industry for SEO purposes. ';
        }

        if ( ! empty( $tone ) ) {
            $prompt .= 'Write in a ' . $tone . ' tone. ';
        }

        if ( ! empty( $language ) && strtolower( $language ) !== 'english' ) {
            $prompt .= 'Write the alt text in ' . $language . '. ';
        }

        if ( ! empty( $custom_prompt ) ) {
            $prompt .= 'ADDITIONAL INSTRUCTIONS: ' . $custom_prompt . ' ';
        }

        $prompt .= 'Return ONLY the alt text, nothing else — no quotes, no labels, no prefixes.';

        return $prompt;
    }

    /**
     * Core: Call Gemini API to generate alt text for an image.
     */
    private function generate_alt_text_for_image( $attachment_id ) {
        $api_key = get_option( $this->option_api_key );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured. Go to Settings → AI Alt Text to add it.', 'jesuspended-alt-text-ai' ) );
        }

        $image_path = get_attached_file( $attachment_id );
        if ( ! $image_path || ! file_exists( $image_path ) ) {
            return new WP_Error( 'no_file', __( 'Image file not found.', 'jesuspended-alt-text-ai' ) );
        }

        $image_data = file_get_contents( $image_path );
        if ( ! $image_data ) {
            return new WP_Error( 'read_error', __( 'Could not read image file.', 'jesuspended-alt-text-ai' ) );
        }

        $mime_type = mime_content_type( $image_path );
        $base64    = base64_encode( $image_data );

        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $api_key;

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $this->build_prompt(),
                        ),
                        array(
                            'inline_data' => array(
                                'mime_type' => $mime_type,
                                'data'      => $base64,
                            ),
                        ),
                    ),
                ),
            ),
        );

        $response = wp_remote_post( $api_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 ) {
            $error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Gemini API error.', 'jesuspended-alt-text-ai' );
            return new WP_Error( 'api_error', $error_msg );
        }

        $alt_text = '';
        if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
            $alt_text = sanitize_text_field( trim( $data['candidates'][0]['content']['parts'][0]['text'] ) );
        }

        if ( empty( $alt_text ) ) {
            return new WP_Error( 'empty_response', __( 'Gemini returned an empty response.', 'jesuspended-alt-text-ai' ) );
        }

        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

        return $alt_text;
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        $niche         = get_option( $this->option_niche, '' );
        $custom_prompt = get_option( $this->option_custom_prompt, '' );
        $language      = get_option( $this->option_language, 'English' );
        $tone          = get_option( $this->option_tone, 'professional' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'AI Alt Text Generator — Settings', 'jesuspended-alt-text-ai' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'ai_alt_text_settings' ); ?>

                <!-- API Key Section -->
                <h2><?php esc_html_e( 'API Configuration', 'jesuspended-alt-text-ai' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ai_alt_text_api_key"><?php esc_html_e( 'Google Gemini API Key', 'jesuspended-alt-text-ai' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ai_alt_text_api_key" name="<?php echo esc_attr( $this->option_api_key ); ?>"
                                   value="<?php echo esc_attr( get_option( $this->option_api_key ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">
                                <?php esc_html_e( 'Get your free API key from Google AI Studio (aistudio.google.com).', 'jesuspended-alt-text-ai' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Niche & Context Section -->
                <h2><?php esc_html_e( 'Website Niche & Context', 'jesuspended-alt-text-ai' ); ?></h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Tell the AI about your website so it generates more relevant, SEO-optimized alt text with industry-specific keywords.', 'jesuspended-alt-text-ai' ); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ai_alt_text_niche"><?php esc_html_e( 'Website Niche / Industry', 'jesuspended-alt-text-ai' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ai_alt_text_niche" name="<?php echo esc_attr( $this->option_niche ); ?>"
                                   value="<?php echo esc_attr( $niche ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'e.g., Furniture, Real Estate, Food & Recipe, Fashion, Technology...', 'jesuspended-alt-text-ai' ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Enter your website\'s niche or industry. The AI will use relevant keywords and terminology from this field.', 'jesuspended-alt-text-ai' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ai_alt_text_tone"><?php esc_html_e( 'Writing Tone', 'jesuspended-alt-text-ai' ); ?></label>
                        </th>
                        <td>
                            <select id="ai_alt_text_tone" name="<?php echo esc_attr( $this->option_tone ); ?>">
                                <option value="professional" <?php selected( $tone, 'professional' ); ?>><?php esc_html_e( 'Professional', 'jesuspended-alt-text-ai' ); ?></option>
                                <option value="casual" <?php selected( $tone, 'casual' ); ?>><?php esc_html_e( 'Casual', 'jesuspended-alt-text-ai' ); ?></option>
                                <option value="descriptive" <?php selected( $tone, 'descriptive' ); ?>><?php esc_html_e( 'Descriptive', 'jesuspended-alt-text-ai' ); ?></option>
                                <option value="technical" <?php selected( $tone, 'technical' ); ?>><?php esc_html_e( 'Technical', 'jesuspended-alt-text-ai' ); ?></option>
                                <option value="friendly" <?php selected( $tone, 'friendly' ); ?>><?php esc_html_e( 'Friendly', 'jesuspended-alt-text-ai' ); ?></option>
                                <option value="luxurious" <?php selected( $tone, 'luxurious' ); ?>><?php esc_html_e( 'Luxurious / Premium', 'jesuspended-alt-text-ai' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ai_alt_text_language"><?php esc_html_e( 'Alt Text Language', 'jesuspended-alt-text-ai' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ai_alt_text_language" name="<?php echo esc_attr( $this->option_language ); ?>"
                                   value="<?php echo esc_attr( $language ); ?>"
                                   class="regular-text"
                                   placeholder="English" />
                            <p class="description">
                                <?php esc_html_e( 'Language for the generated alt text. Default: English.', 'jesuspended-alt-text-ai' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ai_alt_text_custom_prompt"><?php esc_html_e( 'Custom Instructions', 'jesuspended-alt-text-ai' ); ?></label>
                        </th>
                        <td>
                            <textarea id="ai_alt_text_custom_prompt" name="<?php echo esc_attr( $this->option_custom_prompt ); ?>"
                                      rows="5" class="large-text"
                                      placeholder="<?php esc_attr_e( 'e.g., Always include the brand name "MyBrand" when the logo is visible. Focus on materials and craftsmanship for product images. Keep alt text under 125 characters.', 'jesuspended-alt-text-ai' ); ?>"><?php echo esc_textarea( $custom_prompt ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Add any custom instructions for the AI. This is your personal system prompt — you can tell it exactly how you want your alt text written.', 'jesuspended-alt-text-ai' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Prompt Preview -->
                <h2><?php esc_html_e( 'Prompt Preview', 'jesuspended-alt-text-ai' ); ?></h2>
                <div style="background:#f0f0f1; border:1px solid #c3c4c7; border-radius:4px; padding:12px 16px; max-width:700px;">
                    <code style="white-space:pre-wrap; word-break:break-word; font-size:12px;">
                        <?php echo esc_html( $this->build_prompt() ); ?>
                    </code>
                </div>
                <p class="description"><?php esc_html_e( 'This is the prompt that will be sent to Gemini along with each image. Save settings to update.', 'jesuspended-alt-text-ai' ); ?></p>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the bulk generation page.
     */
    public function render_bulk_page() {
        $images = get_posts( array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => 50,
            'post_status'    => 'inherit',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Bulk Generate Alt Text with AI', 'jesuspended-alt-text-ai' ); ?></h1>
            <p><?php esc_html_e( 'Select images below and click "Generate Alt Text" to automatically create alt text using Gemini AI.', 'jesuspended-alt-text-ai' ); ?></p>

            <?php if ( empty( get_option( $this->option_api_key ) ) ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'Please configure your Gemini API key in Settings → AI Alt Text first.', 'jesuspended-alt-text-ai' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            $niche = get_option( $this->option_niche, '' );
            if ( ! empty( $niche ) ) : ?>
                <div class="notice notice-info" style="display:inline-block;">
                    <p><?php printf( esc_html__( 'Active niche: %s', 'jesuspended-alt-text-ai' ), '<strong>' . esc_html( $niche ) . '</strong>' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $images ) ) : ?>
                <div class="ai-bulk-controls" style="margin: 15px 0;">
                    <label>
                        <input type="checkbox" id="ai-select-all" />
                        <?php esc_html_e( 'Select All', 'jesuspended-alt-text-ai' ); ?>
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="checkbox" id="ai-select-missing" />
                        <?php esc_html_e( 'Select Only Missing Alt Text', 'jesuspended-alt-text-ai' ); ?>
                    </label>
                    &nbsp;&nbsp;
                    <button type="button" class="button button-primary" id="ai-bulk-generate-btn">
                        <?php esc_html_e( '🤖 Generate Alt Text for Selected', 'jesuspended-alt-text-ai' ); ?>
                    </button>
                    <span class="spinner" id="ai-bulk-spinner" style="float:none;"></span>
                </div>

                <div id="ai-bulk-progress" style="display:none; margin: 10px 0;">
                    <div class="ai-progress-bar-wrap">
                        <div class="ai-progress-bar" style="width:0%;">0%</div>
                    </div>
                    <p id="ai-bulk-status"></p>
                </div>

                <table class="wp-list-table widefat fixed striped" id="ai-images-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="ai-header-check" /></th>
                            <th style="width:80px;"><?php esc_html_e( 'Image', 'jesuspended-alt-text-ai' ); ?></th>
                            <th><?php esc_html_e( 'Title', 'jesuspended-alt-text-ai' ); ?></th>
                            <th><?php esc_html_e( 'Current Alt Text', 'jesuspended-alt-text-ai' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Action', 'jesuspended-alt-text-ai' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'jesuspended-alt-text-ai' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $images as $image ) :
                            $thumb   = wp_get_attachment_image( $image->ID, array( 60, 60 ) );
                            $alt     = get_post_meta( $image->ID, '_wp_attachment_image_alt', true );
                            $has_alt = ! empty( $alt );
                        ?>
                        <tr data-id="<?php echo esc_attr( $image->ID ); ?>" data-has-alt="<?php echo $has_alt ? '1' : '0'; ?>">
                            <td><input type="checkbox" class="ai-image-check" value="<?php echo esc_attr( $image->ID ); ?>" /></td>
                            <td><?php echo $thumb; ?></td>
                            <td><?php echo esc_html( $image->post_title ); ?></td>
                            <td class="ai-current-alt"><?php echo $has_alt ? esc_html( $alt ) : '<em>' . esc_html__( '(none)', 'jesuspended-alt-text-ai' ) . '</em>'; ?></td>
                            <td>
                                <button type="button" class="button button-small ai-single-generate-btn" data-id="<?php echo esc_attr( $image->ID ); ?>">
                                    <?php esc_html_e( 'Generate', 'jesuspended-alt-text-ai' ); ?>
                                </button>
                            </td>
                            <td class="ai-status"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?php esc_html_e( 'No images found in your media library.', 'jesuspended-alt-text-ai' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}

new AI_Alt_Text_Generator();
