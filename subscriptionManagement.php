<?php

	class SubscriptionManagement {
		/* The possible results of processing the update form. */
		const SR_NONE = 0;
		const SR_FAILURE = 1;
		const SR_SUCCESS = 2;
		const SR_SUCCESS_UNSUB = 3;

		/* Strings that say what happened when the update form is processed successfully. */
		const MESSAGE_SUCCESS = 'Thank you, your subscription options have been updated.';
		const MESSAGE_SUCCESS_UNSUB = 'Sorry to see you go. If this was a mistake, please feel free to adjust your preferences!';
		const MESSAGE_UNAVAILABLE = 'Sorry, the subscription management form is currently unavailable. Please contact us if the problem persists.';
		const MESSAGE_EMAIL = 'Sorry - for some reason we couldn\'t automatically identify your email. Please enter it here to manage your preferences.';

		const SUCCESS_LINK_LABEL = 'Back to Subscriptions';

		public static function init() {
			wp_enqueue_style( 'subscription-management-is-css',
				plugin_dir_url( __FILE__ ) . 'css/subscription-management-is.css',
				array(), SUBSCRIPTIONMANAGEMENT__VERSION );
			wp_enqueue_script( 'subscription-management-is-js',
				plugin_dir_url( __FILE__ ) . 'js/subscription-management-is.js',
				array(), SUBSCRIPTIONMANAGEMENT__VERSION );

			add_shortcode( 'is_manage_subscriptions', array( 'SubscriptionManagement', 'manageSubscriptions' ) );
		}

		/* Configures an Infusionsoft API object. */
		public static function connect() {
			$app_name = get_option( 'subscription_isdk_app_name' );
			$api_key  = get_option( 'subscription_isdk_api_key' );
			if ( empty( $app_name ) || empty( $api_key ) ) {
				return null;
			}

			$api = new iSDK();
			if ( ! $api->cfgCon( $app_name, $api_key ) ) {
				return null;
			}

			return $api;
		}

		/* Main subscriptions form shortcode handler. */
		public static function manageSubscriptions() {
			/* If we don't have an email, ask the user to enter one. */
			$user_email = self::getUserEmail();
			if ( $user_email === null ) {
				return self::renderEmailForm();
			}

			/* Get the set of tags configured as subscription tags, and fetch the user's current tag set. */
			$api = self::connect();
			list( $contact_id, $current_tag_set ) = self::getOrCreateContact( $api, $user_email );
			$active_tags = self::loadActiveTagSet();
			if ( $contact_id === null || empty( $active_tags ) ) {
				return self::renderDialogBox( 'error', self::MESSAGE_UNAVAILABLE );
			}

			/* Process the submission. */
			$process_code = self::processUpdateForm( $api, $active_tags, $user_email, $contact_id, $current_tag_set );
			if ( $process_code >= self::SR_SUCCESS ) {
				return self::handleUpdateSuccess( $user_email, $process_code );
			}

			/* Render the update form. */

			return self::renderUpdateForm( $active_tags, $user_email, $contact_id, $current_tag_set );
		}

		/* If reCAPTCHA protection is enabled, ensure the request contains a valid user response. */
		protected static function checkReCaptcha() {
			$recaptcha_enable     = get_option( 'subscription_recaptcha_enable' );
			$recaptcha_site_key   = get_option( 'subscription_recaptcha_site_key' );
			$recaptcha_secret_key = get_option( 'subscription_recaptcha_secret_key' );
			if ( ! $recaptcha_enable || ! $recaptcha_site_key || ! $recaptcha_secret_key ) {
				return true;
			}

			if ( empty( $_POST['g-recaptcha-response'] ) ) {
				return false;
			}
			$user_response = $_POST['g-recaptcha-response'];
			$user_ip       = $_SERVER['REMOTE_ADDR'];

			$verify_url = "https://www.google.com/recaptcha/api/siteverify?secret={$recaptcha_secret_key}&response={$user_response}&remoteip={$user_ip}";
			$response   = wp_remote_get( $verify_url );
			if ( ! is_wp_error( $response ) ) {
				$response_data = wp_remote_retrieve_body( $response );
				$result        = json_decode( $response_data, true );

				return isset( $result['success'] ) && $result['success'] === true;
			}

			return false;
		}

		protected static function getUserEmail() {
			/* Look for an email in the query parameters. */
			if ( ! empty( $_REQUEST['email'] ) ) {
				$email = filter_var( $_REQUEST['email'], FILTER_VALIDATE_EMAIL );
				if ( $email ) {
					return $email;
				}
			}

			/* Use the logged in user's email if possible. */
			if ( is_user_logged_in() ) {
				$user = wp_get_current_user();
				if ( ! empty( $user->user_email ) ) {
					return $user->user_email;
				}
			}

			return null;
		}

		/* Builds markup for a form asking the user to enter an email address. */
		protected static function renderEmailForm() {
			$html = '<div id="email_tracking_manage_subscriptions">';
			$html .= self::renderDialogMessage( 'error', self::MESSAGE_EMAIL );
			$html .= '<form action="" method="post" id="sm_email_form" class="sm_form">';
			$html .= '<input type="hidden" name="formtype" value="emailupdate">';

			$html .= '<ul class="sm_fields">';

			$html .= '<li class="sm_field_text">';
			$html .= '<input type="email" name="email" placeholder="Enter Your Email" id="email" />';
			$html .= '</li>';

			$html .= '</ul>';
			$html .= '<div class="sm_footer">';
			$html .= '<button type="submit" value="Manage Your Preferences">Manage Your Preferences</button>';
			$html .= '</div>';
			$html .= '</form>';
			$html .= '</div>';

			return $html;
		}

		/* Builds markup for a success or failure message to be displayed in lieu of the form. */
		protected static function renderDialogMessage( $classes, $content ) {
			$html = '<p class="sm_dialog_message ' . $classes . '">';
			$html .= $content;
			$html .= '</p>';

			return $html;
		}

		/* Renders a message inside the common container. */
		protected static function renderDialogBox( $classes, $content, $link_text = null, $link_url = null, $redirect_url = null ) {
			$redirect_attr = $redirect_url !== null ? 'data-redirect-url="' . esc_attr( $redirect_url ) . '"' : '';
			$html          = '<div id="email_tracking_manage_subscriptions"' . $redirect_attr . '>';
			$html          .= self::renderDialogMessage( $classes, $content, $redirect_url );
			if ( $link_text ) {
				$html .= sprintf( '<a class="sm_dialog_link" href="%s">%s</a>', $link_url, $link_text );
			}
			$html .= '</div>';

			return $html;
		}

		/* Builds markup for a message to be shown after subscriptions are updated successfully. */
		protected static function handleUpdateSuccess( $user_email, $process_code ) {
			$message      = null;
			$redirect_url = null;
			if ( $process_code === self::SR_SUCCESS ) {
				$redirect_url = get_option( 'subscription_update_redirect_url' );
				$message      = self::MESSAGE_SUCCESS;
			} else if ( $process_code === self::SR_SUCCESS_UNSUB ) {
				$redirect_url = get_option( 'subscription_unsubscribe_redirect_url' );
				$message      = self::MESSAGE_SUCCESS_UNSUB;
			} else {
				throw new LogicException( 'handleUpdateSuccess() called incorrectly' );
			}

			$redirect_url = ! empty( $redirect_url ) ? site_url( $redirect_url ) : null;
			$return_url   = '?email=' . $user_email;

			return self::renderDialogBox( 'success', $message, self::SUCCESS_LINK_LABEL, $return_url, $redirect_url );
		}

		/* Maybe process a submission of the subscription form. */
		protected static function processUpdateForm( $api, $active_tags, $user_email, $contact_id, $current_tag_set ) {
			/* Is this a form submission? */
			if ( ! isset( $_POST['formtype'] ) || $_POST['formtype'] !== 'subupdate' ) {
				return self::SR_NONE;
			}

			/* Make sure we have a reCAPTCHA response if enabled. */
			if ( ! self::checkReCaptcha() ) {
				return self::SR_FAILURE;
			}

			/* Which tags did the user select? */
			$tags_to_apply  = array();
			$tags_to_remove = array();
			if ( ! empty( $_POST['unsubscribe'] ) ) {
				$unsubscribing  = true;
				$tags_to_remove = $active_tags;
			} else {
				foreach ( $active_tags as $tag_id => $tag_name ) {
					$field_name = 'tag_' . $tag_id;
					if ( empty( $_POST[ $field_name ] ) ) {
						$tags_to_remove[ $tag_id ] = $tag_id;
					} else {
						$tags_to_apply[ $tag_id ] = $tag_id;
					}
				}
				$unsubscribing = count( $tags_to_remove ) === count( $active_tags );
			}

			/* If an "unsubscribed" tag is set, toggle it according to whether the user is unsubscribing. */
			$unsub_tag_id = get_option( 'subscription_default_tag_id' );
			if ( $unsub_tag_id ) {
				$unsub_tag_id = (int) $unsub_tag_id;
				if ( $unsubscribing ) {
					$tags_to_apply[ $unsub_tag_id ] = $unsub_tag_id;
				} else {
					$tags_to_remove[ $unsub_tag_id ] = $unsub_tag_id;
				}
			}

			/* Apply the update tag unconditionally. */
			$update_tag_id = get_option( 'subscription_update_tag_id' );
			if ( $update_tag_id ) {
				$update_tag_id                   = (int) $update_tag_id;
				$tags_to_apply[ $update_tag_id ] = $update_tag_id;
			}

			/* Apply and remove tags from the contact as necessary. */
			$tags_to_apply  = array_diff_key( $tags_to_apply, $current_tag_set );
			$tags_to_remove = array_intersect_key( $tags_to_remove, $current_tag_set );
			foreach ( array_keys( $tags_to_apply ) as $tag_id ) {
				$api->grpAssign( $contact_id, $tag_id );
			}
			foreach ( array_keys( $tags_to_remove ) as $tag_id ) {
				$api->grpRemove( $contact_id, $tag_id );
			}

			return $unsubscribing ? self::SR_SUCCESS_UNSUB : self::SR_SUCCESS;
		}

		/* Retrieves a user's contact ID and assigned tags, creating a contact if necessary. */
		protected static function getOrCreateContact( $api, $user_email ) {
			if ( $api === null ) {
				return array( null, null );
			}
			$contacts = $api->findByEmail( $user_email, array( 'Id', 'Groups' ) );
			if ( empty( $contacts ) ) {
				$contact_id = $api->addWithDupCheck( array( 'Email' => $user_email ), 'Email' );
				if ( empty( $contact_id ) ) {
					return array( null, null );
				}
				$contacts = $api->findByEmail( $user_email, array( 'Id', 'Groups' ) );
			}
			if ( ! is_array( $contacts ) || ! isset( $contacts[0]['Id'] ) ) {
				return array( null, null );
			}

			list( $contact ) = $contacts;
			$contact_id = (int) $contact['Id'];
			$tag_ids    = ! empty( $contact['Groups'] ) ? explode( ',', $contact['Groups'] ) : array();
			$tag_ids    = array_map( 'intval', $tag_ids );
			$tag_set    = array();
			foreach ( $tag_ids as $tag_id ) {
				$tag_set[ $tag_id ] = $tag_id;
			}

			return array( $contact_id, $tag_set );
		}

		/* Load the array of tag IDs which have been configured as subscription tags. */
		public static function loadActiveTagSet() {
			$tag_set = get_option( 'subscription_tag_ids' );

			return $tag_set ? $tag_set : array();
		}

		/* Render the subscription update form. */
		protected static function renderUpdateForm( $active_tags, $user_email, $contact_id, $current_tag_set ) {
			/* Render the form. */
			$html = '<div id="email_tracking_manage_subscriptions">';
			$html .= '<form action="" method="post" id="sm_form" class="sm_form">';

			$html .= '<ul class="sm_fields">';

			/* reCAPCHA field. */
			$recaptcha_enable   = get_option( 'subscription_recaptcha_enable' );
			$recaptcha_site_key = get_option( 'subscription_recaptcha_site_key' );
			if ( $recaptcha_enable && $recaptcha_site_key ) {
				$html .= '<li class="sm_field_recaptcha" style="margin-bottom: 1.5rem;">';
				$html .= '<div class="g-recaptcha" data-sitekey="' . esc_attr( $recaptcha_site_key ) . '" data-size="standard"></div>';
				$html .= '<script src="https://www.google.com/recaptcha/api.js"></script>';
				$html .= '</li>';
			}

			$active_only = get_option( 'only_show_active_tags' );

			/* Tag checkboxes. */
			if ($active_only) {
				foreach ( $active_tags as $tag_id => $tag_name ) {
					$input_name    = 'tag_' . $tag_id;
					$input_checked = ! empty( $current_tag_set[ $tag_id ] ) ? 'checked' : '';

					if ($input_checked == 'checked') {
						$html .= '<li class="sm_field_check">';
						$html .= sprintf( '<input type="checkbox" id="%s" name="%s" value="1" %s>', $input_name, $input_name, $input_checked );
						$html .= '<label class="toggle" for="' . $input_name . '"></label>';
						$html .= '<label class="caption" for="' . $input_name . '">' . $tag_name . '</label>';
						$html .= '</li>';
					}
				}
			} else {
				foreach ( $active_tags as $tag_id => $tag_name ) {

					$input_name    = 'tag_' . $tag_id;
					$input_checked = ! empty( $current_tag_set[ $tag_id ] ) ? 'checked' : '';

					$html .= '<li class="sm_field_check">';
					$html .= sprintf( '<input type="checkbox" id="%s" name="%s" value="1" %s>', $input_name, $input_name, $input_checked );
					$html .= '<label class="toggle" for="' . $input_name . '"></label>';
					$html .= '<label class="caption" for="' . $input_name . '">' . $tag_name . '</label>';
					$html .= '</li>';
				}
			}
			$html .= '<li class="sm_field_button">';
			$html .= '<button type="submit" name="update" value="1" class="save">Save Preferences</button>';
			$html .= '</li>';

			$html .= '<li class="sm_field_separator">Or</li>';

			/* "Unsubscribe from All" option. */
			$html .= '<li class="sm_field_button">';
			$html .= '<button type="submit" name="unsubscribe" value="1" class="unsubscribe">Unsubscribe from All</button>';
			$html .= '</li>';

			$html .= '</ul>';

			$html .= '<input type="hidden" name="email" value="' . $user_email . '">';
			$html .= '<input type="hidden" name="formtype" value="subupdate">';
			$html .= '</form>';
			$html .= '</div>';

			return $html;
		}

	}
