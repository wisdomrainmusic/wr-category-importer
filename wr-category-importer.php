<?php
/**
 * Plugin Name: WR Category Importer
 * Description: Import WooCommerce product categories (product_cat) with up to 6 levels and optional descriptions from CSV.
 * Version: 3.0.0
 * Author: Wisdom Rain
 * Text Domain: wr-category-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WR_Category_Importer {

    const TAXONOMY   = 'product_cat';
    const CAPABILITY = 'manage_woocommerce';

    // 6 seviye, her seviye: name + description = 2 kolon
    const LEVELS           = 6;
    const FIELDS_PER_LEVEL = 2;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    /**
     * Admin menü kaydı
     */
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

    /**
     * Admin sayfa
     */
    public function render_admin_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'wr-category-importer' ) );
        }

        $message = '';
        $errors  = [];

        if ( isset( $_POST['wrci_submit'] ) ) {
            check_admin_referer( 'wrci_import_categories', 'wrci_nonce' );

            if ( empty( $_FILES['wrci_csv']['tmp_name'] ) ) {
                $errors[] = __( 'Please upload a CSV file.', 'wr-category-importer' );
            } else {
                $file   = $_FILES['wrci_csv']['tmp_name'];
                $result = $this->process_csv( $file );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                } else {
                    $message = sprintf(
                        /* translators: 1: rows, 2: created, 3: existing */
                        __( 'Import complete. Rows: %1$d | Created terms: %2$d | Existing/updated terms: %3$d', 'wr-category-importer' ),
                        intval( $result['rows'] ),
                        intval( $result['created'] ),
                        intval( $result['existing'] )
                    );
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WR Category Importer (6 Level – Name + Description)', 'wr-category-importer' ); ?></h1>

            <?php if ( $message ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <?php if ( $errors ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( $errors as $err ) : ?>
                        <p><?php echo esc_html( $err ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p>
                <?php esc_html_e( 'Upload a UTF-8 CSV file with up to 6 hierarchical levels. Each level supports a category name and an optional description.', 'wr-category-importer' ); ?>
            </p>

            <h2><?php esc_html_e( 'CSV Column Order (12 columns)', 'wr-category-importer' ); ?></h2>

            <table class="widefat" style="max-width:100%;margin-bottom:20px;">
                <thead>
                    <tr>
                        <th>level_1</th>
                        <th>level_1_desc</th>
                        <th>level_2</th>
                        <th>level_2_desc</th>
                        <th>level_3</th>
                        <th>level_3_desc</th>
                        <th>level_4</th>
                        <th>level_4_desc</th>
                        <th>level_5</th>
                        <th>level_5_desc</th>
                        <th>level_6</th>
                        <th>level_6_desc</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Kadın</td>
                        <td>Tüm kadın ürünleri</td>
                        <td>Giyim</td>
                        <td>Kadın giyim ürünleri</td>
                        <td>Elbise</td>
                        <td>Elbise kategorisi açıklaması</td>
                        <td>Mini Elbise</td>
                        <td>Mini elbise modelleri</td>
                        <td>Yazlık</td>
                        <td>Yaz sezonu ürünleri</td>
                        <td></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
                <?php wp_nonce_field( 'wrci_import_categories', 'wrci_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="wrci_csv"><?php esc_html_e( 'CSV File', 'wr-category-importer' ); ?></label>
                        </th>
                        <td>
                            <input type="file" name="wrci_csv" id="wrci_csv" accept=".csv,text/csv" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Header Row', 'wr-category-importer' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wrci_has_header" value="1" checked />
                                <?php esc_html_e( 'First row is header (will be skipped).', 'wr-category-importer' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="wrci_submit" class="button button-primary">
                        <?php esc_html_e( 'Import Categories', 'wr-category-importer' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * CSV dosyasını işler
     */
    protected function process_csv( string $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'wrci_file_not_found', __( 'Uploaded file could not be found.', 'wr-category-importer' ) );
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'wrci_file_open_error', __( 'Could not open the uploaded CSV file.', 'wr-category-importer' ) );
        }

        $has_header = ! empty( $_POST['wrci_has_header'] );
        $row_num    = 0;
        $created    = 0;
        $existing   = 0;

        while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( $row_num === 1 && $has_header ) {
                continue;
            }

            // Toplam kolon sayısını 12'ye normalize et
            $data = array_pad( $data, self::LEVELS * self::FIELDS_PER_LEVEL, '' );

            // Tamamen boş satır ise geç
            if ( empty( array_filter( $data, 'strlen' ) ) ) {
                continue;
            }

            $parent_id = 0;

            // 6 seviye için döngü
            for ( $level = 0; $level < self::LEVELS; $level++ ) {
                $base_index = $level * self::FIELDS_PER_LEVEL;

                $name = isset( $data[ $base_index ] ) ? trim( (string) $data[ $base_index ] ) : '';
                if ( $name === '' ) {
                    continue; // Bu seviye boşsa bu level'ı atla
                }

                $desc = isset( $data[ $base_index + 1 ] ) ? trim( (string) $data[ $base_index + 1 ] ) : '';

                // Terimi al/oluştur
                $term_result = $this->get_or_create_term( $name, $parent_id );

                if ( is_wp_error( $term_result ) ) {
                    // Bu satırı komple atla ama import devam etsin
                    continue 2;
                }

                $term_id = $term_result['term_id'];

                if ( $term_result['created'] ) {
                    $created++;
                } else {
                    $existing++;
                }

                // Description overwrite: EVET (sende böyle istemiştik)
                if ( $desc !== '' ) {
                    $this->update_term_description( $term_id, $desc );
                }

                // Bir sonraki seviye için parent bu term olsun
                $parent_id = $term_id;
            }
        }

        fclose( $handle );

        return [
            'rows'     => $row_num - ( $has_header ? 1 : 0 ),
            'created'  => $created,
            'existing' => $existing,
        ];
    }

    /**
     * Var olan terimi bulur, yoksa oluşturur.
     */
    protected function get_or_create_term( string $name, int $parent_id = 0 ) {
        $existing = term_exists( $name, self::TAXONOMY, $parent_id );

        if ( $existing && ! is_wp_error( $existing ) ) {
            $term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
            return [
                'term_id' => $term_id,
                'created' => false,
            ];
        }

        $args = [
            'parent' => $parent_id,
            'slug'   => sanitize_title( $name ),
        ];

        $insert = wp_insert_term( $name, self::TAXONOMY, $args );

        if ( is_wp_error( $insert ) ) {
            return $insert;
        }

        return [
            'term_id' => (int) $insert['term_id'],
            'created' => true,
        ];
    }

    /**
     * Description alanını günceller (overwrite = ON)
     */
    protected function update_term_description( int $term_id, string $desc ) {
        wp_update_term(
            $term_id,
            self::TAXONOMY,
            [ 'description' => $desc ]
        );
    }
}

new WR_Category_Importer();
