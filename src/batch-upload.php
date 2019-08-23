<?php

/**
 * Plugin Name: Common - Batch Post Uploader
 * Description: A plugin to add a function to the admin page that will upload Articles and Issues from a selected CSV file.
 * Author: Mike W. Leavitt
 * Version: 1.0
 */

//defined( 'add_action' ) or die( "I don't think you're running this from WordPress..." );

$config_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wp-config.php';
if ( !file_exists( $config_path ) )
    $config_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-config.php';

$load_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wp-load.php';
if ( !file_exists( $load_path ) )
    $load_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'wordpress' . DIRECTORY_SEPARATOR . 'wp-load.php';

require_once( $config_path );
require_once( $load_path );


/**
 * Load all our plugin CSS and JavaScript
 * 
 * @return void
 */
function batch_upload_load_scripts() {
    
    global $pagenow;

    if( !( $pagenow == 'tools.php' || !( isset( $_GET['page']) && $_GET['page'] == 'batch-upload-panel')))
        return;

    // Load CSS and JS here
    wp_enqueue_style( 'batch-upload-plugin-style', plugin_dir_url(__FILE__) . 'dist/main.css' );
}
add_action('admin_enqueue_scripts', 'batch_upload_load_scripts');


/**
 * Add the menu page in the Tools section, because that fits pretty well with the plugin's purpose.
 * 
 * @return void
 */
function batch_upload_menu() {

    // Add our custom page under the "Tools" menu in the admin screen.
    add_management_page( 'Batch Uploader', 'Batch Uploader', 'administrator', 'batch-upload-panel', 'batch_upload_build_page' );
}
add_action( 'admin_menu', 'batch_upload_menu' );


/**
 * Build the admin page that the user will see and interact with.
 * 
 * @return void
 */
function batch_upload_build_page() {

    // Checking to make sure the user is allowed to muck about with the site.
    if ( !current_user_can( 'administrator' ) )
        wp_die( __('You do not have sufficient permissions to access this page.') );

    // Load the HTML from the outside page we'll create.
    include_once( plugin_dir_path( __FILE__ ) . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'upload-page.php' );

}


/**
 * This is the value that we specify in the hidden input named "action" --we use that as the name of the hook, then
 * call the function below to handle the data validation/manipulation and post creation.
 * 
 * @return void
 */
add_action( 'admin_post_batch_upload_validate', 'batch_upload_process_data' );
function batch_upload_process_data() {
    
    // Some placeholders, at least one of which will be set and sent back to the original page
    // to control the little alert boxes that pop up.
    $success = NULL;
    $failure = NULL;
    $fail_why = NULL;

    // Data validation GOOOOOOOO:

    // Checks user privileges.
    if( !current_user_can( 'manage_options' ) ) {

        $failure = TRUE;
        $fail_why = 'You do not have permission to perform this action.';
        error_log( $fail_why );
    }

    // Verifies the nonce. Between this and the privilege check, we can be reasonably sure everything's legit.
    if( isset( $_POST[ 'batch-fields' ] ) && !wp_verify_nonce( $_POST[ 'batch-fields' ], admin_url( 'admin-post.php' ) ) ) {

        $failure = TRUE;
        $fail_why = "You're kinda' spoopy...";
        error_log( $fail_why );
    }

    // Checking to make sure the file is good. It's not a super smart check, so if the user has screwed something up, but the
    // file extension is still .csv, then this won't stop it.
    if( !isset( $_FILES[ 'csv-name' ] ) || strtolower( pathinfo( $_FILES[ 'csv-name' ]['name'], PATHINFO_EXTENSION ) ) != 'csv' ) {

        $failure = TRUE;
        $fail_why = 'No CSV File selected to upload';
        error_log( $fail_why );
    }

    // Checks to make sure we know what type of post we're making.
    if( !isset( $_POST[ 'post-type' ] ) || $_POST[ 'post-type' ] == 'none' ) {

        $failure = TRUE;
        $fail_why = 'No valid post type selected.';
        error_log( $fail_why );
    }

    // If there's a failure from the above, there's no reason to stick around.
    if( $failure )
        batch_upload_redirect_fail( $fail_why );

    // These are for adding the post thumbnails from an image URL. The URLs in these uploads didn't work,
    // but this might be worth playing around with in the future.
    /*
    $post_ids = array();
    $img_urls = array();
    */

    // Opens the CSV file in read mode.
    if( ( $f = fopen( $_FILES[ 'csv-name' ]['tmp_name'], 'r' ) ) != FALSE ) {

        // For debug purposes:
        // error_log( "Opening the file..." );
        // error_log( "Reading the file..." );

        // Read each line of the CSV file, and create a new post from the data.
        while( ( $data = fgetcsv( $f, 1000, ',') ) != FALSE ) {

            // The array that we'll eventually pass to wp_insert_post()
            $postarr = array(
                'post_date' => '',
                'post_content' => '',
                'post_title' => '',
                'post_status' => 'draft', // This could be set to 'publish' if the posts aren't meant to be stubs.
                'post_type' => $_POST[ 'post-type' ]
            );

            // Run the right function based on post type.
            switch( $_POST[ 'post-type' ] ) {

                case 'article':
                    $postarr = batch_upload_process_article( $data, $postarr );
                    break;
                case 'issue':
                    $postarr = batch_upload_process_issue( $data, $postarr );
                    break;
                default:
                    break;
            }

            // Do stuff specific to Issues
            if( $postarr[ 'post_type' ] == 'issue' ) {

                // More for the Featured Image thing. Not working right now.
                /*
                array_push( $img_urls, $postarr[ 'img_url' ] );
                unset( $postarr[ 'img_url' ] );
                array_values( $postarr );
                */

                // Create the post, and store its ID so we can use it later.
                $post_id = wp_insert_post( wp_slash( $postarr ) );

                // For some reason, wp_insert_post() doesn't seem to be adding the post meta fields from
                // $postarr['meta_input'], so we'll have to do it ourselves.
                $meta_keys = array(
                    'journal-title',
                    'theme',
                    'pub-date',
                    'cov-date',
                    'vol-num',
                    'issue-num',
                    'isbn',
                    'issn',
                    'pur-url',
                    'editorial'
                );

                // Also for image thing. Nonfunctional ATM.
                /*
                if( $post_id )
                    array_push( $post_ids, $post_id );
                */

            } else { // Article-specific stuff--similar in principle to the issue, if not in execution.

                // Create the post, and store its ID so we can use it later.
                $post_id = wp_insert_post( wp_slash( $postarr ) );

                // Set the meta field names for an Article, instead of an Issue.
                $meta_keys = array(
                    'author1-last',
                    'author1-first', 
                    'other-authors', 
                    'issue', 
                    'start', 
                    'end', 
                    'pur-url', 
                    'doi', 
                    'body',
                    'abstract',
                    'auth-url',
                    'auth-info',
                    'auth-rev',
                    'title-rev',
                    'url-rev'
                );
            }

            // Because for some reason wp_insert_post() fails to add the post metadata, even when the 'meta_input' field
            // is included and formatted correctly (as near as I can tell).
            batch_upload_add_meta( $post_id, $meta_keys, $postarr['meta_input'] );

        }

        // Close the file.
        fclose( $f );

        // For debug:
        // error_log( "Closing the file...");
        // error_log( "Done!" );

        /* Not Working right now
        if( !empty( $post_ids ) && !empty( $img_urls) )
            batch_upload_set_thumbnail( $post_ids, $img_urls );
        */
        
        // Set $success to true, so we can generate the notice when we reload the main page.
        $success = TRUE;

    } else { // Something went wrong trying to read the file.

        $failure = TRUE;
        $fail_why = 'There was a problem reading the file you uploaded.';
        error_log( $fail_why );
    }

    // Putting together the additions to the redirect URL so the $_GET variable will be populated properly.
    $get_vars = ( isset( $success ) ? '&success=' . $success : '' ) . ( isset( $failure ) ? '&failure=' . $failure : '' )
        . ( isset( $fail_why ) ? '&fail-why=' . $fail_why : '' );

    // Send the user back to the Batch Uploader's main page, along with any additional data.
    if( wp_redirect( admin_url( 'tools.php?page=batch-upload-panel' . $get_vars ) ) )
        exit;
    
}


/**
 * Get the array of arguments ready for calling wp_insert_post(), with settings specific to an Article.
 * 
 * @param array     $data       The raw data values pulled from one line of the loaded CSV.
 * @param array     $postarr    The proto array of post arguments.
 * 
 * @return array    $postarr   The finished array, with all its bits filled out.
 */
function batch_upload_process_article( $data, $postarr ) {

    // The field names we're concerned with, in the order they appear on the CSV.
    $data_fields = array(
        'title',
        'content',
        'author1-last',
        'author1-first', 
        'other-authors', 
        'issue', 
        'start', 
        'end', 
        'pur-url', 
        'doi', 
        'excerpt',
        'abstract',
        'auth-info',
        'auth-url',
        'auth-rev',
        'title-rev',
        'url-rev',
        'tags'
    );

    // Container for the associative array that we'll be stitching together.
    $data_parsed = array();

    // Assign the data from the CSV's $data array with the right field name.
    foreach( $data_fields as $i=>$key ) {

        $data_parsed[ $key ] = $data[ $i ];
    }

    // We don't need these anymore. Sure, they'll be garbage collected when the function finishes executing,
    // but might as well free up the memory early.
    unset( $data_fields, $data );

    // Suffixes to make it easier to loop through all the fields prefixed with 'post_' that WordPress wants.
    $post_suffixes = array(
        'date',
        'content',
        'title',
        'excerpt'
    );

    // Update the stuff that's going into the main array, as opposed to the unique post meta stuff.
    foreach( $post_suffixes as $sfx ) {

        if( $sfx == 'date' ) {
            $date = date_create();
            $postarr[ 'post_date' ] = date_format( $date, 'Y-m-d H:i:s' );
        } else if( $sfx == 'content' && ( $data_parsed[ 'content' ] == '' || $data_parsed[ 'content' ] == NULL ) )
            $postarr[ 'post_content' ] = 'This post is a stub. Please edit it to add content.';
        else
            $postarr[ 'post_' . $sfx ] = isset( $data_parsed[ $sfx ] ) ? $data_parsed[ $sfx ] : NULL ;
    }

    // Get rid of this one, too.
    unset( $post_suffixes );

    // These are all the meta keys for the Article Custom Post Type.
    $article_meta_keys = array( 
        'author1-last',
        'author1-first', 
        'other-authors', 
        'issue', 
        'start', 
        'end', 
        'pur-url', 
        'doi', 
        'body',
        'abstract',
        'auth-url',
        'auth-info',
        'auth-rev',
        'title-rev',
        'url-rev'
    );

    // Grab all the meta values from the parsed data array and assign them to $article_meta.
    $article_meta = array();
    foreach( $article_meta_keys as $i=>$key ) {

        if( isset( $data_parsed[ $key ] ) && $data_parsed[ $key ] != '' )
            $article_meta[ $key ] = $data_parsed[ $key ];
    }

    // Done with $article_meta_keys.
    unset( $article_meta_keys );

    // Set the meta to the right field in the $postarr array. wp_insert_post() won't use it right, but
    // this is how we'll pass it back to the main function, so we can handle it ourselves there.
    $postarr['meta_input'] = $article_meta;

    // Get and attach the tags, if any.
    $tags = array_map( 'trim', explode( ',', $data_parsed[ 'tags' ] ) );
    $postarr['tags_input'] = $tags;

    // Return the completed array.
    return $postarr;
}


/**
 * Second verse, same as the first (more or less).
 * 
 * Get the array of arguments ready for calling wp_insert_post(), with settings specific to an Issue.
 * 
 * @param array     $data       The raw data values pulled from one line of the loaded CSV.
 * @param array     $postarr    The proto array of post arguments.
 * 
 * @return array    $postarr   The finished array, with all its bits filled out.
 */
function batch_upload_process_issue( $data, $postarr ) {

    // The field names we're concerned with, in the order they appear on the CSV.
    $data_fields = array(
        'title',
        'vol-num',
        'issue-num',
        'journal-title',
        'pub-date',
        'cov-date',
        'img_url',
		'theme',
		'isbn',
		'issn',
		'pur-url',
    );

    // Container for the associative array that we'll be stitching together.
    $data_parsed = array();

    // Assign the data from the CSV's $data array with the right field name.
    foreach( $data_fields as $i=>$key ) 
        $data_parsed[ $key ] = $data[ $i ];

    // We don't need this anymore. Sure, it'll be garbage collected when the function finishes executing,
    // but might as well free up the memory early.
    unset( $data_fields );

    $postarr[ 'post_title' ] = $data_parsed[ 'title' ] . ' | ' . $data_parsed[ 'cov-date' ];
    $postarr[ 'post_content' ] = "This Issue is a stub. Please add appropriate body text here.\n\nClick here for the <a href=\"" . $data_parsed[ 'img_url' ] . "\" target=\"_blank\">JSTOR entry</a>." ;

    $post_date = date_create();
    $postarr[ 'post_date' ] = date_format( $post_date, 'Y-m-d H:i:s' );

    unset( $post_date );

    $year = array();
    $date_patt = '/mm\/dd\/(\d{4})/';
    preg_match( $date_patt, $data_parsed[ 'pub-date' ], $year );

    $mmdd = array();
    $seas_patt = '/(\w+)\s*\d{4}/';
    preg_match( $seas_patt, $data_parsed[ 'cov-date' ], $mmdd );

    $seasons = array(
        'spring' => '-04-01',
        'summer' => '-07-01',
        'fall' => '-10-01',
        'winter' => '-01-01'
    );
    
    $data_parsed[ 'pub-date' ] = date_create( $year[1] . $seasons[ strtolower( $mmdd[1] ) ] );

    unset( $year, $mmdd );

    // These are all the meta keys for the Issue Custom Post Type.
    $meta_keys = array( 
        'journal-title',
		'theme',
		'pub-date',
		'cov-date',
		'vol-num',
		'issue-num',
		'isbn',
		'issn',
		'pur-url',
		'editorial'
    );

    // Grab all the meta values from the parsed data array and assign them to $issue_meta.
    $issue_meta = array();

    foreach( $meta_keys as $key ) {

        if( isset( $data_parsed[ $key ] ) && $data_parsed[ $key ] != '' )
            $issue_meta[ $key ] = $data_parsed[ $key ];
    }

    // Done with $article_meta_keys.
    unset( $meta_keys );

    // Set the meta to the right field in the $postarr array. wp_insert_post() won't use it right, but
    // this is how we'll pass it back to the main function, so we can handle it ourselves there.
    $postarr[ 'meta_input' ] = $issue_meta;

    // This is the URL for the image, but it wasn't working with the JSTOR links we had. I ended up just putting
    // a link to the JSTOR page in the body.
    //$postarr[ 'img_url' ] = $data_parsed[ 'img_url' ];

    // Return the completed array.
    return $postarr;
}

/**
 * This might still work, but the JSTOR URLs we've got are getting a HTTP 403 response, so I'm not sure it'll work with the FHQ
 * issues specifically.
 */
function batch_upload_set_thumbnail( $post_ids, $img_urls ) {

    foreach( $post_ids as $i=>$post_id ) {

        $post               = get_post( $post_id ); // Get the post object associated with the Post ID.
        $img_name           = $post->post_name; // Get the post_name attribute to use as the image name.
        $upload_dir         = wp_upload_dir(); // Cache the upload directory array.
        $img_data           = file_get_contents( $img_urls[ $i ] ); // Get the image data from the URL.
        $unique_file_name   = wp_unique_filename( $upload_dir[ 'path' ], $img_name ); // Create a unique name for the file on the server.
        $filename           = basename( $unique_file_name ); // Create the image file name.

        // Check folder permissions and define file location.
        if ( wp_mkdir_p( $upload_dir[ 'path' ] ) ) {
            $file = $upload_dir[ 'path' ] . DIRECTORY_SEPARATOR . $filename;

        } else {
            $file = $upload_dir[ 'basedir' ] . DIRECTORY_SEPARATOR . $filename;
        }

        // Write the image file to the server.
        file_put_contents( $file, $img_data );

        // Check image filetype.
        $wp_filetype = wp_check_filetype( $filename, NULL );

        // Set attachment data.
        $attachment = array(
            'post_mime_type' => $wp_filetype[ 'type' ],
            'post_title' => sanitize_file_name( $filename ),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Create attachment.
        $attach_id = wp_insert_attachment( $attachment, $file, $post_id );

        // Include image.php, if it's not already in there.
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        // Define attachment metadata.
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file );

        // Assign metadata to attachment.
        wp_update_attachment_metadata( $attach_id, $attach_data );

        // Assign image to post.
        set_post_thumbnail( $post_id, $attach_id );
    }
}


/**
 * Since wp_insert_post() doesn't seem to want to update the post meta the way it's supposed to,
 * we'll just do it ourselves. Ironcially, this is almost the exact same code that the wp_insert_post()
 * function is supposed to run--but it works here, and not there.
 * 
 * Weird.
 * 
 * @param int   $post_id    The ID of the newly-created post, so we know which one we're updating.
 * @param array $meta_keys  The names of the post meta fields we'll be modifying.
 * @param array $meta_data  The array containing the actual data, passed from the old $postarr
 * 
 * @return void
 */
function batch_upload_add_meta( $post_id, $meta_keys, $meta_data ) {

    foreach( $meta_keys as $key ) {

        if( isset( $meta_data[ $key ] ) )
            update_post_meta( $post_id, $key, $meta_data[ $key ] );
        else
            update_post_meta( $post_id, $key, NULL );
    }
}


/**
 * This redirects the user back to the main admin page, and set the $_GET variable so that the page can generate
 * its error message.
 * 
 * @param string $fail_why  The reason for the failure, as best the function could determine.
 * 
 * @return void
 */
function batch_upload_redirect_fail( $fail_why ) {

    // Set the bit of the string that we'll slap onto the end.
    $get_vars = '&failure=1&fail-why=' . $fail_why;

    // Send the user back, with the right get string.
    if( wp_redirect( admin_url( 'tools.php?page=batch-upload-panel' . $get_vars ) ) )
        exit;
}
?>