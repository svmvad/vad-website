<?php



namespace Netdust\VAD\Services\MagicLink;


Class NTDST_MagicLink_Hooks {
    /**
     * Process login request
     *
     * @return array
     */
    function process_login_request() {
        $show_form    = true;
        $errors       = new \WP_Error();
        $info         = '<p class="message">' . __( 'Please enter your username or email address. You will receive an email message to log in. <a href="'.wp_registration_url().'" >Create an account</a> if you are not registred yet.', 'magic-login' ) . '</p>';
        $is_processed = false;

        // process form request
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['log'] ) ) {
            $user_name = sanitize_user( wp_unslash( $_POST['log'] ) );
            $user      = get_user_by( 'login', $user_name );

            if ( ! $user && strpos( $user_name, '@' ) ) {
                $user = get_user_by( 'email', $user_name );
            }

            $is_processed = true;

            /**
             * Short circuit to prevent unwanted requests
             */
            $send_link = apply_filters( 'magic_login_pre_send_login_link', null, $user );

            if ( ! is_a( $user, '\WP_User' ) ) {
                $info      = '';
                $errors    = new \WP_Error( 'missing_user', __( 'There is no account with that username or email address. <a href="'.wp_registration_url().'" >Create an account</a> if you are not registred yet.', 'magic-login' ) );
                $show_form = true;
            } elseif ( null !== $send_link ) {
                $info      = '';
                $errors    = $send_link;
                $show_form = false;
            } else {
                $errors = $this->send_login_link( $user );
            }

            if ( ! is_wp_error( $errors ) ) {
                $show_form = false;
                $info      = '<p class="message magic_login_block_login_success">' . __( 'Please check your inbox for the login link. If you did not receive a login email, check your spam folder too.', 'magic-login' ) . '</p>';
            }
        }

        return [
            'show_form'    => $show_form,
            'errors'       => $errors,
            'info'         => $info,
            'is_processed' => $is_processed,
        ];
    }

    /**
     * Login form actions
     */
    function action_magic_login() {

        $login_request = $this->process_login_request();

        login_header( esc_html__( 'Log in', 'magic-login' ), $login_request['info'], $login_request['errors'] );

        if ( $login_request['show_form'] ) {
            $this->login_form();
        }

        login_footer();
        exit;
    }


    /**
     * Send magic link to user
     *
     * @param object $user \WP_User object
     *
     * @return bool
     */
    function send_login_link( $user ) {
        if ( is_multisite() ) {
            $site_name = get_network()->site_name;
        } else {
            $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        }

        $login_link  = app('magiclink')->create_login_link( $user );
        $login_email = app('magiclink')->login_email;

        list( $token_ttl, $selected_interval ) = app('magiclink')->get_ttl_with_interval( app('magiclink')->token_ttl );
        $selected_interval_str                 = strtolower( $selected_interval );

        $allowed_intervals = app('magiclink')->get_allowed_intervals();

        if ( isset( $allowed_intervals[ $selected_interval ] ) ) {
            $selected_interval_str = strtolower( $allowed_intervals[ $selected_interval ] ); // translated interval
        }

        $placeholder_values = [
            '{{SITEURL}}'               => home_url(),
            '{{USERNAME}}'              => $user->user_login,
            '{{SITENAME}}'              => $site_name,
            '{{EXPIRES}}'               => app('magiclink')->token_ttl,
            '{{EXPIRES_WITH_INTERVAL}}' => $token_ttl . ' ' . $selected_interval_str,
            '{{MAGIC_LINK}}'            => $login_link,
        ];

        $login_email   = str_replace( array_keys( $placeholder_values ), $placeholder_values, $login_email );
        $email_subject = sprintf( __( 'Log in to %s', 'magic-login' ), $site_name ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment

        $login_email   = apply_filters( 'magic_login_email_content', $login_email, $placeholder_values );
        $email_subject = apply_filters( 'magic_login_email_subject', $email_subject );

        $headers = apply_filters( 'magic_login_email_headers', array( 'Content-Type: text/html; charset=UTF-8' ) );

        foreach ( (array) $headers as $header ) {
            if ( false !== stripos( $header, 'text/html' ) ) {
                // convert line breaks to br
                $login_email = nl2br( $login_email );
                break;
            }
        }

        do_action( 'magic_login_send_login_link', $user );

        return wp_mail( $user->user_email, $email_subject, $login_email, $headers );
    }


    /**
     * login form
     */
    function login_form() {
        ?>
        <form name="magicloginform" id="magicloginform" action="<?php echo esc_url( site_url( 'wp-login.php?action=magic_login', 'login_post' ) ); ?>" method="post" autocomplete="off">
            <p>
                <label for="user_login"><?php esc_html_e( 'Username or Email Address', 'magic-login' ); ?></label>
                <input type="text" name="log" id="user_login" class="input" value="" size="20" autocapitalize="off" required />
            </p>
            <?php

            /**
             * Fires following the 'email' field in the login form.
             *
             * @since 1.0
             */
            do_action( 'magic_login_form' );

            ?>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" style="float: none;width: 100%;" class="magic-login-submit button button-primary button-hero" value="<?php esc_attr_e( 'Send me the link', 'magic-login' ); ?>" />
                <input type="hidden" name="http_referer" value="<?= $_REQUEST['redirect_to']; ?>" />
                <input type="hidden" name="testcookie" value="1" />
            </p>
        </form>
        <?php
    }

    /**
     * Redirect to magic login page once it used as default login method
     */
    function maybe_redirect() {
        global $pagenow;

        if ( 'wp-login.php' !== $pagenow ) {
            return;
        }

        if ( ! empty( ( $_POST ) ) ) {
            return;
        }

        if ( isset( $_REQUEST['interim-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        /**
         * short-circuit if want to interrupt redirect
         */
        if ( null !== apply_filters( 'magic_login_before_login_form_redirect', null ) ) {
            return;
        }

        if ( true === app('magiclink')->is_default ) {
            wp_safe_redirect( esc_url_raw( add_query_arg( 'action', 'magic_login' ) ) );
            exit;
        }
    }


    /**
     * Handle login request
     */
    function handle_login_request() {
        global $pagenow;

        /**
         * Since 1.2.2 $pagenow control has been deprecated
         * in favor compatibility with 3rd party plugins
         */
        if ( 'wp-login.php' !== $pagenow && empty( $_GET['magic-login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( empty( $_GET['user_id'] ) || empty( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        do_action( 'magic_login_handle_login_request' );

        if ( is_user_logged_in() ) {
            $error = sprintf( __( 'Invalid magic login token, but you are logged in as \'%1$s\'. <a href="%2$s">Go to the dashboard instead</a>?', 'magic-login' ), wp_get_current_user()->user_login, admin_url() );
        } else {
            $error = sprintf( __( 'Invalid magic login token. <a href="%s">Try signing in instead</a>?', 'magic-login' ), wp_login_url() );
        }

        // Use a generic error message to ensure user ids can't be sniffed
        $user = get_user_by( 'id', (int) $_GET['user_id'] ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $user ) {
            do_action( 'magic_login_invalid_user' );
            wp_die( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        $tokens        = app('magiclink')->get_user_tokens( $user->ID, true );
        $is_valid      = false;
        $current_token = null;
        foreach ( $tokens as $i => $token_data ) {
            if ( hash_equals( $token_data['token'], $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $is_valid      = true;
                $current_token = $token_data;
                unset( $tokens[ $i ] );
                break;
            }
        }

        if ( ! $is_valid ) {
            do_action( 'magic_login_invalid_token' );

            /**
             * Invalid token error message.
             * Since 1.2
             */
            $error_message = apply_filters( 'magic_login_invalid_token_error_message', $error );
            wp_die( wp_kses_post( $error_message ) );
        }

        /**
         * Fires before setting up auth cookie
         *
         * @since 1.0
         */
        do_action( 'magic_login_before_login', $user, $current_token );

        update_user_meta( $user->ID, NTDST_MagicLink::$TOKEN_USER_META, $tokens );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );

        /**
         * Fires after setting up auth cookie
         *
         * @since 1.0
         */
        do_action( 'magic_login_logged_in', $user );

        /**
         * Some plugins integrated with core's wp_login hook.
         * So fire it here too.
         *
         * @since 1.3
         */
        do_action( 'wp_login', $user->user_login, $user );

        $default_redirect = app('magiclink')->get_user_default_redirect( $user );
        $login_redirect   = apply_filters( 'magic_login_redirect', $default_redirect, $user );
        wp_safe_redirect( $login_redirect );
        exit;
    }

    /**
     * Handle cleanup process for expired tokens
     *
     * @param int $user_id user id
     */
    function cleanup_expired_tokens( $user_id ) {

        $ttl         = absint( app('magiclink')->token_ttl );
        $tokens      = get_user_meta( $user_id, NTDST_MagicLink::$TOKEN_USER_META, true );
        $tokens      = is_string( $tokens ) ? array( $tokens ) : $tokens;
        $live_tokens = array();

        foreach ( $tokens as $token ) {
            if ( empty( $token ) || ! isset( $token['time'] ) ) {
                continue;
            }

            // not expired yet
            if ( absint( $token['time'] ) + ( $ttl * MINUTE_IN_SECONDS ) > time() ) {
                $live_tokens[] = $token;
            }
        }

        update_user_meta( $user_id, NTDST_MagicLink::$TOKEN_USER_META, $live_tokens );
    }

    /**
     * Add login button to wp-login.php
     */
    function print_login_button() {
        if ( ! app('magiclink')->add_login_button ) {
            return;
        }

        $login_url = site_url( 'wp-login.php?action=magic_login', 'login_post' );
        ?>
        <script type="text/javascript">
            (function () {
                document.getElementById('lostpasswordform').insertAdjacentHTML(
                    'beforeend',
                    '<div id="continue-with-magic-login" class="continue-with-magic-login" style="display:inline-block;font-size:small;text-align:right;width:100%;">of ' +
                    '<a href="<?php echo esc_url( $login_url ); ?>" class="link">' +
                    '<?php esc_html_e( 'Send me the login link', 'magic-login' ); ?>' +
                    '</a>' +
                    '</div>'
                );
            })();
        </script>
        <?php

    }



}