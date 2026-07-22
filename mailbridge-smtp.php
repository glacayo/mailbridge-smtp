<?php
/**
 * Plugin Name: MailBridge SMTP
 * Plugin URI: https://github.com/glacayo/mailbridge-smtp
 * Description: General SMTP delivery for WordPress emails, including forms and plugins that use wp_mail().
 * Version: 1.0.0
 * Author: Geovanny Lacayo
 * Author URI: https://github.com/glacayo
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mailbridge-smtp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Network: false
 *
 * @package MailBridge_SMTP
 */

// Security: Prevent direct access.
defined('ABSPATH') || exit;

// Security: Define plugin constants.
define('MAILBRIDGE_SMTP_VERSION', '1.0.0');
define('MAILBRIDGE_SMTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAILBRIDGE_SMTP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MAILBRIDGE_SMTP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('MAILBRIDGE_SMTP_TEXT_DOMAIN', 'mailbridge-smtp');
define('MAILBRIDGE_SMTP_OPTION_NAME', 'mailbridge_smtp_options');
define('MAILBRIDGE_SMTP_LAST_ERROR_TRANSIENT', 'mailbridge_smtp_last_error');

/**
 * Get default SMTP settings.
 *
 * @return array
 */
function mailbridge_smtp_get_default_settings() {
    return [
        'enabled' => 0,
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'authentication' => 1,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '',
        'smtp_debug' => 0,
    ];
}

// Security: Minimum WordPress version check.
if (version_compare($GLOBALS['wp_version'] ?? '0', '5.8', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('MailBridge SMTP requires WordPress 5.8 or later.', 'mailbridge-smtp');
        echo '</p></div>';
    });
    return;
}

/**
 * Main plugin class.
 */
final class MailBridge_SMTP_Plugin {

    /**
     * Single instance of the plugin.
     *
     * @var MailBridge_SMTP_Plugin|null
     */
    private static $instance = null;

    /**
     * Flag to bypass SMTP for diagnostic tests.
     *
     * @var bool
     */
    private $bypass_smtp = false;

    /**
     * Get single instance.
     *
     * @return MailBridge_SMTP_Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_textdomain();
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('phpmailer_init', [$this, 'configure_smtp']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_mail_failed', [$this, 'capture_mail_failure']);

        add_filter('plugin_action_links_' . MAILBRIDGE_SMTP_PLUGIN_BASENAME, [$this, 'add_action_links']);

        add_action('wp_ajax_mailbridge_smtp_test', [$this, 'handle_test_connection']);
        add_action('wp_ajax_mailbridge_smtp_diagnostic', [$this, 'handle_diagnostic']);
    }

    /**
     * Load plugin text domain.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            MAILBRIDGE_SMTP_TEXT_DOMAIN,
            false,
            dirname(MAILBRIDGE_SMTP_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Add admin menu under Tools.
     *
     * @return void
     */
    public function add_admin_menu() {
        add_management_page(
            esc_html__('MailBridge SMTP Settings', 'mailbridge-smtp'),
            esc_html__('MailBridge SMTP', 'mailbridge-smtp'),
            'manage_options',
            'mailbridge-smtp',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets($hook) {
        if ('tools_page_mailbridge-smtp' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'mailbridge-smtp-admin',
            MAILBRIDGE_SMTP_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MAILBRIDGE_SMTP_VERSION
        );

        wp_enqueue_script(
            'mailbridge-smtp-admin',
            MAILBRIDGE_SMTP_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            MAILBRIDGE_SMTP_VERSION,
            true
        );

        wp_localize_script('mailbridge-smtp-admin', 'mailbridgeSmtp', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mailbridge_smtp_test'),
            'testing' => esc_html__('Testing connection...', 'mailbridge-smtp'),
            'success' => esc_html__('Connection successful!', 'mailbridge-smtp'),
            'error' => esc_html__('Connection failed. Please check your settings.', 'mailbridge-smtp'),
        ]);

        wp_localize_script('mailbridge-smtp-admin', 'mailbridgeSmtpDiagnostic', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mailbridge_smtp_diagnostic'),
            'testing' => esc_html__('Testing...', 'mailbridge-smtp'),
            'success_wp' => esc_html__('wp_mail() accepted the send request. Check your inbox.', 'mailbridge-smtp'),
            'success_mail' => esc_html__('mail() accepted the send request. Check your inbox.', 'mailbridge-smtp'),
            'error' => esc_html__('Test failed. Check diagnostics for details.', 'mailbridge-smtp'),
            'rate_limited' => esc_html__('Please wait 30 seconds between tests.', 'mailbridge-smtp'),
        ]);
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings() {
        if (!current_user_can('manage_options')) {
            return;
        }

        register_setting(
            'mailbridge_smtp_settings',
            MAILBRIDGE_SMTP_OPTION_NAME,
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => mailbridge_smtp_get_default_settings(),
            ]
        );
    }

    /**
     * Get saved options merged with defaults.
     *
     * @return array
     */
    private function get_options() {
        $options = get_option(MAILBRIDGE_SMTP_OPTION_NAME, []);

        if (!is_array($options)) {
            $options = [];
        }

        return wp_parse_args($options, mailbridge_smtp_get_default_settings());
    }

    /**
     * Sanitize settings before save.
     *
     * @param array $input Raw input settings.
     * @return array Sanitized settings.
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        $defaults = mailbridge_smtp_get_default_settings();
        $input = is_array($input) ? $input : [];

        foreach ($defaults as $key => $default) {
            if (!isset($input[$key])) {
                $sanitized[$key] = in_array($key, ['enabled', 'authentication', 'smtp_debug'], true) ? 0 : $default;
                continue;
            }

            switch ($key) {
                case 'enabled':
                case 'authentication':
                case 'smtp_debug':
                    $sanitized[$key] = absint($input[$key]) ? 1 : 0;
                    break;

                case 'host':
                    $sanitized[$key] = sanitize_text_field($input[$key]);
                    if (!empty($sanitized[$key]) && !$this->is_valid_host($sanitized[$key])) {
                        add_settings_error(
                            'mailbridge_smtp',
                            'invalid_host',
                            esc_html__('Invalid SMTP host format.', 'mailbridge-smtp')
                        );
                        $sanitized[$key] = $default;
                    }
                    break;

                case 'port':
                    $port = absint($input[$key]);
                    $sanitized[$key] = ($port >= 1 && $port <= 65535) ? $port : $default;
                    break;

                case 'encryption':
                    $allowed = ['none', 'ssl', 'tls'];
                    $sanitized[$key] = in_array($input[$key], $allowed, true) ? $input[$key] : $default;
                    break;

                case 'username':
                    $sanitized[$key] = sanitize_email($input[$key]);
                    if (empty($sanitized[$key]) && !empty($input[$key])) {
                        $sanitized[$key] = sanitize_text_field($input[$key]);
                    }
                    break;

                case 'password':
                    if (!empty($input[$key]) && '********' !== $input[$key]) {
                        $sanitized[$key] = $this->encrypt_password($input[$key]);
                        if ('' === $sanitized[$key]) {
                            add_settings_error(
                                'mailbridge_smtp',
                                'password_encrypt_failed',
                                esc_html__('SMTP password could not be encrypted because OpenSSL is unavailable or incomplete on this server.', 'mailbridge-smtp')
                            );
                        }
                    } else {
                        $existing = get_option(MAILBRIDGE_SMTP_OPTION_NAME, []);
                        $sanitized[$key] = is_array($existing) ? ($existing['password'] ?? '') : '';
                    }
                    break;

                case 'from_email':
                    $sanitized[$key] = sanitize_email($input[$key]);
                    break;

                case 'from_name':
                    $sanitized[$key] = sanitize_text_field($input[$key]);
                    break;

                default:
                    $sanitized[$key] = sanitize_text_field($input[$key]);
            }
        }

        return $sanitized;
    }

    /**
     * Validate host format.
     *
     * @param string $host Hostname to validate.
     * @return bool
     */
    private function is_valid_host($host) {
        if (preg_match('/[<>"\']/', $host)) {
            return false;
        }

        if ('localhost' === strtolower($host)) {
            return true;
        }

        return (bool) preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $host)
            || filter_var($host, FILTER_VALIDATE_IP);
    }

    /**
     * Check if PHP native mail() is available for diagnostics.
     *
     * @return bool
     */
    private function is_php_mail_available() {
        $disabled_functions = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $disabled_functions = array_map('strtolower', $disabled_functions);

        return function_exists('mail')
            && is_callable('mail')
            && !in_array('mail', $disabled_functions, true);
    }

    /**
     * Check if OpenSSL encryption helpers required for password storage are available.
     *
     * @return bool
     */
    private function is_openssl_available() {
        $disabled_functions = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $disabled_functions = array_map('strtolower', $disabled_functions);

        foreach (['openssl_cipher_iv_length', 'openssl_encrypt', 'openssl_decrypt'] as $function) {
            if (!function_exists($function) || !is_callable($function) || in_array($function, $disabled_functions, true)) {
                return false;
            }
        }

        return openssl_cipher_iv_length('aes-256-gcm') > 0;
    }

    /**
     * Get a clear admin-visible OpenSSL availability error.
     *
     * @return string
     */
    private function get_openssl_unavailable_message() {
        return __('OpenSSL is unavailable or incomplete on this server, so MailBridge SMTP cannot safely store SMTP passwords.', 'mailbridge-smtp');
    }

    /**
     * Store the latest admin-visible SMTP error without logging secrets.
     *
     * @param string $message Error message.
     * @param string $source Error source identifier.
     * @return void
     */
    private function record_last_error($message, $source = 'smtp') {
        $message = sanitize_text_field(wp_strip_all_tags((string) $message));

        if ('' === $message) {
            return;
        }

        set_transient(
            MAILBRIDGE_SMTP_LAST_ERROR_TRANSIENT,
            [
                'message' => $message,
                'source' => sanitize_key($source),
                'time' => current_time('mysql'),
            ],
            DAY_IN_SECONDS
        );
    }

    /**
     * Get the latest admin-visible SMTP error.
     *
     * @return array|null
     */
    private function get_last_error() {
        $last_error = get_transient(MAILBRIDGE_SMTP_LAST_ERROR_TRANSIENT);

        if (!is_array($last_error) || empty($last_error['message'])) {
            return null;
        }

        return $last_error;
    }

    /**
     * Render the latest SMTP error below WordPress settings errors.
     *
     * @return void
     */
    private function render_last_error_notice() {
        $last_error = $this->get_last_error();

        if (null === $last_error) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('Last MailBridge SMTP error:', 'mailbridge-smtp'); ?></strong>
                <?php echo esc_html($last_error['message']); ?>
            </p>
            <?php if (!empty($last_error['time'])) : ?>
                <p><?php printf(esc_html__('Recorded at: %s', 'mailbridge-smtp'), esc_html($last_error['time'])); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Capture WordPress mail failures while MailBridge SMTP is enabled.
     *
     * @param WP_Error $error WordPress mail error.
     * @return void
     */
    public function capture_mail_failure($error) {
        if (!($error instanceof WP_Error)) {
            return;
        }

        $options = $this->get_options();
        if (empty($options['enabled'])) {
            return;
        }

        $message = $error->get_error_message();
        if ('' === $message) {
            $message = __('WordPress reported a mail delivery failure.', 'mailbridge-smtp');
        }

        $this->record_last_error($message, 'wp_mail_failed');
    }

    /**
     * Redact SMTP authentication data before writing debug output.
     *
     * @param string $line Debug output line.
     * @param int    $auth_lines_remaining Number of AUTH LOGIN credential lines to hide.
     * @return string Redacted debug output line.
     */
    private function redact_smtp_debug_line($line, &$auth_lines_remaining) {
        if (preg_match('/AUTH\s+PLAIN\s+/i', $line)) {
            $auth_lines_remaining = 0;
            return preg_replace('/AUTH\s+PLAIN\s+.*/i', 'AUTH PLAIN [credentials hidden]', $line);
        }

        if (preg_match('/AUTH\s+LOGIN/i', $line)) {
            $auth_lines_remaining = 2;
            return $line;
        }

        if ($auth_lines_remaining > 0 && false !== stripos($line, 'CLIENT -> SERVER:')) {
            $auth_lines_remaining--;
            return preg_replace('/(CLIENT -> SERVER:\s*).*/i', '$1[credentials hidden]', $line);
        }

        return $line;
    }

    /**
     * Encrypt password for storage.
     *
     * @param string $password Plain text password.
     * @return string Encrypted password.
     */
    private function encrypt_password($password) {
        if (!$this->is_openssl_available()) {
            $this->record_last_error($this->get_openssl_unavailable_message(), 'openssl');
            return '';
        }

        $key = hash_hmac('sha256', wp_salt('auth'), 'mailbridge_smtp_v1', true);
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        $ciphertext = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $ciphertext) {
            $this->record_last_error(__('SMTP password could not be encrypted; check the server OpenSSL configuration.', 'mailbridge-smtp'), 'password_encrypt');
            return '';
        }

        $payload = wp_json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ]);

        if (false === $payload) {
            $this->record_last_error(__('SMTP password could not be encoded for storage.', 'mailbridge-smtp'), 'password_encrypt');
            return '';
        }

        return base64_encode($payload);
    }

    /**
     * Decrypt stored password.
     *
     * @param string $encrypted Encrypted password.
     * @return string Decrypted password.
     */
    private function decrypt_password($encrypted) {
        if ('' === (string) $encrypted) {
            return '';
        }

        $decrypt_error = __('Stored SMTP password could not be decrypted; re-enter the password.', 'mailbridge-smtp');
        if (!$this->is_openssl_available()) {
            $this->record_last_error($this->get_openssl_unavailable_message(), 'openssl');
            return '';
        }

        $key = hash_hmac('sha256', wp_salt('auth'), 'mailbridge_smtp_v1', true);
        $data = base64_decode($encrypted, true);

        if (false === $data) {
            $this->record_last_error($decrypt_error, 'password_decrypt');
            return '';
        }

        $payload = json_decode($data, true);
        if (!is_array($payload) || empty($payload['iv']) || empty($payload['tag']) || empty($payload['ciphertext'])) {
            $this->record_last_error($decrypt_error, 'password_decrypt');
            return '';
        }

        $iv = base64_decode($payload['iv'], true);
        $tag = base64_decode($payload['tag'], true);
        $ciphertext = base64_decode($payload['ciphertext'], true);

        if (false === $iv || false === $tag || false === $ciphertext) {
            $this->record_last_error($decrypt_error, 'password_decrypt');
            return '';
        }

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $decrypted) {
            $this->record_last_error($decrypt_error, 'password_decrypt');
            return '';
        }

        return $decrypted;
    }

    /**
     * Configure PHPMailer for SMTP.
     *
     * @param PHPMailer $phpmailer PHPMailer instance.
     * @return void
     */
    public function configure_smtp($phpmailer) {
        if ($this->bypass_smtp) {
            return;
        }

        $options = $this->get_options();

        if (empty($options['enabled']) || empty($options['host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = sanitize_text_field($options['host']);
        $phpmailer->Port = absint($options['port']);

        if ('none' !== $options['encryption']) {
            $phpmailer->SMTPSecure = $options['encryption'];
        }

        if (!empty($options['authentication'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $options['username'];
            $phpmailer->Password = $this->decrypt_password($options['password']);
        }

        if (!empty($options['from_email'])) {
            $phpmailer->From = sanitize_email($options['from_email']);
        }

        if (!empty($options['from_name'])) {
            $phpmailer->FromName = sanitize_text_field($options['from_name']);
        }

        if (!empty($options['smtp_debug'])
            && current_user_can('manage_options')
            && defined('WP_DEBUG') && WP_DEBUG
            && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG
        ) {
            $phpmailer->SMTPDebug = 2;
            $auth_debug_lines_remaining = 0;
            $phpmailer->Debugoutput = function($str, $level) use (&$auth_debug_lines_remaining) {
                $line = $this->redact_smtp_debug_line(trim($str), $auth_debug_lines_remaining);
                error_log('MailBridge SMTP Debug [' . $level . ']: ' . $line);
            };
        }
    }

    /**
     * Get passive diagnostics data.
     *
     * @return array
     */
    private function get_diagnostics_data() {
        return [
            'php_mail_available' => $this->is_php_mail_available(),
            'wp_mail_ready' => function_exists('wp_mail'),
            'php_version' => phpversion(),
            'php_sapi' => php_sapi_name(),
            'disable_functions' => ini_get('disable_functions') ?: 'None',
            'sendmail_path' => ini_get('sendmail_path') ?: 'Not set',
            'smtp_host' => ini_get('SMTP') ?: 'Not set',
            'smtp_port' => ini_get('smtp_port') ?: 'Not set',
        ];
    }

    /**
     * Render diagnostics section.
     *
     * @return void
     */
    private function render_diagnostics_section() {
        $diagnostics = $this->get_diagnostics_data();
        ?>
        <div class="mailbridge-smtp-diagnostics">
            <h2><?php esc_html_e('Mail Diagnostics', 'mailbridge-smtp'); ?></h2>
            <p><?php esc_html_e('Check if your server allows WordPress and PHP mail functions. Some hosting providers disable these to prevent spam.', 'mailbridge-smtp'); ?></p>

            <div class="mailbridge-diagnostic-status">
                <div class="mailbridge-diagnostic-card <?php echo $diagnostics['php_mail_available'] ? 'success' : 'error'; ?>">
                    <strong><?php esc_html_e('PHP mail()', 'mailbridge-smtp'); ?></strong><br>
                    <?php echo $diagnostics['php_mail_available']
                        ? esc_html__('Available', 'mailbridge-smtp')
                        : esc_html__('Disabled or Unavailable', 'mailbridge-smtp'); ?>
                </div>
                <div class="mailbridge-diagnostic-card <?php echo $diagnostics['wp_mail_ready'] ? 'success' : 'warning'; ?>">
                    <strong><?php esc_html_e('WordPress wp_mail()', 'mailbridge-smtp'); ?></strong><br>
                    <?php echo $diagnostics['wp_mail_ready']
                        ? esc_html__('Ready', 'mailbridge-smtp')
                        : esc_html__('Not Available', 'mailbridge-smtp'); ?>
                </div>
            </div>

            <div class="mailbridge-diagnostic-config">
                <h3><?php esc_html_e('Server Mail Configuration', 'mailbridge-smtp'); ?></h3>
                <table>
                    <tr>
                        <th><?php esc_html_e('PHP Version', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['php_version']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('PHP SAPI', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['php_sapi']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('disable_functions', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['disable_functions']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('sendmail_path', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['sendmail_path']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('SMTP Host (php.ini)', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['smtp_host']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('SMTP Port (php.ini)', 'mailbridge-smtp'); ?></th>
                        <td><?php echo esc_html($diagnostics['smtp_port']); ?></td>
                    </tr>
                </table>
            </div>

            <h3><?php esc_html_e('Send Tests', 'mailbridge-smtp'); ?></h3>
            <p><?php esc_html_e('Test if your server can actually send emails using native WordPress or PHP functions.', 'mailbridge-smtp'); ?></p>

            <p>
                <label for="mailbridge_diagnostic_email">
                    <?php esc_html_e('Test Email Address:', 'mailbridge-smtp'); ?>
                </label>
                <input type="email"
                       id="mailbridge_diagnostic_email"
                       class="regular-text"
                       value="<?php echo esc_attr(get_option('admin_email')); ?>">
            </p>

            <p>
                <button type="button" id="mailbridge_diagnostic_wp_btn" class="button button-secondary">
                    <?php esc_html_e('Test wp_mail() (Native)', 'mailbridge-smtp'); ?>
                </button>
                <button type="button" id="mailbridge_diagnostic_mail_btn" class="button button-secondary">
                    <?php esc_html_e('Test mail() (PHP)', 'mailbridge-smtp'); ?>
                </button>
            </p>

            <div id="mailbridge_diagnostic_result" class="mailbridge-smtp-result"></div>
        </div>
        <?php
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'mailbridge-smtp'));
        }

        $options = $this->get_options();
        ?>
        <div class="wrap mailbridge-smtp-wrap">
            <h1><?php esc_html_e('MailBridge SMTP Settings', 'mailbridge-smtp'); ?></h1>

            <?php settings_errors('mailbridge_smtp'); ?>
            <?php $this->render_last_error_notice(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('mailbridge_smtp_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_enabled">
                                <?php esc_html_e('Enable SMTP', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox"
                                   id="mailbridge_smtp_enabled"
                                   name="mailbridge_smtp_options[enabled]"
                                   value="1"
                                   <?php checked(1, $options['enabled']); ?>>
                            <p class="description">
                                <?php esc_html_e('Enable SMTP for all WordPress emails, including forms and plugin notifications.', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_host">
                                <?php esc_html_e('SMTP Host', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mailbridge_smtp_host"
                                   name="mailbridge_smtp_options[host]"
                                   value="<?php echo esc_attr($options['host']); ?>"
                                   class="regular-text"
                                   placeholder="smtp.gmail.com"
                                   required>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_port">
                                <?php esc_html_e('SMTP Port', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number"
                                   id="mailbridge_smtp_port"
                                   name="mailbridge_smtp_options[port]"
                                   value="<?php echo esc_attr($options['port']); ?>"
                                   class="small-text"
                                   min="1"
                                   max="65535"
                                   required>
                            <p class="description">
                                <?php esc_html_e('Common ports: 25 (no encryption), 465 (SSL), 587 (TLS).', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_encryption">
                                <?php esc_html_e('Encryption', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <select id="mailbridge_smtp_encryption" name="mailbridge_smtp_options[encryption]">
                                <option value="none" <?php selected($options['encryption'], 'none'); ?>>
                                    <?php esc_html_e('None', 'mailbridge-smtp'); ?>
                                </option>
                                <option value="ssl" <?php selected($options['encryption'], 'ssl'); ?>>
                                    <?php esc_html_e('SSL', 'mailbridge-smtp'); ?>
                                </option>
                                <option value="tls" <?php selected($options['encryption'], 'tls'); ?>>
                                    <?php esc_html_e('TLS', 'mailbridge-smtp'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_auth">
                                <?php esc_html_e('Authentication', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox"
                                   id="mailbridge_smtp_auth"
                                   name="mailbridge_smtp_options[authentication]"
                                   value="1"
                                   <?php checked(1, $options['authentication']); ?>>
                            <p class="description">
                                <?php esc_html_e('Enable SMTP authentication (required for most servers).', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_username">
                                <?php esc_html_e('Username', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mailbridge_smtp_username"
                                   name="mailbridge_smtp_options[username]"
                                   value="<?php echo esc_attr($options['username']); ?>"
                                   class="regular-text"
                                   autocomplete="off">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_password">
                                <?php esc_html_e('Password', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="password"
                                   id="mailbridge_smtp_password"
                                   name="mailbridge_smtp_options[password]"
                                   value="<?php echo !empty($options['password']) ? '********' : ''; ?>"
                                   class="regular-text"
                                   autocomplete="new-password">
                            <p class="description">
                                <?php esc_html_e('Leave as ******** to keep existing password.', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_from_email">
                                <?php esc_html_e('From Email', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="email"
                                   id="mailbridge_smtp_from_email"
                                   name="mailbridge_smtp_options[from_email]"
                                   value="<?php echo esc_attr($options['from_email']); ?>"
                                   class="regular-text"
                                   placeholder="noreply@example.com">
                            <p class="description">
                                <?php esc_html_e('Email address that emails will be sent from.', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_from_name">
                                <?php esc_html_e('From Name', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="mailbridge_smtp_from_name"
                                   name="mailbridge_smtp_options[from_name]"
                                   value="<?php echo esc_attr($options['from_name']); ?>"
                                   class="regular-text"
                                   placeholder="My Website">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="mailbridge_smtp_debug">
                                <?php esc_html_e('Debug Mode', 'mailbridge-smtp'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="checkbox"
                                   id="mailbridge_smtp_debug"
                                   name="mailbridge_smtp_options[smtp_debug]"
                                   value="1"
                                   <?php checked(1, $options['smtp_debug']); ?>>
                            <p class="description">
                                <?php esc_html_e('Enable SMTP debugging (logs to WordPress debug log).', 'mailbridge-smtp'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save Settings', 'mailbridge-smtp')); ?>
            </form>

            <div class="mailbridge-smtp-test-section">
                <h2><?php esc_html_e('Test Connection', 'mailbridge-smtp'); ?></h2>
                <p><?php esc_html_e('Send a test email to verify your SMTP settings.', 'mailbridge-smtp'); ?></p>

                <p>
                    <label for="mailbridge_smtp_test_email">
                        <?php esc_html_e('Test Email Address:', 'mailbridge-smtp'); ?>
                    </label>
                    <input type="email"
                           id="mailbridge_smtp_test_email"
                           class="regular-text"
                           value="<?php echo esc_attr(get_option('admin_email')); ?>">
                </p>

                <button type="button" id="mailbridge_smtp_test_btn" class="button button-secondary">
                    <?php esc_html_e('Send Test Email', 'mailbridge-smtp'); ?>
                </button>

                <div id="mailbridge_smtp_test_result" class="mailbridge-smtp-result"></div>
            </div>

            <?php $this->render_diagnostics_section(); ?>
        </div>
        <?php
    }

    /**
     * Add action links to plugin page.
     *
     * @param array $links Existing links.
     * @return array Modified links.
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=mailbridge-smtp')) . '">';
        $settings_link .= esc_html__('Settings', 'mailbridge-smtp');
        $settings_link .= '</a>';

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Handle AJAX test connection request.
     *
     * @return void
     */
    public function handle_test_connection() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mailbridge_smtp_test')) {
            wp_send_json_error(esc_html__('Security check failed.', 'mailbridge-smtp'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permission denied.', 'mailbridge-smtp'));
        }

        $rate_limit_key = 'mailbridge_smtp_test_' . get_current_user_id();
        if (get_transient($rate_limit_key)) {
            wp_send_json_error(esc_html__('Please wait before sending another test email.', 'mailbridge-smtp'));
        }
        set_transient($rate_limit_key, 1, 30);

        $options = $this->get_options();
        if (empty($options['enabled'])) {
            wp_send_json_error(esc_html__('SMTP is not enabled.', 'mailbridge-smtp'));
        }

        $test_email = sanitize_email($_POST['test_email'] ?? '');
        if (empty($test_email) || !is_email($test_email)) {
            wp_send_json_error(esc_html__('Invalid test email address.', 'mailbridge-smtp'));
        }

        $subject = esc_html__('MailBridge SMTP Test Email', 'mailbridge-smtp');
        $message = esc_html__('This is a test email from MailBridge SMTP. If you received this email, your SMTP configuration is working correctly.', 'mailbridge-smtp');
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        try {
            $sent = wp_mail($test_email, $subject, $message, $headers);
        } catch (\Throwable $e) {
            $error_message = sprintf(
                /* translators: 1: exception class, 2: exception message. */
                __('Test email failed with %1$s: %2$s', 'mailbridge-smtp'),
                get_class($e),
                sanitize_text_field($e->getMessage())
            );
            $this->record_last_error($error_message, 'test_connection');
            wp_send_json_error($error_message);
        }

        if ($sent) {
            wp_send_json_success(esc_html__('Test email sent successfully!', 'mailbridge-smtp'));
        }

        $this->record_last_error(__('Failed to send test email. Please check your SMTP settings.', 'mailbridge-smtp'), 'test_connection');
        wp_send_json_error(esc_html__('Failed to send test email. Please check your SMTP settings.', 'mailbridge-smtp'));
    }

    /**
     * Handle AJAX diagnostic request.
     *
     * @return void
     */
    public function handle_diagnostic() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mailbridge_smtp_diagnostic')) {
            wp_send_json_error(esc_html__('Security check failed.', 'mailbridge-smtp'));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(esc_html__('Permission denied.', 'mailbridge-smtp'));
        }

        $user_id = get_current_user_id();
        $transient_key = 'mailbridge_smtp_diag_' . $user_id;
        if (get_transient($transient_key)) {
            wp_send_json_error(esc_html__('Please wait 30 seconds between tests.', 'mailbridge-smtp'));
        }

        $test_type = sanitize_text_field($_POST['test_type'] ?? '');
        if (!in_array($test_type, ['native_wp_mail', 'direct_mail'], true)) {
            wp_send_json_error(esc_html__('Invalid test type.', 'mailbridge-smtp'));
        }

        $test_email = sanitize_email($_POST['test_email'] ?? '');
        if (empty($test_email) || !is_email($test_email)) {
            wp_send_json_error(esc_html__('Invalid test email address.', 'mailbridge-smtp'));
        }

        set_transient($transient_key, true, 30);

        $subject = esc_html__('MailBridge SMTP Diagnostic Test', 'mailbridge-smtp');
        $message = esc_html__('This is a diagnostic test email. If you received this, the mail function is working.', 'mailbridge-smtp');
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        if ('native_wp_mail' === $test_type) {
            $this->bypass_smtp = true;
            try {
                $sent = wp_mail($test_email, $subject, $message, $headers);
                if ($sent) {
                    wp_send_json_success(esc_html__('wp_mail() accepted the send request. Check your inbox.', 'mailbridge-smtp'));
                }

                wp_send_json_error(esc_html__('wp_mail() failed. The server may be blocking email sending.', 'mailbridge-smtp'));
            } catch (\Throwable $e) {
                wp_send_json_error(esc_html__('wp_mail() threw an exception: ', 'mailbridge-smtp') . $e->getMessage());
            } finally {
                $this->bypass_smtp = false;
            }
        }

        if (!$this->is_php_mail_available()) {
            wp_send_json_error(esc_html__('PHP mail() is disabled or unavailable on this server. Check the disable_functions setting or contact your hosting provider.', 'mailbridge-smtp'));
        }

        $sent = mail($test_email, $subject, $message, implode("\r\n", $headers));
        if ($sent) {
            wp_send_json_success(esc_html__('mail() accepted the send request. Check your inbox.', 'mailbridge-smtp'));
        }

        wp_send_json_error(esc_html__('mail() returned false. The server may be blocking PHP mail().', 'mailbridge-smtp'));
    }

    /**
     * Get plugin version.
     *
     * @return string
     */
    public static function get_version() {
        return MAILBRIDGE_SMTP_VERSION;
    }
}

// Initialize plugin.
add_action('plugins_loaded', ['MailBridge_SMTP_Plugin', 'get_instance']);

// Activation hook.
register_activation_hook(__FILE__, function() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    if (false === get_option(MAILBRIDGE_SMTP_OPTION_NAME, false)) {
        add_option(MAILBRIDGE_SMTP_OPTION_NAME, mailbridge_smtp_get_default_settings());
    }
});

// Deactivation hook.
register_deactivation_hook(__FILE__, function() {
    if (!current_user_can('deactivate_plugins')) {
        return;
    }
});
