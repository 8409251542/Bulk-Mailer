<?php
/**
 * Plugin Name: Multi SMTP Mailer (Rotation + Reports)
 * Description: Add multiple SMTP accounts, send bulk emails with SMTP rotation (round-robin/random), and view delivery reports.
 * Version: 1.0.0
 * Author: ChatGPT
 */

if (!defined('ABSPATH')) { exit; }

class MSM_Multi_SMTP_Mailer {
    const VERSION = '1.0.0';
    const OPT_DB_VERSION = 'msm_db_version';

    private static $instance = null;
    private $tables = [];

    // Global-ish context for current SMTP used in this send
    public static $current_smtp = null; // array when set

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->tables['accounts'] = $wpdb->prefix . 'msm_smtp_accounts';
        $this->tables['logs']     = $wpdb->prefix . 'msm_mail_logs';

        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_posts']);

        // Configure PHPMailer only when a send cycle sets a current SMTP
        add_action('phpmailer_init', function($phpmailer){
            if (!empty(self::$current_smtp) && is_array(self::$current_smtp)) {
                $acc = self::$current_smtp;
                $phpmailer->isSMTP();
                $phpmailer->Host       = $acc['host'];
                $phpmailer->Port       = (int)$acc['port'];
                $phpmailer->SMTPAuth   = true;
                $phpmailer->Username   = $acc['username'];
                $phpmailer->Password   = $acc['password'];
                $phpmailer->SMTPSecure = $acc['encryption'] ?: '';
                if (!empty($acc['from_email'])) {
                    $phpmailer->setFrom($acc['from_email'], $acc['from_name'] ?: $acc['from_email']);
                }
            }
        });
    }

    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $tblA = $this->tables['accounts'];
        $tblL = $this->tables['logs'];

        $sql_accounts = "CREATE TABLE $tblA (
            id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
            label VARCHAR(100) NOT NULL,
            host VARCHAR(191) NOT NULL,
            port SMALLINT NOT NULL DEFAULT 587,
            username VARCHAR(191) NOT NULL,
            password TEXT NOT NULL,
            encryption VARCHAR(10) DEFAULT 'tls',
            from_name VARCHAR(191) DEFAULT '',
            from_email VARCHAR(191) DEFAULT '',
            daily_limit INT DEFAULT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql_logs = "CREATE TABLE $tblL (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            recipient VARCHAR(191) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            smtp_id MEDIUMINT(9) DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            error TEXT NULL,
            message_id VARCHAR(191) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_sent_at (sent_at),
            KEY idx_status (status),
            KEY idx_recipient (recipient)
        ) $charset_collate;";

        dbDelta($sql_accounts);
        dbDelta($sql_logs);

        update_option(self::OPT_DB_VERSION, self::VERSION);
    }

    public function admin_menu() {
        $cap = 'manage_options';
        add_menu_page(
            'Multi SMTP Mailer', 'Multi SMTP Mailer', $cap, 'msm_mailer', [$this, 'page_accounts'], 'dashicons-email-alt', 56
        );
        add_submenu_page('msm_mailer', 'SMTP Accounts', 'SMTP Accounts', $cap, 'msm_mailer', [$this, 'page_accounts']);
        add_submenu_page('msm_mailer', 'Bulk Sender', 'Bulk Sender', $cap, 'msm_bulk', [$this, 'page_bulk']);
        add_submenu_page('msm_mailer', 'Reports', 'Reports', $cap, 'msm_reports', [$this, 'page_reports']);
    }

    /* -------------------- Data Helpers -------------------- */
    private function sanitize_account($data){
        return [
            'label'      => sanitize_text_field($data['label'] ?? ''),
            'host'       => sanitize_text_field($data['host'] ?? ''),
            'port'       => (int)($data['port'] ?? 587),
            'username'   => sanitize_text_field($data['username'] ?? ''),
            'password'   => $data['password'] ?? '', // keep as-is
            'encryption' => in_array(($data['encryption'] ?? ''), ['ssl','tls','STARTTLS','']) ? $data['encryption'] : 'tls',
            'from_name'  => sanitize_text_field($data['from_name'] ?? ''),
            'from_email' => sanitize_email($data['from_email'] ?? ''),
            'daily_limit'=> isset($data['daily_limit']) && $data['daily_limit'] !== '' ? (int)$data['daily_limit'] : null,
            'active'     => isset($data['active']) ? 1 : 0,
        ];
    }

    private function get_accounts($only_active = false){
        global $wpdb; $tbl = $this->tables['accounts'];
        if ($only_active) return $wpdb->get_results("SELECT * FROM $tbl WHERE active=1 ORDER BY id ASC", ARRAY_A);
        return $wpdb->get_results("SELECT * FROM $tbl ORDER BY id ASC", ARRAY_A);
    }

    private function get_account($id){
        global $wpdb; $tbl = $this->tables['accounts'];
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id=%d", $id), ARRAY_A);
    }

    private function insert_account($data){
        global $wpdb; $tbl = $this->tables['accounts'];
        $ok = $wpdb->insert($tbl, $data);
        return $ok ? $wpdb->insert_id : false;
    }

    private function update_account($id, $data){
        global $wpdb; $tbl = $this->tables['accounts'];
        return $wpdb->update($tbl, $data, ['id' => (int)$id]) !== false;
    }

    private function delete_account($id){
        global $wpdb; $tbl = $this->tables['accounts'];
        return $wpdb->delete($tbl, ['id' => (int)$id]) !== false;
    }

    private function log_mail($row){
        global $wpdb; $tbl = $this->tables['logs'];
        $wpdb->insert($tbl, [
            'sent_at'   => current_time('mysql'),
            'recipient' => $row['recipient'] ?? '',
            'subject'   => $row['subject'] ?? '',
            'smtp_id'   => $row['smtp_id'] ?? null,
            'status'    => $row['status'] ?? 'unknown',
            'error'     => $row['error'] ?? null,
            'message_id'=> $row['message_id'] ?? null,
        ]);
    }

    /* -------------------- POST Handlers -------------------- */
    public function handle_posts(){
        if (!current_user_can('manage_options')) return;

        // Add / Update Account
        if (isset($_POST['msm_action']) && wp_verify_nonce($_POST['msm_nonce'] ?? '', 'msm_accounts')) {
            $action = sanitize_text_field($_POST['msm_action']);
            if ($action === 'add_account') {
                $data = $this->sanitize_account($_POST);
                if ($data['host'] && $data['username'] && $data['password']) {
                    $this->insert_account($data);
                    add_action('admin_notices', function(){ echo '<div class="notice notice-success"><p>SMTP account added.</p></div>'; });
                } else {
                    add_action('admin_notices', function(){ echo '<div class="notice notice-error"><p>Host, Username, and Password are required.</p></div>'; });
                }
            }
            if ($action === 'update_account' && !empty($_POST['id'])) {
                $id = (int)$_POST['id'];
                $data = $this->sanitize_account($_POST);
                $this->update_account($id, $data);
                add_action('admin_notices', function(){ echo '<div class="notice notice-success"><p>SMTP account updated.</p></div>'; });
            }
        }

        // Delete account
        if (isset($_GET['msm_delete']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'msm_del_'.(int)$_GET['msm_delete'])) {
            $this->delete_account((int)$_GET['msm_delete']);
            add_action('admin_notices', function(){ echo '<div class="notice notice-success"><p>SMTP account deleted.</p></div>'; });
        }

        // Bulk send
        if (isset($_POST['msm_bulk_send']) && wp_verify_nonce($_POST['msm_nonce'] ?? '', 'msm_bulk')) {
            $this->process_bulk_send($_POST);
        }
    }

    /* -------------------- Pages -------------------- */
    public function page_accounts(){
        $accounts = $this->get_accounts(false);
        ?>
        <div class="wrap">
            <h1>SMTP Accounts</h1>
            <div style="display:flex; gap:24px; align-items:flex-start;">
                <form method="post" action="">
                    <?php wp_nonce_field('msm_accounts','msm_nonce'); ?>
                    <input type="hidden" name="msm_action" value="add_account" />
                    <h2>Add New SMTP</h2>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row">Label</th><td><input name="label" type="text" class="regular-text" placeholder="My SMTP #1"></td></tr>
                        <tr><th scope="row">Host</th><td><input name="host" type="text" class="regular-text" required placeholder="smtp.example.com"></td></tr>
                        <tr><th scope="row">Port</th><td><input name="port" type="number" value="587" min="1"></td></tr>
                        <tr><th scope="row">Encryption</th><td>
                            <select name="encryption"><option value="tls">tls</option><option value="ssl">ssl</option><option value="">none</option></select>
                        </td></tr>
                        <tr><th scope="row">Username</th><td><input name="username" type="text" class="regular-text" required></td></tr>
                        <tr><th scope="row">Password</th><td><input name="password" type="password" class="regular-text" required></td></tr>
                        <tr><th scope="row">From Name</th><td><input name="from_name" type="text" class="regular-text"></td></tr>
                        <tr><th scope="row">From Email</th><td><input name="from_email" type="email" class="regular-text" placeholder="no-reply@example.com"></td></tr>
                        <tr><th scope="row">Daily Limit (optional)</th><td><input name="daily_limit" type="number" class="small-text" placeholder="e.g. 500"></td></tr>
                        <tr><th scope="row">Active</th><td><label><input name="active" type="checkbox" checked> Active</label></td></tr>
                    </table>
                    <?php submit_button('Add SMTP'); ?>
                </form>

                <div style="flex:1">
                    <h2>Existing Accounts</h2>
                    <table class="widefat fixed striped">
                        <thead><tr>
                            <th>ID</th><th>Label</th><th>Host</th><th>User</th><th>From</th><th>Enc</th><th>Port</th><th>Active</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php if ($accounts): foreach($accounts as $a): ?>
                            <tr>
                                <td><?php echo (int)$a['id']; ?></td>
                                <td><?php echo esc_html($a['label']); ?></td>
                                <td><?php echo esc_html($a['host']); ?></td>
                                <td><?php echo esc_html($a['username']); ?></td>
                                <td><?php echo esc_html($a['from_name'] . ' <' . $a['from_email'] . '>'); ?></td>
                                <td><?php echo esc_html($a['encryption']); ?></td>
                                <td><?php echo esc_html($a['port']); ?></td>
                                <td><?php echo $a['active'] ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <details>
                                        <summary>Edit</summary>
                                        <form method="post" style="margin-top:8px;">
                                            <?php wp_nonce_field('msm_accounts','msm_nonce'); ?>
                                            <input type="hidden" name="msm_action" value="update_account" />
                                            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>" />
                                            <p><label>Label<br><input name="label" value="<?php echo esc_attr($a['label']); ?>" class="regular-text"></label></p>
                                            <p><label>Host<br><input name="host" value="<?php echo esc_attr($a['host']); ?>" class="regular-text"></label></p>
                                            <p><label>Port<br><input name="port" type="number" value="<?php echo (int)$a['port']; ?>" class="small-text"></label></p>
                                            <p><label>Encryption<br>
                                                <select name="encryption">
                                                    <?php foreach(['tls','ssl',''] as $enc){ echo '<option value="'.esc_attr($enc).'"'.selected($a['encryption'],$enc,false).'>'.($enc?:'none').'</option>'; } ?>
                                                </select></label></p>
                                            <p><label>Username<br><input name="username" value="<?php echo esc_attr($a['username']); ?>" class="regular-text"></label></p>
                                            <p><label>Password<br><input name="password" type="text" value="<?php echo esc_attr($a['password']); ?>" class="regular-text"></label></p>
                                            <p><label>From Name<br><input name="from_name" value="<?php echo esc_attr($a['from_name']); ?>" class="regular-text"></label></p>
                                            <p><label>From Email<br><input name="from_email" type="email" value="<?php echo esc_attr($a['from_email']); ?>" class="regular-text"></label></p>
                                            <p><label>Daily Limit<br><input name="daily_limit" type="number" value="<?php echo esc_attr($a['daily_limit']); ?>" class="small-text"></label></p>
                                            <p><label><input type="checkbox" name="active" <?php checked($a['active'],1); ?>> Active</label></p>
                                            <?php submit_button('Save Changes', 'secondary'); ?>
                                        </form>
                                        <p>
                                            <?php $del_url = wp_nonce_url(admin_url('admin.php?page=msm_mailer&msm_delete='.$a['id']), 'msm_del_'.$a['id']); ?>
                                            <a class="button button-link-delete" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('Delete this SMTP account?');">Delete</a>
                                        </p>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="9">No SMTP accounts yet. Add one on the left.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function page_bulk(){
        $accounts = $this->get_accounts(true);
        ?>
        <div class="wrap">
            <h1>Bulk Sender</h1>
            <?php if (!$accounts): ?>
                <div class="notice notice-warning"><p>Please add at least one <a href="<?php echo admin_url('admin.php?page=msm_mailer'); ?>">SMTP account</a> and activate it.</p></div>
            <?php endif; ?>
            <form method="post" action="">
                <?php wp_nonce_field('msm_bulk','msm_nonce'); ?>
                <input type="hidden" name="msm_bulk_send" value="1" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Recipients</th>
                        <td>
                            <textarea name="recipients" rows="8" cols="80" class="large-text" placeholder="one@example.com\nName <two@example.com>"></textarea>
                            <p class="description">One per line. Formats supported: <code>email@domain.com</code> or <code>Name &lt;email@domain.com&gt;</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Subject</th>
                        <td><input name="subject" type="text" class="regular-text" style="width:40em" required></td>
                    </tr>
                    <tr>
                        <th>Message (HTML allowed)</th>
                        <td>
                            <?php wp_editor('', 'msm_message', ['textarea_name' => 'message', 'textarea_rows' => 10]); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>From (override)</th>
                        <td>
                            <input name="from_name" type="text" class="regular-text" placeholder="Optional"> &nbsp;
                            <input name="from_email" type="email" class="regular-text" placeholder="no-reply@example.com">
                            <p class="description">If set, overrides SMTP account From for this campaign.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Rotation</th>
                        <td>
                            <label><input type="radio" name="rotation" value="round" checked> Round-robin</label>
                            &nbsp; <label><input type="radio" name="rotation" value="random"> Random</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Use SMTPs</th>
                        <td>
                            <?php if ($accounts): foreach($accounts as $a): ?>
                                <label style="display:inline-block;margin-right:12px;">
                                    <input type="checkbox" name="smtp_ids[]" value="<?php echo (int)$a['id']; ?>" checked>
                                    <?php echo esc_html($a['label'] ?: ($a['host'].' ('.$a['username'].')')); ?>
                                </label>
                            <?php endforeach; else: echo 'No active SMTPs.'; endif; ?>
                            <p class="description">Select which SMTP accounts to rotate through.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Headers (optional)</th>
                        <td>
                            <textarea name="headers" rows="3" cols="80" class="large-text" placeholder="Reply-To: you@example.com\nX-Campaign: August"></textarea>
                            <p class="description">One header per line.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Test Mode</th>
                        <td>
                            <label><input type="checkbox" name="test_mode" value="1"> Send only to first 3 recipients (dry-run sample)</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Send Bulk Emails'); ?>
            </form>
        </div>
        <?php
    }

    public function page_reports(){
        global $wpdb; $tbl = $this->tables['logs'];
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $q = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        $where = 'WHERE 1=1';
        $params = [];
        if ($status) { $where .= ' AND status=%s'; $params[] = $status; }
        if ($q) { $where .= ' AND (recipient LIKE %s OR subject LIKE %s)'; $params[] = "%$q%"; $params[] = "%$q%"; }
        $sql = $wpdb->prepare("SELECT * FROM $tbl $where ORDER BY id DESC LIMIT 200", $params);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        ?>
        <div class="wrap">
            <h1>Reports</h1>
            <form method="get" style="margin:12px 0;">
                <input type="hidden" name="page" value="msm_reports">
                <input type="text" name="q" placeholder="Search recipient/subject" value="<?php echo esc_attr($q); ?>">
                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach(['sent','failed'] as $s){ echo '<option value="'.esc_attr($s).'"'.selected($status,$s,false).'>'.esc_html(ucfirst($s)).'</option>'; } ?>
                </select>
                <?php submit_button('Filter', 'secondary', '', false); ?>
            </form>

            <table class="widefat fixed striped">
                <thead><tr>
                    <th>ID</th><th>Sent At</th><th>Recipient</th><th>Subject</th><th>SMTP ID</th><th>Status</th><th>Error</th>
                </tr></thead>
                <tbody>
                <?php if ($rows): foreach($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo esc_html($r['sent_at']); ?></td>
                        <td><?php echo esc_html($r['recipient']); ?></td>
                        <td><?php echo esc_html($r['subject']); ?></td>
                        <td><?php echo esc_html($r['smtp_id']); ?></td>
                        <td><?php echo esc_html($r['status']); ?></td>
                        <td><?php echo esc_html(wp_trim_words($r['error'], 18)); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7">No logs yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* -------------------- Bulk Send Logic -------------------- */
    private function parse_recipients($text){
        $lines = preg_split('/\r?\n/', trim($text));
        $out = [];
        foreach($lines as $line){
            $line = trim($line);
            if (!$line) continue;
            // Match "Name <email@x>" or plain email
            if (preg_match('/^(.+?)\s*<([^>]+)>$/', $line, $m)) {
                $out[] = ['email' => sanitize_email($m[2]), 'name' => sanitize_text_field($m[1])];
            } else {
                $out[] = ['email' => sanitize_email($line), 'name' => ''];
            }
        }
        // remove invalid
        return array_values(array_filter($out, function($r){ return is_email($r['email']); }));
    }

    private function parse_headers($text){
        $out = [];
        $lines = preg_split('/\r?\n/', trim($text));
        foreach($lines as $l){ $l = trim($l); if (!$l) continue; $out[] = $l; }
        return $out;
    }

    private function pick_smtp($pool, $i, $mode = 'round'){
        if (!$pool) return null;
        if ($mode === 'random') { return $pool[array_rand($pool)]; }
        return $pool[$i % count($pool)];
    }

    public function process_bulk_send($post){
        if (!current_user_can('manage_options')) return;
        $recipients = $this->parse_recipients($post['recipients'] ?? '');
        $subject    = sanitize_text_field($post['subject'] ?? '');
        $message    = wp_kses_post($post['message'] ?? '');
        $headersTxt = $post['headers'] ?? '';
        $headers    = $this->parse_headers($headersTxt);
        $rotation   = ($post['rotation'] ?? 'round') === 'random' ? 'random' : 'round';
        $test_mode  = !empty($post['test_mode']);

        $ids = array_map('intval', $post['smtp_ids'] ?? []);
        $pool = [];
        if ($ids) {
            foreach ($ids as $id) { $acc = $this->get_account($id); if ($acc && $acc['active']) $pool[] = $acc; }
        } else {
            $pool = $this->get_accounts(true);
        }

        if (!$recipients || !$subject || !$message) {
            add_action('admin_notices', function(){ echo '<div class="notice notice-error"><p>Please provide recipients, subject and message.</p></div>'; });
            return;
        }
        if (!$pool) {
            add_action('admin_notices', function(){ echo '<div class="notice notice-error"><p>No active SMTP accounts available.</p></div>'; });
            return;
        }

        $limit = $test_mode ? min(3, count($recipients)) : count($recipients);
        $sent = 0; $failed = 0;

        $from_name  = sanitize_text_field($post['from_name'] ?? '');
        $from_email = sanitize_email($post['from_email'] ?? '');
        if ($from_email && !is_email($from_email)) $from_email = '';

        for ($i=0; $i<$limit; $i++) {
            $rcpt = $recipients[$i];
            $acc  = $this->pick_smtp($pool, $i, $rotation);

            // Respect daily limit if set
            if (!empty($acc['daily_limit'])) {
                // Count mails sent today for this SMTP
                global $wpdb; $tbl = $this->tables['logs'];
                $today = gmdate('Y-m-d');
                $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE smtp_id=%d AND DATE(sent_at)=%s", $acc['id'], $today));
                if ($count >= (int)$acc['daily_limit']) {
                    // skip this SMTP by picking another (try all once)
                    $tried = 0; $picked = null;
                    while ($tried < count($pool)) {
                        $tried++; $acc2 = $this->pick_smtp($pool, $i+$tried, $rotation);
                        if (empty($acc2['daily_limit'])) { $picked = $acc2; break; }
                        $count2 = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tbl WHERE smtp_id=%d AND DATE(sent_at)=%s", $acc2['id'], $today));
                        if ($count2 < (int)$acc2['daily_limit']) { $picked = $acc2; break; }
                    }
                    if ($picked) { $acc = $picked; } else {
                        // all limits exhausted
                        $this->log_mail([
                            'recipient' => $rcpt['email'],
                            'subject'   => $subject,
                            'smtp_id'   => null,
                            'status'    => 'failed',
                            'error'     => 'All SMTP daily limits exhausted.'
                        ]);
                        $failed++; continue;
                    }
                }
            }

            // Prepare headers with From override if set
            $this_headers = $headers;
            if ($from_email) {
                $from_line = $from_name ? sprintf('From: %s <%s>', $from_name, $from_email) : sprintf('From: %s', $from_email);
                $this_headers[] = $from_line;
            }

            // Set current SMTP for this iteration
            self::$current_smtp = $acc;

            $to = $rcpt['name'] ? sprintf('%s <%s>', $rcpt['name'], $rcpt['email']) : $rcpt['email'];
            $ok = wp_mail($to, $subject, $message, $this_headers);

            // Grab Message-ID if available
            $message_id = null;
            if (did_action('phpmailer_init')) {
                // PHPMailer instance is not directly available here cleanly; leave null.
            }

            if ($ok) { $sent++; $status='sent'; $error=null; } else { $failed++; $status='failed'; $error='wp_mail returned false'; }

            $this->log_mail([
                'recipient' => $rcpt['email'],
                'subject'   => $subject,
                'smtp_id'   => $acc['id'] ?? null,
                'status'    => $status,
                'error'     => $error,
                'message_id'=> $message_id,
            ]);

            // Clear current SMTP after each send
            self::$current_smtp = null;
        }

        add_action('admin_notices', function() use ($sent, $failed, $limit, $test_mode){
            $msg = $test_mode ? 'Test mode: ' : '';
            echo '<div class="notice notice-info"><p>'.$msg.'Processed '.esc_html($limit).' recipients. Sent: '.esc_html($sent).', Failed: '.esc_html($failed).'. See <a href="'.admin_url('admin.php?page=msm_reports').'">Reports</a>.</p></div>';
        });
    }
}

MSM_Multi_SMTP_Mailer::instance();
