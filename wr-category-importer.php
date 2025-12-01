<?php
/**
 * Plugin Name: WR Category Importer
 * Description: Import WooCommerce product categories (product_cat) from CSV with up to 6 hierarchical levels.
 * Version: 1.1.0
 * Author: Wisdom Rain
 * Text Domain: wr-category-importer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WR_Category_Importer {

    const TAXONOMY = 'product_cat';
    const CAPABILITY = 'manage_woocommerce';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_menu() {
        add_menu_page(
            __( 'WR Category Importer', 'wr-category-importer' ),
            __( 'Category Importer', 'wr-category-importer' ),
            self::CAPABILITY,
            'wr-category-importer',
            [ $this, 'render_admin_page' ],
            'dashicons-category',
            56
        );
    }

    public function render_admin_page() {

        $message = '';
        $errors  = [];

        if ( isset( $_POST['wrci_submit'] ) ) {

            check_admin_referer( 'wrci_import_categories', 'wrci_nonce' );

            if ( empty( $_FILES['wrci_csv']['tmp_name'] ) ) {
                $errors[] = __( 'Please upload a CSV file.', 'wr-category-importer' );
            } else {
                $file = $_FILES['wrci_csv']['tmp_name'];

                $result = $this->process_csv( $file );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                } else {
                    $message = sprintf(
                        'Import complete — Rows: %d | Created: %d | Existing: %d',
                        $result['rows'], $result['created'], $result['skipped']
                    );
                }
            }
        }

        ?>
        <div class="wrap">
            <h1>WR Category Importer (6 Level)</h1>

            <?php if ( $message ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <?php if ( $errors ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( $errors as $err ) echo '<p>'.esc_html($err).'</p>'; ?>
                </div>
            <?php endif; ?>

            <p>Upload a UTF-8 CSV with up to 6 levels:</p>

            <table class="widefat" style="max-width:600px;">
                <thead>
                    <tr>
                        <th>level_1</th><th>level_2</th><th>level_3</th>
                        <th>level_4</th><th>level_5</th><th>level_6</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Kadın</td><td>Giyim</td><td>Elbise</td>
                        <td>Mini Elbise</td><td>Uzun Elbise</td><td>2025 Koleksiyonu</td>
                    </tr>
                </tbody>
            </table>

            <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
                <?php wp_nonce_field( 'wrci_import_categories', 'wrci_nonce' ); ?>

                <p><input type="file" name="wrci_csv" accept=".csv,text/csv" required></p>

                <p>
                    <label>
                        <input type="checkbox" name="wrci_has_header" value="1" checked>
                        First row is header
                    </label>
                </p>

                <p><button class="button button-primary" name="wrci_submit">Import Categories</button></p>
            </form>
        </div>
        <?php
    }

    protected function process_csv( string $file_path ) {

        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_missing', 'CSV file not found.' );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'cannot_open', 'CSV file could not be opened.' );
        }

        $has_header = ! empty( $_POST['wrci_has_header'] );

        $row_num  = 0;
        $created  = 0;
        $skipped  = 0;

        while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {

            $row_num++;

            if ( $row_num === 1 && $has_header ) continue;

            // Normalize to 6 columns
            $data = array_pad( $data, 6, '' );

            if ( empty( array_filter( $data, 'strlen' ) ) ) continue;

            $parent_id = 0;

            // 6 LEVEL LOOP
            for ( $level = 0; $level < 6; $level++ ) {

                $name = trim( $data[$level] );
                if ( $name === '' ) continue;

                $term = $this->get_or_create_term( $name, $parent_id );

                if ( is_wp_error( $term ) ) {
                    continue 2;
                }

                if ( $term['created'] ) $created++;
                else $skipped++;

                $parent_id = $term['term_id'];
            }
        }

        fclose( $handle );

        return [
            'rows'    => $row_num - ( $has_header ? 1 : 0 ),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    protected function get_or_create_term( string $name, int $parent_id = 0 ) {

        $exists = term_exists( $name, self::TAXONOMY, $parent_id );

        if ( $exists && ! is_wp_error( $exists ) ) {
            $term_id = is_array( $exists ) ? $exists['term_id'] : $exists;
            return [ 'term_id' => $term_id, 'created' => false ];
        }

        $insert = wp_insert_term( $name, self::TAXONOMY, [
            'parent' => $parent_id,
            'slug'   => sanitize_title( $name ),
        ]);

        if ( is_wp_error( $insert ) ) return $insert;

        return [
            'term_id' => $insert['term_id'],
            'created' => true,
        ];
    }
}

new WR_Category_Importer();
