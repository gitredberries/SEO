<?php
/**
 * Plugin Name: Redberries SEO
 * Description: Inject custom <head> scripts globally or per‑post, and preload critical images on any single post type to boost Core Web Vitals and SEO.
 * Version: 1.2.0
 * Author: Redberries Digital
 * License: GPL2+
 * Text Domain: redberries-seo
 * Icon URI: https://redberries.ae/redberries-logo.png
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Redberries_SEO {

    const OPTION_KEY = 'rbseo_options';           // global options (settings page)
    const META_KEY   = '_rbseo_meta';             // per‑post meta array

    public function __construct() {
        /* Admin */
        add_action( 'admin_menu',              [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init',              [ $this, 'register_settings' ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',               [ $this, 'save_post' ], 10, 2 );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );

        /* Front‑end */
        add_action( 'wp_head', [ $this, 'output_header_assets' ], 1 );
    }

    /* ------------------------------------------------------------------------- */
    /* Settings Page (Global)                                                    */
    /* ------------------------------------------------------------------------- */

    public function register_admin_menu() {
        add_menu_page(
            __( 'Redberries SEO', 'redberries-seo' ),
            __( 'Redberries SEO', 'redberries-seo' ),
            'manage_options',
            'redberries-seo',
            [ $this, 'settings_page' ],
            plugin_dir_url( __FILE__ ) . 'redberries-logo.png',
            80
        );
    }

    public function register_settings() {
        register_setting(
            'rbseo_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_options' ],
                'default'           => [ 'scripts' => '', 'images' => [] ],
            ]
        );
    }

    public function sanitize_options( $input ) {
        $out            = [];
        
        // Sanitize scripts separately to allow ld+json
        if ( isset( $input['scripts'] ) ) {
            $allowed_html = [
                'script' => [
                    'type' => true,
                ],
            ];
            // Add other allowed tags as needed, for example:
            // $allowed_html['style'] = ['type' => true];
            // $allowed_html['noscript'] = [];
            
            $out['scripts'] = wp_kses( $input['scripts'], $allowed_html );
        } else {
            $out['scripts'] = '';
        }

        $out['images'] = [];
        if ( ! empty( $input['images'] ) && is_array( $input['images'] ) ) {
            foreach ( $input['images'] as $id ) {
                $out['images'][] = absint( $id );
            }
        }
        return $out;
    }

    public function settings_page() {
        $opt = get_option( self::OPTION_KEY, [ 'scripts' => '', 'images' => [] ] );
        ?>
        <div class="wrap rbseo-wrapper">
            <div class="rbseo-header" style="display:flex;align-items:center;gap:12px;">
                <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'redberries-logo.png' ); ?>" alt="Redberries SEO" width="48" height="48" />
                <h1 class="wp-heading-inline" style="margin:0;"><?php esc_html_e( 'Redberries SEO', 'redberries-seo' ); ?></h1>
            </div>
            <hr class="wp-header-end" />
            <form method="post" action="options.php">
                <?php settings_fields( 'rbseo_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="rbseo_scripts"><?php esc_html_e( 'Global Header Scripts', 'redberries-seo' ); ?></label></th>
                        <td>
                            <textarea id="rbseo_scripts" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scripts]" rows="6" cols="80" class="large-text code"><?php echo esc_textarea( $opt['scripts'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'These scripts/meta tags will load on every page unless overridden on a specific post.', 'redberries-seo' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Global Preload Images', 'redberries-seo' ); ?></th>
                        <td>
                            <button type="button" class="button rbseo-select-images" data-target="global"><?php esc_html_e( 'Select Images', 'redberries-seo' ); ?></button>
                            <ul class="rbseo-image-list" id="rbseo-image-list-global">
                                <?php if ( ! empty( $opt['images'] ) ) :
                                    foreach ( $opt['images'] as $id ) :
                                        $thumb = wp_get_attachment_thumb_url( $id );
                                        ?>
                                        <li data-id="<?php echo esc_attr( $id ); ?>">
                                            <img src="<?php echo esc_url( $thumb ); ?>" />
                                            <span class="dashicons dashicons-no-alt rbseo-remove"></span>
                                            <input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[images][]" value="<?php echo esc_attr( $id ); ?>" />
                                        </li>
                                    <?php endforeach; endif; ?>
                            </ul>
                            <p class="description"><?php esc_html_e( 'Hero/above‑the‑fold images to preload everywhere.', 'redberries-seo' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------------- */
    /* Meta Box (Per‑post)                                                       */
    /* ------------------------------------------------------------------------- */

    public function add_meta_boxes() {
        $types = get_post_types( [ 'show_ui' => true ], 'names' );
        foreach ( $types as $type ) {
            add_meta_box( 'rbseo_meta_box', __( 'Redberries SEO', 'redberries-seo' ), [ $this, 'render_meta_box' ], $type, 'normal', 'default' );
        }
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'rbseo_meta_save', 'rbseo_meta_nonce' );
        $meta = get_post_meta( $post->ID, self::META_KEY, true );
        $meta = wp_parse_args( $meta, [ 'scripts' => '', 'images' => [] ] );
        ?>
        <p>
            <label for="rbseo_meta_scripts"><strong><?php esc_html_e( 'Header Scripts for this Post', 'redberries-seo' ); ?></strong></label>
            <textarea id="rbseo_meta_scripts" name="rbseo_meta[scripts]" rows="4" style="width:100%;" class="code"><?php echo esc_textarea( $meta['scripts'] ); ?></textarea>
        </p>
        <p>
            <button type="button" class="button rbseo-select-images" data-target="meta"><?php esc_html_e( 'Select Images to Preload', 'redberries-seo' ); ?></button>
        </p>
        <ul class="rbseo-image-list" id="rbseo-image-list-meta">
            <?php if ( ! empty( $meta['images'] ) ) :
                foreach ( $meta['images'] as $id ) :
                    $thumb = wp_get_attachment_thumb_url( $id );
                    ?>
                    <li data-id="<?php echo esc_attr( $id ); ?>">
                        <img src="<?php echo esc_url( $thumb ); ?>" />
                        <span class="dashicons dashicons-no-alt rbseo-remove"></span>
                        <input type="hidden" name="rbseo_meta[images][]" value="<?php echo esc_attr( $id ); ?>" />
                    </li>
                <?php endforeach; endif; ?>
        </ul>
        <p class="description"><?php esc_html_e( 'These preloads/scripts apply only to this specific post/page.', 'redberries-seo' ); ?></p>
        <?php
    }

    public function save_post( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['rbseo_meta_nonce'] ) || ! wp_verify_nonce( $_POST['rbseo_meta_nonce'], 'rbseo_meta_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['rbseo_meta'] ) && is_array( $_POST['rbseo_meta'] ) ) {
            $meta               = [];

            // Sanitize scripts separately to allow ld+json
            if ( isset( $_POST['rbseo_meta']['scripts'] ) ) {
                $allowed_html = [
                    'script' => [
                        'type' => true,
                    ],
                ];
                // Add other allowed tags as needed
                $meta['scripts'] = wp_kses( $_POST['rbseo_meta']['scripts'], $allowed_html );
            } else {
                $meta['scripts'] = '';
            }
            
            $meta['images']     = [];
            if ( ! empty( $_POST['rbseo_meta']['images'] ) && is_array( $_POST['rbseo_meta']['images'] ) ) {
                foreach ( $_POST['rbseo_meta']['images'] as $id ) {
                    $meta['images'][] = absint( $id );
                }
            }
            update_post_meta( $post_id, self::META_KEY, $meta );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    /* ------------------------------------------------------------------------- */
    /* Front‑end Output                                                          */
    /* ------------------------------------------------------------------------- */

    public function output_header_assets() {
        // Global options.
        $opt  = get_option( self::OPTION_KEY, [ 'scripts' => '', 'images' => [] ] );
        $imgs = $opt['images'];
        $js   = $opt['scripts'];

        // Per‑post merge (only on single post/page/CPT).
        if ( is_singular() ) {
            $meta = get_post_meta( get_queried_object_id(), self::META_KEY, true );
            if ( ! empty( $meta ) && is_array( $meta ) ) {
                if ( ! empty( $meta['images'] ) ) {
                    // Prepend per‑post images so they get priority.
                    $imgs = array_merge( $meta['images'], $imgs );
                    $imgs = array_unique( $imgs );
                }
                if ( ! empty( $meta['scripts'] ) ) {
                    $js .= "\n" . $meta['scripts'];
                }
            }
        }

        // Output preloads.
        foreach ( $imgs as $id ) {
            $src = wp_get_attachment_url( $id );
            if ( $src ) {
                echo "\n<link rel=\"preload\" as=\"image\" href=\"" . esc_url( $src ) . "\" fetchpriority=\"high\">";
            }
        }

        // Output scripts.
        if ( ! empty( trim( $js ) ) ) {
            echo "\n<!-- Redberries SEO -->\n" . $js . "\n";
        }
    }

    /* ------------------------------------------------------------------------- */
    /* Admin Assets (shared by settings + meta box)                              */
    /* ------------------------------------------------------------------------- */

    public function enqueue_admin_assets( $hook ) {
        // Load everywhere in admin where editing or our plugin page.
        $allowed = [ 'post.php', 'post-new.php', 'toplevel_page_redberries-seo' ];
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'rbseo-admin', plugin_dir_url( __FILE__ ) . 'rbseo-admin.js', [ 'jquery' ], '1.2.0', true );
        wp_localize_script( 'rbseo-admin', 'rbseo', [ 'optionKey' => self::OPTION_KEY ] );
        wp_enqueue_style( 'rbseo-admin', plugin_dir_url( __FILE__ ) . 'rbseo-admin.css', [], '1.2.0' );
    }
}

new Redberries_SEO();


?>
