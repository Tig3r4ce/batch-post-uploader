<?php

/**
 * Plugin Name: Batch Post Uploader
 * Description: Upload posts in bulk by reading rows of data from a customized CSV file.
 * Author: Mike W. Leavitt
 * Version: 1.0
 */

$config_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wp-config.php';
if ( !file_exists( $config_path ) )
    $config_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-config.php';

$load_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( !file_exists( $load_path ) )
    $load_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-load.php';

require_once( $config_path );
require_once( $load_path );


add_action( 'admin_enqueue_scripts', 'batch_post_upload_load_scripts' );
function batch_post_upload_load_scripts() {
    global $pagenow;

    if( !( $pagenow == 'tools.php' || !( isset( $_GET[ 'page' ] ) && $_GET[ 'page' ] == 'batch-post-upload-panel' ) ) );
        return;
    
    // Load CSS and JS here.
}


add_action( 'admin_menu', 'batch_post_upload_menu' );
function batch_post_upload_menu() {

    // Add our custom page under the "Tools" menu in the admin screen.
    add_management_page(
        'Batch Post Uploader',
        'Batch Post Uploader',
        'administrator',
        'batch-post-upload-panel',
        'batch_post_upload_build_page'
    );
}


function batch_post_upload_build_page() {
    if( !current_user_can( 'administrator' ) )
        wp_die( __("You do not have sufficient permissions to access this page.", 'batch-post-uploader' ) );

    include_once( plugin_dir_path(__FILE__) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'upload-page.php' );
    // TODO: actually create upload-page.php, and make the CSS and JS to go with it.
}


add_action( 'admin_post_batch_post_upload', 'batch_post_upload_process_data' );
function batch_post_upload_process_data() {
    $success = NULL;
    $msg = __( "An unknown error occurred.", 'batch-post-uploader' );

    if( !current_user_can( 'manage_options' ) ) {
        $success = FALSE;
        $msg = __( "You do not have permission to perform this action.", 'batch-post-uploader' );
    }

    if( !isset( $_POST[ 'batch-fields' ] ) || !wp_verify_nonce( $_POST[ 'batch-fields' ], admin_url( 'admin-post.php' ) ) ) {
        $success = FALSE;
        $msg = __( "Failed to verify nonce.", 'batch-post-uploader' );
    }

    if( !isset( $_FILES[ 'csv-file' ] ) || strtolower( pathinfo( $_FILES[ 'csv-file' ][ 'name' ], PATHINFO_EXTENSION ) ) != 'csv' ) {
        $success = FALSE;
        $msg = __( "No CSV file selected to upload.", 'batch-post-uploader' );
    }

    if( !isset( $_POST[ 'post-type' ] ) || $_POST[ 'post-type' ] == 'none' ) {
        $success = FALSE;
        $msg = __( "No post type selected.", 'batch-post-uploader' );
    }

    if( $success === FALSE ) {
        batch_post_upload_done_redirect( $success, $msg );
    }

    if( ( $f = fopen( $_FILES[ 'csv-file' ][ 'tmp_name' ], 'r' ) ) != FALSE ) {

        $char_limit = isset( $_POST[ 'char-limit' ] ) ? $_POST[ 'char-limit' ] : 1000;
        $delimiter = isset( $_POST[ 'delimiter' ] ) ? $_POST[ 'delimiter' ] : ',';

        while( ( $data = fgetcsv( $f, $char_limit, $delimiter ) ) ) {
            
            $now = date_create();

            $postarr = array(
                'post_date' => date_format( $now, 'Y-m-d H:i:s' ),
                'post_content' => sprintf( __( "This %s is a stub. Please edit it to add content.", 'batch-post-uploader' ), $_POST[ 'post-type' ] ),
                'post_title' => sprintf( __( "New %s (%s)", 'batch-post-uploader'), $_POST[ 'post-type' ], date_format( $now, 'Y-m-d H:i:s' ) ),
                'post_status' => 'draft',
                'post_type' => $_POST[ 'post-type' ]
            );

            $postarr = batch_post_upload_parse_row( $postarr, $data, $_POST[ 'csv-schema' ] );

            $post_id = wp_insert_post( $postarr );

            if( isset( $postarr[ 'meta_input' ] ) ) {

                foreach( $postarr[ 'meta_input' ] as $key=>$value ) {
                    update_post_meta( $post_id, $key, $value );
                }
            }
        }

        fclose( $f );

        $success = TRUE;
        $msg = '';

    } else {
        $success = FALSE;
        $msg = __( "There was a problem reading the file you uploaded.", 'batch-post-uploader' );
    }

    batch_post_upload_done_redirect( $success, $msg );
}


function batch_post_upload_parse_row( $postarr, $data, $schema ) {
    // TODO: Figure out data processing (probably after figuring out front-end)
}


function batch_post_upload_done_redirect( $success, $msg = '' ) {
    $get_info = "&success=" . $success . ( $msg != '' && $msg != NULL ? "&msg=" . $msg : '' );

    if( wp_redirect( admin_url( 'tools.php?page=batch-post-upload-panel' . $get_info ) ) )
        exit;
}

?>