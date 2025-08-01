<?php

//Handles the download request
function handle_sdm_download_via_direct_post() {
	if ( (isset( $_REQUEST['smd_process_download'] ) && $_REQUEST['smd_process_download'] == '1') || (isset( $_REQUEST['sdm_process_download'] ) && $_REQUEST['sdm_process_download'] == '1') ) {
		
		do_action('sdm_download_via_direct_post');
		
		global $wpdb;
		$download_id = isset( $_REQUEST['download_id'] ) ? absint( $_REQUEST['download_id'] ) : 0;
		if ( ! $download_id ) {
			wp_die( __( 'Error! Incorrect download item id.', 'simple-download-monitor' ) );
		}

		$download_title = get_the_title( $download_id );
		$download_link = get_post_meta( $download_id, 'sdm_upload', true );		
		if ( empty( $download_link ) ) {
			wp_die( printf( __( 'Error! This download item (%s) does not have any download link. Edit this item and specify a downloadable file URL for it.', 'simple-download-monitor' ), $download_id ) );
		}

		sdm_recaptcha_verify();

		//Check download password (if applicable for this download)
		$post_object = get_post( $download_id ); // Get post object
		$post_pass   = $post_object->post_password; // Get post password
		if ( ! empty( $post_pass ) ) {//This download item has a password. So validate the password.
			$pass_val = isset($_REQUEST['pass_text']) ? $_REQUEST['pass_text'] : '';
			if ( empty( $pass_val ) ) {//No password was submitted with the download request.
				do_action( 'sdm_process_download_request_no_password' );

				$dl_post_url = get_permalink( $download_id );
				$error_msg   = __( 'Error! This download requires a password.', 'simple-download-monitor' );
				$error_msg  .= '<p>';
				$error_msg  .= '<a href="' . $dl_post_url . '">' . __( 'Click here', 'simple-download-monitor' ) . '</a>';
				$error_msg  .= __( ' and enter a valid password for this item', 'simple-download-monitor' );
				$error_msg  .= '</p>';
				wp_die( $error_msg );
			}
			if ( $post_pass != $pass_val ) {
				//Incorrect password submitted.
				do_action( 'sdm_process_download_request_incorrect_password' );

				wp_die( __( 'Error! Incorrect password. This download requires a valid password.', 'simple-download-monitor' ) );
			} else {
				//Password is valid. Go ahead with the download
			}
		}
		//End of password check

		$main_option = get_option( 'sdm_downloads_options' );

		$ipaddress = '';
		//Check if do not capture IP is enabled.
		if ( ! isset( $main_option['admin_do_not_capture_ip'] ) ) {
			$ipaddress = sdm_get_ip_address();
		}

		$user_agent = '';
		//Check if do not capture User Agent is enabled.
		if ( ! isset( $main_option['admin_do_not_capture_user_agent'] ) ) {
			//Get the user agent data. The get_browser() function doesn't work on many servers. So use the HTTP var.
			if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
				$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] );
			}
		}

		$referrer_url = '';
		//Check if do not capture Referer URL is enabled.
		if ( ! isset( $main_option['admin_do_not_capture_referrer_url'] ) ) {
			//Get the user agent data. The get_browser() function doesn't work on many servers. So use the HTTP var.
			if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
				$referrer_url = sanitize_text_field( $_SERVER['HTTP_REFERER'] );
			}
		}

		$date_time = current_time( 'mysql' );
		$visitor_country = ! empty( $ipaddress ) ? sdm_ip_info( $ipaddress, 'country' ) : '';

		$visitor_name = sdm_get_logged_in_user();

		$anonymous_can_download = get_post_meta( $download_id, 'sdm_item_anonymous_can_download', true );

		// Check if we only allow the download for logged-in users
		if ( isset( $main_option['only_logged_in_can_download'] ) ) {
			if ( $main_option['only_logged_in_can_download'] && $visitor_name === false && ! $anonymous_can_download ) {
				//User not logged in, let's display the error message.
				//But first let's see if we have login page URL set so we can display it as well
				$loginMsg = '';
				if ( isset( $main_option['general_login_page_url'] ) && ! empty( $main_option['general_login_page_url'] ) ) {
					//We have a login page URL set. Lets use it.

					if ( isset( $main_option['redirect_user_back_to_download_page'] ) ) {
						//Redirect to download page after login feature is enabled.
						$dl_post_url    = get_permalink( $download_id );//The single download item page
						$redirect_url   = apply_filters( 'sdm_after_login_redirect_query_arg', $dl_post_url );
						$login_page_url = add_query_arg( array( 'sdm_redirect_to' => urlencode( $redirect_url ) ), $main_option['general_login_page_url'] );
					} else {
						$login_page_url = $main_option['general_login_page_url'];
					}

					$tpl      = __( '__Click here__ to go to login page.', 'simple-download-monitor' );
					$loginMsg = preg_replace( '/__(.*)__/', ' <a href="' . $login_page_url . '">$1</a> $2', $tpl );
				}
				wp_die( __( 'You need to be logged in to download this file.', 'simple-download-monitor' ) . $loginMsg );
			}
		}

		$visitor_name = ( $visitor_name === false ) ? __( 'Not Logged In', 'simple-download-monitor' ) : $visitor_name;

		// Get option for global disabling of download logging
		$no_logs = isset( $main_option['admin_no_logs'] );

		// Get option for logging only unique IPs
		$unique_ips = isset( $main_option['admin_log_unique'] );

		// Get post meta for individual disabling of download logging
		$get_meta = get_post_meta( $download_id, 'sdm_item_no_log', true );
		$item_logging_checked = isset( $get_meta ) && $get_meta === 'on' ? 'on' : 'off';

		$dl_logging_needed = true;

		// Check if download logs have been disabled (globally or per download item)
		if ( $no_logs === true || $item_logging_checked === 'on' ) {
			$dl_logging_needed = false;
		}

		// Check if we are only logging unique ips
		if ( $unique_ips === true ) {
			$check_ip = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'sdm_downloads WHERE post_id="' . $download_id . '" AND visitor_ip = "' . $ipaddress . '"' );

			//This IP is already logged for this download item. No need to log it again.
			if ( $check_ip ) {
				$dl_logging_needed = false;
			}
		}

		// Check if "Do Not Count Downloads from Bots" setting is enabled
		if ( isset( $main_option['admin_dont_log_bots'] ) ) {
			//it is. Now let's check if visitor is a bot
			if ( sdm_visitor_is_bot() ) {
				//visitor is a bot. We neither log nor count this download
				$dl_logging_needed = false;
			}
		}

		// Check if download logging and count is ignored via URL query parameter.
		if( isset( $_REQUEST['sdm_ignore_logging'] ) && $_REQUEST['sdm_ignore_logging'] == '1' ) {
			$dl_logging_needed = false;
		}

		if ( $dl_logging_needed ) {
			// We need to log this download.
			$table = $wpdb->prefix . 'sdm_downloads';
			$data  = array(
				'post_id'         => $download_id,
				'post_title'      => $download_title,
				'file_url'        => $download_link,
				'visitor_ip'      => $ipaddress,
				'date_time'       => $date_time,
				'visitor_country' => $visitor_country,
				'visitor_name'    => $visitor_name,
				'user_agent'      => $user_agent,
				'referrer_url'    => $referrer_url,
			);

			$data         = array_filter( $data ); //Remove any null values.
			$insert_table = $wpdb->insert( $table, $data );

			if ( $insert_table ) {
				//Download request was logged successfully
			} else {
				//Failed to log the download request
				wp_die( __( 'Error! Failed to log the download request in the database table', 'simple-download-monitor' ) );
			}
		}

		// Allow plugin extensions to hook into download request.
		do_action( 'sdm_process_download_request', $download_id, $download_link );

		// Check and process the download for Enhanced File Protection
		// Note: if the download is for a protected file, the function will handle the download request and then terminate the script execution.
		SDM_Protected_Download_Request_Handler::process_enhanced_protected_download_request( $download_id, $download_link );

		// Continue with the standard download process.

		// Should the item be dispatched using PHP dispatch?
		$sdm_item_php_dispatch = get_post_meta( $download_id, 'sdm_item_dispatch', true );
		
		// Trigger a filter so other plugins can override the PHP dispatch setting.
		$php_dispatch = apply_filters( 'sdm_dispatch_downloads', $sdm_item_php_dispatch );

		// Only local file can be dispatched.
		if ( $php_dispatch && ( stripos( $download_link, WP_CONTENT_URL ) === 0 ) ) {
			// Get file path
			$file_path = SDM_Utils_File_System_Related::get_uploaded_file_path_from_url($download_link);

			if ( ! is_file( $file_path ) ) {
				wp_die( __( 'File not found.', 'simple-download-monitor' ), 404 );
			}

			$is_hidden_or_noext_file_disallowed = isset( $main_option['general_allow_hidden_noext_dispatch'] ) ? empty( $main_option['general_allow_hidden_noext_dispatch'] ) : true;
			//Check if hidden or no-extension file download option is allowed.
			if( $is_hidden_or_noext_file_disallowed ){
				//Hidden or no-extension file download is NOT allowed. Let's check if this is request for a hidden or no-ext file download.
				if ( SDM_Utils_File_System_Related::check_is_hidden_or_no_extension_file($file_path) ) {
					// Found a hidden or no-ext file. Do not use PHP dispatch.
					sdm_redirect_to_url( $download_link );
					exit;
				}
			}

			// Check if the file extension is disallowed.
			if ( ! SDM_Utils_File_System_Related::check_is_file_extension_allowed($file_path) ) {
				// Disallowed file extension; Don't use PHP dispatching (instead use the normal redirect).
				sdm_redirect_to_url( $download_link );
				exit;
			}

			// Try to dispatch file (terminates script execution on success)
			sdm_dispatch_file( $file_path );
		}

		// As a fallback or when dispatching is disabled, redirect to the file
		// (and terminate script execution).
		sdm_redirect_to_url( $download_link );
	}
}

/**
 * Dispatch file with $filename and terminate script execution, if the file is
 * readable and headers have not been sent yet.
 *
 * @param string $filename
 * @return void
 */
function sdm_dispatch_file( $filename ) {
	if ( headers_sent() ) {
		trigger_error( __FUNCTION__ . ": Cannot dispatch file $filename, headers already sent." );
		return;
	}

	if ( ! is_readable( $filename ) ) {
		trigger_error( __FUNCTION__ . ": Cannot dispatch file $filename, file is not readable." );
		return;
	}

	header( 'Content-Description: File Transfer' );
	header( 'Content-Type: application/octet-stream' ); // http://stackoverflow.com/a/20509354
	header( 'Content-Disposition: attachment; filename="' . basename( $filename ) . '"' );
	header( 'Expires: 0' );
	header( 'Cache-Control: must-revalidate' );
	header( 'Pragma: public' );
	header( 'Content-Length: ' . filesize( $filename ) );

	ob_end_clean();
	readfile( $filename );
	exit;
}

/**
 * If reCAPTCHA Enabled verify answer, send it to google API
 */
function sdm_recaptcha_verify() {
    if ( ! sdm_is_any_recaptcha_enabled() ){
        // Nothing to do here.
        return;
    }

    if (!isset($_REQUEST['g-recaptcha-response'])){
        if ( $_SERVER['REQUEST_METHOD'] == 'GET' ){
            // Most probably is a download request via direct link. Visitor must validate captcha through a intermediate page.
            SDM_Debug::log('This is a download request via direct download link. So captcha needs to be verified first through an intermediate page.', true);
            sdm_show_intermediate_page_for_captcha_validation();
        } else {
            // Request method POST.
            wp_die( '<p><strong>' . __( 'Error! ', 'simple-download-monitor' ) . '</strong> ' . __( 'Google reCAPTCHA verification failed.', 'simple-download-monitor' ) . ' ' . __( 'Do you have JavaScript enabled?', 'simple-download-monitor' ) . "</p>\n\n<p><a href=" . wp_get_referer() . '>&laquo; ' . __( 'Back', 'simple-download-monitor' ) . '</a>', '', 403 );
        }
    }

    $token = sanitize_text_field( $_REQUEST['g-recaptcha-response'] );

    if ( sdm_is_recaptcha_v3_enabled() ) {
        sdm_recaptcha_v3_verify($token);
    } else if ( sdm_is_recaptcha_v2_enabled() ) {
        sdm_recaptcha_v2_verify($token);
    }
}

function sdm_recaptcha_v2_verify( $token ) {
	$main_advanced_opts   = get_option( 'sdm_advanced_options' );
	$recaptcha_secret_key = $main_advanced_opts['recaptcha_secret_key'];
	$response             = wp_remote_get( "https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret_key}&response={$token}" );
	$response             = json_decode( $response['body'], 1 );

	if ( $response['success'] ) {
		return true;
	} else {
		wp_die( '<p><strong>' . __( 'ERROR:', 'simple-download-monitor' ) . '</strong> ' . __( 'Google reCAPTCHA verification failed.', 'simple-download-monitor' ) . "</p>\n\n<p><a href=" . wp_get_referer() . '>&laquo; ' . __( 'Back', 'simple-download-monitor' ) . '</a>', '', 403 );
	}
}

function sdm_recaptcha_v3_verify( $token ) {
	$main_advanced_opts   = get_option( 'sdm_advanced_options' );
	$recaptcha_secret_key = $main_advanced_opts['recaptcha_v3_secret_key'];
	$response             = wp_remote_get( "https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret_key}&response={$token}" );
	$response             = json_decode( $response['body'], 1 );

	if ( $response['success'] ) {
		return true;
	} else {
		wp_die( '<p><strong>' . __( 'Error! ', 'simple-download-monitor' ) . '</strong> ' . __( 'Google reCAPTCHA v3 verification failed.', 'simple-download-monitor' ) . "</p>\n\n<p><a href=" . wp_get_referer() . '>&laquo; ' . __( 'Back', 'simple-download-monitor' ) . '</a>', '', 403 );
	}
}

function sdm_show_intermediate_page_for_captcha_validation() {
    $content = '';
    $content .= '<div id="sdm_captcha_verifying_content">';
    if ( sdm_is_recaptcha_v3_enabled() ) {
        wp_enqueue_script('sdm-recaptcha-v3-scripts-lib');
        $content .=  wpautop(esc_html__('Verifying that you are human...', 'simple-download-monitor'));
        $content .= '<img src="https://www.gstatic.com/recaptcha/api2/logo_48.png" alt="Verifying Captcha Spinner" class="sdm_g_captcha_spinner">';
    } else if (sdm_is_recaptcha_v2_enabled()) {
        $content .=  wpautop(esc_html__('Please verify that you are human', 'simple-download-monitor'));
        $content .= '<div class="g-recaptcha sdm-g-recaptcha" data-callback="sdm_on_intermediate_page_token_generation"></div>';
    }
    $content .= '</div>';

	sdm_dl_request_intermediate_page($content);
}