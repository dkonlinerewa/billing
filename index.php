<?php
ob_start(); // Buffer all output to allow headers to be sent at any point

// Mobile redirect (admin portal is desktop-only)
if(!isset($_GET['dev'])){
    $ua=$_SERVER['HTTP_USER_AGENT']??'';
    if(preg_match('/android|iphone|ipod|blackberry|iemobile|opera mini/i',$ua)){
        header('Location: https://www.hidk.in');exit();
    }
}
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Exception $e) {
    error_log("Session start error: " . $e->getMessage());
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

if (isset($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

try {
    $db = new SQLite3('invoices.db');
    $db->enableExceptions(true);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$tables = [
    "CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT UNIQUE,
        customer_name TEXT,
        customer_phone TEXT,
        customer_address TEXT,
        customer_email TEXT,
        invoice_date TEXT,
        payment_status TEXT DEFAULT 'unpaid',
        paid_amount REAL DEFAULT 0,
        payment_notes TEXT,
        gst_amount REAL DEFAULT 0,
        gst_rate REAL DEFAULT 0,
        gst_inclusive INTEGER DEFAULT 0,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_number TEXT UNIQUE,
        customer_name TEXT,
        customer_phone TEXT,
        customer_email TEXT,
        customer_address TEXT,
        service_description TEXT,
        booking_date TEXT,
        expected_completion_date TEXT,
        advance_fees REAL DEFAULT 0,
        total_estimated_cost REAL DEFAULT 0,
        payment_status TEXT DEFAULT 'pending',
        payment_method TEXT,
        transaction_id TEXT,
        notes TEXT,
        status TEXT DEFAULT 'pending', -- pending, in_progress, completed, cancelled
        converted_to_invoice INTEGER DEFAULT 0,
        converted_invoice_id INTEGER,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS booking_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER,
        s_no INTEGER,
        description TEXT,
        estimated_amount REAL DEFAULT 0,
        actual_amount REAL DEFAULT 0
    )",
    
    "CREATE TABLE IF NOT EXISTS booking_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER,
        payment_date TEXT,
        amount REAL DEFAULT 0,
        payment_method TEXT,
        transaction_id TEXT,
        notes TEXT,
        is_advance INTEGER DEFAULT 1,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS booking_shares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER,
        share_token TEXT UNIQUE,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP,
        is_active INTEGER DEFAULT 1
    )",
    
    
    
    
    
    "CREATE TABLE IF NOT EXISTS invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        s_no INTEGER,
        particulars TEXT,
        amount REAL DEFAULT 0,
        service_charge REAL DEFAULT 0,
        discount REAL DEFAULT 0,
        remark TEXT
    )",
    
    "CREATE TABLE IF NOT EXISTS purchases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        s_no INTEGER,
        particulars TEXT,
        qty REAL DEFAULT 1,
        rate REAL DEFAULT 0,
        purchase_amount REAL DEFAULT 0,
        amount_received REAL DEFAULT 0
    )",
    
    "CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        payment_date TEXT,
        amount REAL DEFAULT 0,
        payment_method TEXT,
        transaction_id TEXT,
        notes TEXT,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE,
        setting_value TEXT
    )",
    
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        email TEXT,
        role TEXT DEFAULT 'accountant',
        has_nongst_access INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS user_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT,
        details TEXT,
        ip_address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS delete_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        invoice_number TEXT,
        requested_by INTEGER,
        reason TEXT,
        status TEXT DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_at TIMESTAMP,
        approved_by INTEGER
    )",
    
    "CREATE TABLE IF NOT EXISTS sent_invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        sent_via TEXT,
        sent_to TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_by INTEGER
    )",
    
    "CREATE TABLE IF NOT EXISTS invoice_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER,
    share_token TEXT UNIQUE,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    is_active INTEGER DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_share_token ON invoice_shares (share_token);
CREATE INDEX IF NOT EXISTS idx_invoice_id ON invoice_shares (invoice_id);",
    "CREATE INDEX IF NOT EXISTS idx_booking_token ON booking_shares (share_token)",
    "CREATE INDEX IF NOT EXISTS idx_booking_id ON booking_shares (booking_id)",
    "CREATE INDEX IF NOT EXISTS idx_booking_status ON bookings (status)",
    "CREATE INDEX IF NOT EXISTS idx_booking_payment_status ON bookings (payment_status)",
    
    "CREATE TABLE IF NOT EXISTS invoice_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_name TEXT UNIQUE,
        template_data TEXT,
        is_default INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS staff_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        expense_date TEXT,
        category TEXT,
        description TEXT,
        amount REAL DEFAULT 0,
        receipt_path TEXT,
        submitted_by INTEGER,
        approved_by INTEGER,
        status TEXT DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS invoice_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER,
        db_source TEXT DEFAULT 'gst',
        seal_x REAL DEFAULT 0,
        seal_y REAL DEFAULT 0,
        seal_w REAL DEFAULT 80,
        seal_h REAL DEFAULT 80,
        seal_affixed INTEGER DEFAULT 0,
        seal_affixed_at TIMESTAMP,
        signature_x REAL DEFAULT 0,
        signature_y REAL DEFAULT 0,
        signature_w REAL DEFAULT 120,
        signature_h REAL DEFAULT 50,
        sig_affixed INTEGER DEFAULT 0,
        sig_affixed_at TIMESTAMP,
        signed_by INTEGER,
        locked INTEGER DEFAULT 0,
        locked_at TIMESTAMP,
        UNIQUE(invoice_id, db_source)
    )",

    "CREATE TABLE IF NOT EXISTS academy_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        course_name TEXT NOT NULL,
        course_code TEXT UNIQUE,
        description TEXT,
        duration_months INTEGER DEFAULT 1,
        admission_fee REAL DEFAULT 0,
        course_fee_total REAL DEFAULT 0,
        course_fee_monthly REAL DEFAULT 0,
        exam_fee REAL DEFAULT 0,
        certificate_fee REAL DEFAULT 0,
        shifts TEXT DEFAULT 'Morning,Evening',
        is_active INTEGER DEFAULT 1,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS academy_enrollments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        enrollment_id TEXT UNIQUE,
        candidate_name TEXT NOT NULL,
        relative_name TEXT,
        relation TEXT DEFAULT 'F/o',
        dob TEXT,
        age INTEGER,
        phone TEXT,
        alternate_phone TEXT,
        email TEXT,
        address TEXT,
        qualification TEXT,
        course_id INTEGER,
        shift TEXT,
        batch_start_date TEXT,
        batch_end_date TEXT,
        fee_type TEXT DEFAULT 'monthly',
        total_fee REAL DEFAULT 0,
        admission_fee REAL DEFAULT 0,
        exam_fee REAL DEFAULT 0,
        certificate_fee REAL DEFAULT 0,
        discount REAL DEFAULT 0,
        amount_paid REAL DEFAULT 0,
        balance REAL DEFAULT 0,
        status TEXT DEFAULT 'active',
        photo_path TEXT,
        id_proof_path TEXT,
        notes TEXT,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS academy_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        enrollment_id INTEGER,
        payment_date TEXT,
        amount REAL DEFAULT 0,
        fee_type TEXT,
        payment_method TEXT DEFAULT 'Cash',
        transaction_id TEXT,
        receipt_number TEXT,
        installment_number INTEGER DEFAULT 1,
        notes TEXT,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS academy_installments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        enrollment_id INTEGER,
        installment_number INTEGER,
        due_date TEXT,
        amount REAL DEFAULT 0,
        fee_type TEXT DEFAULT 'course',
        status TEXT DEFAULT 'pending',
        paid_on TEXT,
        reminder_sent INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

foreach ($tables as $table) {
    try {
        $db->exec($table);
    } catch (Exception $e) {
        error_log("Table creation error: " . $e->getMessage());
    }
}

// Migration: add has_nongst_access column to users if not exists
try {
    $db->exec("ALTER TABLE users ADD COLUMN has_nongst_access INTEGER DEFAULT 0");
} catch (Exception $e) {
    // Column already exists, ignore
}

// Migration: migrate old 'staff' role to 'accountant'
try {
    $db->exec("UPDATE users SET role = 'accountant' WHERE role = 'staff'");
} catch (Exception $e) {
    error_log("Role migration error: " . $e->getMessage());
}

// Migration: add customer_gst_number to invoices (GST DB)
try {
    $db->exec("ALTER TABLE invoices ADD COLUMN customer_gst_number TEXT DEFAULT 'NA'");
} catch (Exception $e) { /* already exists */ }
try {
    $db->exec("UPDATE invoices SET customer_gst_number = 'NA' WHERE customer_gst_number IS NULL OR customer_gst_number = ''");
} catch (Exception $e) { error_log("GST migration error: " . $e->getMessage()); }

// Migration: add GST financial columns to GST DB
foreach (['gst_amount REAL DEFAULT 0', 'gst_rate REAL DEFAULT 0', 'gst_inclusive INTEGER DEFAULT 0'] as $col) {
    try { $db->exec("ALTER TABLE invoices ADD COLUMN $col"); } catch (Exception $e) {}
}

// Migration: add signature/seal columns to users
foreach (['signature_path TEXT', 'sign_passcode TEXT'] as $col) {
    try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (Exception $e) {}
}
// Migration: purchases receipt path
try { $db->exec("ALTER TABLE purchases ADD COLUMN receipt_path TEXT"); } catch (Exception $e) {}

// Migration: add new user profile columns
foreach ([
    'full_name TEXT',
    'designation TEXT',
    'has_academy_access INTEGER DEFAULT 0'
] as $col) {
    try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch (Exception $e) {}
}
// Set NA for missing full_name/designation on existing users
try { $db->exec("UPDATE users SET full_name = 'NA' WHERE full_name IS NULL OR full_name = ''"); } catch (Exception $e) {}
try { $db->exec("UPDATE users SET designation = 'NA' WHERE designation IS NULL OR designation = ''"); } catch (Exception $e) {}

// Migration: add composite image path and relative positioning to invoice_signatures
foreach ([
    'seal_composite_path TEXT',
    'sig_composite_path TEXT',
    'seal_page_x REAL DEFAULT 0',
    'seal_page_y REAL DEFAULT 0',
    'sig_page_x REAL DEFAULT 0',
    'sig_page_y REAL DEFAULT 0'
] as $col) {
    try { $db->exec("ALTER TABLE invoice_signatures ADD COLUMN $col"); } catch (Exception $e) {}
}

// Migration: create seal_requests table
$db->exec("CREATE TABLE IF NOT EXISTS seal_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_id INTEGER,
    db_source TEXT DEFAULT 'gst',
    requested_by INTEGER,
    request_note TEXT,
    status TEXT DEFAULT 'pending',
    admin_note TEXT,
    reviewed_by INTEGER,
    reviewed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(invoice_id, db_source, status)
)");

// Migration: settings for seal, sign passcode, academy
foreach ([
    'seal_path' => '',
    'sign_passcode' => '',
    'academy_name' => 'Skill Training Academy',
    'academy_address' => '',
    'academy_phone' => '',
    'academy_email' => '',
    'enrollment_prefix' => 'ENR',
    'academy_enabled' => '1'
] as $k => $v) {
    $s = $db->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (:k, :v)");
    $s->bindValue(':k', $k, SQLITE3_TEXT);
    $s->bindValue(':v', $v, SQLITE3_TEXT);
    $s->execute();
}

// ============================================================
// CHAT DATABASE (separate chat.db)
// ============================================================
function getChatDb() {
    static $chat_db = null;
    if ($chat_db === null) {
        try {
            $chat_db = new SQLite3('chat.db');
            $chat_db->enableExceptions(true);
            $chat_db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_user INTEGER NOT NULL,
                to_user INTEGER NOT NULL,
                message TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $chat_db->exec("CREATE INDEX IF NOT EXISTS idx_chat_conv ON chat_messages(from_user, to_user)");
            $chat_db->exec("CREATE TABLE IF NOT EXISTS chat_online (
                user_id INTEGER PRIMARY KEY,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Exception $e) {
            error_log("Chat DB error: " . $e->getMessage());
            return null;
        }
    }
    return $chat_db;
}

// Heartbeat: keep user online in chat
function chatHeartbeat() {
    $cdb = getChatDb();
    if (!$cdb || !isLoggedIn()) return;
    $uid = $_SESSION['user_id'];
    $cdb->exec("INSERT OR REPLACE INTO chat_online (user_id, last_seen) VALUES ($uid, CURRENT_TIMESTAMP)");
}

function chatGetOnlineUsers() {
    global $db;
    $cdb = getChatDb();
    if (!$cdb) return [];
    // Users active within last 3 minutes
    $result = $cdb->query("SELECT user_id FROM chat_online WHERE last_seen >= datetime('now','-3 minutes')");
    $online = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $online[] = $row['user_id'];
    // Get all users except self
    $me = $_SESSION['user_id'] ?? 0;
    $stmt = $db->query("SELECT id, username, full_name, designation, role FROM users ORDER BY username ASC");
    $users = [];
    while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
        if ($row['id'] == $me) continue;
        $row['is_online'] = in_array($row['id'], $online);
        // unread count
        $uq = $cdb->prepare("SELECT COUNT(*) as cnt FROM chat_messages WHERE from_user=:fid AND to_user=:tid AND is_read=0");
        $uq->bindValue(':fid', $row['id'], SQLITE3_INTEGER);
        $uq->bindValue(':tid', $me, SQLITE3_INTEGER);
        $row['unread'] = $uq->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] ?? 0;
        $users[] = $row;
    }
    return $users;
}

function chatGetMessages($with_user_id, $since_id = 0) {
    $cdb = getChatDb();
    if (!$cdb) return [];
    $me = $_SESSION['user_id'] ?? 0;
    $stmt = $cdb->prepare("SELECT * FROM chat_messages WHERE
        ((from_user=:me AND to_user=:them) OR (from_user=:them2 AND to_user=:me2))
        AND id > :sid
        ORDER BY id ASC LIMIT 100");
    $stmt->bindValue(':me',   $me,           SQLITE3_INTEGER);
    $stmt->bindValue(':them', $with_user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':them2',$with_user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':me2',  $me,           SQLITE3_INTEGER);
    $stmt->bindValue(':sid',  $since_id,     SQLITE3_INTEGER);
    $result = $stmt->execute();
    $msgs = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $msgs[] = $row;
    // Mark as read
    $cdb->exec("UPDATE chat_messages SET is_read=1 WHERE from_user=$with_user_id AND to_user=$me AND is_read=0");
    return $msgs;
}

function chatSendMessage($to_user_id, $message) {
    $cdb = getChatDb();
    if (!$cdb) return false;
    $me  = $_SESSION['user_id'] ?? 0;
    $msg = trim($message);
    if (!$msg || !$to_user_id || $to_user_id == $me) return false;
    $stmt = $cdb->prepare("INSERT INTO chat_messages (from_user, to_user, message) VALUES (:f,:t,:m)");
    $stmt->bindValue(':f', $me,         SQLITE3_INTEGER);
    $stmt->bindValue(':t', $to_user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':m', $msg,        SQLITE3_TEXT);
    $stmt->execute();
    return $cdb->lastInsertRowID();
}

function chatTotalUnread() {
    $cdb = getChatDb();
    if (!$cdb || !isLoggedIn()) return 0;
    $me = $_SESSION['user_id'] ?? 0;
    $r  = $cdb->querySingle("SELECT COUNT(*) FROM chat_messages WHERE to_user=$me AND is_read=0");
    return intval($r);
}

// Initialize Non-GST database
function getNonGSTDb() {
    static $nongst_db = null;
    if ($nongst_db === null) {
        try {
            $nongst_db = new SQLite3('nongst_invoices.db');
            $nongst_db->enableExceptions(true);
            initNonGSTDb($nongst_db);
        } catch (Exception $e) {
            error_log("NonGST DB error: " . $e->getMessage());
            return null;
        }
    }
    return $nongst_db;
}

function initNonGSTDb($nongst_db) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT UNIQUE,
            customer_name TEXT,
            customer_phone TEXT,
            customer_address TEXT,
            customer_email TEXT,
            invoice_date TEXT,
            payment_status TEXT DEFAULT 'unpaid',
            paid_amount REAL DEFAULT 0,
            payment_notes TEXT,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS invoice_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER,
            s_no INTEGER,
            particulars TEXT,
            amount REAL DEFAULT 0,
            service_charge REAL DEFAULT 0,
            discount REAL DEFAULT 0,
            remark TEXT
        )",
        "CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER,
            s_no INTEGER,
            particulars TEXT,
            qty REAL DEFAULT 1,
            rate REAL DEFAULT 0,
            purchase_amount REAL DEFAULT 0,
            amount_received REAL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER,
            payment_date TEXT,
            amount REAL DEFAULT 0,
            payment_method TEXT,
            transaction_id TEXT,
            notes TEXT,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS invoice_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER,
            share_token TEXT UNIQUE,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP,
            is_active INTEGER DEFAULT 1
        )",
        "CREATE INDEX IF NOT EXISTS idx_share_token ON invoice_shares (share_token)",
        "CREATE INDEX IF NOT EXISTS idx_invoice_id ON invoice_shares (invoice_id)"
    ];
    foreach ($tables as $sql) {
        try { $nongst_db->exec($sql); } catch (Exception $e) { /* already exists */ }
    }
    // Migration: add customer_gst_number if missing
    try { $nongst_db->exec("ALTER TABLE invoices ADD COLUMN customer_gst_number TEXT DEFAULT 'NA'"); } catch (Exception $e) {}
    try { $nongst_db->exec("UPDATE invoices SET customer_gst_number = 'NA' WHERE customer_gst_number IS NULL OR customer_gst_number = ''"); } catch (Exception $e) {}
    // Migration: add GST financial columns
    foreach (['gst_amount REAL DEFAULT 0', 'gst_rate REAL DEFAULT 0', 'gst_inclusive INTEGER DEFAULT 0'] as $col) {
        try { $nongst_db->exec("ALTER TABLE invoices ADD COLUMN $col"); } catch (Exception $e) {}
    }
}


// ═══ YATRA + QR TABLES ════════════════════════════════
$_yt = [
"CREATE TABLE IF NOT EXISTS yatras (id INTEGER PRIMARY KEY AUTOINCREMENT, yatra_name TEXT NOT NULL, destination TEXT, departure_date TEXT, return_date TEXT, closing_date TEXT, bus_details TEXT, per_person_amount REAL DEFAULT 0, total_seats INTEGER DEFAULT 0, description TEXT, status TEXT DEFAULT 'active', is_archived INTEGER DEFAULT 0, created_by INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS yatra_bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_ref TEXT UNIQUE, pnr TEXT UNIQUE, yatra_id INTEGER, yatra_name TEXT, lead_passenger_name TEXT NOT NULL, phone TEXT, email TEXT, address TEXT, emergency_contact TEXT, emergency_contact_name TEXT, total_passengers INTEGER DEFAULT 1, booking_amount REAL DEFAULT 0, total_amount REAL DEFAULT 0, amount_paid REAL DEFAULT 0, balance REAL DEFAULT 0, payment_status TEXT DEFAULT 'unpaid', booking_date TEXT, notes TEXT, status TEXT DEFAULT 'confirmed', created_by INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS yatra_passengers (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, name TEXT NOT NULL, age INTEGER, gender TEXT, id_proof_type TEXT, id_proof_number TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS yatra_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, payment_date TEXT, amount REAL DEFAULT 0, payment_method TEXT DEFAULT 'Cash', transaction_id TEXT, notes TEXT, created_by INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE IF NOT EXISTS yatra_booking_shares (id INTEGER PRIMARY KEY AUTOINCREMENT, yatra_booking_id INTEGER, share_token TEXT UNIQUE, created_by INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, expires_at TIMESTAMP, is_active INTEGER DEFAULT 1)",
"CREATE INDEX IF NOT EXISTS idx_yatra_share ON yatra_booking_shares(share_token)",
"CREATE TABLE IF NOT EXISTS qr_verifications (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, doc_type TEXT NOT NULL, doc_id INTEGER NOT NULL, doc_number TEXT, doc_title TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_active INTEGER DEFAULT 1)",
"CREATE INDEX IF NOT EXISTS idx_qr_token ON qr_verifications(token)",
];
foreach($_yt as $_s){ try{$db->exec($_s);}catch(Exception $e){} }
try{$db->exec("ALTER TABLE yatra_bookings ADD COLUMN pnr TEXT");}catch(Exception $e){}

$defaultSettings = [
    'company_name' => 'D K ASSOCIATES',
    'logo_path' => '',
    'qr_path' => '',
    'qr_path_nongst' => '',
    'next_invoice_serial' => '001',
    'invoice_serial_month' => '',
    'next_nongst_serial' => 'A065',
    'nongst_serial_month' => '',
    'currency_symbol' => '₹',
    'office_address' => '2nd Floor, Utopia Tower, Near College Chowk Flyover, Rewa (M.P.)',
    'office_phone' => '07662-455311, 9329578335',
    'company_email' => 'care@hidk.in',
    'company_website' => 'https://hidk.in/',
    'payment_upi_id' => 'hidk.in@aubank',
    'payment_upi_id_nongst' => '',
    'payment_note' => 'After making payment online, please share transaction screenshot on 9329578335 or contact on 07662-455311, 9329578335.',
    'payment_methods' => 'Cash,UPI,Bank Transfer',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'whatsapp_message' => 'Dear {customer_name}, your invoice {invoice_number} dated {date} is ready. Total Amount: {total_amount}, Paid: {paid_amount}, Balance Due: {balance}. View invoice: {invoice_url}',
    'invoice_template' => 'default',
    'enable_backups' => '1',
    'backup_retention_days' => '30',
    'reply_to_email' => 'care@hidk.in',
    'company_gst_number' => '',
    'default_gst_rate' => '18'
];

foreach ($defaultSettings as $key => $value) {
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (:key, :value)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

$defaultTemplate = json_encode([
    'header' => [
        'show_logo' => true,
        'show_company_name' => true,
        'show_company_address' => true,
        'show_company_contact' => true,
        'show_invoice_number' => true,
        'show_invoice_date' => true,
        'show_payment_status' => true,
        'logo_position' => 'left',
        'company_info_position' => 'center'
    ],
    'customer' => [
        'show_customer_section' => true,
        'show_name' => true,
        'show_phone' => true,
        'show_email' => true,
        'show_address' => true,
        'customer_label' => 'BILL TO:'
    ],
    'items' => [
        'show_items_table' => true,
        'show_sno' => true,
        'show_particulars' => true,
        'show_amount' => true,
        'show_service_charge' => true,
        'show_discount' => true,
        'show_remark' => true,
        'table_striped' => true,
        'show_totals_row' => true
    ],
    'purchases' => [
        'show_purchases_table' => true,
        'show_purchase_sno' => true,
        'show_purchase_particulars' => true,
        'show_purchase_qty' => true,
        'show_purchase_rate' => true,
        'show_purchase_amount' => true,
        'show_amount_received' => true
    ],
    'summary' => [
    'show_summary_section' => true,
    'show_service_charge' => true,
    'show_purchase_total' => true,  // Add this line
    'show_purchase_payable' => true,
    'show_total_payable' => true,
    'show_rounded_total' => true,
    'show_paid_amount' => true,
    'show_balance' => true,
    'summary_position' => 'right'
],
    'payment' => [
        'show_qr_code' => true,
        'show_payment_button' => true,
        'show_payment_note' => true,
        'qr_size' => '140',
        'payment_button_text' => 'Pay Now'
    ],
    'footer' => [
        'show_thankyou_note' => true,
        'show_contact_info' => true,
        'show_signature' => true,
        'thankyou_text' => 'Thank You for Your Business!',
        'signature_text' => 'Authorized Signatory'
    ],
    'styling' => [
        'primary_color' => '#3498db',
        'secondary_color' => '#2c3e50',
        'success_color' => '#27ae60',
        'warning_color' => '#f39c12',
        'danger_color' => '#e74c3c',
        'font_family' => 'Arial, sans-serif',
        'font_size' => '14px',
        'border_radius' => '4px',
        'table_header_bg' => '#f8f9fa',
        'table_row_hover' => '#f8f9fa'
    ]
]);

$stmt = $db->prepare("INSERT OR IGNORE INTO invoice_templates (template_name, template_data, is_default) VALUES ('default', :template, 1)");
$stmt->bindValue(':template', $defaultTemplate, SQLITE3_TEXT);
$stmt->execute();

$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if ($result['count'] == 0) {
    $hashedPassword = password_hash('Pass@123', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, email, role) VALUES ('admin', :password, 'admin@hidk.in', 'admin')");
    $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    $stmt->execute();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ?page=login');
        exit();
    }
    
    // Role-based page access control
    $page = $_GET['page'] ?? 'dashboard';
    $role = getUserRole();

    // Academy pages - check permission separately
    $academyPages = ['academy', 'academy_courses', 'view_enrollment', 'edit_enrollment', 'create_enrollment', 'academy_reminders'];

    // Pages blocked for accountant
    $accountantBlocked = ['view_invoice', 'edit_invoice', 'view_booking', 'edit_booking', 'settings', 'users', 'invoice_templates', 'export', 'pending_deletions', 'payment_link'];

    // Pages blocked for manager
    $managerBlocked = ['users', 'invoice_templates'];

    if ($role === 'accountant' && in_array($page, $accountantBlocked)) {
        $_SESSION['error'] = "Access denied: Accountants cannot access this page.";
        header('Location: ?page=dashboard');
        exit();
    }

    if ($role === 'manager' && in_array($page, $managerBlocked)) {
        $_SESSION['error'] = "Access denied: Managers cannot access this page.";
        header('Location: ?page=dashboard');
        exit();
    }

    // Academy access: admin/manager always, accountant only if has_academy_access=1
    if (in_array($page, $academyPages)) {
        $hasAcademy = isAdmin() || isManager() || (!empty($_SESSION['has_academy_access']));
        if (!$hasAcademy) {
            $_SESSION['error'] = "You do not have access to the Academy module.";
            header('Location: ?page=dashboard');
            exit();
        }
    }
}

function getUserRole() {
    return $_SESSION['role'] ?? 'accountant';
}

function isAdmin() {
    return (getUserRole() === 'admin');
}

function isManager() {
    return (getUserRole() === 'manager');
}

function isAccountant() {
    return (getUserRole() === 'accountant');
}

function canViewNonGST() {
    if (isAdmin()) return true;
    if (isManager() && !empty($_SESSION['has_nongst_access'])) return true;
    return false;
}

function hasAcademyAccess() {
    if (isAdmin() || isManager()) return true;
    return !empty($_SESSION['has_academy_access']);
}

function isDefaultAdmin() {
    return (isAdmin() && $_SESSION['username'] === 'admin');
}

function getSetting($key, $default = '') {
    global $db;
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result) {
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['setting_value'] : $default;
    }
    return $default;
}

function updateSetting($key, $value) {
    global $db;
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (:key, :value)");
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * GST Invoice Serial: INV26[A-L][001-FFF]
 * 3-digit hex, resets to 001 on 1st of each month
 */
function generateInvoiceNumber() {
    $prefix = 'INV';
    $yy = '26';
    $monthCodes = ['A','B','C','D','E','F','G','H','I','J','K','L'];
    $currentMonth = (int)date('n');
    $monthCode = $monthCodes[$currentMonth - 1];
    $currentYearMonth = date('Ym');

    $storedMonth = getSetting('invoice_serial_month', '');
    if ($storedMonth !== $currentYearMonth) {
        updateSetting('invoice_serial_month', $currentYearMonth);
        updateSetting('next_invoice_serial', '001');
        $hexSerial = '001';
    } else {
        $hexSerial = getSetting('next_invoice_serial', '001');
        if (empty($hexSerial)) $hexSerial = '001';
    }

    $hexSerial = strtoupper(str_pad($hexSerial, 3, '0', STR_PAD_LEFT));
    $nextHex = incrementHex3($hexSerial);
    updateSetting('next_invoice_serial', $nextHex);

    return $prefix . $yy . $monthCode . $hexSerial;
}

/**
 * Non-GST Invoice Serial: INV26[01-12][A065-FFFF]
 * 4-digit hex starting at A065, resets to A065 on 1st of each month
 * Month as 2-digit number: January=01, February=02, ... December=12
 */
function generateNonGSTInvoiceNumber() {
    $prefix = 'INV';
    $yy = '26';
    $monthNum = str_pad((int)date('n'), 2, '0', STR_PAD_LEFT); // 01-12
    $currentYearMonth = date('Ym');

    $storedMonth = getSetting('nongst_serial_month', '');
    if ($storedMonth !== $currentYearMonth) {
        updateSetting('nongst_serial_month', $currentYearMonth);
        updateSetting('next_nongst_serial', 'A065');
        $hexSerial = 'A065';
    } else {
        $hexSerial = getSetting('next_nongst_serial', 'A065');
        if (empty($hexSerial)) $hexSerial = 'A065';
    }

    $hexSerial = strtoupper(str_pad($hexSerial, 4, '0', STR_PAD_LEFT));
    $nextHex = incrementHex4($hexSerial);
    updateSetting('next_nongst_serial', $nextHex);

    return $prefix . $yy . $monthNum . $hexSerial;
}

/** Increment 3-digit hex (GST serials: 001 → FFF) */
function incrementHex3($hex) {
    $dec = hexdec($hex) + 1;
    if ($dec > 0xFFF) $dec = 0x001; // wrap safety
    return strtoupper(str_pad(dechex($dec), 3, '0', STR_PAD_LEFT));
}

/** Increment 4-digit hex (Non-GST serials: A065 → FFFF) */
function incrementHex4($hex) {
    $dec = hexdec($hex) + 1;
    if ($dec > 0xFFFF) $dec = 0xA065; // wrap safety
    return strtoupper(str_pad(dechex($dec), 4, '0', STR_PAD_LEFT));
}

/** Legacy alias — kept for booking conversion which always goes to GST DB */
function incrementHex($hex) {
    return incrementHex3($hex);
}

function generateUPILink($balance_amount, $invoice_number, $description = '', $isNonGST = false) {
    // Pick the correct UPI ID: Non-GST uses its own, falls back to GST/default
    if ($isNonGST) {
        $upi_id = getSetting('payment_upi_id_nongst', '');
        if (empty($upi_id)) {
            $upi_id = getSetting('payment_upi_id', 'paytmqr28100505010111fj4g1x21p1@paytm');
        }
    } else {
        $upi_id = getSetting('payment_upi_id', 'paytmqr28100505010111fj4g1x21p1@paytm');
    }
    $company_name = getSetting('company_name', 'D K ASSOCIATES');
    
    $rounded_amount = max(1, round(floatval($balance_amount)));
    $clean_invoice = preg_replace('/[^A-Za-z0-9\-]/', '', $invoice_number);
    
    // Use custom description if provided, otherwise use invoice number
    if (!empty($description)) {
        $note = substr($description, 0, 50); // Limit to 50 chars
    } else {
        $note = "Invoice: " . $clean_invoice;
    }
    
    // Create UPI link with proper encoding
    $params = [
        'pa' => $upi_id,
        'pn' => $company_name,
        'am' => $rounded_amount,
        'tn' => $note,
        'cu' => 'INR'
    ];
    
    $link = "upi://pay?" . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    
    return $link;
}

function generatePaymentLink($amount, $purpose = 'Payment', $customer_name = '', $customer_phone = '') {
    global $db;
    
    // Create payment_links table if it doesn't exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS payment_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE,
            amount REAL,
            purpose TEXT,
            customer_name TEXT,
            customer_phone TEXT,
            status TEXT DEFAULT 'pending',
            payment_id INTEGER,
            created_by INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP,
            is_active INTEGER DEFAULT 1
        )
    ");
    
    // Create index if not exists
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payment_token ON payment_links (token)");
    
    // Generate a unique token using multiple sources for guaranteed uniqueness
    do {
        $token = bin2hex(random_bytes(16)) . '_' . time() . '_' . uniqid();
        
        // Check if token already exists
        $stmt = $db->prepare("SELECT id FROM payment_links WHERE token = :token");
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $exists = ($result !== false);
    } while ($exists);
    
    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $stmt = $db->prepare("
        INSERT INTO payment_links (token, amount, purpose, customer_name, customer_phone, created_by, expires_at) 
        VALUES (:token, :amount, :purpose, :customer_name, :customer_phone, :user_id, :expires_at)
    ");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':purpose', $purpose, SQLITE3_TEXT);
    $stmt->bindValue(':customer_name', $customer_name, SQLITE3_TEXT);
    $stmt->bindValue(':customer_phone', $customer_phone, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':expires_at', $expires_at, SQLITE3_TEXT);
    $stmt->execute();
    
    $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/';
    return $base_url . 'payment_link.php?token=' . $token;
}

function generatePaymentLinkHandler() {
    $amount        = floatval($_POST['amount'] ?? 0);
    $purpose       = trim($_POST['purpose'] ?? 'Payment Request');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone= trim($_POST['customer_phone'] ?? '');

    if ($amount <= 0) {
        $_SESSION['error'] = "Please enter a valid amount!";
        header('Location: ?page=payment_link'); exit();
    }
    if (empty($purpose)) {
        $_SESSION['error'] = "Please enter a payment description!";
        header('Location: ?page=payment_link'); exit();
    }

    $payment_url = generatePaymentLink($amount, $purpose, $customer_name, $customer_phone);
    $_SESSION['generated_payment_link'] = [
        'url'           => $payment_url,
        'amount'        => $amount,
        'purpose'       => $purpose,
        'customer_name' => $customer_name,
        'customer_phone'=> preg_replace('/[^0-9]/', '', $customer_phone)
    ];
    $_SESSION['success'] = "Payment link generated successfully!";
    header('Location: ?page=payment_link'); exit();
}

function generateQRCode($text, $size = 180) {
    if (empty($text)) return '';
    $encoded_text = urlencode($text);
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encoded_text}&format=png&margin=10";
}

function getPaymentMethods() {
    $methods = getSetting('payment_methods', 'Cash,UPI,Bank Transfer,Card,Cheque');
    return explode(',', $methods);
}

function logAction($action, $details = '') {
    global $db;
    if (!isset($_SESSION['user_id'])) return;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $stmt = $db->prepare("INSERT INTO user_logs (user_id, action, details, ip_address) VALUES (:user_id, :action, :details, :ip_address)");
    $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':action', $action, SQLITE3_TEXT);
    $stmt->bindValue(':details', $details, SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', $ip_address, SQLITE3_TEXT);
    $stmt->execute();
}

function getInvoiceData($invoice_id, $targetDb = null) {
    global $db;
    
    $useDb = $targetDb ?? $db;
    
    $stmt = $useDb->prepare("SELECT * FROM invoices WHERE id = :id");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $invoice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$invoice) return null;
    
    if (isset($invoice['created_by']) && $invoice['created_by'] > 0) {
        $stmt = $db->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->bindValue(':id', $invoice['created_by'], SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $invoice['created_by_name'] = $user ? $user['username'] : 'Unknown';
    } else {
        $invoice['created_by_name'] = 'System';
    }
    
    $stmt = $useDb->prepare("SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY s_no");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $items_result = $stmt->execute();
    $items = [];
    while ($item = $items_result->fetchArray(SQLITE3_ASSOC)) $items[] = $item;
    $invoice['parsed_items'] = $items;
    
    $stmt = $useDb->prepare("SELECT * FROM purchases WHERE invoice_id = :id ORDER BY s_no");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $purchases_result = $stmt->execute();
    $purchases = [];
    while ($purchase = $purchases_result->fetchArray(SQLITE3_ASSOC)) {
        // Calculate purchase amount if not already set
        if ($purchase['purchase_amount'] == 0 && $purchase['qty'] > 0 && $purchase['rate'] > 0) {
            $purchase['purchase_amount'] = $purchase['qty'] * $purchase['rate'];
        }
        $purchases[] = $purchase;
    }
    $invoice['parsed_purchases'] = $purchases;
    
    $stmt = $useDb->prepare("SELECT * FROM payments WHERE invoice_id = :id ORDER BY payment_date DESC");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $payments_result = $stmt->execute();
    $payments = [];
    while ($payment = $payments_result->fetchArray(SQLITE3_ASSOC)) $payments[] = $payment;
    $invoice['parsed_payments'] = $payments;
    
    $amount_total = $service_total = $discount_total = $purchase_total = $purchase_received = 0;
    
    foreach ($invoice['parsed_items'] as $item) {
        $amount_total += floatval($item['amount']);
        $service_total += floatval($item['service_charge']);
        $discount_total += floatval($item['discount']);
    }
    
    foreach ($invoice['parsed_purchases'] as $purchase) {
        $purchase_amount = floatval($purchase['purchase_amount']);
        if ($purchase_amount == 0 && $purchase['qty'] > 0 && $purchase['rate'] > 0) {
            $purchase_amount = $purchase['qty'] * $purchase['rate'];
        }
        $purchase_total += $purchase_amount;
        $purchase_received += floatval($purchase['amount_received']);
    }
    
    $service_charge_payable = $service_total - $discount_total;
    $purchase_payable = $purchase_total - $purchase_received;
    $subtotal = $service_charge_payable + $purchase_payable;

    // GST calculations
    $gst_amount_stored = floatval($invoice['gst_amount'] ?? 0);
    $gst_rate          = floatval($invoice['gst_rate'] ?? 0);
    $gst_inclusive     = (bool)($invoice['gst_inclusive'] ?? 0);

    // If gst_rate is 0 but gst_amount is also 0, but this IS the GST DB,
    // fall back to the system default GST rate so existing invoices show breakdown.
    // We detect "GST DB invoice" by checking if gst_rate was ever set > 0 OR amount > 0.
    // For truly non-GST invoices stored in this function, gst_rate stays 0.
    $isGSTInvoice = ($gst_rate > 0 || $gst_amount_stored > 0 || $gst_inclusive);

    // Determine effective GST amount and taxable base
    if ($isGSTInvoice) {
        if ($gst_inclusive) {
            // GST is inside the billed amounts — extract it
            $effectiveRate = $gst_rate > 0 ? $gst_rate : floatval(getSetting('default_gst_rate', '18'));
            $gst_effective = round($subtotal * $effectiveRate / (100 + $effectiveRate), 2);
            $gst_rate      = $effectiveRate;
            $taxable_base  = round($subtotal - $gst_effective, 2);
            $total_payable = $subtotal;
        } else {
            // GST is exclusive — add on top
            $effectiveRate = $gst_rate > 0 ? $gst_rate : 0;
            if ($effectiveRate > 0) {
                $gst_effective = round($subtotal * $effectiveRate / 100, 2);
            } else {
                $gst_effective = $gst_amount_stored;
            }
            $gst_rate      = $effectiveRate;
            $taxable_base  = $subtotal;
            $total_payable = $subtotal + $gst_effective;
        }
    } else {
        // Non-GST invoice — no tax at all
        $gst_effective = 0;
        $taxable_base  = $subtotal;
        $total_payable = $subtotal;
    }

    $rounded_total = round($total_payable);
    $paid_amount   = floatval($invoice['paid_amount'] ?? 0);
    $balance       = $rounded_total - $paid_amount;

    $invoice['totals'] = [
        'amount_total'           => $amount_total,
        'service_total'          => $service_total,
        'discount_total'         => $discount_total,
        'service_charge_payable' => $service_charge_payable,
        'purchase_total'         => $purchase_total,
        'purchase_received'      => $purchase_received,
        'purchase_payable'       => $purchase_payable,
        'subtotal'               => $subtotal,          // pre-GST total
        'taxable_base'           => $taxable_base,      // amount on which GST is shown
        'gst_amount'             => $gst_effective,     // GST value shown in breakdown
        'gst_rate'               => $gst_rate,
        'gst_inclusive'          => $gst_inclusive,
        'total_payable'          => $total_payable,
        'rounded_total'          => $rounded_total,
        'paid_amount'            => $paid_amount,
        'balance'                => $balance
    ];
    
    return $invoice;
}

function getAllInvoices($search = '', $start_date = '', $end_date = '', $payment_status = '', $targetDb = null, $isNonGST = false) {
    global $db;
    
    $useDb = $targetDb ?? $db;
    
    $query = "SELECT i.* FROM invoices i WHERE 1=1";
    $params = [];
    
    // For non-GST view: limit to records created within last 7 days (for payment updates)
    if ($isNonGST) {
        $query .= " AND i.created_at >= datetime('now', '-7 days')";
    }
    
    if ($search) {
        $query .= " AND (i.customer_name LIKE :search OR i.invoice_number LIKE :search OR i.customer_phone LIKE :search OR i.customer_email LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if ($start_date) {
        $query .= " AND i.invoice_date >= :start_date";
        $params[':start_date'] = $start_date;
    }
    if ($end_date) {
        $query .= " AND i.invoice_date <= :end_date";
        $params[':end_date'] = $end_date;
    }
    if ($payment_status && $payment_status !== 'all') {
        $query .= " AND i.payment_status = :payment_status";
        $params[':payment_status'] = $payment_status;
    }
    $query .= " ORDER BY i.created_at DESC";
    
    $stmt = $useDb->prepare($query);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $invoices = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Try to get created_by_name from main DB users table
        if (!empty($row['created_by'])) {
            $ustmt = $db->prepare("SELECT username FROM users WHERE id = :id");
            $ustmt->bindValue(':id', $row['created_by'], SQLITE3_INTEGER);
            $urow = $ustmt->execute()->fetchArray(SQLITE3_ASSOC);
            $row['created_by_name'] = $urow ? $urow['username'] : 'Unknown';
        } else {
            $row['created_by_name'] = 'Unknown';
        }
        $invoices[] = $row;
    }
    
    return $invoices;
}

function getInvoiceTemplate($template_name = null) {
    global $db;

    // Full safe defaults — any key missing from stored template falls back here
    $defaults = [
        'header'   => [
            'show_logo' => true, 'show_company_name' => true,
            'show_company_address' => true, 'show_company_contact' => true,
            'show_invoice_number' => true, 'show_invoice_date' => true,
            'show_payment_status' => true, 'logo_position' => 'left',
            'company_info_position' => 'center'
        ],
        'customer' => [
            'show_customer_section' => true, 'show_name' => true,
            'show_phone' => true, 'show_email' => true,
            'show_address' => true, 'customer_label' => 'BILL TO:'
        ],
        'items'    => [
            'show_items_table' => true, 'show_sno' => true,
            'show_particulars' => true, 'show_amount' => true,
            'show_service_charge' => true, 'show_discount' => true,
            'show_remark' => true, 'table_striped' => true, 'show_totals_row' => true
        ],
        'purchases' => [
            'show_purchases_table' => true, 'show_purchase_sno' => true,
            'show_purchase_particulars' => true, 'show_purchase_qty' => true,
            'show_purchase_rate' => true, 'show_purchase_amount' => true,
            'show_amount_received' => true
        ],
        'summary'  => [
            'show_summary_section' => true, 'show_service_charge' => true,
            'show_purchase_total' => true, 'show_purchase_payable' => true,
            'show_total_payable' => true, 'show_rounded_total' => true,
            'show_paid_amount' => true, 'show_balance' => true,
            'summary_position' => 'right'
        ],
        'payment'  => [
            'show_qr_code' => true, 'show_payment_button' => true,
            'show_payment_note' => true, 'qr_size' => '140',
            'payment_button_text' => 'Pay Now'
        ],
        'footer'   => [
            'show_thankyou_note' => true, 'show_contact_info' => true,
            'show_signature' => true,
            'thankyou_text' => 'Thank You for Your Business!',
            'signature_text' => 'Authorized Signatory'
        ],
        'styling'  => [
            'primary_color' => '#3498db', 'secondary_color' => '#2c3e50',
            'success_color' => '#27ae60', 'warning_color'   => '#f39c12',
            'danger_color'  => '#e74c3c', 'font_family'     => 'Arial, sans-serif',
            'font_size'     => '14px',   'border_radius'   => '4px',
            'table_header_bg' => '#f8f9fa', 'table_row_hover' => '#f8f9fa'
        ]
    ];

    if ($template_name === null) $template_name = getSetting('invoice_template', 'default');

    $stored = null;
    $stmt = $db->prepare("SELECT template_data FROM invoice_templates WHERE template_name = :name");
    $stmt->bindValue(':name', $template_name, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($result) $stored = json_decode($result['template_data'], true);

    if (!$stored) {
        $stmt = $db->prepare("SELECT template_data FROM invoice_templates WHERE is_default = 1 LIMIT 1");
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($result) $stored = json_decode($result['template_data'], true);
    }

    if (!$stored) return $defaults;

    // Deep merge: stored values win, defaults fill in any missing keys
    foreach ($defaults as $section => $keys) {
        if (!isset($stored[$section])) {
            $stored[$section] = $keys;
        } else {
            foreach ($keys as $k => $v) {
                if (!isset($stored[$section][$k])) {
                    $stored[$section][$k] = $v;
                }
            }
        }
    }
    return $stored;
}

// ============ BOOKING MODULE FUNCTIONS ============

function generateBookingNumber() {
    $prefix = 'BK';
    $yy = date('y');
    $mm = date('m');
    
    // Get last booking number
    global $db;
    $result = $db->query("SELECT booking_number FROM bookings ORDER BY id DESC LIMIT 1");
    $last = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($last && preg_match('/BK\d{4}(\d+)/', $last['booking_number'], $matches)) {
        $seq = intval($matches[1]) + 1;
    } else {
        $seq = 1;
    }
    
    return $prefix . $yy . $mm . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

function getBookingData($booking_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT * FROM bookings WHERE id = :id");
    $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
    $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$booking) return null;
    
    // Get created by name
    if (isset($booking['created_by']) && $booking['created_by'] > 0) {
        $stmt = $db->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->bindValue(':id', $booking['created_by'], SQLITE3_INTEGER);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $booking['created_by_name'] = $user ? $user['username'] : 'Unknown';
    } else {
        $booking['created_by_name'] = 'System';
    }
    
    // Get booking items
    $stmt = $db->prepare("SELECT * FROM booking_items WHERE booking_id = :id ORDER BY s_no");
    $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
    $items_result = $stmt->execute();
    $items = [];
    while ($item = $items_result->fetchArray(SQLITE3_ASSOC)) $items[] = $item;
    $booking['parsed_items'] = $items;
    
    // Get booking payments
    $stmt = $db->prepare("SELECT * FROM booking_payments WHERE booking_id = :id ORDER BY payment_date DESC");
    $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
    $payments_result = $stmt->execute();
    $payments = [];
    while ($payment = $payments_result->fetchArray(SQLITE3_ASSOC)) $payments[] = $payment;
    $booking['parsed_payments'] = $payments;
    
    // Calculate totals
    $total_estimated = $booking['total_estimated_cost'];
    $total_advance_paid = 0;
    
    foreach ($booking['parsed_payments'] as $payment) {
        if ($payment['is_advance']) {
            $total_advance_paid += floatval($payment['amount']);
        }
    }
    
    $booking['totals'] = [
        'total_estimated' => $total_estimated,
        'advance_paid' => $total_advance_paid,
        'balance' => $total_estimated - $total_advance_paid,
        'advance_fees' => $booking['advance_fees']
    ];
    
    return $booking;
}

function getAllBookings($search = '', $status = '', $date_from = '', $date_to = '') {
    global $db;
    
    $query = "SELECT b.*, u.username as created_by_name FROM bookings b LEFT JOIN users u ON b.created_by = u.id WHERE 1=1";
    $params = [];
    
    if (!isAdmin() && isset($_SESSION['user_id'])) {
        $query .= " AND (b.created_by = :user_id)";
        $params[':user_id'] = $_SESSION['user_id'];
    }
    
    if ($search) {
        $query .= " AND (b.customer_name LIKE :search OR b.booking_number LIKE :search OR b.customer_phone LIKE :search OR b.service_description LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    if ($status && $status !== 'all' && $status !== '') {
        $query .= " AND b.status = :status";
        $params[':status'] = $status;
    }
    
    if ($date_from) {
        $query .= " AND b.booking_date >= :date_from";
        $params[':date_from'] = $date_from;
    }
    
    if ($date_to) {
        $query .= " AND b.booking_date <= :date_to";
        $params[':date_to'] = $date_to;
    }
    
    $query .= " ORDER BY b.created_at DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_numeric($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    
    $bookings = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $bookings[] = $row;
    }
    
    return $bookings;
}

function getBookingStats() {
    global $db;
    
    $where_clause = "";
    if (!isAdmin()) {
        $where_clause = " WHERE created_by = " . $_SESSION['user_id'];
    }
    
    $stats = [];
    
    $result = $db->query("SELECT COUNT(*) as count FROM bookings" . $where_clause);
    $stats['total'] = $result->fetchArray(SQLITE3_ASSOC)['count'];
    
    $result = $db->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'" . $where_clause);
    $stats['pending'] = $result->fetchArray(SQLITE3_ASSOC)['count'];
    
    $result = $db->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'in_progress'" . $where_clause);
    $stats['in_progress'] = $result->fetchArray(SQLITE3_ASSOC)['count'];
    
    $result = $db->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'completed'" . $where_clause);
    $stats['completed'] = $result->fetchArray(SQLITE3_ASSOC)['count'];
    
    $result = $db->query("SELECT SUM(advance_fees) as total FROM bookings WHERE payment_status != 'pending'" . $where_clause);
    $stats['total_advance'] = floatval($result->fetchArray(SQLITE3_ASSOC)['total'] ?? 0);
    
    return $stats;
}

function convertBookingToInvoice($booking_id) {
    global $db;
    
    $booking = getBookingData($booking_id);
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        return false;
    }
    
    if ($booking['converted_to_invoice']) {
        $_SESSION['error'] = "Booking already converted to invoice!";
        return false;
    }
    
    if ($booking['status'] != 'completed') {
        $_SESSION['error'] = "Only completed bookings can be converted to invoices!";
        return false;
    }
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        // Generate invoice number
        $invoice_number = generateInvoiceNumber();
        
        // Create invoice
        $stmt = $db->prepare("
            INSERT INTO invoices (
                invoice_number, customer_name, customer_phone, 
                customer_email, customer_address, invoice_date, 
                payment_status, paid_amount, created_by
            ) VALUES (
                :invoice_number, :name, :phone, :email, :address, 
                :date, :status, :paid_amount, :created_by
            )
        ");
        
        $stmt->bindValue(':invoice_number', $invoice_number, SQLITE3_TEXT);
        $stmt->bindValue(':name', $booking['customer_name'], SQLITE3_TEXT);
        $stmt->bindValue(':phone', $booking['customer_phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $booking['customer_email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $booking['customer_address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':date', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':status', 'paid', SQLITE3_TEXT);
        $stmt->bindValue(':paid_amount', $booking['advance_fees'], SQLITE3_FLOAT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        $invoice_id = $db->lastInsertRowID();
        
        // Add invoice items from booking items
        if (!empty($booking['parsed_items'])) {
            foreach ($booking['parsed_items'] as $index => $item) {
                $stmt = $db->prepare("
                    INSERT INTO invoice_items (
                        invoice_id, s_no, particulars, amount, 
                        service_charge, discount, remark
                    ) VALUES (
                        :invoice_id, :s_no, :particulars, :amount, 0, 0, ''
                    )
                ");
                $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                $stmt->bindValue(':s_no', $index + 1, SQLITE3_INTEGER);
                $stmt->bindValue(':particulars', $item['description'], SQLITE3_TEXT);
                $stmt->bindValue(':amount', $item['actual_amount'] ?: $item['estimated_amount'], SQLITE3_FLOAT);
                $stmt->execute();
            }
        } else {
            // If no items, add service description as item
            $stmt = $db->prepare("
                INSERT INTO invoice_items (
                    invoice_id, s_no, particulars, amount, service_charge, discount, remark
                ) VALUES (
                    :invoice_id, 1, :particulars, :amount, 0, 0, ''
                )
            ");
            $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
            $stmt->bindValue(':particulars', $booking['service_description'], SQLITE3_TEXT);
            $stmt->bindValue(':amount', $booking['total_estimated_cost'], SQLITE3_FLOAT);
            $stmt->execute();
        }
        
        // Add advance payment to invoice if any
        if ($booking['advance_fees'] > 0) {
            $stmt = $db->prepare("
                INSERT INTO payments (
                    invoice_id, payment_date, amount, payment_method, 
                    transaction_id, notes, created_by
                ) VALUES (
                    :invoice_id, :date, :amount, :method, :txn_id, :notes, :created_by
                )
            ");
            $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
            $stmt->bindValue(':date', $booking['booking_date'], SQLITE3_TEXT);
            $stmt->bindValue(':amount', $booking['advance_fees'], SQLITE3_FLOAT);
            $stmt->bindValue(':method', $booking['payment_method'] ?? 'Cash', SQLITE3_TEXT);
            $stmt->bindValue(':txn_id', $booking['transaction_id'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':notes', 'Advance payment from booking #' . $booking['booking_number'], SQLITE3_TEXT);
            $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
        
        // Update booking record
        $stmt = $db->prepare("
            UPDATE bookings SET 
            converted_to_invoice = 1,
            converted_invoice_id = :invoice_id,
            status = 'converted',
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('CONVERT_BOOKING', "Converted booking {$booking['booking_number']} to invoice {$invoice_number}");
        
        $_SESSION['success'] = "Booking converted to invoice successfully! Invoice #: {$invoice_number}";
        
        return $invoice_id;
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error converting booking: " . $e->getMessage();
        return false;
    }
}

function addBookingPayment($booking_id, $amount, $payment_method, $transaction_id = '', $notes = '') {
    global $db;
    
    $booking = getBookingData($booking_id);
    if (!$booking) return false;
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        // Add payment record
        $stmt = $db->prepare("
            INSERT INTO booking_payments (
                booking_id, payment_date, amount, payment_method, 
                transaction_id, notes, is_advance, created_by
            ) VALUES (
                :booking_id, :date, :amount, :method, :txn_id, :notes, 1, :created_by
            )
        ");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $stmt->bindValue(':date', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $stmt->bindValue(':method', $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
        $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        // Calculate total advance paid
        $total_paid = $booking['totals']['advance_paid'] + $amount;
        
        // Update booking advance_fees and payment status
        $payment_status = 'pending';
        if ($total_paid >= $booking['advance_fees']) {
            $payment_status = 'paid';
        } elseif ($total_paid > 0) {
            $payment_status = 'partial';
        }
        
        $stmt = $db->prepare("
            UPDATE bookings SET 
            advance_fees = :advance_fees,
            payment_status = :status,
            payment_method = :method,
            transaction_id = :txn_id,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':advance_fees', $total_paid, SQLITE3_FLOAT);
        $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
        $stmt->bindValue(':method', $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('ADD_BOOKING_PAYMENT', "Added advance payment of {$amount} to booking {$booking_id}");
        
        return true;
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error adding payment: " . $e->getMessage();
        return false;
    }
}

function getAllTemplates() {
    global $db;
    $result = $db->query("SELECT * FROM invoice_templates ORDER BY is_default DESC, template_name ASC");
    $templates = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $templates[] = $row;
    return $templates;
}

function displayInvoiceHTML($invoice, $isNonGST = false) {
    $template = getInvoiceTemplate();
    $balance_amount = isset($invoice['totals']['balance']) ? floatval($invoice['totals']['balance']) : 0;
    $invoice_number = $invoice['invoice_number'] ?? 'N/A';
    $upi_link = generateUPILink($balance_amount, $invoice_number, '', $isNonGST);
    $qr_size = $template['payment']['qr_size'] ?? 140;
    // Dynamic QR is primary. Uploaded static QR is fallback when UPI ID is missing.
    $upi_id_set = $isNonGST
        ? (!empty(getSetting('payment_upi_id_nongst', '')) || !empty(getSetting('payment_upi_id', '')))
        : !empty(getSetting('payment_upi_id', ''));
    if (!empty($upi_link) && $upi_id_set) {
        $qr_code_url = generateQRCode($upi_link, $qr_size);
    } else {
        // Fallback: use uploaded static QR image
        $static_qr = $isNonGST ? getSetting('qr_path_nongst', '') : getSetting('qr_path', '');
        $qr_code_url = ($static_qr && file_exists($static_qr)) ? $static_qr : generateQRCode($upi_link, $qr_size);
    }
    $isNonGSTView = $isNonGST;
    ?>
    
    <div class="invoice-preview" id="invoicePreview">
        <?php if ($template['header']['show_logo'] || $template['header']['show_company_name'] || $template['header']['show_invoice_number']): ?>
        <div class="invoice-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #eee;">
            <div style="flex: 1; text-align: Left;">
                <?php 
                if ($template['header']['show_logo']):
                    $logo_path = getSetting('logo_path');
                    if ($logo_path && file_exists($logo_path)): ?>
                        <img src="<?php echo $logo_path; ?>" style="max-width: 130px; max-height: 130px; margin-bottom: 5px;" alt="Logo">
                    <?php endif;
                endif; ?>                
            </div>
            <div style="flex: 2;">
                <?php if ($template['header']['show_company_name']): ?>
                <h2 style="margin: 0 0 5px 0; font-size: 22px; color: <?php echo $template['styling']['secondary_color'] ?? '#2c3e50'; ?>;"><?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?></h2>
                <?php endif; ?>
                
                <?php if ($template['header']['show_company_address'] || $template['header']['show_company_contact']): ?>
                <div style="font-size: 12px; line-height: 1.4; color: #555;">
                    <?php if ($template['header']['show_company_address']): ?>
                    <?php echo nl2br(htmlspecialchars(getSetting('office_address', '2nd Floor, Utopia Tower, Near College Chowk Flyover, Rewa (M.P.)'))); ?><br>
                    <?php endif; ?>
                    
                    <?php if ($template['header']['show_company_contact']): ?>
                    <?php if (!empty(getSetting('office_phone'))): ?>
                    <strong>Phone:</strong> <?php echo htmlspecialchars(getSetting('office_phone', '07662-455311, 9329578335')); ?> <br> 
                    <?php endif; ?>
                    
                    <?php if (!empty(getSetting('company_email'))): ?>
                    <strong>Email:</strong> <?php echo htmlspecialchars(getSetting('company_email', 'care@hidk.in')); ?> | 
                    <?php endif; ?>
                    
                    <?php if (!empty(getSetting('company_website'))): ?>
                    <strong>Website:</strong> <?php echo htmlspecialchars(getSetting('company_website', 'https://hidk.in/')); ?>
                    <?php endif; ?>
                    <?php 
                    $gst_number = getSetting('company_gst_number', '');
                    // Show GST number only if this is a GST invoice (not non-GST)
                    $isNonGSTView = isset($isNonGST) ? $isNonGST : false;
                    if (!empty($gst_number) && !$isNonGSTView): ?>
                    <br><strong>GSTIN:</strong> <?php echo htmlspecialchars($gst_number); ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div style="flex: 1; text-align: right;">
                
                <?php if ($template['header']['show_invoice_number'] || $template['header']['show_invoice_date'] || $template['header']['show_payment_status']): ?>
                <div style="font-size: 13px;">
                    <?php if ($template['header']['show_invoice_number']): ?>
                    <div><strong>No:</strong> <?php echo htmlspecialchars($invoice_number); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($template['header']['show_invoice_date']): ?>
                    <div><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($invoice['invoice_date'])); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($template['header']['show_payment_status']): ?>
                    <div>
                        <strong>Status:</strong> 
                        <span class="payment-badge <?php echo $invoice['payment_status']; ?>" style="font-size: 11px;">
                            <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_status'])); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($template['customer']['show_customer_section']): ?>
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; border-left: 4px solid <?php echo $template['styling']['primary_color'] ?? '#3498db'; ?>; font-size: 13px;">
            <div style="flex: 1;">
                <strong><?php echo htmlspecialchars($template['customer']['customer_label'] ?? 'BILL TO:'); ?></strong><br>
                <div style="margin-top: 3px;">
                    <?php if ($template['customer']['show_name']): ?>
                    <strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong><br>
                    <?php endif; ?>
                    
                    <?php if ($template['customer']['show_phone'] && !empty($invoice['customer_phone'])): ?>
                    📞 <?php echo htmlspecialchars($invoice['customer_phone']); ?><br>
                    <?php endif; ?>
                    
                    <?php if ($template['customer']['show_email'] && !empty($invoice['customer_email'])): ?>
                    📧 <?php echo htmlspecialchars($invoice['customer_email']); ?><br>
                    <?php endif; ?>
                    
                    <?php if ($template['customer']['show_address'] && !empty($invoice['customer_address'])): ?>
                    📍 <?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?>
                    <?php endif; ?>
                    <?php 
                    // Show customer GSTIN only on GST invoices, skip if NA or empty
                    $custGSTNum = $invoice['customer_gst_number'] ?? '';
                    if (!$isNonGSTView && !empty($custGSTNum) && strtoupper($custGSTNum) !== 'NA'): ?>
                    <br>🏷️ <strong>GSTIN:</strong> <?php echo htmlspecialchars($custGSTNum); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($template['summary']['show_summary_section']): ?>
            <div style="flex: 1; text-align: right;">
                <strong>PAYMENT SUMMARY:</strong><br>
                <div style="margin-top: 3px;">
                    <table style="width: 100%; font-size: 13px;">
                        <?php if ($template['summary']['show_total_payable']): ?>
                        <tr>
                            <td style="padding: 2px 5px; text-align: right;">Total Amount:</td>
                            <td style="padding: 2px 5px; font-weight: bold;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($invoice['totals']['rounded_total'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($template['summary']['show_paid_amount']): ?>
                        <tr>
                            <td style="padding: 2px 5px; text-align: right;">Paid Amount:</td>
                            <td style="padding: 2px 5px; color: #27ae60;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($invoice['totals']['paid_amount'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        
                        <?php if ($template['summary']['show_balance']): ?>
                        <tr>
                            <td style="padding: 2px 5px; text-align: right;">Balance Due:</td>
                            <td style="padding: 2px 5px; color: #e74c3c; font-weight: bold;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($invoice['totals']['balance'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($template['items']['show_items_table']): ?>
        <div style="margin-bottom: 15px;">
            <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #34495e; padding-bottom: 5px; border-bottom: 1px solid #ecf0f1;">Invoice Details</h3>
            <table style="width: 100%; font-size: 13px; margin-bottom: 10px; <?php echo $template['items']['table_striped'] ? 'border-collapse: collapse;' : ''; ?>">
                <thead>
                    <tr style="background-color: <?php echo $template['styling']['table_header_bg'] ?? '#f8f9fa'; ?>;">
                        <?php if ($template['items']['show_sno']): ?>
                        <th style="padding: 8px; text-align: left; width: 5%;">#</th>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_particulars']): ?>
                        <th style="padding: 8px; text-align: left; width: 40%;">Particulars</th>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_amount']): ?>
                        <th style="padding: 8px; text-align: right; width: 15%;">Amount</th>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_service_charge']): ?>
                        <th style="padding: 8px; text-align: right; width: 15%;">S. Charge</th>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_discount']): ?>
                        <th style="padding: 8px; text-align: right; width: 15%;">Discount</th>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_remark']): ?>
                        <th style="padding: 8px; text-align: left; width: 10%;">Remark</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoice['parsed_items'])): ?>
                    <tr>
                        <td colspan="6" style="padding: 10px; text-align: center;">No items found</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($invoice['parsed_items'] as $index => $item): ?>
                    <tr style="<?php echo $template['items']['table_striped'] && $index % 2 == 0 ? 'background-color: ' . ($template['styling']['table_row_hover'] ?? '#f8f9fa') . ';' : ''; ?> border-bottom: 1px solid #f0f0f0;">
                        <?php if ($template['items']['show_sno']): ?>
                        <td style="padding: 8px;"><?php echo $index + 1; ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_particulars']): ?>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($item['particulars']); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_amount']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($item['amount'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_service_charge']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($item['service_charge'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_discount']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($item['discount'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_remark']): ?>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($item['remark']); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                
                <?php if ($template['items']['show_totals_row']): ?>
                <tfoot>
                    <tr style="background-color: <?php echo $template['styling']['table_header_bg'] ?? '#f8f9fa'; ?>; font-weight: bold;">
                        <td colspan="<?php 
                            $colspan = 0;
                            if ($template['items']['show_sno']) $colspan++;
                            if ($template['items']['show_particulars']) $colspan++;
                            echo $colspan;
                        ?>" style="padding: 8px; text-align: right;">Totals:</td>
                        
                        <?php if ($template['items']['show_amount']): ?>
                        <td style="padding: 8px; text-align: right; border-top: 2px solid #ddd;"><?php echo number_format($invoice['totals']['amount_total'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_service_charge']): ?>
                        <td style="padding: 8px; text-align: right; border-top: 2px solid #ddd;"><?php echo number_format($invoice['totals']['service_total'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_discount']): ?>
                        <td style="padding: 8px; text-align: right; border-top: 2px solid #ddd;"><?php echo number_format($invoice['totals']['discount_total'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['items']['show_remark']): ?>
                        <td style="padding: 8px; border-top: 2px solid #ddd;"></td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($template['purchases']['show_purchases_table'] && !empty($invoice['parsed_purchases'])): ?>
        <div style="margin-bottom: 15px;">
            <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #34495e; padding-bottom: 5px; border-bottom: 1px solid #ecf0f1;">Purchases</h3>
            <table style="width: 100%; font-size: 13px; margin-bottom: 10px;">
                <thead>
                    <tr style="background-color: <?php echo $template['styling']['table_header_bg'] ?? '#f8f9fa'; ?>;">
                        <?php if ($template['purchases']['show_purchase_sno']): ?>
                        <th style="padding: 8px; text-align: left; width: 5%;">S.No</th>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_particulars']): ?>
                        <th style="padding: 8px; text-align: left; width: 25%;">Particulars</th>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_qty']): ?>
                        <th style="padding: 8px; text-align: right; width: 10%;">Qty</th>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_rate']): ?>
                        <th style="padding: 8px; text-align: right; width: 12%;">Rate (₹)</th>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_amount']): ?>
                        <th style="padding: 8px; text-align: right; width: 15%;">Purchase Amount (₹)</th>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_amount_received']): ?>
                        <th style="padding: 8px; text-align: right; width: 15%;">Amount Received (₹)</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoice['parsed_purchases'] as $index => $purchase): 
                        $purchase_amount = $purchase['purchase_amount'];
                        if ($purchase_amount == 0 && $purchase['qty'] > 0 && $purchase['rate'] > 0) {
                            $purchase_amount = $purchase['qty'] * $purchase['rate'];
                        }
                    ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <?php if ($template['purchases']['show_purchase_sno']): ?>
                        <td style="padding: 8px;"><?php echo $index + 1; ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_particulars']): ?>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($purchase['particulars']); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_qty']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($purchase['qty'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_rate']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($purchase['rate'], 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_purchase_amount']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($purchase_amount, 2); ?></td>
                        <?php endif; ?>
                        
                        <?php if ($template['purchases']['show_amount_received']): ?>
                        <td style="padding: 8px; text-align: right;"><?php echo number_format($purchase['amount_received'], 2); ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($invoice['parsed_payments'])): ?>
        <div style="margin-bottom: 15px;">
            <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #34495e; padding-bottom: 5px; border-bottom: 1px solid #ecf0f1;">Payment History</h3>
            <table style="width: 100%; font-size: 13px; margin-bottom: 10px;">
                <thead>
                    <tr style="background-color: <?php echo $template['styling']['table_header_bg'] ?? '#f8f9fa'; ?>;">
                        <th style="padding: 8px; text-align: left;">Date</th>
                        <th style="padding: 8px; text-align: left;">Method</th>
                        <th style="padding: 8px; text-align: right;">Amount</th>
                        <th style="padding: 8px; text-align: left;">Transaction ID</th>
                        <th style="padding: 8px; text-align: left;">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoice['parsed_payments'] as $payment): ?>
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 8px;"><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                        <td style="padding: 8px; text-align: right; color: #27ae60;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($payment['amount'], 2); ?></td>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($payment['transaction_id']); ?></td>
                        <td style="padding: 8px;"><?php echo htmlspecialchars($payment['notes']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <div style="display: flex; justify-content: space-between; margin-bottom: 15px; gap: 20px;">
            <?php if ($template['summary']['show_summary_section']): ?>
            <div style="flex: 4; padding: 15px; background: #f8f9fa; border-radius: 5px; font-size: 13px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px; color: #2c3e50;">Amount Breakdown</h4>
                <table style="width: 100%;">
                    <?php
                    $cur = getSetting('currency_symbol', '₹');
                    $totals = $invoice['totals'];
                    $hasGST = ($totals['gst_amount'] > 0);
                    ?>

                    <?php if ($template['summary']['show_service_charge']): ?>
                    <tr>
                        <td style="padding: 4px 0;"><?php echo $hasGST ? 'Service Charge Subtotal:' : 'Service Charge Payable:'; ?></td>
                        <td style="padding: 4px 0; text-align: right; font-weight: bold;"><?php echo $cur; ?> <?php echo number_format($totals['service_charge_payable'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($template['summary']['show_purchase_total']) && $template['summary']['show_purchase_total'] && $totals['purchase_total'] > 0): ?>
                    <tr>
                        <td style="padding: 4px 0;"><?php echo $hasGST ? 'Purchase Subtotal:' : 'Purchase Payable:'; ?></td>
                        <td style="padding: 4px 0; text-align: right; font-weight: bold;"><?php echo $cur; ?> <?php echo number_format($totals['purchase_payable'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($hasGST): ?>
                    <!-- GST bifurcation block — only for GST invoices -->
                    <tr>
                        <td style="padding: 4px 0; border-top: 1px solid #ddd;">
                            <?php echo $totals['gst_inclusive'] ? 'Taxable Amount (excl. GST):' : 'Taxable Amount:'; ?>
                        </td>
                        <td style="padding: 4px 0; border-top: 1px solid #ddd; text-align: right;"><?php echo $cur; ?> <?php echo number_format($totals['taxable_base'], 2); ?></td>
                    </tr>
                    <?php
                    $gstLabel = 'GST';
                    if ($totals['gst_rate'] > 0) {
                        // Split into CGST + SGST (each half)
                        $halfRate = $totals['gst_rate'] / 2;
                        $halfAmt  = $totals['gst_amount'] / 2;
                        ?>
                    <tr>
                        <td style="padding: 4px 0; color: #555;">CGST (<?php echo number_format($halfRate, 1); ?>%):</td>
                        <td style="padding: 4px 0; text-align: right; color: #555;"><?php echo $cur; ?> <?php echo number_format($halfAmt, 2); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #555;">SGST (<?php echo number_format($halfRate, 1); ?>%):</td>
                        <td style="padding: 4px 0; text-align: right; color: #555;"><?php echo $cur; ?> <?php echo number_format($halfAmt, 2); ?></td>
                    </tr>
                        <?php
                    } else { ?>
                    <tr>
                        <td style="padding: 4px 0; color: #555;">GST <?php echo $totals['gst_inclusive'] ? '(Inclusive):' : '(Exclusive):'; ?></td>
                        <td style="padding: 4px 0; text-align: right; color: #555;"><?php echo $cur; ?> <?php echo number_format($totals['gst_amount'], 2); ?></td>
                    </tr>
                    <?php } ?>
                    <?php endif; ?>

                    <?php if ($template['summary']['show_total_payable']): ?>
                    <tr style="border-top: 2px solid #2c3e50;">
                        <td style="padding: 6px 0; font-size: 14px; font-weight: bold; color: #2c3e50;">
                            <?php echo $hasGST ? ($totals['gst_inclusive'] ? 'Total (GST Inclusive):' : 'Total (incl. GST):') : 'Total Payable:'; ?>
                        </td>
                        <td style="padding: 6px 0; text-align: right; font-size: 14px; font-weight: bold; color: #2c3e50;"><?php echo $cur; ?> <?php echo number_format($totals['total_payable'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($template['summary']['show_rounded_total'] && $totals['rounded_total'] != $totals['total_payable']): ?>
                    <tr>
                        <td style="padding: 4px 0; color: #666; font-style: italic;">Rounded Amount:</td>
                        <td style="padding: 4px 0; text-align: right; color: #666; font-style: italic;"><?php echo $cur; ?> <?php echo number_format($totals['rounded_total'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($template['summary']['show_paid_amount'] && $totals['paid_amount'] > 0): ?>
                    <tr>
                        <td style="padding: 4px 0; color: #27ae60;">Paid Amount:</td>
                        <td style="padding: 4px 0; text-align: right; color: #27ae60; font-weight: bold;"><?php echo $cur; ?> <?php echo number_format($totals['paid_amount'], 2); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($template['summary']['show_balance']): ?>
                    <tr style="border-top: 1px solid #ddd;">
                        <td style="padding: 4px 0; color: #e74c3c; font-weight: bold;">Balance Due:</td>
                        <td style="padding: 4px 0; text-align: right; color: #e74c3c; font-weight: bold;"><?php echo $cur; ?> <?php echo number_format($totals['balance'], 2); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php if ($template['payment']['show_payment_note']): ?>
                <div style="padding: 10px; background: #fff8e1; border-radius: 4px; border-left: 4px solid #ffb300; font-size: 12px; margin-top: 15px;">
                    <strong>Payment Instructions:</strong> <?php echo htmlspecialchars(getSetting('payment_note', 'After making payment online, please share transaction screenshot on 9329578335 or contact on 07662-455311, 9329578335.')); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($template['payment']['show_qr_code']): ?>
            <div style="flex: 1; padding: 15px; background: #f8f9fa; border-radius: 5px; text-align: Right;">
                <?php if ($balance_amount > 0 && !empty($upi_link) && !empty($qr_code_url)): ?>
                    <div id="qrCodeContainer">
                        <div id="primaryQR">
                            <a href="<?php echo htmlspecialchars($upi_link); ?>" onclick="handleUPIClick(event, '<?php echo htmlspecialchars($upi_link); ?>')">
                                <img src="<?php echo htmlspecialchars($qr_code_url); ?>" 
                                     id="qrCodeImage"
                                     style="max-width: <?php echo $template['payment']['qr_size'] ?? 140; ?>px; border: 1px solid #ddd; padding: 5px; background: white;"
                                     alt="Payment QR Code"
                                     onerror="fallbackQRCode()">
                            </a>
                        </div>
                        
                        <div id="fallbackQR" style="display: none;">
                            <a href="<?php echo htmlspecialchars($upi_link); ?>" onclick="handleUPIClick(event, '<?php echo htmlspecialchars($upi_link); ?>')">
                                <img src="https://quickchart.io/qr?text=<?php echo urlencode($upi_link); ?>&size=<?php echo $template['payment']['qr_size'] ?? 140; ?>" 
                                     style="max-width: <?php echo $template['payment']['qr_size'] ?? 140; ?>px; border: 1px solid #ddd; padding: 5px; background: white;"
                                     alt="Payment QR Code">
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($template['payment']['show_payment_button']): ?>
                    <div style="margin-top: 10px;">
                        <a href="<?php echo htmlspecialchars($upi_link); ?>" 
                           style="color: white; text-decoration: none; font-size: 14px; padding: 8px 15px; background: #25D366; border-radius: 4px; display: inline-block; font-weight: bold;"
                           onclick="handleUPIClick(event, '<?php echo htmlspecialchars($upi_link); ?>')">
                           <?php echo htmlspecialchars($template['payment']['payment_button_text'] ?? 'Pay'); ?> ₹<?php echo round($balance_amount); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                <?php elseif ($balance_amount <= 0): ?>
                    <div style="padding: 15px; background: #d4edda; border-radius: 4px;">
                        <div style="color: #155724; font-weight: bold;">✓ Paid</div>
                        <div style="font-size: 12px; color: #666;">No payment due</div>
                    </div>
                <?php endif; ?>
                
                <div id="qrStatus" style="margin-top: 5px; font-size: 11px; color: #666;"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($invoice['totals']['balance'] > 0 && !isset($_GET['share']) && !isset($_GET['email'])): ?>
        <div class="payment-form no-email">
            <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #34495e;">Add Payment</h3>
            <form method="POST" id="addPaymentForm">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                
                <div class="row">
                    <div class="form-group">
                        <label>Payment Date:</label>
                        <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Method:</label>
                        <select name="payment_method" required>
                            <option value="">Select Method</option>
                            <?php foreach (getPaymentMethods() as $method): ?>
                            <option value="<?php echo htmlspecialchars(trim($method)); ?>"><?php echo htmlspecialchars(trim($method)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (₹):</label>
                        <input type="number" name="amount" step="0.01" min="1" max="<?php echo $invoice['totals']['balance']; ?>" required>
                        <small>Balance due: ₹<?php echo number_format($invoice['totals']['balance'], 2); ?></small>
                    </div>
                </div>
                
                <div class="row">
                    <div class="form-group">
                        <label>Transaction ID:</label>
                        <input type="text" name="transaction_id" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label>Notes:</label>
                        <input type="text" name="notes" placeholder="Optional">
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="submit">Record Payment</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5px; padding-top: 15px; border-top: 2px solid #eee; font-size: 13px;">
            <div>
                <?php if ($template['footer']['show_thankyou_note']): ?>
                <div style="font-weight: bold; margin-bottom: 5px;"><?php echo htmlspecialchars($template['footer']['thankyou_text'] ?? 'Thank You for Your Business!'); ?></div>
                <?php endif; ?>
                <?php if ($template['footer']['show_contact_info']): ?>
                <div style="color: #666;">For any queries, contact: <?php echo htmlspecialchars(getSetting('office_phone', '07662-455311, 9329578335')); ?></div>
                <?php endif; ?>
            </div>
            
            <?php if ($template['footer']['show_signature']): ?>
            <div style="text-align: right; font-weight: bold;">
                <div style="margin-bottom: 30px; border-top: 1px solid #333; width: 150px; padding-top: 5px; margin-left: auto;">
                    <?php echo htmlspecialchars($template['footer']['signature_text'] ?? 'Authorized Signatory'); ?><br/>
                    <?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?>
                </div>
                <div style="font-weight: bold;"></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="action-buttons no-print" style="margin-top: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button onclick="printInvoice()" class="btn-print" style="padding: 12px 20px; font-weight: bold; text-decoration: none;">
                    <span style="display: inline-block; margin-right: 8px;">🖨️</span> Print / Save as PDF
                </button>
                
                <?php 
                $canEdit = isAdmin() || $invoice['created_by'] == $_SESSION['user_id'];
                if ($canEdit): ?>
                <a href="?page=edit_invoice&id=<?php echo $invoice['id']; ?>" class="btn-warning" style="padding: 12px 20px; font-weight: bold; text-decoration: none;">
                    <span style="display: inline-block; margin-right: 8px;">✏️</span> Edit Invoice
                </a>
                <?php endif; ?>
                
                <a href="?page=invoices" class="btn-secondary" style="padding: 12px 20px; font-weight: bold; text-decoration: none;">
                    <span style="display: inline-block; margin-right: 8px;">📋</span> Back to List
                </a>
            </div>
            
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-left: auto;">
                <?php if (!empty($invoice['parsed_payments'])): ?>
                <button onclick="showDeletePaymentModal()" class="btn" style="background: linear-gradient(135deg, #e74c3c, #c0392b); padding: 12px 20px; font-weight: bold; text-decoration: none;">
                    <span style="display: inline-block; margin-right: 8px;">🗑️</span> Delete Payment
                </button>
                <?php endif; ?>
                
                <?php if (!empty($invoice['customer_email']) || !empty($invoice['customer_phone'])): ?>
                <div style="position: relative; display: inline-block;">
                    <button onclick="toggleShareDropdown()" class="btn-warning" style="padding: 12px 20px; font-weight: bold; text-decoration: none; display: flex; align-items: center;">
                        <span style="display: inline-block; margin-right: 8px;">📤</span> Share Invoice
                        <span style="margin-left: 8px; font-size: 12px;">▼</span>
                    </button>
                    <div id="shareDropdown" style="display: none; position: absolute; top: 100%; left: 0; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; min-width: 200px; margin-top: 5px;">
                        <?php if (!empty($invoice['customer_email'])): ?>
                        <a href="javascript:void(0)" onclick="sendEmail(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['customer_email']); ?>')" 
                           style="display: block; padding: 12px 15px; color: #333; text-decoration: none; border-bottom: 1px solid #eee; transition: background 0.2s;"
                           onmouseover="this.style.background='#f8f9fa';" 
                           onmouseout="this.style.background='white';">
                            <span style="display: inline-block; margin-right: 10px; color: #3498db;">📧</span> Send Email
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_phone'])): ?>
                        <a href="javascript:void(0)" onclick="sendWhatsApp(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['customer_phone']); ?>')" 
                           style="display: block; padding: 12px 15px; color: #333; text-decoration: none; transition: background 0.2s;"
                           onmouseover="this.style.background='#f8f9fa';" 
                           onmouseout="this.style.background='white';">
                            <span style="display: inline-block; margin-right: 10px; color: #25D366;">📱</span> Send WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Delete Payment Modal -->
        <div id="deletePaymentModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%; max-height: 80vh; overflow-y: auto;">
                <h3 style="margin: 0 0 20px 0; color: #e74c3c;">Delete Payment</h3>
                <p style="margin-bottom: 20px; color: #666;">Select a payment to delete:</p>
                
                <div id="paymentList" style="max-height: 300px; overflow-y: auto; margin-bottom: 20px;">
                    <?php if (!empty($invoice['parsed_payments'])): ?>
                        <?php foreach ($invoice['parsed_payments'] as $payment): ?>
                        <div class="payment-item" style="padding: 12px; border: 1px solid #eee; border-radius: 4px; margin-bottom: 10px; cursor: pointer; transition: background 0.2s;"
                             onclick="selectPayment(this, <?php echo $payment['id']; ?>, '<?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($payment['amount'], 2); ?>', '<?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?>', '<?php echo htmlspecialchars($payment['payment_method']); ?>')"
                             onmouseover="this.style.background='#f8f9fa';" 
                             onmouseout="this.style.background='white';">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-weight: bold; color: #2c3e50;"><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></div>
                                    <div style="font-size: 13px; color: #666;"><?php echo htmlspecialchars($payment['payment_method']); ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: bold; color: #27ae60;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($payment['amount'], 2); ?></div>
                                    <?php if (!empty($payment['transaction_id'])): ?>
                                    <div style="font-size: 12px; color: #999;">ID: <?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div id="selectedPaymentInfo" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 4px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50;">Selected Payment:</h4>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="font-weight: bold;" id="selectedDate"></div>
                            <div style="font-size: 13px; color: #666;" id="selectedMethod"></div>
                        </div>
                        <div style="font-weight: bold; color: #e74c3c; font-size: 18px;" id="selectedAmount"></div>
                    </div>
                    <input type="hidden" id="selectedPaymentId" value="">
                </div>
                
                <form method="POST" id="deletePaymentForm" style="display: none;">
                    <input type="hidden" name="action" value="delete_payment">
                    <input type="hidden" name="payment_id" id="deletePaymentId">
                </form>
                
                <div class="action-buttons">
                    <button type="button" onclick="submitDeletePayment()" id="deleteButton" style="background: #e74c3c; padding: 12px 24px;" disabled>Delete Selected Payment</button>
                    <button type="button" onclick="closeDeletePaymentModal()" class="btn-secondary">Cancel</button>
                </div>
            </div>
        </div>

        <script>
        let selectedPaymentId = null;

        function showDeletePaymentModal() {
            document.getElementById('deletePaymentModal').style.display = 'flex';
            resetPaymentSelection();
        }

        function closeDeletePaymentModal() {
            document.getElementById('deletePaymentModal').style.display = 'none';
            resetPaymentSelection();
        }

        function selectPayment(element, paymentId, amount, date, method) {
            // Remove selection from all items
            document.querySelectorAll('.payment-item').forEach(item => {
                item.style.borderColor = '#eee';
                item.style.background = 'white';
            });
            
            // Highlight selected item
            element.style.borderColor = '#e74c3c';
            element.style.background = '#ffebee';
            
            // Update selected payment info
            selectedPaymentId = paymentId;
            document.getElementById('selectedDate').textContent = date;
            document.getElementById('selectedMethod').textContent = method;
            document.getElementById('selectedAmount').textContent = amount;
            document.getElementById('selectedPaymentId').value = paymentId;
            
            // Show info and enable delete button
            document.getElementById('selectedPaymentInfo').style.display = 'block';
            document.getElementById('deleteButton').disabled = false;
        }

        function resetPaymentSelection() {
            selectedPaymentId = null;
            document.querySelectorAll('.payment-item').forEach(item => {
                item.style.borderColor = '#eee';
                item.style.background = 'white';
            });
            document.getElementById('selectedPaymentInfo').style.display = 'none';
            document.getElementById('deleteButton').disabled = true;
            document.getElementById('selectedPaymentId').value = '';
        }

        function submitDeletePayment() {
            if (!selectedPaymentId) return;
            
            if (confirm('Are you sure you want to delete this payment?')) {
                document.getElementById('deletePaymentId').value = selectedPaymentId;
                document.getElementById('deletePaymentForm').submit();
            }
        }

        function toggleShareDropdown() {
            const dropdown = document.getElementById('shareDropdown');
            dropdown.style.display = dropdown.style.display === 'none' || dropdown.style.display === '' ? 'block' : 'none';
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('shareDropdown');
            const shareButton = document.querySelector('button[onclick="toggleShareDropdown()"]');
            
            if (dropdown && shareButton && 
                !dropdown.contains(event.target) && 
                !shareButton.contains(event.target)) {
                dropdown.style.display = 'none';
            }
        });
        </script>
    </div>
    
    <script>
        function fallbackQRCode() {
            console.log('Primary QR code failed, trying fallback...');
            document.getElementById('primaryQR').style.display = 'none';
            document.getElementById('fallbackQR').style.display = 'block';
            document.getElementById('qrStatus').innerHTML = 'Using alternative QR service';
            document.getElementById('qrStatus').style.color = '#e67e22';
        }
        
        function handleUPIClick(event, upiLink) {
            // Check if on mobile device
            if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                event.preventDefault();
                
                // Try to open UPI app directly
                window.location.href = upiLink;
                
                // Fallback after 500ms if app doesn't open
                setTimeout(function() {
                    if (document.hasFocus()) {
                        // App didn't open, offer to copy UPI ID
                        if (confirm('Would you like to copy UPI ID to clipboard?')) {
                            const upiId = upiLink.match(/pa=([^&]+)/)?.[1] || '';
                            if (upiId) {
                                navigator.clipboard.writeText(decodeURIComponent(upiId)).then(function() {
                                    alert('UPI ID copied to clipboard!');
                                });
                            }
                        }
                    }
                }, 500);
            }
            // On desktop, let the link open normally
        }
        
        function copyUPILink() {
            const upiLink = '<?php echo htmlspecialchars($upi_link); ?>';
            navigator.clipboard.writeText(upiLink).then(function() {
                alert('Payment link copied to clipboard!');
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const qrImage = document.getElementById('qrCodeImage');
            if (qrImage) {
                qrImage.onload = function() {
                    document.getElementById('qrStatus').style.color = '#27ae60';
                };
                
                qrImage.onerror = function() {
                    fallbackQRCode();
                };
                
                setTimeout(function() {
                    if (!qrImage.complete || qrImage.naturalWidth === 0) {
                        qrImage.onerror();
                    }
                }, 2000);
            }
        });
        
        function printInvoice() {
            window.print();
        }
        
        function confirmDeletePayment(paymentId, amount) {
            if (confirm('Are you sure you want to delete payment of ' + amount + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_payment';
                form.appendChild(actionInput);
                
                var paymentInput = document.createElement('input');
                paymentInput.type = 'hidden';
                paymentInput.name = 'payment_id';
                paymentInput.value = paymentId;
                form.appendChild(paymentInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function showPaymentModal() {
            const form = document.getElementById('addPaymentForm');
            if (form) {
                form.scrollIntoView({ behavior: 'smooth' });
            }
        }
    </script>
    <?php
}

function sendInvoiceEmailHandler() {
    global $db;
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        $stmt = $db->prepare("SELECT customer_email FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $invoice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $email = $invoice['customer_email'] ?? '';
    }
    
    if (empty($email)) {
        $_SESSION['error'] = "No email address provided and customer email not found!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email address format!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    $invoice = getInvoiceData($invoice_id);
    if (!$invoice) {
        $_SESSION['error'] = "Invoice not found!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    // Get email settings from database
    $company_name = getSetting('company_name', 'D K ASSOCIATES');
    $company_email = getSetting('company_email', 'care@hidk.in');
    $reply_to_email = getSetting('reply_to_email', 'care@hidk.in');
    $website = getSetting('company_website', 'https://hidk.in/');
    
    // Generate a share token for the invoice
$stmt = $db->prepare("SELECT share_token FROM invoice_shares WHERE invoice_id = :invoice_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
$stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
$existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if ($existing) {
    $share_token = $existing['share_token'];
} else {
    // Generate a share token for the invoice
    $share_token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("INSERT INTO invoice_shares (invoice_id, share_token, created_by, expires_at) VALUES (:invoice_id, :token, :user_id, datetime('now', '+30 days'))");
    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->execute();
}
    
    // Create a public URL that doesn't require login
    $invoice_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/share_invoice.php?token=' . $share_token;
    
    $subject = $_POST['subject'] ?? "Invoice {$invoice['invoice_number']} from {$company_name}";
    
    // Generate HTML content for the email
    ob_start();
    displayInvoiceHTML($invoice);
    $invoice_html = ob_get_clean();
    
    // Clean up the HTML for email - remove internal-only elements
$invoice_html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $invoice_html);
$invoice_html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $invoice_html);
$invoice_html = preg_replace('/<div class="payment-form[^>]*>.*?<\/form>\s*<\/div>/is', '', $invoice_html);
$invoice_html = preg_replace('/<div class="action-buttons no-print[^>]*>.*?<\/div>/is', '', $invoice_html);
$invoice_html = preg_replace('/<div class="status-form[^>]*>.*?<\/form>\s*<\/div>/is', '', $invoice_html);
$invoice_html = preg_replace('/class="no-email"/', '', $invoice_html);
// After other cleanup lines, add:
$invoice_html = preg_replace('/<button[^>]*onclick="toggleShareDropdown\(\)"[^>]*>.*?<\/button>/is', '', $invoice_html);
$invoice_html = preg_replace('/<div id="shareDropdown".*?<\/div>/is', '', $invoice_html);
$invoice_html = preg_replace('/<a[^>]*onclick="sendEmail[^>]*>.*?<\/a>/is', '', $invoice_html);
$invoice_html = preg_replace('/<a[^>]*onclick="sendWhatsApp[^>]*>.*?<\/a>/is', '', $invoice_html);

    $message = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Invoice {$invoice['invoice_number']}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .invoice-container { max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; background: #fff; }
        .header { border-bottom: 2px solid #3498db; padding-bottom: 15px; margin-bottom: 20px; }
        .footer { border-top: 2px solid #3498db; padding-top: 15px; margin-top: 20px; font-size: 12px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; }
        .total-row { font-weight: bold; background-color: #f8f9fa; }
        .qr-code { text-align: center; margin: 20px 0; }
    </style>
</head>
<body>
    <div class='invoice-container'>
        {$invoice_html}
        <div class='footer'>
            <p>This is an automated email from {$company_name}. Please do not reply to this email.</p>
            <p>If you have any questions, contact us at: {$company_email} or call " . getSetting('office_phone', '07662-455311, 9329578335') . "</p>
        </div>
    </div>
</body>
</html>
";
    
    $text_message = "Dear {$invoice['customer_name']},

Your invoice {$invoice['invoice_number']} dated " . date('d-m-Y', strtotime($invoice['invoice_date'])) . " is ready.

Invoice Amount: ₹" . number_format($invoice['totals']['rounded_total'], 2) . "
Paid Amount: ₹" . number_format($invoice['totals']['paid_amount'], 2) . "
Balance Due: ₹" . number_format($invoice['totals']['balance'], 2) . "

You can view your invoice online at:
{$invoice_url}

Payment can be made via UPI using the QR code attached or by clicking the payment link.

Payment Note: " . getSetting('payment_note') . "

Thank you for your business!

Best regards,
{$company_name}
" . getSetting('office_phone') . "
{$company_email}
{$website}
";
    
    $headers = "From: {$company_name} <{$company_email}>\r\n";
    $headers .= "Reply-To: {$reply_to_email}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    $sent = mail($email, $subject, $message, $headers);
    
    if ($sent) {
        $stmt = $db->prepare("INSERT INTO sent_invoices (invoice_id, sent_via, sent_to, sent_by) VALUES (:invoice_id, 'email', :sent_to, :sent_by)");
        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
        $stmt->bindValue(':sent_to', $email, SQLITE3_TEXT);
        $stmt->bindValue(':sent_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        logAction('SEND_EMAIL', "Sent invoice {$invoice['invoice_number']} to {$email}");
        $_SESSION['success'] = "Invoice sent successfully to {$email}!";
    } else {
        $_SESSION['error'] = "Failed to send email. Please check your server mail configuration.";
    }
    
    header('Location: ?page=view_invoice&id=' . $invoice_id);
    exit();
}

function sendInvoiceWhatsAppHandler() {
    global $db;
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $phone = $_POST['phone'] ?? '';
    
    if (empty($phone)) {
        $stmt = $db->prepare("SELECT customer_phone FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $invoice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $phone = $invoice['customer_phone'] ?? '';
    }
    
    if (empty($phone)) {
        $_SESSION['error'] = "No phone number provided and customer phone not found!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $invoice = getInvoiceData($invoice_id);
    
    // Check if share token already exists for this invoice
    $stmt = $db->prepare("SELECT share_token FROM invoice_shares WHERE invoice_id = :invoice_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        $share_token = $existing['share_token'];
    } else {
        // Generate new share token if none exists
        $share_token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO invoice_shares (invoice_id, share_token, created_by, expires_at) VALUES (:invoice_id, :token, :user_id, datetime('now', '+30 days'))");
        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
        $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
    }
    
    // Create a public URL that doesn't require login
    $base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/';
    $invoice_url = $base_url . 'share_invoice.php?token=' . $share_token;
    
    $template = getSetting('whatsapp_message', 'Dear customer, your invoice {invoice_number} for {total_amount} is ready. Balance Due: {balance}. View at: {invoice_url}');
    
    $total_amount = isset($invoice['totals']['rounded_total']) ? $invoice['totals']['rounded_total'] : 0;
    $balance_due = isset($invoice['totals']['balance']) ? $invoice['totals']['balance'] : 0;
    
    $message = str_replace(
        ['{invoice_number}', '{total_amount}', '{balance}', '{invoice_url}', '{customer_name}', '{date}', '{paid_amount}'],
        [
            $invoice['invoice_number'],
            '₹' . number_format($total_amount, 2),
            '₹' . number_format($balance_due, 2),
            $invoice_url,
            $invoice['customer_name'],
            date('d-m-Y', strtotime($invoice['invoice_date'])),
            '₹' . number_format($invoice['totals']['paid_amount'], 2)
        ],
        $template
    );
    
    // Fix: Properly encode the message and ensure URL is valid
    $encoded_message = urlencode($message);
    // Remove any problematic characters that might break the URL
    $encoded_message = str_replace(['%0D', '%0A'], '%0A', $encoded_message);
    
    $whatsapp_url = "https://wa.me/{$phone}?text=" . $encoded_message;
    
    $_SESSION['whatsapp_url'] = $whatsapp_url;
    $_SESSION['success'] = "WhatsApp message prepared. You'll be redirected to WhatsApp...";
    
    $stmt = $db->prepare("INSERT INTO sent_invoices (invoice_id, sent_via, sent_to, sent_by) VALUES (:invoice_id, 'whatsapp', :sent_to, :sent_by)");
    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':sent_to', $phone, SQLITE3_TEXT);
    $stmt->bindValue(':sent_by', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('SEND_WHATSAPP', "Sent invoice {$invoice_id} to {$phone}");
    
    header('Location: ?page=view_invoice&id=' . $invoice_id . '&whatsapp=1');
    exit();
}

function sendBookingEmailHandler() {
    global $db;
    
    $booking_id = $_POST['booking_id'] ?? 0;
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        $stmt = $db->prepare("SELECT customer_email FROM bookings WHERE id = :id");
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $email = $booking['customer_email'] ?? '';
    }
    
    if (empty($email)) {
        $_SESSION['error'] = "No email address provided!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Invalid email address format!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    $booking = getBookingData($booking_id);
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    // Get share token
    $stmt = $db->prepare("SELECT share_token FROM booking_shares WHERE booking_id = :booking_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        $share_token = $existing['share_token'];
    } else {
        $share_token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO booking_shares (booking_id, share_token, created_by, expires_at) VALUES (:booking_id, :token, :user_id, datetime('now', '+30 days'))");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
    }
    
    $booking_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/booking_receipt.php?token=' . $share_token;
    
    $company_name = getSetting('company_name', 'D K ASSOCIATES');
    $company_email = getSetting('company_email', 'care@hidk.in');
    
    $subject = "Booking Receipt: {$booking['booking_number']} from {$company_name}";
    
    $message = "
    <html>
    <head>
        <title>Booking Receipt</title>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; }
            .header { background: #f8f9fa; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>{$company_name}</h2>
                <h3>Booking Receipt: {$booking['booking_number']}</h3>
            </div>
            <div class='content'>
                <p>Dear {$booking['customer_name']},</p>
                <p>Your service booking has been confirmed.</p>
                <p><strong>Booking Details:</strong><br>
                Booking #: {$booking['booking_number']}<br>
                Date: " . date('d-m-Y', strtotime($booking['booking_date'])) . "<br>
                Service: {$booking['service_description']}<br>
                Estimated Cost: ₹" . number_format($booking['total_estimated_cost'], 2) . "<br>
                Advance Paid: ₹" . number_format($booking['advance_fees'], 2) . "<br>
                Balance Due: ₹" . number_format($booking['totals']['balance'], 2) . "</p>
                <p>View your booking online: <a href='{$booking_url}'>Click here</a></p>
            </div>
            <div class='footer'>
                <p>{$company_name}<br>
                " . getSetting('office_phone', '') . "<br>
                {$company_email}</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $headers = "From: {$company_name} <{$company_email}>\r\n";
    $headers .= "Reply-To: " . getSetting('reply_to_email', $company_email) . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $sent = mail($email, $subject, $message, $headers);
    
    if ($sent) {
        logAction('SEND_BOOKING_EMAIL', "Sent booking {$booking['booking_number']} to {$email}");
        $_SESSION['success'] = "Booking receipt sent successfully to {$email}!";
    } else {
        $_SESSION['error'] = "Failed to send email. Please check your server mail configuration.";
    }
    
    header('Location: ?page=view_booking&id=' . $booking_id);
    exit();
}

function sendBookingWhatsAppHandler() {
    global $db;
    
    $booking_id = $_POST['booking_id'] ?? 0;
    $phone = $_POST['phone'] ?? '';
    
    if (empty($phone)) {
        $stmt = $db->prepare("SELECT customer_phone FROM bookings WHERE id = :id");
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $phone = $booking['customer_phone'] ?? '';
    }
    
    if (empty($phone)) {
        $_SESSION['error'] = "No phone number provided!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $booking = getBookingData($booking_id);
    
    // Get share token
    $stmt = $db->prepare("SELECT share_token FROM booking_shares WHERE booking_id = :booking_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        $share_token = $existing['share_token'];
    } else {
        $share_token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT INTO booking_shares (booking_id, share_token, created_by, expires_at) VALUES (:booking_id, :token, :user_id, datetime('now', '+30 days'))");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
    }
    
    $booking_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/booking_receipt.php?token=' . $share_token;
    
    $message = "Dear {$booking['customer_name']}, your booking {$booking['booking_number']} is confirmed. Estimated Cost: ₹" . number_format($booking['total_estimated_cost'], 2) . ", Advance Paid: ₹" . number_format($booking['advance_fees'], 2) . ", Balance: ₹" . number_format($booking['totals']['balance'], 2) . ". View details: {$booking_url}";
    
    $encoded_message = urlencode($message);
    $whatsapp_url = "https://wa.me/{$phone}?text=" . $encoded_message;
    
    $_SESSION['whatsapp_url'] = $whatsapp_url;
    $_SESSION['success'] = "WhatsApp message prepared. You'll be redirected to WhatsApp...";
    
    logAction('SEND_BOOKING_WHATSAPP', "Sent booking {$booking_id} to {$phone}");
    
    header('Location: ?page=view_booking&id=' . $booking_id . '&whatsapp=1');
    exit();
}

if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($result && password_verify($password, $result['password'])) {
        $_SESSION['user_id']          = $result['id'];
        $_SESSION['username']         = $result['username'];
        $_SESSION['email']            = $result['email'];
        $_SESSION['role']             = $result['role'];
        $_SESSION['full_name']        = $result['full_name'] ?? 'NA';
        $_SESSION['designation']      = $result['designation'] ?? 'NA';
        $_SESSION['has_nongst_access']  = $result['has_nongst_access'] ?? 0;
        $_SESSION['has_academy_access'] = $result['has_academy_access'] ?? 0;
        $_SESSION['success'] = "Login successful!";
        
        logAction('LOGIN', "User logged in: {$username}");
        
        header('Location: ?page=dashboard');
        exit();
    } else {
        $_SESSION['error'] = "Invalid username or password!";
        header('Location: ?page=login');
        exit();
    }
}

if (isset($_GET['logout'])) {
    if (isset($_SESSION['username'])) {
        logAction('LOGOUT', "User logged out: {$_SESSION['username']}");
    }
    session_destroy();
    header('Location: ?page=login');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_invoice': createInvoice(); break;
            case 'update_invoice': updateInvoice(); break;
            case 'delete_invoice': deleteInvoice(); break;
            case 'request_delete_invoice': requestDeleteInvoice(); break;
            case 'approve_delete_request': approveDeleteRequest(); break;
            case 'reject_delete_request': rejectDeleteRequest(); break;
            case 'create_booking': createBooking(); break;
            case 'update_booking': updateBooking(); break;
            case 'delete_booking': deleteBooking(); break;
            case 'send_booking_email': sendBookingEmailHandler(); break;
            case 'send_booking_whatsapp': sendBookingWhatsAppHandler(); break;
            case 'update_booking_status': updateBookingStatus(); break;
            case 'add_booking_payment': addBookingPaymentHandler(); break;
            case 'convert_booking': convertBookingHandler(); break;
            case 'save_settings': saveSettings(); break;
            case 'upload_logo': uploadLogo(); break;
            case 'upload_qr': uploadQR(); break;
            case 'upload_qr_nongst': uploadQRNonGST(); break;
            case 'change_password': changePassword(); break;
            case 'export_invoices': exportInvoices(); break;
            case 'export_table_csv': exportTableCSV(); break;
            case 'import_table_csv': importTableCSV(); break;
            case 'export_invoices_pdf': exportInvoicesPDF(); break;
            case 'create_user': createUser(); break;
            case 'update_user': updateUser(); break;
            case 'delete_user': deleteUser(); break;
            case 'update_nongst_access': updateNonGSTAccess(); break;
            case 'update_payment_status': updatePaymentStatus(); break;
            case 'add_payment': addPayment(); break;
            case 'delete_payment': deletePayment(); break;
            case 'send_email': sendInvoiceEmailHandler(); break;
            case 'send_whatsapp': sendInvoiceWhatsAppHandler(); break;
            case 'send_booking_email': sendBookingEmailHandler(); break;
            case 'send_booking_whatsapp': sendBookingWhatsAppHandler(); break;
            case 'save_template': saveTemplate(); break;
            case 'set_default_template': setDefaultTemplate(); break;
            case 'delete_template': deleteTemplate(); break;
            case 'add_staff_expense': addStaffExpense(); break;
            case 'update_staff_expense': updateStaffExpense(); break;
            case 'delete_staff_expense': deleteStaffExpense(); break;
            case 'approve_expense': approveExpense(); break;
            case 'add_purchase_record': addPurchaseRecord(); break;
            case 'upload_seal': uploadSeal(); break;
            case 'upload_user_signature': uploadUserSignature(); break;
            case 'set_sign_passcode': setSignPasscode(); break;
            case 'affix_seal_signature': affixSealSignature(); break;
            // Academy actions
            case 'save_academy_course': saveAcademyCourse(); break;
            case 'delete_academy_course': deleteAcademyCourse(); break;
            case 'create_enrollment': createEnrollment(); break;
            case 'update_enrollment': updateEnrollment(); break;
            case 'add_academy_payment': addAcademyPayment(); break;
            case 'delete_academy_payment': deleteAcademyPayment(); break;
            case 'generate_installment_schedule': generateInstallmentSchedule(); break;
            case 'update_academy_access': updateAcademyAccess(); break;
            case 'generate_link': generatePaymentLinkHandler(); break;
            // Seal request actions
            case 'request_seal': requestSeal(); break;
            // Yatra actions
            case 'save_yatra': saveYatra(); break;
            case 'delete_yatra': deleteYatra(); break;
            case 'archive_yatra': archiveYatra(); break;
            case 'unarchive_yatra': unarchiveYatra(); break;
            case 'create_yatra_booking': createYatraBooking(); break;
            case 'update_yatra_booking': updateYatraBooking(); break;
            case 'add_yatra_payment': addYatraPayment(); break;
            case 'delete_yatra_payment': deleteYatraPayment(); break;
            case 'cancel_yatra_booking': cancelYatraBooking(); break;
            case 'approve_seal_request': approveSealRequest(); break;
            case 'reject_seal_request': rejectSealRequest(); break;
        }
    }
}



function createInvoice() {
    global $db;
    
    // Role check: must be at least accountant
    $role = getUserRole();
    if (!in_array($role, ['admin', 'manager', 'accountant'])) {
        $_SESSION['error'] = "You do not have permission to create invoices.";
        header('Location: ?page=dashboard');
        exit();
    }
    
    // GST Detection: route to correct DB
    $gst_amount = floatval($_POST['gst_amount'] ?? 0);
    $gst_rate = floatval($_POST['gst_rate'] ?? 0);
    $gst_inclusive = isset($_POST['gst_inclusive']) && $_POST['gst_inclusive'] === '1';
    $isGST = ($gst_amount > 0 || $gst_rate > 0 || $gst_inclusive);
    
    $targetDb = $isGST ? $db : getNonGSTDb();
    if ($targetDb === null) {
        $_SESSION['error'] = "Could not connect to Non-GST database.";
        header('Location: ?page=create_invoice');
        exit();
    }

    // Use separate serial sequences: GST gets letter-month hex3, NonGST gets number-month hex4
    $invoice_number = $isGST ? generateInvoiceNumber() : generateNonGSTInvoiceNumber();
    
    $targetDb->exec('BEGIN TRANSACTION');
    
    try {
        $stmt = $targetDb->prepare("
            INSERT INTO invoices (invoice_number, customer_name, customer_phone, customer_email, customer_address, invoice_date, customer_gst_number, gst_amount, gst_rate, gst_inclusive, created_by) 
            VALUES (:invoice_number, :name, :phone, :email, :address, :date, :cust_gst, :gst_amount, :gst_rate, :gst_inclusive, :created_by)
        ");
        $stmt->bindValue(':invoice_number', $invoice_number, SQLITE3_TEXT);
        $stmt->bindValue(':name', $_POST['customer_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $_POST['customer_phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $_POST['customer_email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $_POST['customer_address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':date', $_POST['invoice_date'] ?? date('Y-m-d'), SQLITE3_TEXT);
        $custGST = trim($_POST['customer_gst_number'] ?? 'NA');
        $stmt->bindValue(':cust_gst', ($isGST && !empty($custGST)) ? $custGST : 'NA', SQLITE3_TEXT);
        $stmt->bindValue(':gst_amount', $gst_amount, SQLITE3_FLOAT);
        $stmt->bindValue(':gst_rate', $gst_rate, SQLITE3_FLOAT);
        $stmt->bindValue(':gst_inclusive', $gst_inclusive ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        $invoice_id = $targetDb->lastInsertRowID();
        
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $item_count = 0;
            foreach ($_POST['items'] as $index => $item) {
                if (!empty($item['particulars'])) {
                    $stmt = $targetDb->prepare("
                        INSERT INTO invoice_items (invoice_id, s_no, particulars, amount, service_charge, discount, remark) 
                        VALUES (:invoice_id, :s_no, :particulars, :amount, :service_charge, :discount, :remark)
                    ");
                    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':s_no', ++$item_count, SQLITE3_INTEGER);
                    $stmt->bindValue(':particulars', trim($item['particulars']), SQLITE3_TEXT);
                    $stmt->bindValue(':amount', floatval($item['amount'] ?? 0), SQLITE3_FLOAT);
                    $stmt->bindValue(':service_charge', floatval($item['service_charge'] ?? 0), SQLITE3_FLOAT);
                    $stmt->bindValue(':discount', floatval($item['discount'] ?? 0), SQLITE3_FLOAT);
                    $stmt->bindValue(':remark', trim($item['remark'] ?? ''), SQLITE3_TEXT);
                    $stmt->execute();
                }
            }
        }
        
        if (isset($_POST['purchases']) && is_array($_POST['purchases'])) {
            $purchase_count = 0;
            foreach ($_POST['purchases'] as $index => $purchase) {
                if (!empty($purchase['particulars'])) {
                    $qty = floatval($purchase['qty'] ?? 1);
                    $rate = floatval($purchase['rate'] ?? 0);
                    $purchase_amount = $qty * $rate;
                    
                    $stmt = $targetDb->prepare("
                        INSERT INTO purchases (invoice_id, s_no, particulars, qty, rate, purchase_amount, amount_received) 
                        VALUES (:invoice_id, :s_no, :particulars, :qty, :rate, :purchase_amount, :amount_received)
                    ");
                    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':s_no', ++$purchase_count, SQLITE3_INTEGER);
                    $stmt->bindValue(':particulars', trim($purchase['particulars']), SQLITE3_TEXT);
                    $stmt->bindValue(':qty', $qty, SQLITE3_FLOAT);
                    $stmt->bindValue(':rate', $rate, SQLITE3_FLOAT);
                    $stmt->bindValue(':purchase_amount', $purchase_amount, SQLITE3_FLOAT);
                    $stmt->bindValue(':amount_received', floatval($purchase['amount_received'] ?? 0), SQLITE3_FLOAT);
                    $stmt->execute();
                }
            }
        }
        
        $targetDb->exec('COMMIT');
        
        $dbLabel = $isGST ? 'GST' : 'Non-GST';
        logAction('CREATE_INVOICE', "Created {$dbLabel} invoice: {$invoice_number}");
        
        $_SESSION['success'] = "Invoice created successfully! Invoice Number: $invoice_number" . ($isGST ? '' : ' (Non-GST)');
        $_SESSION['last_invoice_id'] = $invoice_id;
        header('Location: ?page=create_invoice');
        exit();
        
    } catch (Exception $e) {
        $targetDb->exec('ROLLBACK');
        $_SESSION['error'] = "Error creating invoice: " . $e->getMessage();
        header('Location: ?page=create_invoice');
        exit();
    }
}

function updateInvoice() {
    global $db;
    
    $invoice_id = intval($_POST['invoice_id'] ?? 0);

    // Check if invoice is locked (signed)
    $lockStmt = $db->prepare("SELECT locked FROM invoice_signatures WHERE invoice_id=:id AND db_source='gst'");
    $lockStmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $lockRow = $lockStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($lockRow && $lockRow['locked']) {
        $_SESSION['error'] = "This invoice is signed and locked. Editing is not permitted.";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    if (!isAdmin()) {
        $stmt = $db->prepare("SELECT created_by FROM invoices WHERE id = :id");
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $invoice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$invoice || $invoice['created_by'] != $_SESSION['user_id']) {
            $_SESSION['error'] = "You don't have permission to edit this invoice!";
            header('Location: ?page=invoices');
            exit();
        }
    }
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $custGST = trim($_POST['customer_gst_number'] ?? 'NA');
        if (empty($custGST)) $custGST = 'NA';

        // Read GST fields from POST (edit form passes them as hidden/visible inputs)
        $upd_gst_amount    = floatval($_POST['gst_amount'] ?? 0);
        $upd_gst_rate      = floatval($_POST['gst_rate'] ?? 0);
        $upd_gst_inclusive = isset($_POST['gst_inclusive']) ? 1 : 0;

        $stmt = $db->prepare("
            UPDATE invoices SET 
            customer_name = :name,
            customer_phone = :phone,
            customer_email = :email,
            customer_address = :address,
            customer_gst_number = :cust_gst,
            gst_amount = :gst_amount,
            gst_rate = :gst_rate,
            gst_inclusive = :gst_inclusive,
            invoice_date = :date,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':name', $_POST['customer_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $_POST['customer_phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $_POST['customer_email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $_POST['customer_address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':cust_gst', $custGST, SQLITE3_TEXT);
        $stmt->bindValue(':gst_amount', $upd_gst_amount, SQLITE3_FLOAT);
        $stmt->bindValue(':gst_rate', $upd_gst_rate, SQLITE3_FLOAT);
        $stmt->bindValue(':gst_inclusive', $upd_gst_inclusive, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $_POST['invoice_date'] ?? date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        // ---------------------------------------------------------------
        // SAFE ITEM HANDLING:
        // - Rows with _delete=1 are explicitly removed
        // - Existing rows (have an id) are updated
        // - New rows (no id or id=0) are inserted
        // - Rows absent from POST but with no _delete flag are LEFT ALONE
        // ---------------------------------------------------------------
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $item_count = 0;
            // First pass: collect the current max s_no so we can assign new ones sequentially
            $kept_ids = [];
            foreach ($_POST['items'] as $item) {
                if (!empty($item['particulars'])) {
                    $item_id = isset($item['id']) ? intval($item['id']) : 0;
                    if ($item_id > 0) $kept_ids[] = $item_id;
                }
            }

            foreach ($_POST['items'] as $index => $item) {
                // Explicit delete flag — remove this row
                if (!empty($item['_delete'])) {
                    $item_id = intval($item['id'] ?? 0);
                    if ($item_id > 0) {
                        $stmt = $db->prepare("DELETE FROM invoice_items WHERE id = :id AND invoice_id = :invoice_id");
                        $stmt->bindValue(':id', $item_id, SQLITE3_INTEGER);
                        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                        $stmt->execute();
                    }
                    continue;
                }

                if (empty($item['particulars'])) continue;

                $item_id = isset($item['id']) ? intval($item['id']) : 0;
                ++$item_count;

                if ($item_id > 0) {
                    // Update existing row — only if it actually belongs to this invoice
                    $stmt = $db->prepare("
                        UPDATE invoice_items SET 
                        s_no = :s_no,
                        particulars = :particulars,
                        amount = :amount,
                        service_charge = :service_charge,
                        discount = :discount,
                        remark = :remark
                        WHERE id = :id AND invoice_id = :invoice_id
                    ");
                    $stmt->bindValue(':id', $item_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                } else {
                    // New row
                    $stmt = $db->prepare("
                        INSERT INTO invoice_items (invoice_id, s_no, particulars, amount, service_charge, discount, remark) 
                        VALUES (:invoice_id, :s_no, :particulars, :amount, :service_charge, :discount, :remark)
                    ");
                }
                $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                $stmt->bindValue(':s_no', $item_count, SQLITE3_INTEGER);
                $stmt->bindValue(':particulars', trim($item['particulars']), SQLITE3_TEXT);
                $stmt->bindValue(':amount', floatval($item['amount'] ?? 0), SQLITE3_FLOAT);
                $stmt->bindValue(':service_charge', floatval($item['service_charge'] ?? 0), SQLITE3_FLOAT);
                $stmt->bindValue(':discount', floatval($item['discount'] ?? 0), SQLITE3_FLOAT);
                $stmt->bindValue(':remark', trim($item['remark'] ?? ''), SQLITE3_TEXT);
                $stmt->execute();
            }
        }
        
        // SAFE PURCHASE HANDLING — same pattern
        if (isset($_POST['purchases']) && is_array($_POST['purchases'])) {
            $purchase_count = 0;
            foreach ($_POST['purchases'] as $index => $purchase) {
                // Explicit delete flag
                if (!empty($purchase['_delete'])) {
                    $purchase_id = intval($purchase['id'] ?? 0);
                    if ($purchase_id > 0) {
                        $stmt = $db->prepare("DELETE FROM purchases WHERE id = :id AND invoice_id = :invoice_id");
                        $stmt->bindValue(':id', $purchase_id, SQLITE3_INTEGER);
                        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                        $stmt->execute();
                    }
                    continue;
                }

                if (empty($purchase['particulars'])) continue;

                $purchase_id = isset($purchase['id']) ? intval($purchase['id']) : 0;
                $qty = floatval($purchase['qty'] ?? 1);
                $rate = floatval($purchase['rate'] ?? 0);
                $purchase_amount = $qty * $rate;
                ++$purchase_count;

                if ($purchase_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE purchases SET 
                        s_no = :s_no,
                        particulars = :particulars,
                        qty = :qty,
                        rate = :rate,
                        purchase_amount = :purchase_amount,
                        amount_received = :amount_received
                        WHERE id = :id AND invoice_id = :invoice_id
                    ");
                    $stmt->bindValue(':id', $purchase_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO purchases (invoice_id, s_no, particulars, qty, rate, purchase_amount, amount_received) 
                        VALUES (:invoice_id, :s_no, :particulars, :qty, :rate, :purchase_amount, :amount_received)
                    ");
                }
                $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
                $stmt->bindValue(':s_no', $purchase_count, SQLITE3_INTEGER);
                $stmt->bindValue(':particulars', trim($purchase['particulars']), SQLITE3_TEXT);
                $stmt->bindValue(':qty', $qty, SQLITE3_FLOAT);
                $stmt->bindValue(':rate', $rate, SQLITE3_FLOAT);
                $stmt->bindValue(':purchase_amount', $purchase_amount, SQLITE3_FLOAT);
                $stmt->bindValue(':amount_received', floatval($purchase['amount_received'] ?? 0), SQLITE3_FLOAT);
                $stmt->execute();
            }
        }
        
        $db->exec('COMMIT');
        
        logAction('UPDATE_INVOICE', "Updated invoice ID: {$invoice_id}");
        
        $_SESSION['success'] = "Invoice updated successfully!";
        header('Location: ?page=edit_invoice&id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error updating invoice: " . $e->getMessage();
        header('Location: ?page=edit_invoice&id=' . $invoice_id);
        exit();
    }
}

function deleteInvoice() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can delete invoices directly!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $db->exec("DELETE FROM invoice_items WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM purchases WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM payments WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM invoices WHERE id = $invoice_id");
        
        $db->exec('COMMIT');
        
        logAction('DELETE_INVOICE', "Admin directly deleted invoice ID: {$invoice_id}");
        
        $_SESSION['success'] = "Invoice deleted successfully!";
        header('Location: ?page=invoices');
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error deleting invoice: " . $e->getMessage();
        header('Location: ?page=invoices');
        exit();
    }
}

function requestDeleteInvoice() {
    global $db;
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';
    
    $stmt = $db->prepare("SELECT invoice_number, created_by FROM invoices WHERE id = :id");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $invoice = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$invoice) {
        $_SESSION['error'] = "Invoice not found!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $stmt = $db->prepare("SELECT id FROM delete_requests WHERE invoice_id = :id AND status = 'pending'");
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($existing) {
        $_SESSION['error'] = "Delete request already pending for this invoice!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $stmt = $db->prepare("
        INSERT INTO delete_requests (invoice_id, invoice_number, requested_by, reason) 
        VALUES (:invoice_id, :invoice_number, :requested_by, :reason)
    ");
    $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':invoice_number', $invoice['invoice_number'], SQLITE3_TEXT);
    $stmt->bindValue(':requested_by', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->execute();
    
    logAction('REQUEST_DELETE_INVOICE', "Requested deletion of invoice: {$invoice['invoice_number']}");
    
    $_SESSION['success'] = "Delete request submitted for admin approval!";
    header('Location: ?page=invoices');
    exit();
}

function approveDeleteRequest() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can approve delete requests!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $request_id = $_POST['request_id'] ?? 0;
    
    $stmt = $db->prepare("SELECT * FROM delete_requests WHERE id = :id AND status = 'pending'");
    $stmt->bindValue(':id', $request_id, SQLITE3_INTEGER);
    $request = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$request) {
        $_SESSION['error'] = "Delete request not found or already processed!";
        header('Location: ?page=pending_deletions');
        exit();
    }
    
    $invoice_id = $request['invoice_id'];
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $db->exec("DELETE FROM invoice_items WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM purchases WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM payments WHERE invoice_id = $invoice_id");
        $db->exec("DELETE FROM invoices WHERE id = $invoice_id");
        
        $stmt = $db->prepare("
            UPDATE delete_requests SET 
            status = 'approved',
            approved_at = CURRENT_TIMESTAMP,
            approved_by = :approved_by
            WHERE id = :id
        ");
        $stmt->bindValue(':approved_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':id', $request_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('APPROVE_DELETE', "Approved deletion of invoice ID: {$invoice_id}");
        
        $_SESSION['success'] = "Invoice deleted successfully!";
        header('Location: ?page=pending_deletions');
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error deleting invoice: " . $e->getMessage();
        header('Location: ?page=pending_deletions');
        exit();
    }
}

function rejectDeleteRequest() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can reject delete requests!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $request_id = $_POST['request_id'] ?? 0;
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    $stmt = $db->prepare("
        UPDATE delete_requests SET 
        status = 'rejected',
        reason = CONCAT(reason, ' [Rejected: ', :rejection_reason, ']'),
        approved_at = CURRENT_TIMESTAMP,
        approved_by = :approved_by
        WHERE id = :id AND status = 'pending'
    ");
    $stmt->bindValue(':rejection_reason', $rejection_reason, SQLITE3_TEXT);
    $stmt->bindValue(':approved_by', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':id', $request_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('REJECT_DELETE', "Rejected delete request ID: {$request_id}");
    
    $_SESSION['success'] = "Delete request rejected!";
    header('Location: ?page=pending_deletions');
    exit();
}


function createBooking() {
    global $db;
    
    $booking_number = generateBookingNumber();
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $advance_fees = floatval($_POST['advance_fees'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? '';
        $transaction_id = $_POST['transaction_id'] ?? '';
        
        $stmt = $db->prepare("
            INSERT INTO bookings (
                booking_number, customer_name, customer_phone, customer_email,
                customer_address, service_description, booking_date,
                expected_completion_date, advance_fees, total_estimated_cost,
                payment_method, transaction_id, notes, created_by
            ) VALUES (
                :booking_number, :name, :phone, :email, :address,
                :service_desc, :booking_date, :expected_date, :advance_fees,
                :total_cost, :payment_method, :txn_id, :notes, :created_by
            )
        ");
        
        $stmt->bindValue(':booking_number', $booking_number, SQLITE3_TEXT);
        $stmt->bindValue(':name', $_POST['customer_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $_POST['customer_phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $_POST['customer_email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $_POST['customer_address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':service_desc', $_POST['service_description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':booking_date', $_POST['booking_date'] ?? date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':expected_date', $_POST['expected_completion_date'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':advance_fees', $advance_fees, SQLITE3_FLOAT);
        $stmt->bindValue(':total_cost', floatval($_POST['total_estimated_cost'] ?? 0), SQLITE3_FLOAT);
        $stmt->bindValue(':payment_method', $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
        $stmt->bindValue(':notes', $_POST['notes'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        $booking_id = $db->lastInsertRowID();
        
        // Add booking items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $item_count = 0;
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description'])) {
                    $stmt = $db->prepare("
                        INSERT INTO booking_items (booking_id, s_no, description, estimated_amount)
                        VALUES (:booking_id, :s_no, :description, :estimated_amount)
                    ");
                    $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':s_no', ++$item_count, SQLITE3_INTEGER);
                    $stmt->bindValue(':description', trim($item['description']), SQLITE3_TEXT);
                    $stmt->bindValue(':estimated_amount', floatval($item['estimated_amount'] ?? 0), SQLITE3_FLOAT);
                    $stmt->execute();
                }
            }
        }
        
        // Add advance payment if any
        if ($advance_fees > 0 && !empty($payment_method)) {
            $stmt = $db->prepare("
                INSERT INTO booking_payments (
                    booking_id, payment_date, amount, payment_method,
                    transaction_id, notes, is_advance, created_by
                ) VALUES (
                    :booking_id, :date, :amount, :method, :txn_id, :notes, 1, :created_by
                )
            ");
            $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
            $stmt->bindValue(':date', $_POST['booking_date'] ?? date('Y-m-d'), SQLITE3_TEXT);
            $stmt->bindValue(':amount', $advance_fees, SQLITE3_FLOAT);
            $stmt->bindValue(':method', $payment_method, SQLITE3_TEXT);
            $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
            $stmt->bindValue(':notes', 'Advance payment', SQLITE3_TEXT);
            $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->execute();
            
            $payment_status = $advance_fees >= $_POST['total_estimated_cost'] ? 'paid' : 'partial';
            $stmt = $db->prepare("UPDATE bookings SET payment_status = :status WHERE id = :id");
            $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
            $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
            $stmt->execute();
        }
        
        $db->exec('COMMIT');
        
        logAction('CREATE_BOOKING', "Created booking: {$booking_number}");
        
        $_SESSION['success'] = "Booking created successfully! Booking #: {$booking_number}";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error creating booking: " . $e->getMessage();
        header('Location: ?page=create_booking');
        exit();
    }
}

function updateBooking() {
    global $db;
    
    $booking_id = $_POST['booking_id'] ?? 0;
    
    $booking = getBookingData($booking_id);
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $canEdit = isAdmin() || $booking['created_by'] == $_SESSION['user_id'];
    if (!$canEdit) {
        $_SESSION['error'] = "You don't have permission to edit this booking!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $stmt = $db->prepare("
            UPDATE bookings SET
            customer_name = :name,
            customer_phone = :phone,
            customer_email = :email,
            customer_address = :address,
            service_description = :service_desc,
            booking_date = :booking_date,
            expected_completion_date = :expected_date,
            total_estimated_cost = :total_cost,
            notes = :notes,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        
        $stmt->bindValue(':name', $_POST['customer_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $_POST['customer_phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $_POST['customer_email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $_POST['customer_address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':service_desc', $_POST['service_description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':booking_date', $_POST['booking_date'] ?? date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':expected_date', $_POST['expected_completion_date'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':total_cost', floatval($_POST['total_estimated_cost'] ?? 0), SQLITE3_FLOAT);
        $stmt->bindValue(':notes', $_POST['notes'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Handle items - delete existing and re-add
        $db->exec("DELETE FROM booking_items WHERE booking_id = $booking_id");
        
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $item_count = 0;
            foreach ($_POST['items'] as $item) {
                if (!empty($item['description'])) {
                    $stmt = $db->prepare("
                        INSERT INTO booking_items (booking_id, s_no, description, estimated_amount)
                        VALUES (:booking_id, :s_no, :description, :estimated_amount)
                    ");
                    $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':s_no', ++$item_count, SQLITE3_INTEGER);
                    $stmt->bindValue(':description', trim($item['description']), SQLITE3_TEXT);
                    $stmt->bindValue(':estimated_amount', floatval($item['estimated_amount'] ?? 0), SQLITE3_FLOAT);
                    $stmt->execute();
                }
            }
        }
        
        $db->exec('COMMIT');
        
        logAction('UPDATE_BOOKING', "Updated booking ID: {$booking_id}");
        
        $_SESSION['success'] = "Booking updated successfully!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error updating booking: " . $e->getMessage();
        header('Location: ?page=edit_booking&id=' . $booking_id);
        exit();
    }
}

function deleteBooking() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can delete bookings!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $booking_id = $_POST['booking_id'] ?? 0;
    
    $stmt = $db->prepare("SELECT booking_number FROM bookings WHERE id = :id");
    $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
    $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        // Delete related records
        $db->exec("DELETE FROM booking_items WHERE booking_id = $booking_id");
        $db->exec("DELETE FROM booking_payments WHERE booking_id = $booking_id");
        $db->exec("DELETE FROM booking_shares WHERE booking_id = $booking_id");
        $db->exec("DELETE FROM bookings WHERE id = $booking_id");
        
        $db->exec('COMMIT');
        
        logAction('DELETE_BOOKING', "Deleted booking: {$booking['booking_number']}");
        
        $_SESSION['success'] = "Booking deleted successfully!";
        header('Location: ?page=bookings');
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error deleting booking: " . $e->getMessage();
        header('Location: ?page=bookings');
        exit();
    }
}

function updateBookingStatus() {
    global $db;
    
    $booking_id = $_POST['booking_id'] ?? 0;
    $status = $_POST['status'] ?? '';
    
    $booking = getBookingData($booking_id);
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
    if (!in_array($status, $valid_statuses)) {
        $_SESSION['error'] = "Invalid status!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    $stmt = $db->prepare("UPDATE bookings SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('UPDATE_BOOKING_STATUS', "Updated booking {$booking_id} status to {$status}");
    
    $_SESSION['success'] = "Booking status updated to " . str_replace('_', ' ', $status);
    header('Location: ?page=view_booking&id=' . $booking_id);
    exit();
}

function addBookingPaymentHandler() {
    global $db;
    
    $booking_id = $_POST['booking_id'] ?? 0;
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? '';
    $transaction_id = $_POST['transaction_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $is_installment = isset($_POST['is_installment']) ? 1 : 0;
    
    if ($amount <= 0) {
        $_SESSION['error'] = "Payment amount must be greater than 0!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
    
    $booking = getBookingData($booking_id);
    if (!$booking) {
        $_SESSION['error'] = "Booking not found!";
        header('Location: ?page=bookings');
        exit();
    }
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        // Add payment record
        $stmt = $db->prepare("
            INSERT INTO booking_payments (
                booking_id, payment_date, amount, payment_method, 
                transaction_id, notes, is_advance, created_by
            ) VALUES (
                :booking_id, :date, :amount, :method, :txn_id, :notes, 1, :created_by
            )
        ");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $stmt->bindValue(':date', date('Y-m-d'), SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $stmt->bindValue(':method', $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
        $stmt->bindValue(':notes', $notes . ($is_installment ? ' (Installment Payment)' : ''), SQLITE3_TEXT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        // Calculate total paid
        $total_paid = $booking['totals']['advance_paid'] + $amount;
        
        // Update booking advance_fees and payment status
        $payment_status = 'pending';
        if ($total_paid >= $booking['total_estimated_cost']) {
            $payment_status = 'paid';
        } elseif ($total_paid > 0) {
            $payment_status = 'partial';
        }
        
        $stmt = $db->prepare("
            UPDATE bookings SET 
            advance_fees = :advance_fees,
            payment_status = :status,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':advance_fees', $total_paid, SQLITE3_FLOAT);
        $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
        $stmt->bindValue(':id', $booking_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('ADD_BOOKING_PAYMENT', "Added " . ($is_installment ? "installment" : "advance") . " payment of {$amount} to booking {$booking_id}");
        
        $_SESSION['success'] = "Payment of " . getSetting('currency_symbol', '₹') . " " . number_format($amount, 2) . " added successfully!";
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error adding payment: " . $e->getMessage();
        header('Location: ?page=view_booking&id=' . $booking_id);
        exit();
    }
}

function convertBookingHandler() {
    $booking_id = $_POST['booking_id'] ?? 0;
    
    $invoice_id = convertBookingToInvoice($booking_id);
    
    if ($invoice_id) {
        header('Location: ?page=view_invoice&id=' . $invoice_id);
    } else {
        header('Location: ?page=view_booking&id=' . $booking_id);
    }
    exit();
}

function updatePaymentStatus() {
    global $db;
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $payment_status = $_POST['payment_status'] ?? 'unpaid';
    $paid_amount = floatval($_POST['paid_amount'] ?? 0);
    $payment_notes = $_POST['payment_notes'] ?? '';
    
    $invoiceData = getInvoiceData($invoice_id);
    if (!$invoiceData) {
        $_SESSION['error'] = "Invoice not found!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $total_payable = $invoiceData['totals']['rounded_total'];
    
    if ($payment_status === 'paid' && $paid_amount <= 0) {
        $paid_amount = $total_payable;
    }
    
    if ($paid_amount > $total_payable) {
        $_SESSION['error'] = "Paid amount cannot exceed total payable amount!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
    
    $stmt = $db->prepare("
        UPDATE invoices SET 
        payment_status = :status,
        paid_amount = :paid_amount,
        payment_notes = :notes,
        updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
    $stmt->bindValue(':paid_amount', $paid_amount, SQLITE3_FLOAT);
    $stmt->bindValue(':notes', $payment_notes, SQLITE3_TEXT);
    $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('UPDATE_PAYMENT_STATUS', "Updated payment status for invoice {$invoice_id} to {$payment_status}");
    
    $_SESSION['success'] = "Payment status updated successfully!";
    header('Location: ?page=view_invoice&id=' . $invoice_id);
    exit();
}

function addPayment() {
    global $db;
    
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? '';
    $transaction_id = $_POST['transaction_id'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if ($amount <= 0) {
    $_SESSION['error'] = "Payment amount must be greater than 0!";
    header('Location: ?page=view_invoice&id=' . $invoice_id);
    exit();
}
    
    $invoiceData = getInvoiceData($invoice_id);
    if (!$invoiceData) {
        $_SESSION['error'] = "Invoice not found!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $rounded_total = $invoiceData['totals']['rounded_total'];
    $current_paid = $invoiceData['paid_amount'];
    $new_total_paid = $current_paid + $amount;
    
if ($new_total_paid > $rounded_total) {
    $_SESSION['error'] = "Payment amount would exceed total payable amount!";
    header('Location: ?page=view_invoice&id=' . $invoice_id);
    exit();
}
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $stmt = $db->prepare("
            INSERT INTO payments (invoice_id, payment_date, amount, payment_method, transaction_id, notes, created_by) 
            VALUES (:invoice_id, :date, :amount, :method, :txn_id, :notes, :created_by)
        ");
        $stmt->bindValue(':invoice_id', $invoice_id, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $payment_date, SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $stmt->bindValue(':method', $payment_method, SQLITE3_TEXT);
        $stmt->bindValue(':txn_id', $transaction_id, SQLITE3_TEXT);
        $stmt->bindValue(':notes', $notes, SQLITE3_TEXT);
        $stmt->bindValue(':created_by', $_SESSION['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        
        $payment_status = 'partially_paid';
        if ($new_total_paid >= $rounded_total) {
            $payment_status = 'paid';
            $new_total_paid = $rounded_total;
        } elseif ($new_total_paid <= 0) {
            $payment_status = 'unpaid';
        }
        
        $stmt = $db->prepare("
            UPDATE invoices SET 
            payment_status = :status,
            paid_amount = :paid_amount,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
        $stmt->bindValue(':paid_amount', $new_total_paid, SQLITE3_FLOAT);
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('ADD_PAYMENT', "Added payment of {$amount} for invoice {$invoice_id}");
        
        $_SESSION['success'] = "Payment of ₹" . number_format($amount, 2) . " recorded successfully!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error recording payment: " . $e->getMessage();
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
}

function deletePayment() {
    global $db;
    
    $payment_id = $_POST['payment_id'] ?? 0;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can delete payments!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $stmt = $db->prepare("SELECT * FROM payments WHERE id = :id");
    $stmt->bindValue(':id', $payment_id, SQLITE3_INTEGER);
    $payment = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$payment) {
        $_SESSION['error'] = "Payment not found!";
        header('Location: ?page=invoices');
        exit();
    }
    
    $invoice_id = $payment['invoice_id'];
    $amount = $payment['amount'];
    
    $db->exec('BEGIN TRANSACTION');
    
    try {
        $stmt = $db->prepare("DELETE FROM payments WHERE id = :id");
        $stmt->bindValue(':id', $payment_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $invoiceData = getInvoiceData($invoice_id);
        $rounded_total = $invoiceData['totals']['rounded_total'];
        $current_paid = $invoiceData['paid_amount'];
        $new_paid_amount = $current_paid - $amount;
        
        if ($new_paid_amount < 0) $new_paid_amount = 0;
        
        $payment_status = 'unpaid';
        if ($new_paid_amount > 0) {
            $payment_status = 'partially_paid';
            if ($new_paid_amount >= $rounded_total) {
                $payment_status = 'paid';
                $new_paid_amount = $rounded_total;
            }
        }
        
        $stmt = $db->prepare("
            UPDATE invoices SET 
            payment_status = :status,
            paid_amount = :paid_amount,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindValue(':status', $payment_status, SQLITE3_TEXT);
        $stmt->bindValue(':paid_amount', $new_paid_amount, SQLITE3_FLOAT);
        $stmt->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $db->exec('COMMIT');
        
        logAction('DELETE_PAYMENT', "Deleted payment ID: {$payment_id} for invoice {$invoice_id}");
        
        $_SESSION['success'] = "Payment deleted successfully!";
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error'] = "Error deleting payment: " . $e->getMessage();
        header('Location: ?page=view_invoice&id=' . $invoice_id);
        exit();
    }
}

function saveSettings() {
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can change settings!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $settings = [
        'company_name' => $_POST['company_name'] ?? '',
        'currency_symbol' => $_POST['currency_symbol'] ?? '',
        'office_address' => $_POST['office_address'] ?? '',
        'office_phone' => $_POST['office_phone'] ?? '',
        'company_email' => $_POST['company_email'] ?? '',
        'company_website' => $_POST['company_website'] ?? '',
        'payment_upi_id' => $_POST['payment_upi_id'] ?? '',
        'payment_upi_id_nongst' => $_POST['payment_upi_id_nongst'] ?? '',
        'payment_note' => $_POST['payment_note'] ?? '',
        'payment_methods' => $_POST['payment_methods'] ?? '',
        'smtp_host' => $_POST['smtp_host'] ?? '',
        'smtp_port' => $_POST['smtp_port'] ?? '',
        'smtp_username' => $_POST['smtp_username'] ?? '',
        'smtp_password' => $_POST['smtp_password'] ?? '',
        'smtp_encryption' => $_POST['smtp_encryption'] ?? '',
        'whatsapp_message' => $_POST['whatsapp_message'] ?? '',
        'enable_backups' => $_POST['enable_backups'] ?? '1',
        'backup_retention_days' => $_POST['backup_retention_days'] ?? '30',
        'reply_to_email' => $_POST['reply_to_email'] ?? '',
        'company_gst_number' => $_POST['company_gst_number'] ?? '',
        'default_gst_rate' => floatval($_POST['default_gst_rate'] ?? 18),
        'academy_name'     => $_POST['academy_name'] ?? '',
        'academy_address'  => $_POST['academy_address'] ?? '',
        'academy_phone'    => $_POST['academy_phone'] ?? '',
        'academy_email'    => $_POST['academy_email'] ?? '',
        'enrollment_prefix'=> strtoupper(preg_replace('/[^A-Za-z0-9]/','',$_POST['enrollment_prefix'] ?? 'ENR')),
    ];
    
    // Only allow default admin to change invoice serials
    if (isDefaultAdmin()) {
        if (isset($_POST['next_invoice_serial'])) {
            $serial = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_POST['next_invoice_serial']));
            $settings['next_invoice_serial'] = str_pad($serial ?: '001', 3, '0', STR_PAD_LEFT);
        }
        if (isset($_POST['next_nongst_serial'])) {
            $nserial = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $_POST['next_nongst_serial']));
            $settings['next_nongst_serial'] = str_pad($nserial ?: 'A065', 4, '0', STR_PAD_LEFT);
        }
    }
    
    foreach ($settings as $key => $value) {
        if (!empty($key)) updateSetting($key, $value);
    }
    
    logAction('UPDATE_SETTINGS', "Updated system settings");
    $_SESSION['success'] = "Settings updated successfully!";
    header('Location: ?page=settings');
    exit();
}

function changePassword() {
    global $db;
    
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New passwords do not match!";
        header('Location: ?page=profile');
        exit();
    }
    
    if (strlen($new_password) < 6) {
        $_SESSION['error'] = "New password must be at least 6 characters!";
        header('Location: ?page=profile');
        exit();
    }
    
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$user || !password_verify($current_password, $user['password'])) {
        $_SESSION['error'] = "Current password is incorrect!";
        header('Location: ?page=profile');
        exit();
    }
    
    $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
    $stmt->bindValue(':password', $hashedPassword, SQLITE3_TEXT);
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('CHANGE_PASSWORD', "User changed password");
    $_SESSION['success'] = "Password changed successfully!";
    header('Location: ?page=profile');
    exit();
}

function uploadLogo() {
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can upload logo!";
        header('Location: ?page=settings');
        exit();
    }
    
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $filename = $_FILES['logo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
            $upload_path = 'uploads/' . $new_filename;
            
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            
            if ($_FILES['logo']['size'] > 5 * 1024 * 1024) {
                $_SESSION['error'] = "File size too large. Maximum size is 5MB.";
                header('Location: ?page=settings');
                exit();
            }
            
            $check = getimagesize($_FILES['logo']['tmp_name']);
            if ($check === false) {
                $_SESSION['error'] = "File is not an image.";
                header('Location: ?page=settings');
                exit();
            }
            
            $old_logo = getSetting('logo_path');
            if ($old_logo && file_exists($old_logo)) @unlink($old_logo);
            
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                updateSetting('logo_path', $upload_path);
                logAction('UPLOAD_LOGO', "Uploaded new logo: {$new_filename}");
                $_SESSION['success'] = "Logo uploaded successfully!";
            } else {
                $_SESSION['error'] = "Failed to upload logo. Please check directory permissions.";
            }
        } else {
            $_SESSION['error'] = "Invalid file type. Allowed: jpg, jpeg, png, gif, svg, webp";
        }
    } else {
        $error_msg = "No file uploaded or upload error.";
        if (isset($_FILES['logo']['error'])) {
            switch ($_FILES['logo']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE: $error_msg = "File size too large."; break;
                case UPLOAD_ERR_PARTIAL: $error_msg = "File upload was incomplete."; break;
                case UPLOAD_ERR_NO_FILE: $error_msg = "No file was selected."; break;
                default: $error_msg = "Upload error.";
            }
        }
        $_SESSION['error'] = $error_msg;
    }
    header('Location: ?page=settings');
    exit();
}

function uploadQR() {
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can upload QR code!";
        header('Location: ?page=settings');
        exit();
    }
    
    if (isset($_FILES['qr_code']) && $_FILES['qr_code']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $ext = strtolower(pathinfo($_FILES['qr_code']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $filename = 'qr_' . time() . '.' . $ext;
            $upload_path = 'uploads/' . $filename;
            
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            
            if ($_FILES['qr_code']['size'] > 2 * 1024 * 1024) {
                $_SESSION['error'] = "File size too large. Maximum size is 2MB.";
                header('Location: ?page=settings');
                exit();
            }
            
            $old_qr = getSetting('qr_path');
            if ($old_qr && file_exists($old_qr)) @unlink($old_qr);
            
            if (move_uploaded_file($_FILES['qr_code']['tmp_name'], $upload_path)) {
                updateSetting('qr_path', $upload_path);
                logAction('UPLOAD_QR', "Uploaded new QR code: {$filename}");
                $_SESSION['success'] = "QR Code uploaded successfully!";
            } else {
                $_SESSION['error'] = "Failed to upload QR code!";
            }
        } else {
            $_SESSION['error'] = "Invalid file type. Allowed: jpg, jpeg, png, gif, svg, webp";
        }
    }
    header('Location: ?page=settings');
    exit();
}

function uploadQRNonGST() {
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can upload QR code!";
        header('Location: ?page=settings');
        exit();
    }
    
    if (isset($_FILES['qr_code_nongst']) && $_FILES['qr_code_nongst']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $ext = strtolower(pathinfo($_FILES['qr_code_nongst']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $filename = 'qr_nongst_' . time() . '.' . $ext;
            $upload_path = 'uploads/' . $filename;
            
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            
            if ($_FILES['qr_code_nongst']['size'] > 2 * 1024 * 1024) {
                $_SESSION['error'] = "File size too large. Maximum size is 2MB.";
                header('Location: ?page=settings');
                exit();
            }
            
            $old_qr = getSetting('qr_path_nongst');
            if ($old_qr && file_exists($old_qr)) @unlink($old_qr);
            
            if (move_uploaded_file($_FILES['qr_code_nongst']['tmp_name'], $upload_path)) {
                updateSetting('qr_path_nongst', $upload_path);
                logAction('UPLOAD_QR_NONGST', "Uploaded new Non-GST QR code: {$filename}");
                $_SESSION['success'] = "Non-GST QR Code uploaded successfully!";
            } else {
                $_SESSION['error'] = "Failed to upload Non-GST QR code!";
            }
        } else {
            $_SESSION['error'] = "Invalid file type. Allowed: jpg, jpeg, png, gif, svg, webp";
        }
    }
    header('Location: ?page=settings');
    exit();
}

function createUser() {
    global $db;

    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can create users!";
        header('Location: ?page=users');
        exit();
    }

    $username         = trim($_POST['username'] ?? '');
    $password         = $_POST['password'] ?? '';
    $email            = trim($_POST['email'] ?? '');
    $full_name        = trim($_POST['full_name'] ?? 'NA');
    $designation      = trim($_POST['designation'] ?? 'NA');
    $role             = $_POST['role'] ?? 'accountant';
    $has_nongst_access  = ($role === 'manager' && isset($_POST['has_nongst_access'])) ? 1 : 0;
    $has_academy_access = isset($_POST['has_academy_access']) ? 1 : 0;

    if (empty($full_name))   $full_name   = 'NA';
    if (empty($designation)) $designation = 'NA';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required!";
        header('Location: ?page=users');
        exit();
    }

    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = :username");
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($result['count'] > 0) {
        $_SESSION['error'] = "Username already exists!";
        header('Location: ?page=users');
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, email, full_name, designation, role, has_nongst_access, has_academy_access) VALUES (:u, :p, :e, :fn, :des, :r, :ng, :ac)");
    $stmt->bindValue(':u',   $username,         SQLITE3_TEXT);
    $stmt->bindValue(':p',   $hashedPassword,   SQLITE3_TEXT);
    $stmt->bindValue(':e',   $email,            SQLITE3_TEXT);
    $stmt->bindValue(':fn',  $full_name,        SQLITE3_TEXT);
    $stmt->bindValue(':des', $designation,      SQLITE3_TEXT);
    $stmt->bindValue(':r',   $role,             SQLITE3_TEXT);
    $stmt->bindValue(':ng',  $has_nongst_access,  SQLITE3_INTEGER);
    $stmt->bindValue(':ac',  $has_academy_access, SQLITE3_INTEGER);
    $stmt->execute();

    logAction('CREATE_USER', "Created user: {$username} ({$role}) - {$designation}");
    $_SESSION['success'] = "User '{$username}' created successfully!";
    header('Location: ?page=users');
    exit();
}

function updateUser() {
    global $db;

    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can update users!";
        header('Location: ?page=users');
        exit();
    }

    $user_id            = intval($_POST['user_id'] ?? 0);
    $username           = trim($_POST['username'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $full_name          = trim($_POST['full_name'] ?? 'NA');
    $designation        = trim($_POST['designation'] ?? 'NA');
    $role               = $_POST['role'] ?? 'accountant';
    $password           = $_POST['password'] ?? '';
    $has_nongst_access  = ($role === 'manager' && isset($_POST['has_nongst_access'])) ? 1 : 0;
    $has_academy_access = isset($_POST['has_academy_access']) ? 1 : 0;

    if (empty($full_name))   $full_name   = 'NA';
    if (empty($designation)) $designation = 'NA';

    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$user) {
        $_SESSION['error'] = "User not found!";
        header('Location: ?page=users');
        exit();
    }

    if (!empty($password)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET username=:u, email=:e, full_name=:fn, designation=:des, role=:r, has_nongst_access=:ng, has_academy_access=:ac, password=:p WHERE id=:id");
        $stmt->bindValue(':p', $hashedPassword, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare("UPDATE users SET username=:u, email=:e, full_name=:fn, designation=:des, role=:r, has_nongst_access=:ng, has_academy_access=:ac WHERE id=:id");
    }

    $stmt->bindValue(':u',   $username,           SQLITE3_TEXT);
    $stmt->bindValue(':e',   $email,              SQLITE3_TEXT);
    $stmt->bindValue(':fn',  $full_name,          SQLITE3_TEXT);
    $stmt->bindValue(':des', $designation,        SQLITE3_TEXT);
    $stmt->bindValue(':r',   $role,               SQLITE3_TEXT);
    $stmt->bindValue(':ng',  $has_nongst_access,  SQLITE3_INTEGER);
    $stmt->bindValue(':ac',  $has_academy_access, SQLITE3_INTEGER);
    $stmt->bindValue(':id',  $user_id,            SQLITE3_INTEGER);
    $stmt->execute();

    logAction('UPDATE_USER', "Updated user: {$username} ({$role})");
    $_SESSION['success'] = "User updated successfully!";
    header('Location: ?page=users');
    exit();
}

function deleteUser() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can delete users!";
        header('Location: ?page=users');
        exit();
    }
    
    $user_id = $_POST['user_id'] ?? 0;
    
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error'] = "You cannot delete your own account!";
        header('Location: ?page=users');
        exit();
    }
    
    $stmt = $db->prepare("SELECT username FROM users WHERE id = :id");
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "User not found!";
        header('Location: ?page=users');
        exit();
    }
    
    $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('DELETE_USER', "Deleted user: {$user['username']}");
    $_SESSION['success'] = "User deleted successfully!";
    header('Location: ?page=users');
    exit();
}

function updateNonGSTAccess() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can change Non-GST access!";
        header('Location: ?page=users');
        exit();
    }
    
    $user_id = intval($_POST['user_id'] ?? 0);
    $access = intval($_POST['has_nongst_access'] ?? 0) ? 1 : 0;
    
    // Only allow for manager role
    $stmt = $db->prepare("SELECT role FROM users WHERE id = :id");
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$user || $user['role'] !== 'manager') {
        $_SESSION['error'] = "Non-GST access can only be set for Manager role users!";
        header('Location: ?page=users');
        exit();
    }
    
    $stmt = $db->prepare("UPDATE users SET has_nongst_access = :access WHERE id = :id");
    $stmt->bindValue(':access', $access, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    logAction('UPDATE_NONGST_ACCESS', "Updated Non-GST access for user ID {$user_id}: " . ($access ? 'granted' : 'revoked'));
    $_SESSION['success'] = "Non-GST access " . ($access ? "granted" : "revoked") . " successfully!";
    header('Location: ?page=users');
    exit();
}

function saveTemplate() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can manage templates!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $template_name = $_POST['template_name'] ?? '';
    $template_data = $_POST['template_data'] ?? '';
    
    if (empty($template_name) || empty($template_data)) {
        $_SESSION['error'] = "Template name and data are required!";
        header('Location: ?page=invoice_templates');
        exit();
    }
    
    try {
        $decoded = json_decode($template_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON data: " . json_last_error_msg());
        }
        
        $template_json = json_encode($decoded, JSON_PRETTY_PRINT);
        
        $stmt = $db->prepare("INSERT OR REPLACE INTO invoice_templates (template_name, template_data) VALUES (:name, :data)");
        $stmt->bindValue(':name', $template_name, SQLITE3_TEXT);
        $stmt->bindValue(':data', $template_json, SQLITE3_TEXT);
        $stmt->execute();
        
        logAction('SAVE_TEMPLATE', "Saved template: {$template_name}");
        $_SESSION['success'] = "Template saved successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to save template: " . $e->getMessage();
    }
    
    header('Location: ?page=invoice_templates');
    exit();
}

function setDefaultTemplate() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can set default template!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $template_id = $_POST['template_id'] ?? 0;
    
    if ($template_id <= 0) {
        $_SESSION['error'] = "Invalid template ID!";
        header('Location: ?page=invoice_templates');
        exit();
    }
    
    try {
        $db->exec("UPDATE invoice_templates SET is_default = 0");
        
        $stmt = $db->prepare("UPDATE invoice_templates SET is_default = 1 WHERE id = :id");
        $stmt->bindValue(':id', $template_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        $stmt = $db->prepare("SELECT template_name FROM invoice_templates WHERE id = :id");
        $stmt->bindValue(':id', $template_id, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($result) updateSetting('invoice_template', $result['template_name']);
        
        logAction('SET_DEFAULT_TEMPLATE', "Set template ID {$template_id} as default");
        $_SESSION['success'] = "Default template updated successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to set default template: " . $e->getMessage();
    }
    
    header('Location: ?page=invoice_templates');
    exit();
}

function deleteTemplate() {
    global $db;
    
    if (!isAdmin()) {
        $_SESSION['error'] = "Only admin can delete templates!";
        header('Location: ?page=dashboard');
        exit();
    }
    
    $template_id = $_POST['template_id'] ?? 0;
    
    if ($template_id <= 0) {
        $_SESSION['error'] = "Invalid template ID!";
        header('Location: ?page=invoice_templates');
        exit();
    }
    
    try {
        $stmt = $db->prepare("SELECT template_name, is_default FROM invoice_templates WHERE id = :id");
        $stmt->bindValue(':id', $template_id, SQLITE3_INTEGER);
        $template = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$template) throw new Exception("Template not found");
        if ($template['is_default']) throw new Exception("Cannot delete default template. Set another template as default first.");
        
        $stmt = $db->prepare("DELETE FROM invoice_templates WHERE id = :id");
        $stmt->bindValue(':id', $template_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        logAction('DELETE_TEMPLATE', "Deleted template: {$template['template_name']}");
        $_SESSION['success'] = "Template deleted successfully!";
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Failed to delete template: " . $e->getMessage();
    }
    
    header('Location: ?page=invoice_templates');
    exit();
}

function includeDashboard() {
    $stats = getStatistics();
    $role = getUserRole();
    $cur = getSetting('currency_symbol', '₹');
    $roleBadgeClass = $role === 'admin' ? 'role-admin' : ($role === 'manager' ? 'role-manager' : 'role-accountant');
    ?>

    <!-- Welcome bar -->
    <div class="dash-welcome">
        <h2>👋 Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></h2>
        <span class="dash-role-badge <?php echo $roleBadgeClass; ?>"><?php echo ucfirst($role); ?></span>
    </div>

    <!-- Stats grid -->
    <div class="stats-grid">
        <div class="stat-card-v2">
            <div class="sc-label">Total Invoices</div>
            <div class="sc-value"><?php echo $stats['total_invoices']; ?></div>
            <a href="?page=invoices" class="sc-link">View all →</a>
        </div>
        <div class="stat-card-v2">
            <div class="sc-label">This Month</div>
            <div class="sc-value"><?php echo $stats['this_month_invoices']; ?></div>
            <span style="font-size:11px;color:#aaa;">invoices</span>
        </div>
        <?php if (!isAccountant()): ?>
        <div class="stat-card-v2 green">
            <div class="sc-label">Total Revenue</div>
            <div class="sc-value" style="font-size:20px;"><?php echo $cur . ' ' . number_format($stats['total_revenue'], 0); ?></div>
        </div>
        <div class="stat-card-v2 green">
            <div class="sc-label">Received</div>
            <div class="sc-value" style="font-size:20px;"><?php echo $cur . ' ' . number_format($stats['total_paid'], 0); ?></div>
        </div>
        <div class="stat-card-v2 orange">
            <div class="sc-label">Pending Amount</div>
            <div class="sc-value" style="font-size:20px;"><?php echo $cur . ' ' . number_format($stats['total_pending'], 0); ?></div>
        </div>
        <?php endif; ?>
        <?php if (isAdmin() && $stats['pending_deletions'] > 0): ?>
        <div class="stat-card-v2 red">
            <div class="sc-label">Pending Deletions</div>
            <div class="sc-value"><?php echo $stats['pending_deletions']; ?></div>
            <a href="?page=pending_deletions" class="sc-link">Review →</a>
        </div>
        <?php endif; ?>
        <?php if (isAdmin() && isset($stats['gst_collected'])): ?>
        <div class="stat-card-v2" style="border-left-color:#8e44ad;">
            <div class="sc-label">GST Collected <span style="font-size:10px;font-weight:400;display:block;color:#aaa;"><?php echo htmlspecialchars($stats['gst_filing_period']); ?></span></div>
            <div class="sc-value" style="font-size:19px;color:#8e44ad;"><?php echo $cur . ' ' . number_format($stats['gst_collected'], 0); ?></div>
            <span style="font-size:10px;color:#aaa;">GST Filing Period</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick actions -->
    <div class="dash-quick-actions">
        <a href="?page=create_invoice" class="quick-btn qb-blue">➕ New Invoice</a>
        <a href="?page=create_booking" class="quick-btn qb-green">📅 New Booking</a>
        <?php if (!isAccountant()): ?>
        <a href="?page=payment_link" class="quick-btn qb-orange">🔗 Payment Link</a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="?page=settings" class="quick-btn qb-purple">⚙️ Settings</a>
        <?php endif; ?>
    </div>

    <!-- Recent invoices table -->
    <div class="dash-section-title">📋 Recent Invoices <span style="font-size:12px;font-weight:400;color:#95a5a6;">(last 10)</span></div>
    <?php
    $recentInvoices = getAllInvoices('', '', '');
    $recentInvoices = array_slice($recentInvoices, 0, 10);
    ?>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Invoice No</th>
                <th>Customer</th>
                <th>Date</th>
                <?php if (!isAccountant()): ?><th>Amount</th><?php endif; ?>
                <th>Status</th>
                <?php if (isAdmin()): ?><th>Created By</th><?php endif; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($recentInvoices)): ?>
            <tr><td colspan="6" style="text-align:center;color:#aaa;padding:30px;">No invoices found</td></tr>
            <?php else: ?>
            <?php foreach ($recentInvoices as $invoice):
                $invoiceData = !isAccountant() ? getInvoiceData($invoice['id']) : null;
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                <td style="white-space:nowrap;"><?php echo date('d-m-Y', strtotime($invoice['invoice_date'])); ?></td>
                <?php if (!isAccountant()): ?>
                <td><?php echo $cur . ' ' . (isset($invoiceData['totals']) ? number_format($invoiceData['totals']['rounded_total'], 0) : '0'); ?></td>
                <?php endif; ?>
                <td>
                    <span class="payment-badge <?php echo $invoice['payment_status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_status'])); ?>
                    </span>
                </td>
                <?php if (isAdmin()): ?>
                <td style="font-size:12px;color:#777;"><?php echo htmlspecialchars($invoice['created_by_name'] ?? '—'); ?></td>
                <?php endif; ?>
                <td class="actions-cell">
                    <?php if (!isAccountant()): ?>
                    <a href="?page=view_invoice&id=<?php echo $invoice['id']; ?>" class="action-btn view-btn">View</a>
                    <?php $canEdit = isAdmin() || $invoice['created_by'] == $_SESSION['user_id']; ?>
                    <?php if ($canEdit): ?>
                    <a href="?page=edit_invoice&id=<?php echo $invoice['id']; ?>" class="action-btn edit-btn">Edit</a>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:#aaa;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}

function includeCreateInvoice() {
    ?>
    <h2>Create New Invoice</h2>
    <form id="invoiceForm" method="POST">
        <input type="hidden" name="action" value="create_invoice">
        
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" required>
            </div>
            <div class="form-group">
                <label>Phone:</label>
                <input type="tel" name="customer_phone">
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email">
            </div>
            <div class="form-group">
                <label>Date:</label>
                <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"></textarea>
        </div>
        
        <div class="row" style="background:#f0f8ff;padding:12px 15px;border-radius:5px;border:1px solid #b3d7f5;margin-bottom:15px;">
            <div class="form-group">
                <label>GST Rate (%):</label>
                <input type="number" name="gst_rate" id="gst_rate" step="0.01" value="<?php echo htmlspecialchars(getSetting('default_gst_rate', '18')); ?>" min="0" max="100" oninput="recalcGST()">
                <small>Default: <?php echo htmlspecialchars(getSetting('default_gst_rate', '18')); ?>% — edit as needed</small>
            </div>
            <div class="form-group">
                <label>GST Amount (₹):</label>
                <input type="number" name="gst_amount" id="gst_amount" step="0.01" value="0" min="0" oninput="onGSTAmountEdit()">
                <small>Auto-calculated from rate, or enter manually</small>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
                <input type="checkbox" name="gst_inclusive" id="gst_inclusive" value="1" onchange="updateGSTStatus()">
                <label for="gst_inclusive" style="margin:0;">GST Inclusive Invoice</label>
            </div>
            <div class="form-group" style="padding-top:24px;">
                <span id="gst_status_badge" style="padding:5px 12px;border-radius:4px;font-size:13px;background:#bdc3c7;color:#fff;">Non-GST Invoice</span>
            </div>
        </div>
        
        <div class="form-group" id="customer_gst_row" style="display:none; background:#fffbea; padding:10px 15px; border-radius:5px; border:1px solid #f0d060;">
            <label>Customer GST Number:</label>
            <input type="text" name="customer_gst_number" id="customer_gst_number" value="NA" maxlength="20" placeholder="e.g. 23ABCDE1234F1Z5 or NA">
            <small>Displayed on GST invoice. Default is <strong>NA</strong> — edit if customer has GSTIN.</small>
        </div>
        
        <script>
        var _gstManual = false;

        function recalcGST() {
            _gstManual = false;
            calcGSTFromItems();
        }

        function onGSTAmountEdit() {
            _gstManual = true;
            updateGSTStatus();
        }

        function updateGSTStatus() {
            var amt = parseFloat(document.getElementById('gst_amount').value) || 0;
            var rate = parseFloat(document.getElementById('gst_rate').value) || 0;
            var chk = document.getElementById('gst_inclusive').checked;
            var badge = document.getElementById('gst_status_badge');
            var gstRow = document.getElementById('customer_gst_row');
            var isGST = (amt > 0 || rate > 0 || chk);
            if (isGST) {
                badge.style.background = '#27ae60';
                badge.textContent = 'GST Invoice (' + (rate > 0 ? rate + '%' : 'custom amount') + ') → saved to GST DB';
                if (gstRow) gstRow.style.display = 'block';
            } else {
                badge.style.background = '#bdc3c7';
                badge.textContent = 'Non-GST Invoice → saved to Non-GST DB';
                if (gstRow) gstRow.style.display = 'none';
            }
        }

        function getItemsSubtotal() {
            var total = 0;
            document.querySelectorAll('.item-row').forEach(function(row) {
                // Skip rows marked for deletion
                var delFlag = row.querySelector('input.delete-flag');
                if (delFlag && delFlag.value === '1') return;
                var amt = parseFloat(row.querySelector('input[name*="[amount]"]')?.value) || 0;
                var svc = parseFloat(row.querySelector('input[name*="[service_charge]"]')?.value) || 0;
                var disc = parseFloat(row.querySelector('input[name*="[discount]"]')?.value) || 0;
                total += (amt + svc - disc);
            });
            return total;
        }

        function calcGSTFromItems() {
            if (_gstManual) return;
            var rate = parseFloat(document.getElementById('gst_rate').value) || 0;
            if (rate <= 0) {
                document.getElementById('gst_amount').value = '0';
                updateGSTStatus();
                return;
            }
            var subtotal = getItemsSubtotal();
            var chk = document.getElementById('gst_inclusive').checked;
            var gst;
            if (chk) {
                gst = Math.round(subtotal * rate / (100 + rate) * 100) / 100;
            } else {
                gst = Math.round(subtotal * rate / 100 * 100) / 100;
            }
            document.getElementById('gst_amount').value = gst.toFixed(2);
            updateGSTStatus();
        }

        document.addEventListener('input', function(e) {
            if (e.target.name && (
                e.target.name.includes('[amount]') ||
                e.target.name.includes('[service_charge]') ||
                e.target.name.includes('[discount]')
            )) {
                calcGSTFromItems();
            }
        });

        // Recalculate before submit to ensure gst_amount is current
        document.addEventListener('submit', function(e) {
            if (!_gstManual) calcGSTFromItems();
        });

        // Initial calculation
        calcGSTFromItems();
        </script>
        
        <h3>Invoice Items</h3>
        <div id="items-container">
            <div class="item-row">
                <div class="row">
                    <div class="form-group">
                        <label>S.No.</label>
                        <input type="number" name="items[0][s_no]" value="1" min="1" readonly>
                    </div>
                    <div class="form-group">
                        <label>Particulars *</label>
                        <input type="text" name="items[0][particulars]" placeholder="Enter particulars" required>
                    </div>
                    <div class="form-group">
                        <label>Amount (₹)</label>
                        <input type="number" name="items[0][amount]" step="0.01" value="0" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Service Charge (₹)</label>
                        <input type="number" name="items[0][service_charge]" step="0.01" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Discount (₹)</label>
                        <input type="number" name="items[0][discount]" step="0.01" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Remark</label>
                        <input type="text" name="items[0][remark]" placeholder="Optional">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeItem(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="button" onclick="addItem('items-container')" class="btn-secondary">Add Item</button>
        
        <h3>Purchases</h3>
        <div id="purchases-container">
            <div class="purchase-row">
                <div class="row">
                    <div class="form-group">
                        <label>S.No.</label>
                        <input type="number" name="purchases[0][s_no]" value="1" min="1" readonly>
                    </div>
                    <div class="form-group">
                        <label>Particulars</label>
                        <input type="text" name="purchases[0][particulars]" placeholder="Enter particulars">
                    </div>
                    <div class="form-group">
                        <label>Qty</label>
                        <input type="number" name="purchases[0][qty]" step="0.01" value="1" min="0">
                    </div>
                    <div class="form-group">
                        <label>Rate (₹)</label>
                        <input type="number" name="purchases[0][rate]" step="0.01" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Amount Received (₹)</label>
                        <input type="number" name="purchases[0][amount_received]" step="0.01" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removePurchase(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="button" onclick="addPurchase('purchases-container')" class="btn-secondary">Add Purchase</button>
        
        <div class="action-buttons">
            <button type="submit">Generate Invoice</button>
            <button type="button" onclick="window.location.href='?page=create_invoice'" class="btn-danger">Reset</button>
        </div>
    </form>

    <?php if (isset($_SESSION['last_invoice_id'])): ?>
    <?php
    $last_invoice = getInvoiceData($_SESSION['last_invoice_id']);
    if ($last_invoice):
        displayInvoiceHTML($last_invoice);
    ?>
            <?php if (!isset($_GET['share'])): ?>
        <div class="action-buttons no-print no-email" style="margin-top: 20px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <div class="action-buttons no-print" style="margin-top: 20px;">
            <button onclick="printInvoice()" class="btn-print">Print / Save as PDF</button>
        <a href="?page=create_invoice" class="btn-secondary">Create New Invoice</a>
        <?php if (!empty($last_invoice['customer_email'])): ?>
        <a href="javascript:void(0)" onclick="sendEmail(<?php echo $last_invoice['id']; ?>, '<?php echo htmlspecialchars($last_invoice['customer_email']); ?>')" class="btn-warning">Send Email</a>
        <?php endif; ?>
        <?php if (!empty($last_invoice['customer_phone'])): ?>
        <a href="javascript:void(0)" onclick="sendWhatsApp(<?php echo $last_invoice['id']; ?>, '<?php echo htmlspecialchars($last_invoice['customer_phone']); ?>')" class="btn-warning" style="background: #25D366;">Send WhatsApp</a>
        <?php endif; ?>
    </div>
            </div>
        <?php endif; ?>
    <?php 
    unset($_SESSION['last_invoice_id']);
    endif; ?>
    <?php endif; ?>
    <?php
}

function includeInvoices() {
    global $db;
    
    // Non-GST toggle: only visible if user can view Non-GST
    $showNonGST = canViewNonGST() && isset($_GET['db']) && $_GET['db'] === 'nongst';
    $activeDb = $showNonGST ? getNonGSTDb() : $db;
    ?>
    <h2><?php echo $showNonGST ? 'Non-GST Invoices' : 'All Invoices'; ?></h2>

    <?php if (canViewNonGST()): ?>
    <div style="margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?page=invoices" class="<?php echo !$showNonGST ? 'btn' : 'btn-secondary'; ?>" style="<?php echo !$showNonGST ? 'background:#3498db;color:#fff;' : ''; ?>">📋 GST Invoices</a>
        <a href="?page=invoices&db=nongst" class="<?php echo $showNonGST ? 'btn' : 'btn-secondary'; ?>" style="<?php echo $showNonGST ? 'background:#e67e22;color:#fff;' : ''; ?>">🔒 Non-GST Invoices</a>
    </div>
    <?php endif; ?>

    <div class="search-box">
        <form method="GET" class="search-form">
            <input type="hidden" name="page" value="invoices">
            <div class="form-group">
                <label>Search:</label>
                <input type="text" name="search" placeholder="Search by customer name, invoice number, phone, email..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>From Date:</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($_GET['start_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>To Date:</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($_GET['end_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Payment Status:</label>
                <select name="payment_status">
                    <option value="all" <?php echo ($_GET['payment_status'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="unpaid" <?php echo ($_GET['payment_status'] ?? '') === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="partially_paid" <?php echo ($_GET['payment_status'] ?? '') === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                    <option value="paid" <?php echo ($_GET['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="settled" <?php echo ($_GET['payment_status'] ?? '') === 'settled' ? 'selected' : ''; ?>>Settled</option>
                </select>
            </div>
            <div class="form-group">
                <button type="submit">Search</button>
                <button type="button" onclick="window.location.href='?page=invoices'" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>

    <?php
    $search = $_GET['search'] ?? '';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $payment_status = $_GET['payment_status'] ?? '';
    $invoices = getAllInvoices($search, $start_date, $end_date, $payment_status, $activeDb, $showNonGST);
    ?>

    <table>
        <thead>
            <tr>
                <th>Invoice No</th>
                <th>Customer</th>
                <th>Date</th>
                <th>Total Amount</th>
                <th>Paid</th>
                <th>Balance</th>
                <th>Payment Status</th>
                <?php if (isAdmin()): ?>
                <th>Created By</th>
                <?php endif; ?>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
            <tr>
                <td colspan="<?php echo isAdmin() ? '9' : '8'; ?>" style="text-align: center;">No invoices found</td>
            </tr>
            <?php else: ?>
            <?php foreach ($invoices as $invoice): 
                $invoiceData = getInvoiceData($invoice['id']);
                $canEdit = isAdmin() || $invoice['created_by'] == $_SESSION['user_id'];
            ?>
            <tr>
                <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                <td><?php echo date('d-m-Y', strtotime($invoice['invoice_date'])); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo isset($invoiceData['totals']) ? number_format($invoiceData['totals']['rounded_total'], 2) : '0.00'; ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($invoice['paid_amount'], 2); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo isset($invoiceData['totals']) ? number_format($invoiceData['totals']['balance'], 2) : '0.00'; ?></td>
                <td>
                    <span class="payment-badge <?php echo $invoice['payment_status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_status'])); ?>
                    </span>
                </td>
                <?php if (isAdmin()): ?>
                <td><?php echo htmlspecialchars($invoice['created_by_name'] ?? 'Unknown'); ?></td>
                <?php endif; ?>
                <td class="actions-cell">
                    <?php if (!isAccountant()): ?>
                    <a href="?page=view_invoice&id=<?php echo $invoice['id']; ?><?php echo $showNonGST ? '&db=nongst' : ''; ?>" class="action-btn view-btn">View</a>
                    <?php if ($canEdit): ?>
                    <a href="?page=edit_invoice&id=<?php echo $invoice['id']; ?><?php echo $showNonGST ? '&db=nongst' : ''; ?>" class="action-btn edit-btn">Edit</a>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $invoice['id']; ?>, '<?php echo $invoice['invoice_number']; ?>')" class="action-btn delete-btn">Delete</a>
                    <?php elseif (!isAccountant()): ?>
                    <a href="javascript:void(0)" onclick="requestDelete(<?php echo $invoice['id']; ?>, '<?php echo $invoice['invoice_number']; ?>')" class="action-btn delete-btn">Request Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div id="deleteRequestModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
            <h3>Request Invoice Deletion</h3>
            <p id="deleteRequestText"></p>
            <form method="POST" id="deleteRequestForm">
                <input type="hidden" name="action" value="request_delete_invoice">
                <input type="hidden" name="invoice_id" id="requestInvoiceId">
                
                <div class="form-group">
                    <label>Reason for deletion: *</label>
                    <textarea name="reason" rows="3" required placeholder="Please provide a reason for deleting this invoice..."></textarea>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn-danger">Submit Request</button>
                    <button type="button" onclick="document.getElementById('deleteRequestModal').style.display = 'none'" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

function includePendingDeletions() {
    if (!isAdmin()) {
        echo '<div class="message error">Only admin can view pending requests!</div>';
        return;
    }

    $requests    = getPendingDeleteRequests();
    $sealReqs    = getPendingSealRequests();
    ?>
    <h2>⏳ Pending Requests</h2>

    <!-- Seal Requests -->
    <?php if (!empty($sealReqs)): ?>
    <h3 style="margin:18px 0 10px;color:#8e44ad;">🔏 Pending Seal Requests (<?php echo count($sealReqs); ?>)</h3>
    <table>
        <thead>
            <tr><th>Invoice #</th><th>Requested By</th><th>Note</th><th>Requested At</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($sealReqs as $sr): ?>
        <tr style="background:#fdf4ff;">
            <td>
                <a href="?page=view_invoice&id=<?php echo $sr['invoice_id']; ?><?php echo $sr['db_source']==='nongst'?'&db=nongst':''; ?>" style="color:#8e44ad;font-weight:bold;">
                    <?php echo htmlspecialchars($sr['invoice_number'] ?? 'Invoice #'.$sr['invoice_id']); ?>
                </a>
            </td>
            <td><?php echo htmlspecialchars($sr['requested_by_name'] ?? ''); ?></td>
            <td style="font-size:12px;max-width:200px;"><?php echo htmlspecialchars($sr['request_note'] ?: '—'); ?></td>
            <td style="font-size:12px;"><?php echo date('d-m-Y H:i', strtotime($sr['created_at'])); ?></td>
            <td class="actions-cell">
                <a href="?page=view_invoice&id=<?php echo $sr['invoice_id']; ?><?php echo $sr['db_source']==='nongst'?'&db=nongst':''; ?>" class="action-btn view-btn">View &amp; Seal</a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="reject_seal_request">
                    <input type="hidden" name="request_id" value="<?php echo $sr['id']; ?>">
                    <input type="hidden" name="admin_note" value="Rejected from pending requests.">
                    <button type="submit" class="action-btn delete-btn" style="border:none;cursor:pointer;" onclick="return confirm('Reject this seal request?')">Reject</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Delete Requests -->
    <h3 style="margin:18px 0 10px;color:#e74c3c;">🗑️ Pending Deletion Requests (<?php echo count($requests); ?>)</h3>
    <?php if (empty($requests)): ?>
    <div class="message info">No pending deletion requests.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Invoice Number</th>
                <th>Requested By</th>
                <th>Reason</th>
                <th>Requested At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $request): ?>
            <tr>
                <td><?php echo htmlspecialchars($request['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($request['requested_by_name']); ?></td>
                <td><?php echo htmlspecialchars($request['reason']); ?></td>
                <td><?php echo date('d-m-Y H:i', strtotime($request['created_at'])); ?></td>
                <td class="actions-cell">
                    <a href="?page=view_invoice&id=<?php echo $request['invoice_id']; ?>" class="action-btn view-btn">View Invoice</a>
                    <a href="javascript:void(0)" onclick="approveDelete(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['invoice_number']); ?>')" class="action-btn edit-btn">Approve</a>
                    <a href="javascript:void(0)" onclick="rejectDelete(<?php echo $request['id']; ?>, '<?php echo htmlspecialchars($request['invoice_number']); ?>')" class="action-btn delete-btn">Reject</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div id="rejectModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
            <h3>Reject Deletion Request</h3>
            <p id="rejectText"></p>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action" value="reject_delete_request">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="form-group">
                    <label>Rejection Reason: *</label>
                    <textarea name="rejection_reason" rows="3" required placeholder="Why are you rejecting this request?"></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn-danger">Reject Request</button>
                    <button type="button" onclick="document.getElementById('rejectModal').style.display = 'none'" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

function includeEditInvoice() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Invoice ID not specified!</div>';
        return;
    }
    
    $invoice_id = $_GET['id'];
    $invoice = getInvoiceData($invoice_id);
    
    if (!$invoice) {
        echo '<div class="message error">Invoice not found!</div>';
        return;
    }

    // Block editing if invoice is signed/locked
    $lockChk = $db->prepare("SELECT locked, signed_by FROM invoice_signatures WHERE invoice_id=:id AND db_source='gst'");
    $lockChk->bindValue(':id', $invoice_id, SQLITE3_INTEGER);
    $lockRow = $lockChk->execute()->fetchArray(SQLITE3_ASSOC);
    if ($lockRow && $lockRow['locked']) {
        echo '<div class="message error">🔒 This invoice is signed and locked. It cannot be edited. <a href="?page=view_invoice&id=' . intval($invoice_id) . '">View Invoice →</a></div>';
        return;
    }
    
    $canEdit = isAdmin() || $invoice['created_by'] == $_SESSION['user_id'];
    if (!$canEdit) {
        echo '<div class="message error">You don\'t have permission to edit this invoice!</div>';
        return;
    }
    
    $custGST = $invoice['customer_gst_number'] ?? 'NA';
    if (empty($custGST)) $custGST = 'NA';
    ?>
    <h2>Edit Invoice: <?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
    <form id="editInvoiceForm" method="POST">
        <input type="hidden" name="action" value="update_invoice">
        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
        
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" value="<?php echo htmlspecialchars($invoice['customer_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Phone:</label>
                <input type="tel" name="customer_phone" value="<?php echo htmlspecialchars($invoice['customer_phone']); ?>">
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email" value="<?php echo htmlspecialchars($invoice['customer_email']); ?>">
            </div>
            <div class="form-group">
                <label>Date:</label>
                <input type="date" name="invoice_date" value="<?php echo $invoice['invoice_date']; ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"><?php echo htmlspecialchars($invoice['customer_address']); ?></textarea>
        </div>
        
        <div class="form-group" style="background:#fffbea; padding:10px 15px; border-radius:5px; border:1px solid #f0d060;">
            <label>Customer GST Number:</label>
            <input type="text" name="customer_gst_number" value="<?php echo htmlspecialchars($custGST); ?>" maxlength="20" placeholder="e.g. 23ABCDE1234F1Z5 or NA">
            <small>Leave as <strong>NA</strong> if customer has no GSTIN.</small>
        </div>

        <?php
        $editGstAmt       = floatval($invoice['gst_amount'] ?? 0);
        $editGstRate      = floatval($invoice['gst_rate'] ?? 0);
        $editGstInclusive = (bool)($invoice['gst_inclusive'] ?? 0);
        $isEditGST        = ($editGstAmt > 0 || $editGstRate > 0 || $editGstInclusive);
        ?>
        <div class="row" style="background:#f0f8ff;padding:12px 15px;border-radius:5px;border:1px solid #b3d7f5;margin-bottom:15px;">
            <div class="form-group">
                <label>GST Rate (%):</label>
                <input type="number" name="gst_rate" id="edit_gst_rate" step="0.01" value="<?php echo $editGstRate; ?>" min="0" max="100">
                <small>0 = no GST rate; use amount field below to set manually</small>
            </div>
            <div class="form-group">
                <label>GST Amount (₹):</label>
                <input type="number" name="gst_amount" id="edit_gst_amount" step="0.01" value="<?php echo $editGstAmt; ?>" min="0">
                <small>Stored value — recalculated on view</small>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:24px;">
                <input type="checkbox" name="gst_inclusive" id="edit_gst_inclusive" value="1" <?php echo $editGstInclusive ? 'checked' : ''; ?>>
                <label for="edit_gst_inclusive" style="margin:0;">GST Inclusive</label>
            </div>
            <div class="form-group" style="padding-top:24px;">
                <span style="padding:5px 12px;border-radius:4px;font-size:13px;background:<?php echo $isEditGST?'#27ae60':'#bdc3c7'; ?>;color:#fff;">
                    <?php echo $isEditGST ? 'GST Invoice' : 'Non-GST Invoice'; ?>
                </span>
            </div>
        </div>
        
        <h3>Invoice Items</h3>
        <small style="color:#e67e22;">ℹ️ Click <strong>Mark for Removal</strong> to flag a row for deletion — it will only be deleted when you click <strong>Update Invoice</strong>.</small>
        <div id="edit-items-container" style="margin-top:10px;">
            <?php foreach ($invoice['parsed_items'] as $index => $item): ?>
            <div class="item-row" id="item-row-<?php echo $item['id']; ?>">
                <div class="row">
                    <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="items[<?php echo $index; ?>][_delete]" value="0" class="delete-flag">
                    <div class="form-group">
                        <label>S.No.</label>
                        <input type="number" name="items[<?php echo $index; ?>][s_no]" value="<?php echo $index + 1; ?>" min="1" readonly>
                    </div>
                    <div class="form-group">
                        <label>Particulars *</label>
                        <input type="text" name="items[<?php echo $index; ?>][particulars]" value="<?php echo htmlspecialchars($item['particulars']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Amount (₹)</label>
                        <input type="number" name="items[<?php echo $index; ?>][amount]" step="0.01" value="<?php echo $item['amount']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Service Charge (₹)</label>
                        <input type="number" name="items[<?php echo $index; ?>][service_charge]" step="0.01" value="<?php echo $item['service_charge']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Discount (₹)</label>
                        <input type="number" name="items[<?php echo $index; ?>][discount]" step="0.01" value="<?php echo $item['discount']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Remark</label>
                        <input type="text" name="items[<?php echo $index; ?>][remark]" value="<?php echo htmlspecialchars($item['remark']); ?>">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="markItemDelete(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Mark for Removal</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <button type="button" onclick="addItem('edit-items-container')" class="btn-secondary">Add Item</button>
        
        <h3>Purchases</h3>
        <small style="color:#e67e22;">ℹ️ Click <strong>Mark for Removal</strong> to flag a purchase row for deletion on save.</small>
        <div id="edit-purchases-container" style="margin-top:10px;">
            <?php foreach ($invoice['parsed_purchases'] as $index => $purchase): ?>
            <div class="purchase-row" id="purchase-row-<?php echo $purchase['id']; ?>">
                <div class="row">
                    <input type="hidden" name="purchases[<?php echo $index; ?>][id]" value="<?php echo $purchase['id']; ?>">
                    <input type="hidden" name="purchases[<?php echo $index; ?>][_delete]" value="0" class="delete-flag">
                    <div class="form-group">
                        <label>S.No.</label>
                        <input type="number" name="purchases[<?php echo $index; ?>][s_no]" value="<?php echo $index + 1; ?>" min="1" readonly>
                    </div>
                    <div class="form-group">
                        <label>Particulars</label>
                        <input type="text" name="purchases[<?php echo $index; ?>][particulars]" value="<?php echo htmlspecialchars($purchase['particulars']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Qty</label>
                        <input type="number" name="purchases[<?php echo $index; ?>][qty]" step="0.01" value="<?php echo $purchase['qty']; ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Rate (₹)</label>
                        <input type="number" name="purchases[<?php echo $index; ?>][rate]" step="0.01" value="<?php echo $purchase['rate']; ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Amount Received (₹)</label>
                        <input type="number" name="purchases[<?php echo $index; ?>][amount_received]" step="0.01" value="<?php echo $purchase['amount_received']; ?>">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="markPurchaseDelete(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Mark for Removal</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <button type="button" onclick="addPurchase('edit-purchases-container')" class="btn-secondary">Add Purchase</button>
        
        <div class="action-buttons">
            <button type="submit">Update Invoice</button>
            <a href="?page=view_invoice&id=<?php echo $invoice_id; ?>" class="btn-secondary">Cancel</a>
            <?php if (isAdmin()): ?>
            <a href="javascript:void(0)" onclick="confirmDelete(<?php echo $invoice_id; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')" class="btn-danger">Delete Invoice</a>
            <?php else: ?>
            <a href="javascript:void(0)" onclick="requestDelete(<?php echo $invoice_id; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')" class="btn-danger">Request Deletion</a>
            <?php endif; ?>
        </div>
    </form>
    
    <script>
    function markItemDelete(btn) {
        var row = btn.closest('.item-row');
        var flag = row.querySelector('.delete-flag');
        if (flag.value === '1') {
            // Undo mark
            flag.value = '0';
            row.style.opacity = '1';
            row.style.background = '';
            btn.textContent = 'Mark for Removal';
            btn.style.background = '';
        } else {
            // Mark for deletion
            flag.value = '1';
            row.style.opacity = '0.45';
            row.style.background = '#ffe0e0';
            btn.textContent = '↩ Undo Remove';
            btn.style.background = '#999';
        }
    }

    function markPurchaseDelete(btn) {
        var row = btn.closest('.purchase-row');
        var flag = row.querySelector('.delete-flag');
        if (flag.value === '1') {
            flag.value = '0';
            row.style.opacity = '1';
            row.style.background = '';
            btn.textContent = 'Mark for Removal';
            btn.style.background = '';
        } else {
            flag.value = '1';
            row.style.opacity = '0.45';
            row.style.background = '#ffe0e0';
            btn.textContent = '↩ Undo Remove';
            btn.style.background = '#999';
        }
    }
    </script>
    <?php
}

function includeViewInvoice() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Invoice ID not specified!</div>';
        return;
    }
    
    $invoice_id = intval($_GET['id']);
    $useNonGST  = isset($_GET['db']) && $_GET['db'] === 'nongst' && canViewNonGST();
    $targetDb   = $useNonGST ? getNonGSTDb() : $db;
    $invoice    = getInvoiceData($invoice_id, $targetDb);
    $db_source  = $useNonGST ? 'nongst' : 'gst';
    
    if (!$invoice) {
        echo '<div class="message error">Invoice not found!</div>';
        return;
    }

    // Check if invoice is locked (signed)
    $sigData    = getInvoiceSignatureData($invoice_id, $db_source);
    $isLocked   = $sigData && $sigData['locked'];
    $sealAffixed = $sigData && $sigData['seal_affixed'];
    $sigAffixed  = $sigData && $sigData['sig_affixed'];
    $isNonGST   = $useNonGST;

    // Get current user signature + seal paths
    $userStmt   = $db->prepare("SELECT signature_path, sign_passcode FROM users WHERE id=:id");
    $userStmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $curUser    = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $sealPath   = getSetting('seal_path', '');
    $sigPath    = $curUser['signature_path'] ?? '';
    $hasPasscode = !empty($curUser['sign_passcode']);

    // Render the invoice HTML
    displayInvoiceHTML($invoice, $isNonGST);

    // Seal/Signature Overlay System - Full Rewrite
    // Check pending seal request for this invoice
    $sealReqStmt = $db->prepare("SELECT sr.*, u.username as req_user FROM seal_requests sr LEFT JOIN users u ON sr.requested_by=u.id WHERE sr.invoice_id=:iid AND sr.db_source=:src AND sr.status='pending'");
    $sealReqStmt->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $sealReqStmt->bindValue(':src', $db_source, SQLITE3_TEXT);
    $sealRequest = $sealReqStmt->execute()->fetchArray(SQLITE3_ASSOC);
    ?>

    <!-- Permanently affixed overlays: positioned relative to invoice container via stored page-% coords -->
    <?php if ($sealAffixed && ($sigData['seal_composite_path'] ?? '') && file_exists($sigData['seal_composite_path'] ?? '')): ?>
    <style>
    #invoicePreview { position: relative; }
    .affixed-seal-abs { position: absolute; pointer-events: none; z-index: 200; opacity: 0.88; }
    </style>
    <script>
    (function() {
        function placeAffixed() {
            var box = document.getElementById('invoicePreview');
            if (!box) return;
            var bw = box.offsetWidth, bh = box.offsetHeight;
            var sealEl = document.getElementById('affixedSealImg');
            if (sealEl) {
                var px = parseFloat(sealEl.dataset.px) || 0;
                var py = parseFloat(sealEl.dataset.py) || 0;
                var pw = parseFloat(sealEl.dataset.pw) || 15;
                sealEl.style.left  = (px / 100 * bw) + 'px';
                sealEl.style.top   = (py / 100 * bh) + 'px';
                sealEl.style.width = (pw / 100 * bw) + 'px';
            }
            var sigEl = document.getElementById('affixedSigImg');
            if (sigEl) {
                var px2 = parseFloat(sigEl.dataset.px) || 0;
                var py2 = parseFloat(sigEl.dataset.py) || 0;
                var pw2 = parseFloat(sigEl.dataset.pw) || 20;
                sigEl.style.left  = (px2 / 100 * bw) + 'px';
                sigEl.style.top   = (py2 / 100 * bh) + 'px';
                sigEl.style.width = (pw2 / 100 * bw) + 'px';
            }
        }
        document.addEventListener('DOMContentLoaded', placeAffixed);
        window.addEventListener('resize', placeAffixed);
    })();
    </script>
    <img id="affixedSealImg" class="affixed-seal-abs"
         src="<?php echo htmlspecialchars($sigData['seal_composite_path']); ?>"
         data-px="<?php echo floatval($sigData['seal_page_x'] ?? $sigData['seal_x']); ?>"
         data-py="<?php echo floatval($sigData['seal_page_y'] ?? $sigData['seal_y']); ?>"
         data-pw="<?php echo floatval($sigData['seal_page_w'] ?? 15); ?>"
         style="position:absolute;pointer-events:none;z-index:200;opacity:0.88;"
         alt="Seal">
    <?php endif; ?>
    <?php
    $affSigPath = $sigData['sig_composite_path'] ?? ($sigData['signed_by_sig_path'] ?? '');
    if ($sigAffixed && $affSigPath && file_exists($affSigPath)): ?>
    <img id="affixedSigImg" class="affixed-seal-abs"
         src="<?php echo htmlspecialchars($affSigPath); ?>"
         data-px="<?php echo floatval($sigData['sig_page_x'] ?? $sigData['signature_x']); ?>"
         data-py="<?php echo floatval($sigData['sig_page_y'] ?? $sigData['signature_y']); ?>"
         data-pw="<?php echo floatval($sigData['sig_page_w'] ?? 20); ?>"
         style="position:absolute;pointer-events:none;z-index:200;opacity:0.88;"
         alt="Signature">
    <?php endif; ?>

    <!-- Seal & Signature Panel -->
    <div id="signatureSystem" style="margin-top:16px;background:linear-gradient(135deg,#f8f9fa,#fff);border:1px solid #e0e0e0;border-radius:10px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <h4 style="margin:0;color:#2c3e50;font-size:15px;">🔏 Seal &amp; Signature</h4>
            <?php if ($isLocked): ?>
            <span style="background:#d4edda;color:#155724;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;">✅ Locked &amp; Signed<?php if ($sigData['username']): ?> by <?php echo htmlspecialchars($sigData['username']); ?><?php endif; ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$hasPasscode): ?>
        <div class="message warning" style="font-size:12px;">⚠️ Set a 4-digit signing passcode in your <a href="?page=profile">Profile</a> to affix signatures.</div>
        <?php endif; ?>

        <!-- Status row -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <?php if ($sealAffixed): ?>
            <span style="background:#e8f5e9;color:#2e7d32;padding:4px 10px;border-radius:6px;font-size:12px;">🔏 Seal affixed <?php echo date('d-m-Y H:i', strtotime($sigData['seal_affixed_at'])); ?></span>
            <?php endif; ?>
            <?php if ($sigAffixed): ?>
            <span style="background:#e3f2fd;color:#1565c0;padding:4px 10px;border-radius:6px;font-size:12px;">✍️ Signed <?php echo date('d-m-Y H:i', strtotime($sigData['sig_affixed_at'])); ?></span>
            <?php endif; ?>
            <?php if ($sealRequest): ?>
            <span style="background:#fff3cd;color:#856404;padding:4px 10px;border-radius:6px;font-size:12px;">⏳ Seal requested by <?php echo htmlspecialchars($sealRequest['req_user']); ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$isLocked): ?>
        <!-- Action Buttons -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;" id="affixButtons">
            <?php if ($sealPath && file_exists($sealPath) && isAdmin()): ?>
            <button onclick="startAffixNew('seal')" class="btn-warning" id="btnAffix_seal" style="font-size:13px;padding:8px 14px;">🔏 Place Seal</button>
            <?php endif; ?>
            <?php if ($sigPath && file_exists($sigPath) && $hasPasscode): ?>
            <button onclick="startAffixNew('signature')" class="btn-secondary" id="btnAffix_sig" style="font-size:13px;padding:8px 14px;">✍️ Place Signature</button>
            <?php endif; ?>
            <?php if (!isAdmin() && $sigAffixed && !$sealAffixed && !$sealRequest && $sealPath && file_exists($sealPath)): ?>
            <button onclick="showSealRequestModal()" style="background:#8e44ad;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-size:13px;">📨 Request Seal</button>
            <?php endif; ?>
            <?php if ($sealRequest && isAdmin()): ?>
            <button onclick="showApproveModal(<?php echo $sealRequest['id']; ?>)" style="background:#27ae60;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-size:13px;">✅ Approve Seal Request</button>
            <button onclick="showRejectModal(<?php echo $sealRequest['id']; ?>)" style="background:#e74c3c;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-size:13px;">❌ Reject</button>
            <?php endif; ?>
            <button onclick="cancelAffixNew()" id="btnCancel" style="display:none;background:#95a5a6;color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-size:13px;">✕ Cancel</button>
        </div>

        <!-- Crop/Resize controls (visible during placement) -->
        <div id="affixControls" style="display:none;background:#f0f7ff;border:1px solid #cce0ff;border-radius:8px;padding:14px;margin-bottom:10px;">
            <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:#2c3e50;">🎛️ Adjust Size, Crop &amp; Position</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Width</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="sizeW" min="30" max="400" value="90" oninput="updateOverlaySize()" style="flex:1;">
                        <span id="sizeWVal" style="width:32px;text-align:right;">90</span>px
                    </div>
                </label>
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Height</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="sizeH" min="20" max="400" value="90" oninput="updateOverlaySize()" style="flex:1;">
                        <span id="sizeHVal" style="width:32px;text-align:right;">90</span>px
                    </div>
                </label>
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Opacity</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="opacitySlider" min="20" max="100" value="85" oninput="updateOverlayOpacity()" style="flex:1;">
                        <span id="opacityVal" style="width:32px;text-align:right;">85</span>%
                    </div>
                </label>
                <label style="font-size:12px;display:flex;align-items:flex-end;gap:6px;padding-bottom:2px;">
                    <input type="checkbox" id="lockAspect" checked>
                    <span>Lock aspect ratio</span>
                </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;" id="cropControls">
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Crop Left %</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="cropLeft" min="0" max="49" value="0" oninput="updateCrop()" style="flex:1;">
                        <span id="cropLeftVal" style="width:28px;text-align:right;">0</span>%
                    </div>
                </label>
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Crop Right %</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="cropRight" min="0" max="49" value="0" oninput="updateCrop()" style="flex:1;">
                        <span id="cropRightVal" style="width:28px;text-align:right;">0</span>%
                    </div>
                </label>
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Crop Top %</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="cropTop" min="0" max="49" value="0" oninput="updateCrop()" style="flex:1;">
                        <span id="cropTopVal" style="width:28px;text-align:right;">0</span>%
                    </div>
                </label>
                <label style="font-size:12px;">
                    <div style="margin-bottom:4px;font-weight:600;">Crop Bottom %</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <input type="range" id="cropBottom" min="0" max="49" value="0" oninput="updateCrop()" style="flex:1;">
                        <span id="cropBottomVal" style="width:28px;text-align:right;">0</span>%
                    </div>
                </label>
            </div>
            <div style="font-size:11px;color:#2980b9;background:#e8f4fd;border-radius:4px;padding:6px 10px;">
                💡 Drag to position · Sliders to resize/crop · <strong>Double-click (or tap &amp; hold on mobile) to affix</strong>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Seal Request Modal -->
    <div id="sealRequestModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-width:94vw;box-shadow:0 8px 32px rgba(0,0,0,0.2);">
            <h3 style="margin-bottom:14px;">📨 Request Seal Approval</h3>
            <p style="font-size:13px;color:#555;margin-bottom:12px;">Your signature is affixed. Request admin to approve &amp; place the official seal.</p>
            <textarea id="sealReqNote" rows="3" placeholder="Add a note for admin (optional)..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:13px;"></textarea>
            <div style="display:flex;gap:10px;margin-top:14px;">
                <button onclick="submitSealRequest()" style="background:#8e44ad;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;flex:1;">Send Request</button>
                <button onclick="document.getElementById('sealRequestModal').style.display='none'" style="background:#95a5a6;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Approve Seal Request Modal -->
    <div id="approveSealModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-width:94vw;">
            <h3 style="margin-bottom:12px;">✅ Approve Seal Request</h3>
            <form method="POST">
                <input type="hidden" name="action" value="approve_seal_request">
                <input type="hidden" name="request_id" id="approveReqId">
                <textarea name="admin_note" rows="2" placeholder="Admin note (optional)..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:13px;margin-bottom:10px;"></textarea>
                <div style="display:flex;gap:10px;">
                    <button type="submit" style="background:#27ae60;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;flex:1;">Approve &amp; Place Seal</button>
                    <button type="button" onclick="document.getElementById('approveSealModal').style.display='none'" style="background:#95a5a6;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Seal Request Modal -->
    <div id="rejectSealModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-width:94vw;">
            <h3 style="margin-bottom:12px;">❌ Reject Seal Request</h3>
            <form method="POST">
                <input type="hidden" name="action" value="reject_seal_request">
                <input type="hidden" name="request_id" id="rejectReqId">
                <textarea name="admin_note" rows="2" placeholder="Reason for rejection..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:13px;margin-bottom:10px;" required></textarea>
                <div style="display:flex;gap:10px;">
                    <button type="submit" style="background:#e74c3c;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;flex:1;">Reject</button>
                    <button type="button" onclick="document.getElementById('rejectSealModal').style.display='none'" style="background:#95a5a6;color:#fff;border:none;padding:10px 18px;border-radius:4px;cursor:pointer;font-size:13px;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var activeType = null;
        var dragging   = false;
        var overlayEl  = null;
        var dragOffX   = 0, dragOffY = 0;
        var aspectRatio = 1;
        var origNatW = 1, origNatH = 1;
        // Crop state (0-49 %)
        var crop = {l:0,r:0,t:0,b:0};

        function getInvoiceBox() {
            return document.getElementById('invoicePreview') ||
                   document.querySelector('.invoice-preview') ||
                   document.body;
        }

        window.startAffixNew = function(type) {
            activeType = type;
            document.getElementById('affixControls').style.display = 'block';
            document.getElementById('btnCancel').style.display = 'inline-block';
            ['btnAffix_seal','btnAffix_sig'].forEach(function(id){var b=document.getElementById(id);if(b)b.style.display='none';});

            var src = type === 'seal'
                ? '<?php echo addslashes($sealPath); ?>'
                : '<?php echo addslashes($sigPath); ?>';
            var initW = (type === 'seal') ? 100 : 160;
            var initH = (type === 'seal') ? 100 : 60;

            // Reset crop sliders
            ['cropLeft','cropRight','cropTop','cropBottom'].forEach(function(id){
                document.getElementById(id).value = 0;
                document.getElementById(id+'Val').textContent = '0';
            });
            crop = {l:0,r:0,t:0,b:0};

            document.getElementById('sizeW').value = initW;
            document.getElementById('sizeWVal').textContent = initW;
            document.getElementById('sizeH').value = initH;
            document.getElementById('sizeHVal').textContent = initH;
            document.getElementById('opacitySlider').value = 85;
            document.getElementById('opacityVal').textContent = '85';

            var img = document.createElement('img');
            img.id  = 'liveOverlay';
            img.src = src;
            img.draggable = false;
            img.style.cssText = 'position:fixed;top:220px;left:50%;transform:translateX(-50%);width:'+initW+'px;height:'+initH+'px;object-fit:cover;object-position:center;opacity:.85;cursor:move;z-index:9999;border:2px dashed #3498db;border-radius:4px;user-select:none;touch-action:none;box-shadow:0 2px 12px rgba(0,0,0,.2);';
            img.title = 'Drag to position. Double-click (or tap-hold on mobile) to affix.';

            img.onload = function() {
                origNatW = img.naturalWidth || initW;
                origNatH = img.naturalHeight || initH;
                aspectRatio = origNatW / origNatH;
                if (document.getElementById('lockAspect').checked) {
                    var h = Math.round(initW / aspectRatio);
                    img.style.height = h + 'px';
                    document.getElementById('sizeH').value = h;
                    document.getElementById('sizeHVal').textContent = h;
                }
            };

            // Mouse drag
            img.addEventListener('mousedown', function(e) {
                dragging = true;
                dragOffX = e.clientX - img.getBoundingClientRect().left;
                dragOffY = e.clientY - img.getBoundingClientRect().top;
                e.preventDefault();
            });
            img.addEventListener('dblclick', function() { doAffix(img); });

            // Touch events
            var touchSX, touchSY, touchIL, touchIT, touchHold;
            img.addEventListener('touchstart', function(e) {
                var t = e.touches[0];
                var r = img.getBoundingClientRect();
                touchSX=t.clientX; touchSY=t.clientY;
                touchIL=r.left; touchIT=r.top;
                touchHold = setTimeout(function(){ doAffix(img); }, 800);
                e.preventDefault();
            },{passive:false});
            img.addEventListener('touchmove', function(e) {
                clearTimeout(touchHold);
                var t = e.touches[0];
                img.style.transform = 'none';
                img.style.left = (touchIL + t.clientX - touchSX) + 'px';
                img.style.top  = (touchIT + t.clientY - touchSY) + 'px';
                e.preventDefault();
            },{passive:false});
            img.addEventListener('touchend', function(){ clearTimeout(touchHold); });

            document.body.appendChild(img);
            overlayEl = img;
        };

        document.addEventListener('mousemove', function(e) {
            if (!dragging || !overlayEl) return;
            overlayEl.style.transform = 'none';
            overlayEl.style.left = (e.clientX - dragOffX) + 'px';
            overlayEl.style.top  = (e.clientY - dragOffY) + 'px';
        });
        document.addEventListener('mouseup', function() { dragging = false; });

        window.updateOverlaySize = function() {
            if (!overlayEl) return;
            var w = parseInt(document.getElementById('sizeW').value);
            document.getElementById('sizeWVal').textContent = w;
            overlayEl.style.width = w + 'px';
            if (document.getElementById('lockAspect').checked && aspectRatio > 0) {
                var h = Math.round(w / aspectRatio);
                document.getElementById('sizeH').value = h;
                document.getElementById('sizeHVal').textContent = h;
                overlayEl.style.height = h + 'px';
            } else {
                overlayEl.style.height = document.getElementById('sizeH').value + 'px';
                document.getElementById('sizeHVal').textContent = document.getElementById('sizeH').value;
            }
            updateCrop();
        };

        // Allow manual H change too
        document.getElementById('sizeH').addEventListener('input', function() {
            if (!overlayEl) return;
            var h = parseInt(this.value);
            document.getElementById('sizeHVal').textContent = h;
            overlayEl.style.height = h + 'px';
            if (document.getElementById('lockAspect').checked && aspectRatio > 0) {
                var w = Math.round(h * aspectRatio);
                document.getElementById('sizeW').value = w;
                document.getElementById('sizeWVal').textContent = w;
                overlayEl.style.width = w + 'px';
            }
            updateCrop();
        });

        window.updateOverlayOpacity = function() {
            var o = document.getElementById('opacitySlider').value;
            document.getElementById('opacityVal').textContent = o;
            if (overlayEl) overlayEl.style.opacity = o/100;
        };

        window.updateCrop = function() {
            crop.l = parseInt(document.getElementById('cropLeft').value) || 0;
            crop.r = parseInt(document.getElementById('cropRight').value) || 0;
            crop.t = parseInt(document.getElementById('cropTop').value) || 0;
            crop.b = parseInt(document.getElementById('cropBottom').value) || 0;
            ['cropLeft','cropRight','cropTop','cropBottom'].forEach(function(id) {
                document.getElementById(id+'Val').textContent = document.getElementById(id).value;
            });
            if (!overlayEl) return;
            // Use clip-path to visually crop
            overlayEl.style.clipPath = 'inset('+crop.t+'% '+crop.r+'% '+crop.b+'% '+crop.l+'%)';
        };

        // ── Visual number pad for passcode entry (mobile-first) ──
        function showPasscodeModal(onConfirm) {
            var existing = document.getElementById('passcodeModal');
            if (existing) existing.remove();

            var modal = document.createElement('div');
            modal.id = 'passcodeModal';
            modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:99999;display:flex;align-items:flex-end;justify-content:center;padding-bottom:0;';
            modal.innerHTML = '<div style="background:#fff;border-radius:24px 24px 0 0;width:100%;max-width:380px;padding:24px 20px 36px;box-shadow:0 -8px 40px rgba(0,0,0,.2);animation:slideUpModal .25s ease;">'
                + '<style>@keyframes slideUpModal{from{transform:translateY(100%);opacity:0}to{transform:translateY(0);opacity:1}}'
                + '.np-btn{width:72px;height:72px;border-radius:50%;border:none;font-size:22px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-direction:column;transition:all .15s;-webkit-tap-highlight-color:transparent;user-select:none;}'
                + '.np-btn:active{transform:scale(.92);}'
                + '.np-digit{background:#f3f4f6;color:#111;}'
                + '.np-digit:hover{background:#e5e7eb;}'
                + '.np-del{background:#fee2e2;color:#dc2626;}'
                + '.np-confirm{background:linear-gradient(135deg,#4f46e5,#06b6d4);color:#fff;}'
                + '.np-confirm:disabled{background:#d1d5db;color:#9ca3af;cursor:default;}'
                + '.np-sub{font-size:10px;color:#aaa;font-weight:400;margin-top:2px;}'
                + '</style>'
                + '<div style="text-align:center;margin-bottom:20px;">'
                + '<div style="font-weight:700;font-size:17px;color:#1f2937;margin-bottom:6px;">🔐 Enter Passcode</div>'
                + '<div style="font-size:13px;color:#6b7280;" id="npTypeLabel">Affix ' + (activeType || 'signature') + '</div>'
                + '</div>'
                // PIN dots
                + '<div style="display:flex;justify-content:center;gap:14px;margin-bottom:28px;" id="npDots">'
                + '<div class="np-dot" style="width:18px;height:18px;border-radius:50%;border:2px solid #d1d5db;background:#fff;transition:all .2s;"></div>'
                + '<div class="np-dot" style="width:18px;height:18px;border-radius:50%;border:2px solid #d1d5db;background:#fff;transition:all .2s;"></div>'
                + '<div class="np-dot" style="width:18px;height:18px;border-radius:50%;border:2px solid #d1d5db;background:#fff;transition:all .2s;"></div>'
                + '<div class="np-dot" style="width:18px;height:18px;border-radius:50%;border:2px solid #d1d5db;background:#fff;transition:all .2s;"></div>'
                + '</div>'
                // Number pad grid
                + '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;place-items:center;">'
                + [1,2,3,4,5,6,7,8,9].map(function(n){
                    return '<button class="np-btn np-digit" onclick="npPress('+n+')" type="button">'+n+'</button>';
                  }).join('')
                + '<button class="np-btn np-del" onclick="npDel()" type="button">⌫</button>'
                + '<button class="np-btn np-digit" onclick="npPress(0)" type="button">0</button>'
                + '<button class="np-btn np-confirm" id="npConfirmBtn" onclick="npConfirm()" type="button" disabled>✓<span class="np-sub">Confirm</span></button>'
                + '</div>'
                // Cancel
                + '<button onclick="npCancel()" type="button" style="display:block;width:100%;margin-top:20px;background:none;border:none;color:#9ca3af;font-size:14px;cursor:pointer;padding:8px;">Cancel</button>'
                + '</div>';

            document.body.appendChild(modal);

            var pin = '';
            window.npPress = function(d) {
                if (pin.length >= 4) return;
                pin += String(d);
                npUpdateDots();
                if (pin.length === 4) {
                    document.getElementById('npConfirmBtn').disabled = false;
                }
            };
            window.npDel = function() {
                pin = pin.slice(0, -1);
                document.getElementById('npConfirmBtn').disabled = true;
                npUpdateDots();
            };
            window.npConfirm = function() {
                if (pin.length < 4) return;
                var p = pin;
                npCancel();
                onConfirm(p);
            };
            window.npCancel = function() {
                var m = document.getElementById('passcodeModal');
                if (m) m.remove();
            };
            function npUpdateDots() {
                var dots = document.querySelectorAll('#passcodeModal .np-dot');
                dots.forEach(function(dot, i) {
                    if (i < pin.length) {
                        dot.style.background = '#4f46e5';
                        dot.style.borderColor = '#4f46e5';
                        dot.style.transform = 'scale(1.15)';
                    } else {
                        dot.style.background = '#fff';
                        dot.style.borderColor = '#d1d5db';
                        dot.style.transform = 'scale(1)';
                    }
                });
            }
            // Close on backdrop click
            modal.addEventListener('click', function(e){ if (e.target === modal) npCancel(); });
        }

        function doAffix(img) {
            var isMobile = window.matchMedia('(max-width: 768px), (pointer: coarse)').matches;

            function submitAffix(passcode) {
                if (!passcode) return;
                var rect = img.getBoundingClientRect();
                var absX = rect.left + window.scrollX;
                var absY = rect.top  + window.scrollY;
                var absW = rect.width;
                var absH = rect.height;

                var invBox = getInvoiceBox();
                var ibr = invBox.getBoundingClientRect();
                var ibScrollTop = invBox === document.body ? window.scrollY : 0;
                var pgX = ((rect.left - ibr.left) / ibr.width * 100).toFixed(3);
                var pgY = ((rect.top + window.scrollY - ibr.top - ibScrollTop) / invBox.offsetHeight * 100).toFixed(3);
                var pgW = (rect.width / ibr.width * 100).toFixed(3);

                fetch('?', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: [
                        'action=affix_seal_signature',
                        'invoice_id=<?php echo $invoice_id; ?>',
                        'db_source=<?php echo $db_source; ?>',
                        'affix_type=' + activeType,
                        'passcode=' + encodeURIComponent(passcode),
                        'abs_x=' + absX.toFixed(0),
                        'abs_y=' + absY.toFixed(0),
                        'abs_w=' + absW.toFixed(0),
                        'abs_h=' + absH.toFixed(0),
                        'pos_x=' + pgX, 'pos_y=' + pgY, 'pos_w=' + pgW,
                        'crop_l='+crop.l,'crop_r='+crop.r,'crop_t='+crop.t,'crop_b='+crop.b
                    ].join('&')
                })
                .then(function(r){return r.json();})
                .then(function(res) {
                    if (res.success) {
                        cancelAffixNew();
                        showToast(res.message, 'success');
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        showToast('Error: ' + res.error, 'error');
                    }
                })
                .catch(function(){ showToast('Network error.', 'error'); });
            }

            if (isMobile) {
                showPasscodeModal(submitAffix);
            } else {
                var passcode = prompt('Enter your 4-digit signing passcode to affix ' + activeType + ':');
                submitAffix(passcode);
            }
        }

        window.cancelAffixNew = function() {
            activeType = null;
            if (overlayEl) { overlayEl.remove(); overlayEl = null; }
            document.getElementById('affixControls').style.display = 'none';
            document.getElementById('btnCancel').style.display = 'none';
            ['btnAffix_seal','btnAffix_sig'].forEach(function(id){var b=document.getElementById(id);if(b)b.style.display='inline-block';});
        };

        window.showSealRequestModal = function() {
            document.getElementById('sealRequestModal').style.display = 'flex';
        };
        window.showApproveModal = function(id) {
            document.getElementById('approveReqId').value = id;
            document.getElementById('approveSealModal').style.display = 'flex';
        };
        window.showRejectModal = function(id) {
            document.getElementById('rejectReqId').value = id;
            document.getElementById('rejectSealModal').style.display = 'flex';
        };
        window.submitSealRequest = function() {
            var note = document.getElementById('sealReqNote').value;
            fetch('?', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'action=request_seal&invoice_id=<?php echo $invoice_id; ?>&db_source=<?php echo $db_source; ?>&request_note='+encodeURIComponent(note)
            })
            .then(function(r){return r.json();})
            .then(function(res){
                document.getElementById('sealRequestModal').style.display='none';
                showToast(res.message || res.error, res.success?'success':'error');
                if (res.success) setTimeout(function(){location.reload();},1000);
            });
        };

        function showToast(msg, type) {
            var t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:'+(type==='success'?'#27ae60':'#e74c3c')+';color:#fff;padding:12px 22px;border-radius:24px;font-size:14px;font-weight:600;z-index:99999;box-shadow:0 4px 14px rgba(0,0,0,0.2);transition:opacity .5s;';
            document.body.appendChild(t);
            setTimeout(function(){ t.style.opacity='0'; setTimeout(function(){t.remove();},500); }, 2500);
        }
    })();
    </script>

    <?php
    if (isset($_SESSION['whatsapp_url'])): ?>
    <script>window.open('<?php echo $_SESSION['whatsapp_url']; ?>', '_blank');</script>
    <?php unset($_SESSION['whatsapp_url']); ?>
    <?php endif;
}

function includeSettings() {
    if (!isAdmin()) {
        echo '<div class="message error">Only admin can access settings!</div>';
        return;
    }
    ?>
    <h2>System Settings</h2>
    
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="row">
            <div class="form-group">
                <label>Company Name:</label>
                <input type="text" name="company_name" value="<?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?>">
            </div>
            
            <div class="form-group">
                <label>Currency Symbol:</label>
                <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars(getSetting('currency_symbol', '₹')); ?>" maxlength="5">
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Company GST Number:</label>
                <input type="text" name="company_gst_number" value="<?php echo htmlspecialchars(getSetting('company_gst_number', '')); ?>" placeholder="e.g. 23ABCDE1234F1Z5" maxlength="15">
                <small>Displayed on GST invoices only. Leave blank to hide.</small>
            </div>
            <div class="form-group">
                <label>Default GST Rate (%):</label>
                <input type="number" name="default_gst_rate" value="<?php echo htmlspecialchars(getSetting('default_gst_rate', '18')); ?>" min="0" max="100" step="0.01">
                <small>Pre-filled in the invoice form. Can be edited per invoice.</small>
            </div>
        </div>
        
        <div class="form-group">
            <label>Office Address:</label>
            <textarea name="office_address" rows="3"><?php echo htmlspecialchars(getSetting('office_address', '2nd Floor, Utopia Tower, Near College Chowk Flyover, Rewa (M.P.)')); ?></textarea>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Office Phone:</label>
                <input type="text" name="office_phone" value="<?php echo htmlspecialchars(getSetting('office_phone', '07662-455311, 9329578335')); ?>">
            </div>
            
            <div class="form-group">
                <label>Company Email:</label>
                <input type="email" name="company_email" value="<?php echo htmlspecialchars(getSetting('company_email', 'care@hidk.in')); ?>">
            </div>
            
            <div class="form-group">
                <label>Company Website:</label>
                <input type="url" name="company_website" value="<?php echo htmlspecialchars(getSetting('company_website', 'https://hidk.in/')); ?>">
            </div>
        </div>
        
        <h3>Payment Settings</h3>
        <div class="row">
            <div class="form-group">
                <label>💳 GST Invoice UPI ID:</label>
                <input type="text" name="payment_upi_id" value="<?php echo htmlspecialchars(getSetting('payment_upi_id', '')); ?>" placeholder="e.g. business@upi">
                <small>Used for QR code on <strong>GST invoices</strong>. Format: upi_id@bank</small>
            </div>
            
            <div class="form-group">
                <label>💳 Non-GST Invoice UPI ID:</label>
                <input type="text" name="payment_upi_id_nongst" value="<?php echo htmlspecialchars(getSetting('payment_upi_id_nongst', '')); ?>" placeholder="e.g. personal@upi or leave blank to use GST UPI">
                <small>Used for QR code on <strong>Non-GST invoices</strong>. Leave blank to use GST UPI ID as fallback.</small>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Payment Methods (comma separated):</label>
                <input type="text" name="payment_methods" value="<?php echo htmlspecialchars(getSetting('payment_methods', 'Cash,UPI,Bank Transfer,Card,Cheque')); ?>">
                <small>Separate with commas: Cash,UPI,Bank Transfer,Card,Cheque</small>
            </div>
        </div>
        
        <div class="form-group">
            <label>Payment Note:</label>
            <textarea name="payment_note" rows="3"><?php echo htmlspecialchars(getSetting('payment_note', 'After making payment online, please share transaction screenshot on 9329578335 or contact on 07662-455311, 9329578335.')); ?></textarea>
        </div>
        
        <div class="form-group">
            <label>Reply-To Email:</label>
            <input type="email" name="reply_to_email" value="<?php echo htmlspecialchars(getSetting('reply_to_email', 'care@hidk.in')); ?>">
            <small>Email address that replies should be sent to</small>
        </div>
        
        <div class="form-group">
            <label>WhatsApp Message Template:</label>
            <textarea name="whatsapp_message" rows="3"><?php echo htmlspecialchars(getSetting('whatsapp_message', 'Dear customer, your invoice {invoice_number} for {amount} is ready. View at: {invoice_url}')); ?></textarea>
            <small>Available variables: {invoice_number}, {amount}, {invoice_url}, {customer_name}, {date}, {balance}</small>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Enable Automatic Backups:</label>
                <select name="enable_backups">
                    <option value="1" <?php echo getSetting('enable_backups') === '1' ? 'selected' : ''; ?>>Yes</option>
                    <option value="0" <?php echo getSetting('enable_backups') === '0' ? 'selected' : ''; ?>>No</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Backup Retention (days):</label>
                <input type="number" name="backup_retention_days" value="<?php echo htmlspecialchars(getSetting('backup_retention_days', '30')); ?>" min="1" max="365">
            </div>
        </div>
        
        <h3>Email Settings (SMTP)</h3>
        <div class="row">
            <div class="form-group">
                <label>SMTP Host:</label>
                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars(getSetting('smtp_host')); ?>">
            </div>
            
            <div class="form-group">
                <label>SMTP Port:</label>
                <input type="text" name="smtp_port" value="<?php echo htmlspecialchars(getSetting('smtp_port', '587')); ?>">
            </div>
            
            <div class="form-group">
                <label>SMTP Encryption:</label>
                <select name="smtp_encryption">
                    <option value="tls" <?php echo getSetting('smtp_encryption') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                    <option value="ssl" <?php echo getSetting('smtp_encryption') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    <option value="" <?php echo empty(getSetting('smtp_encryption')) ? 'selected' : ''; ?>>None</option>
                </select>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>SMTP Username:</label>
                <input type="text" name="smtp_username" value="<?php echo htmlspecialchars(getSetting('smtp_username')); ?>">
            </div>
            
            <div class="form-group">
                <label>SMTP Password:</label>
                <input type="password" name="smtp_password" value="<?php echo htmlspecialchars(getSetting('smtp_password')); ?>">
            </div>
        </div>
        
        <?php if (isDefaultAdmin()): ?>
        <div class="row" style="margin-top: 30px;">
            <div class="form-group">
                <label>Next GST Invoice Serial (3-digit Hex):</label>
                <input type="text" name="next_invoice_serial" value="<?php echo htmlspecialchars(getSetting('next_invoice_serial', '001')); ?>" style="background: #f5f5f5; font-family: monospace;" maxlength="3">
                <small>Format: INV26[A-L][001-FFF] e.g. INV26C001. Resets to 001 on 1st of each month.</small>
            </div>
            <div class="form-group">
                <label>Next Non-GST Invoice Serial (4-digit Hex):</label>
                <input type="text" name="next_nongst_serial" value="<?php echo htmlspecialchars(getSetting('next_nongst_serial', 'A065')); ?>" style="background: #f5f5f5; font-family: monospace;" maxlength="4">
                <small>Format: INV26[01-12][A065-FFFF] e.g. INV2603A065. Resets to A065 on 1st of each month.</small>
            </div>
        </div>
        <?php else: ?>
        <div class="row" style="margin-top: 30px;">
            <div class="form-group">
                <label>Next GST Invoice Serial:</label>
                <input type="text" value="<?php echo htmlspecialchars(getSetting('next_invoice_serial', '001')); ?>" style="background: #f5f5f5; font-family: monospace;" readonly>
                <small>Only default admin can modify this setting</small>
            </div>
            <div class="form-group">
                <label>Next Non-GST Invoice Serial:</label>
                <input type="text" value="<?php echo htmlspecialchars(getSetting('next_nongst_serial', 'A065')); ?>" style="background: #f5f5f5; font-family: monospace;" readonly>
                <small>Only default admin can modify this setting</small>
            </div>
        </div>
        <?php endif; ?>

        <h3 style="margin-top:24px;">🎓 Academy Settings</h3>
        <div class="row">
            <div class="form-group">
                <label>Academy / Institute Name:</label>
                <input type="text" name="academy_name" value="<?php echo htmlspecialchars(getSetting('academy_name', 'Skill Training Academy')); ?>">
            </div>
            <div class="form-group">
                <label>Academy Phone:</label>
                <input type="text" name="academy_phone" value="<?php echo htmlspecialchars(getSetting('academy_phone', '')); ?>">
            </div>
            <div class="form-group">
                <label>Academy Email:</label>
                <input type="email" name="academy_email" value="<?php echo htmlspecialchars(getSetting('academy_email', '')); ?>">
            </div>
        </div>
        <div class="row">
            <div class="form-group">
                <label>Academy Address:</label>
                <textarea name="academy_address" rows="2"><?php echo htmlspecialchars(getSetting('academy_address', '')); ?></textarea>
            </div>
            <div class="form-group">
                <label>Enrollment ID Prefix:</label>
                <input type="text" name="enrollment_prefix" value="<?php echo htmlspecialchars(getSetting('enrollment_prefix', 'ENR')); ?>" maxlength="6" placeholder="e.g. ENR, STA, ABC">
                <small>Prefix for auto-generated enrollment IDs (e.g. ENR2024010001)</small>
            </div>
        </div>

        <div class="action-buttons">
            <button type="submit">Save Settings</button>
        </div>
    </form>
    
        <h3>Upload Media</h3>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="action" id="uploadAction" value="upload_logo">
        <input type="file" name="logo" accept="image/*" id="logoInput" style="display: none;">
        <input type="file" name="qr_code" accept="image/*" id="qrInput" style="display: none;">
        <input type="file" name="qr_code_nongst" accept="image/*" id="qrNonGSTInput" style="display: none;">
        
        <div class="row">
            <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                <label>Company Logo:</label>
                <div onclick="document.getElementById('logoInput').click()" style="cursor: pointer; border: 2px dashed #ddd; padding: 20px; text-align: center; border-radius: 5px; height: 250px; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: border-color 0.3s; flex-grow: 1;" 
                     onmouseover="this.style.borderColor='#3498db';" 
                     onmouseout="this.style.borderColor='#ddd';"
                     id="logoUploadArea">
                    <?php if (getSetting('logo_path')): ?>
                        <img src="<?php echo getSetting('logo_path'); ?>" id="logoPreview" style="max-width: 180px; max-height: 150px; margin-bottom: 10px; object-fit: contain;"><br>
                        <span style="color: #3498db; font-size: 13px;">Click to change logo</span>
                    <?php else: ?>
                        <div style="color: #666; font-size: 40px; margin-bottom: 10px;">📷</div>
                        <span style="color: #666; font-size: 14px;">Click to upload logo</span>
                        <small style="color: #999; margin-top: 5px;">JPG, PNG, GIF, SVG, WebP<br>Max 5MB</small>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                <label>📗 GST Invoice — Static QR Code (Optional):</label>
                <div onclick="document.getElementById('qrInput').click()" style="cursor: pointer; border: 2px dashed #27ae60; padding: 20px; text-align: center; border-radius: 5px; height: 250px; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: border-color 0.3s; flex-grow: 1;"
                     onmouseover="this.style.borderColor='#1e8449';" 
                     onmouseout="this.style.borderColor='#27ae60';"
                     id="qrUploadArea">
                    <?php if (getSetting('qr_path')): ?>
                        <img src="<?php echo getSetting('qr_path'); ?>" id="qrPreview" style="max-width: 180px; max-height: 150px; margin-bottom: 10px; object-fit: contain;"><br>
                        <span style="color: #27ae60; font-size: 13px;">Click to change GST QR code</span>
                    <?php else: ?>
                        <div style="color: #27ae60; font-size: 40px; margin-bottom: 10px;">📱</div>
                        <span style="color: #666; font-size: 14px;">Click to upload GST QR code</span>
                        <small style="color: #999; margin-top: 5px;">JPG, PNG, GIF, SVG, WebP<br>Max 2MB<br>Used on GST invoices</small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group" style="flex: 1; display: flex; flex-direction: column;">
                <label>📙 Non-GST Invoice — Static QR Code (Optional):</label>
                <div onclick="document.getElementById('qrNonGSTInput').click()" style="cursor: pointer; border: 2px dashed #e67e22; padding: 20px; text-align: center; border-radius: 5px; height: 250px; display: flex; flex-direction: column; align-items: center; justify-content: center; transition: border-color 0.3s; flex-grow: 1;"
                     onmouseover="this.style.borderColor='#ca6f1e';" 
                     onmouseout="this.style.borderColor='#e67e22';"
                     id="qrNonGSTUploadArea">
                    <?php if (getSetting('qr_path_nongst')): ?>
                        <img src="<?php echo getSetting('qr_path_nongst'); ?>" id="qrNonGSTPreview" style="max-width: 180px; max-height: 150px; margin-bottom: 10px; object-fit: contain;"><br>
                        <span style="color: #e67e22; font-size: 13px;">Click to change Non-GST QR code</span>
                    <?php else: ?>
                        <div style="color: #e67e22; font-size: 40px; margin-bottom: 10px;">📱</div>
                        <span style="color: #666; font-size: 14px;">Click to upload Non-GST QR code</span>
                        <small style="color: #999; margin-top: 5px;">JPG, PNG, GIF, SVG, WebP<br>Max 2MB<br>Used on Non-GST invoices</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="action-buttons" id="uploadButtons" style="display: none;">
            <button type="submit" id="uploadButton">Upload</button>
            <button type="button" onclick="cancelUpload()" class="btn-secondary">Cancel</button>
        </div>
    </form>

    <!-- Seal Upload (separate form) -->
    <div style="margin-top:20px;padding:20px;background:#f8f9fa;border-radius:6px;border:1px solid #dde;">
        <h3 style="margin-bottom:12px;">🔏 Office Seal Upload</h3>
        <p style="font-size:13px;color:#666;margin-bottom:12px;">Upload the office seal image. Only admin can affix it to invoices using a 4-digit passcode.</p>
        <form method="POST" enctype="multipart/form-data" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="upload_seal">
            <?php $sealPath = getSetting('seal_path',''); ?>
            <?php if ($sealPath && file_exists($sealPath)): ?>
            <img src="<?php echo htmlspecialchars($sealPath); ?>" style="max-width:80px;max-height:80px;border:2px dashed #e74c3c;padding:4px;border-radius:4px;">
            <?php endif; ?>
            <div>
                <input type="file" name="seal_image" accept=".jpg,.jpeg,.png,.gif,.webp,.svg" required>
                <small style="display:block;color:#888;margin-top:4px;">PNG/WebP with transparent background recommended. Max 2MB.</small>
            </div>
            <button type="submit" class="btn-warning">Upload Seal</button>
        </form>
    </div>
    
    <script>
        let currentUploadType = '';
        
        document.getElementById('logoInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                currentUploadType = 'logo';
                document.getElementById('uploadAction').value = 'upload_logo';
                document.getElementById('uploadButton').textContent = 'Upload Logo';
                previewImage(this.files[0], 'logoPreview', 'logoUploadArea', 'logo');
                document.getElementById('uploadButtons').style.display = 'flex';
            }
        });
        
        document.getElementById('qrInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                currentUploadType = 'qr';
                document.getElementById('uploadAction').value = 'upload_qr';
                document.getElementById('uploadButton').textContent = 'Upload GST QR Code';
                previewImage(this.files[0], 'qrPreview', 'qrUploadArea', 'GST QR code');
                document.getElementById('uploadButtons').style.display = 'flex';
            }
        });

        document.getElementById('qrNonGSTInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                currentUploadType = 'qr_nongst';
                document.getElementById('uploadAction').value = 'upload_qr_nongst';
                document.getElementById('uploadButton').textContent = 'Upload Non-GST QR Code';
                previewImage(this.files[0], 'qrNonGSTPreview', 'qrNonGSTUploadArea', 'Non-GST QR code');
                document.getElementById('uploadButtons').style.display = 'flex';
            }
        });
        
        function previewImage(file, previewId, containerId, label) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const container = document.getElementById(containerId);
                let preview = document.getElementById(previewId);
                
                if (!preview) {
                    preview = document.createElement('img');
                    preview.id = previewId;
                    preview.style.maxWidth = '180px';
                    preview.style.maxHeight = '150px';
                    preview.style.objectFit = 'contain';
                    preview.style.marginBottom = '10px';
                    container.innerHTML = '';
                    container.appendChild(preview);
                }
                
                preview.src = e.target.result;
                
                const text = document.createElement('span');
                text.style.fontSize = '13px';
                text.textContent = 'Click to change ' + label;
                container.appendChild(text);
            };
            reader.readAsDataURL(file);
        }
        
        function cancelUpload() {
            document.getElementById('logoInput').value = '';
            document.getElementById('qrInput').value = '';
            document.getElementById('qrNonGSTInput').value = '';
            document.getElementById('uploadButtons').style.display = 'none';
            currentUploadType = '';
        }
    </script>
    <?php
}

function includeInvoiceTemplates() {
    if (!isAdmin()) {
        echo '<div class="message error">Only admin can manage templates!</div>';
        return;
    }
    
    $templates = getAllTemplates();
    $current_template = getSetting('invoice_template', 'default');
    $default_template = null;
    
    foreach ($templates as $template) {
        if ($template['is_default']) {
            $default_template = $template;
            break;
        }
    }
    ?>
    
    <h2>Invoice Templates</h2>
    
    <div style="display: flex; gap: 20px; margin-bottom: 30px;">
        <div style="flex: 1;">
            <h3>Available Templates</h3>
            
            <?php if (empty($templates)): ?>
            <div class="message info">No templates found. Create your first template!</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Template Name</th>
                        <th>Default</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $template): 
                        $is_current = ($template['template_name'] === $current_template);
                        $is_default = ($template['is_default'] == 1);
                    ?>
                    <tr <?php if ($is_current) echo 'style="background-color: #f0f7ff;"'; ?>>
                        <td>
                            <?php echo htmlspecialchars($template['template_name']); ?>
                            <?php if ($is_current): ?>
                            <span class="user-badge admin" style="background: #3498db;">Current</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_default): ?>
                            <span class="user-badge admin">✓ Default</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d-m-Y', strtotime($template['created_at'])); ?></td>
                        <td class="actions-cell">
                            <a href="javascript:void(0)" onclick="loadTemplateForEdit(<?php echo $template['id']; ?>)" class="action-btn edit-btn">Edit</a>
                            <?php if (!$is_default): ?>
                            <a href="javascript:void(0)" onclick="setDefaultTemplate(<?php echo $template['id']; ?>)" class="action-btn view-btn">Set Default</a>
                            <a href="javascript:void(0)" onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['template_name']); ?>')" class="action-btn delete-btn">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        
        <div style="flex: 1;">
            <h3>Template Preview</h3>
            <div id="templatePreview" style="background: white; padding: 20px; border-radius: 5px; border: 1px solid #ddd; min-height: 200px;">
                <?php if ($default_template): 
                    $template_data = json_decode($default_template['template_data'], true);
                ?>
                <h4><?php echo htmlspecialchars($default_template['template_name']); ?> (Default)</h4>
                <div style="font-size: 12px; color: #666; margin-bottom: 15px;">
                    Last updated: <?php echo date('d-m-Y H:i', strtotime($default_template['created_at'])); ?>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; font-size: 13px;">
                    <div><strong>Sections Enabled:</strong></div>
                    <div>
                        <?php
                        $enabled_sections = [];
                        if ($template_data['header']['show_logo']) $enabled_sections[] = 'Header';
                        if ($template_data['customer']['show_customer_section']) $enabled_sections[] = 'Customer';
                        if ($template_data['items']['show_items_table']) $enabled_sections[] = 'Items';
                        if ($template_data['payment']['show_qr_code']) $enabled_sections[] = 'Payment';
                        if ($template_data['footer']['show_signature']) $enabled_sections[] = 'Footer';
                        echo implode(', ', $enabled_sections);
                        ?>
                    </div>
                    
                    <div><strong>Primary Color:</strong></div>
                    <div>
                        <span style="display: inline-block; width: 15px; height: 15px; background: <?php echo $template_data['styling']['primary_color']; ?>; border: 1px solid #ddd; vertical-align: middle; margin-right: 5px;"></span>
                        <?php echo $template_data['styling']['primary_color']; ?>
                    </div>
                    
                    <div><strong>QR Code Size:</strong></div>
                    <div><?php echo $template_data['payment']['qr_size']; ?>px</div>
                    
                    <div><strong>Font Family:</strong></div>
                    <div><?php echo $template_data['styling']['font_family']; ?></div>
                </div>
                
                <div style="margin-top: 15px;">
                    <a href="javascript:void(0)" onclick="loadTemplateForEdit(<?php echo $default_template['id']; ?>)" class="btn-warning">Edit This Template</a>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    No default template found. Create or select a template.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div id="templateEditor" style="display: none; margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 5px;">
        <h3>Template Editor</h3>
        <form id="templateForm" method="POST">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="template_id" id="templateId">
            
            <div class="row">
                <div class="form-group">
                    <label>Template Name: *</label>
                    <input type="text" name="template_name" id="templateName" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Template Configuration (JSON): *</label>
                <textarea name="template_data" id="templateData" rows="20" required style="font-family: monospace; font-size: 12px;"></textarea>
                <small>Edit the JSON configuration below. Make sure it's valid JSON.</small>
            </div>
            
            <div class="action-buttons">
                <button type="submit">Save Template</button>
                <button type="button" onclick="hideEditor()" class="btn-secondary">Cancel</button>
                <button type="button" onclick="loadDefaultTemplate()" class="btn-warning">Load Default Structure</button>
            </div>
        </form>
    </div>
    
    <div style="margin-top: 20px;">
        <button type="button" onclick="createNewTemplate()" class="btn">Create New Template</button>
    </div>
    
    <form id="setDefaultForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="set_default_template">
        <input type="hidden" name="template_id" id="defaultTemplateId">
    </form>
    
    <form id="deleteTemplateForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_template">
        <input type="hidden" name="template_id" id="deleteTemplateId">
    </form>
    
    <script>
        function loadTemplateForEdit(templateId) {
            fetch('?ajax=get_template&id=' + templateId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('templateEditor').style.display = 'block';
                        document.getElementById('templateId').value = data.template.id;
                        document.getElementById('templateName').value = data.template.template_name;
                        
                        const templateData = JSON.parse(data.template.template_data);
                        document.getElementById('templateData').value = JSON.stringify(templateData, null, 2);
                        
                        document.getElementById('templateEditor').scrollIntoView({ behavior: 'smooth' });
                    } else {
                        alert('Error loading template: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading template');
                });
        }
        
        function createNewTemplate() {
            document.getElementById('templateEditor').style.display = 'block';
            document.getElementById('templateId').value = '';
            document.getElementById('templateName').value = 'new_template_' + Date.now();
            loadDefaultTemplate();
            document.getElementById('templateEditor').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideEditor() {
            document.getElementById('templateEditor').style.display = 'none';
        }
        
        function loadDefaultTemplate() {
            const defaultTemplate = {
                "header": {
                    "show_logo": true,
                    "show_company_name": true,
                    "show_company_address": true,
                    "show_company_contact": true,
                    "show_invoice_number": true,
                    "show_invoice_date": true,
                    "show_payment_status": true,
                    "logo_position": "left",
                    "company_info_position": "center"
                },
                "customer": {
                    "show_customer_section": true,
                    "show_name": true,
                    "show_phone": true,
                    "show_email": true,
                    "show_address": true,
                    "customer_label": "BILL TO:"
                },
                "items": {
                    "show_items_table": true,
                    "show_sno": true,
                    "show_particulars": true,
                    "show_amount": true,
                    "show_service_charge": true,
                    "show_discount": true,
                    "show_remark": true,
                    "table_striped": true,
                    "show_totals_row": true
                },
                "purchases": {
                    "show_purchases_table": true,
                    "show_purchase_sno": true,
                    "show_purchase_particulars": true,
                    "show_purchase_qty": true,
                    "show_purchase_rate": true,
                    "show_purchase_amount": true,
                    "show_amount_received": true
                },
                "summary": {
                    "show_summary_section": true,
                    "show_service_charge": true,
                    "show_purchase_payable": true,
                    "show_total_payable": true,
                    "show_rounded_total": true,
                    "show_paid_amount": true,
                    "show_balance": true,
                    "summary_position": "right"
                },
                "payment": {
                    "show_qr_code": true,
                    "show_payment_button": true,
                    "show_payment_note": true,
                    "qr_size": "140",
                    "payment_button_text": "Pay Now"
                },
                "footer": {
                    "show_thankyou_note": true,
                    "show_contact_info": true,
                    "show_signature": true,
                    "thankyou_text": "Thank You for Your Business!",
                    "signature_text": "Authorized Signatory"
                },
                "styling": {
                    "primary_color": "#3498db",
                    "secondary_color": "#2c3e50",
                    "success_color": "#27ae60",
                    "warning_color": "#f39c12",
                    "danger_color": "#e74c3c",
                    "font_family": "Arial, sans-serif",
                    "font_size": "14px",
                    "border_radius": "4px",
                    "table_header_bg": "#f8f9fa",
                    "table_row_hover": "#f8f9fa"
                }
            };
            
            document.getElementById('templateData').value = JSON.stringify(defaultTemplate, null, 2);
        }
        
        function setDefaultTemplate(templateId) {
            if (confirm('Set this template as default?')) {
                document.getElementById('defaultTemplateId').value = templateId;
                document.getElementById('setDefaultForm').submit();
            }
        }
        
        function deleteTemplate(templateId, templateName) {
            if (confirm('Are you sure you want to delete template: ' + templateName + '?')) {
                document.getElementById('deleteTemplateId').value = templateId;
                document.getElementById('deleteTemplateForm').submit();
            }
        }
        
        document.getElementById('templateForm').addEventListener('submit', function(e) {
            try {
                JSON.parse(document.getElementById('templateData').value);
            } catch (error) {
                e.preventDefault();
                alert('Invalid JSON format: ' + error.message);
                return false;
            }
            return true;
        });
    </script>
    <?php
}

function includeProfile() {
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    ?>
    <h2>User Profile</h2>

    <?php if (isset($_SESSION['success'])): ?><div class="message success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="message error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="row">
        <div style="flex: 1;">
            <h3>Account Information</h3>
            <div class="form-group">
                <label>Username:</label>
                <input type="text" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="text" value="<?php echo htmlspecialchars($_SESSION['email'] ?? 'Not set'); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Role:</label>
                <input type="text" value="<?php echo ucfirst(getUserRole()); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Account Created:</label>
                <input type="text" value="<?php echo $user ? date('d-m-Y H:i', strtotime($user['created_at'])) : 'Unknown'; ?>" readonly>
            </div>
        </div>
        <div style="flex: 1;">
            <h3>Change Password</h3>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label>Current Password:</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label>New Password:</label>
                    <input type="password" name="new_password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm New Password:</label>
                    <input type="password" name="confirm_password" required minlength="6">
                </div>
                <div class="action-buttons">
                    <button type="submit">Change Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Signature Section -->
    <div style="margin-top:28px;background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;">
        <h3 style="margin-bottom:14px;">✍️ My Signature</h3>
        <div class="row">
            <div style="flex:1;">
                <p style="font-size:13px;color:#555;margin-bottom:12px;">Upload your personal signature image. You can affix it to invoices using your 4-digit signing passcode.</p>
                <?php if ($user['signature_path'] && file_exists($user['signature_path'])): ?>
                <div style="margin-bottom:12px;">
                    <label style="font-size:12px;color:#888;">Current signature:</label><br>
                    <img src="<?php echo htmlspecialchars($user['signature_path']); ?>" style="max-width:200px;max-height:70px;border:1px solid #ddd;padding:6px;background:#fff;border-radius:4px;">
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_user_signature">
                    <input type="file" name="signature_image" accept=".jpg,.jpeg,.png,.gif,.webp,.svg" required>
                    <small style="display:block;color:#888;margin:4px 0 8px;">PNG with transparent background recommended.</small>
                    <button type="submit" class="btn-secondary">Upload Signature</button>
                </form>
            </div>
            <div style="flex:1;">
                <h4 style="margin-bottom:12px;">🔑 Signing Passcode</h4>
                <p style="font-size:13px;color:#555;margin-bottom:10px;">
                    Set a 4-digit PIN to authenticate when affixing your signature or seal to invoices.
                    <?php echo $user['sign_passcode'] ? '<span style="color:#27ae60;font-size:12px;">✓ Passcode is set</span>' : '<span style="color:#e74c3c;font-size:12px;">⚠ No passcode set yet</span>'; ?>
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="set_sign_passcode">
                    <div class="form-group">
                        <label>New 4-digit Passcode:</label>
                        <input type="password" name="sign_passcode" maxlength="4" minlength="4" pattern="\d{4}" placeholder="••••" style="letter-spacing:6px;font-size:20px;width:100px;" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm Passcode:</label>
                        <input type="password" name="sign_passcode_confirm" maxlength="4" minlength="4" pattern="\d{4}" placeholder="••••" style="letter-spacing:6px;font-size:20px;width:100px;" required>
                    </div>
                    <button type="submit" class="btn-warning">Set Passcode</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}

// ============================================================
// SEAL & SIGNATURE SYSTEM
// ============================================================

function uploadSeal() {
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=settings'); exit(); }
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    if (isset($_FILES['seal_image']) && $_FILES['seal_image']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['seal_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
            $fn = 'seal_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['seal_image']['tmp_name'], 'uploads/' . $fn)) {
                $old = getSetting('seal_path');
                if ($old && file_exists($old)) @unlink($old);
                updateSetting('seal_path', 'uploads/' . $fn);
                $_SESSION['success'] = 'Seal uploaded.';
            }
        } else { $_SESSION['error'] = 'Invalid image type.'; }
    }
    header('Location: ?page=settings'); exit();
}

function uploadUserSignature() {
    global $db;
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
            $fn = 'sig_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['signature_image']['tmp_name'], 'uploads/' . $fn)) {
                // Delete old signature
                $old = $db->prepare("SELECT signature_path FROM users WHERE id=:id");
                $old->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
                $oldRow = $old->execute()->fetchArray(SQLITE3_ASSOC);
                if ($oldRow && $oldRow['signature_path'] && file_exists($oldRow['signature_path'])) @unlink($oldRow['signature_path']);
                $stmt = $db->prepare("UPDATE users SET signature_path=:p WHERE id=:id");
                $stmt->bindValue(':p', 'uploads/' . $fn, SQLITE3_TEXT);
                $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
                $stmt->execute();
                $_SESSION['success'] = 'Signature uploaded.';
            }
        } else { $_SESSION['error'] = 'Invalid image type.'; }
    }
    header('Location: ?page=profile'); exit();
}

function setSignPasscode() {
    global $db;
    $passcode = trim($_POST['sign_passcode'] ?? '');
    $confirm  = trim($_POST['sign_passcode_confirm'] ?? '');
    if (!preg_match('/^\d{4}$/', $passcode)) { $_SESSION['error'] = 'Passcode must be exactly 4 digits.'; header('Location: ?page=profile'); exit(); }
    if ($passcode !== $confirm) { $_SESSION['error'] = 'Passcodes do not match.'; header('Location: ?page=profile'); exit(); }
    $hashed = password_hash($passcode, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET sign_passcode=:p WHERE id=:id");
    $stmt->bindValue(':p', $hashed, SQLITE3_TEXT);
    $stmt->bindValue(':id', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Signing passcode updated.';
    header('Location: ?page=profile'); exit();
}

function affixSealSignature() {
    global $db;
    $passcode   = trim($_POST['passcode'] ?? '');
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $db_source  = ($_POST['db_source'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $type       = ($_POST['affix_type'] ?? '') === 'seal' ? 'seal' : 'signature';
    // Page-relative % positions for persistent rendering
    $px = floatval($_POST['pos_x'] ?? 0);   // % from left of invoice container
    $py = floatval($_POST['pos_y'] ?? 0);   // % from top of invoice container
    $pw = floatval($_POST['pos_w'] ?? 15);  // % width of invoice container
    // Absolute pixel positions (for legacy / initial placement reference)
    $ax = floatval($_POST['abs_x'] ?? 0);
    $ay = floatval($_POST['abs_y'] ?? 0);
    $aw = floatval($_POST['abs_w'] ?? 90);
    $ah = floatval($_POST['abs_h'] ?? 90);
    // Crop parameters (% of each edge)
    $cropL = intval($_POST['crop_l'] ?? 0);
    $cropR = intval($_POST['crop_r'] ?? 0);
    $cropT = intval($_POST['crop_t'] ?? 0);
    $cropB = intval($_POST['crop_b'] ?? 0);

    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT sign_passcode, role, signature_path FROM users WHERE id=:id");
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$user || !$user['sign_passcode'] || !password_verify($passcode, $user['sign_passcode'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid passcode.']); exit();
    }
    if ($type === 'seal' && $user['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Only admin can affix seal.']); exit();
    }

    // Determine which image file to use
    if ($type === 'seal') {
        $imgPath = getSetting('seal_path', '');
    } else {
        $imgPath = $user['signature_path'] ?? '';
    }
    if (!$imgPath || !file_exists($imgPath)) {
        echo json_encode(['success' => false, 'error' => 'Image file not found.']); exit();
    }

    // Create composite with crop applied
    $compositePath = createCompositeOverlay($imgPath, intval($aw), intval($ah), $cropL, $cropR, $cropT, $cropB);
    if (!$compositePath) {
        $ext = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
        $compositePath = 'uploads/' . $type . '_composite_' . $invoice_id . '_' . time() . '.' . $ext;
        copy($imgPath, $compositePath);
    }

    // Migration: ensure page_w column exists
    try { $db->exec("ALTER TABLE invoice_signatures ADD COLUMN seal_page_w REAL DEFAULT 15"); } catch(Exception $e){}
    try { $db->exec("ALTER TABLE invoice_signatures ADD COLUMN sig_page_w REAL DEFAULT 20"); } catch(Exception $e){}

    // Upsert invoice_signatures
    $existing = $db->prepare("SELECT id FROM invoice_signatures WHERE invoice_id=:iid AND db_source=:src");
    $existing->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $existing->bindValue(':src', $db_source, SQLITE3_TEXT);
    $row = $existing->execute()->fetchArray(SQLITE3_ASSOC);

    if ($type === 'seal') {
        if ($row) {
            $stmt = $db->prepare("UPDATE invoice_signatures SET seal_x=:ax,seal_y=:ay,seal_w=:aw,seal_h=:ah,seal_page_x=:px,seal_page_y=:py,seal_page_w=:pww,seal_composite_path=:cp,seal_affixed=1,seal_affixed_at=CURRENT_TIMESTAMP,signed_by=:uid WHERE invoice_id=:iid AND db_source=:src");
        } else {
            $stmt = $db->prepare("INSERT INTO invoice_signatures (invoice_id,db_source,seal_x,seal_y,seal_w,seal_h,seal_page_x,seal_page_y,seal_page_w,seal_composite_path,seal_affixed,seal_affixed_at,signed_by) VALUES (:iid,:src,:ax,:ay,:aw,:ah,:px,:py,:pww,:cp,1,CURRENT_TIMESTAMP,:uid)");
        }
        $stmt->bindValue(':cp', $compositePath, SQLITE3_TEXT);
        $stmt->bindValue(':px', $px, SQLITE3_FLOAT);
        $stmt->bindValue(':py', $py, SQLITE3_FLOAT);
        $stmt->bindValue(':pww',$pw, SQLITE3_FLOAT);
    } else {
        if ($row) {
            $stmt = $db->prepare("UPDATE invoice_signatures SET signature_x=:ax,signature_y=:ay,signature_w=:aw,signature_h=:ah,sig_page_x=:px,sig_page_y=:py,sig_page_w=:pww,sig_composite_path=:cp,sig_affixed=1,sig_affixed_at=CURRENT_TIMESTAMP,signed_by=:uid,locked=1,locked_at=CURRENT_TIMESTAMP WHERE invoice_id=:iid AND db_source=:src");
        } else {
            $stmt = $db->prepare("INSERT INTO invoice_signatures (invoice_id,db_source,signature_x,signature_y,signature_w,signature_h,sig_page_x,sig_page_y,sig_page_w,sig_composite_path,sig_affixed,sig_affixed_at,signed_by,locked,locked_at) VALUES (:iid,:src,:ax,:ay,:aw,:ah,:px,:py,:pww,:cp,1,CURRENT_TIMESTAMP,:uid,1,CURRENT_TIMESTAMP)");
        }
        $stmt->bindValue(':cp', $compositePath, SQLITE3_TEXT);
        $stmt->bindValue(':px', $px, SQLITE3_FLOAT);
        $stmt->bindValue(':py', $py, SQLITE3_FLOAT);
        $stmt->bindValue(':pww',$pw, SQLITE3_FLOAT);
    }
    $stmt->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':src', $db_source, SQLITE3_TEXT);
    $stmt->bindValue(':ax', $ax, SQLITE3_FLOAT);
    $stmt->bindValue(':ay', $ay, SQLITE3_FLOAT);
    $stmt->bindValue(':aw', $aw, SQLITE3_FLOAT);
    $stmt->bindValue(':ah', $ah, SQLITE3_FLOAT);
    $stmt->bindValue(':uid', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    logAction('AFFIX_' . strtoupper($type), "Affixed $type on invoice $invoice_id");
    echo json_encode([
        'success'  => true,
        'message'  => ucfirst($type) . ' affixed successfully.',
        'img_path' => $compositePath,
        'page_x'   => $px, 'page_y' => $py, 'page_w' => $pw
    ]);
    exit();
}

function createCompositeOverlay($srcPath, $w, $h, $cropL=0, $cropR=0, $cropT=0, $cropB=0) {
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    $ext     = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    $outPath = 'uploads/composite_' . md5($srcPath . $w . $h . $cropL . $cropR . $cropT . $cropB . time()) . '.png';
    $w = max(20, intval($w));
    $h = max(10, intval($h));

    $src = null;
    if ($ext === 'png'  && function_exists('imagecreatefrompng'))  $src = @imagecreatefrompng($srcPath);
    elseif (in_array($ext,['jpg','jpeg']) && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($srcPath);
    elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($srcPath);
    elseif ($ext === 'gif'  && function_exists('imagecreatefromgif'))  $src = @imagecreatefromgif($srcPath);
    if (!$src) return '';

    $srcW = imagesx($src); $srcH = imagesy($src);

    // Apply crop: compute source rect
    $cl = max(0, min(49, $cropL)); $cr = max(0, min(49, $cropR));
    $ct = max(0, min(49, $cropT)); $cb = max(0, min(49, $cropB));
    $srcX = intval($srcW * $cl / 100);
    $srcY = intval($srcH * $ct / 100);
    $srcCW = intval($srcW * (100 - $cl - $cr) / 100);
    $srcCH = intval($srcH * (100 - $ct - $cb) / 100);
    if ($srcCW < 1) $srcCW = 1;
    if ($srcCH < 1) $srcCH = 1;

    $dst = imagecreatetruecolor($w, $h);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $w, $h, $srcCW, $srcCH);
    imagedestroy($src);
    imagepng($dst, $outPath);
    imagedestroy($dst);
    return $outPath;
}

function requestSeal() {
    global $db;
    if (!isLoggedIn()) { echo json_encode(['success'=>false,'error'=>'Not logged in']); exit(); }
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $db_source  = ($_POST['db_source'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $note       = trim($_POST['request_note'] ?? '');

    // Don't allow admin to request (they can directly affix)
    if (isAdmin()) { echo json_encode(['success'=>false,'error'=>'Admin can affix directly.']); exit(); }

    // Check if already requested
    $chk = $db->prepare("SELECT id FROM seal_requests WHERE invoice_id=:iid AND db_source=:src AND status='pending'");
    $chk->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $chk->bindValue(':src', $db_source, SQLITE3_TEXT);
    if ($chk->execute()->fetchArray()) {
        echo json_encode(['success'=>false,'error'=>'Seal request already pending for this invoice.']); exit();
    }

    // Also verify they have a valid signed signature on this invoice
    $sigChk = $db->prepare("SELECT id FROM invoice_signatures WHERE invoice_id=:iid AND db_source=:src AND sig_affixed=1");
    $sigChk->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $sigChk->bindValue(':src', $db_source, SQLITE3_TEXT);
    if (!$sigChk->execute()->fetchArray()) {
        echo json_encode(['success'=>false,'error'=>'Please affix your signature first before requesting seal.']); exit();
    }

    $stmt = $db->prepare("INSERT INTO seal_requests (invoice_id, db_source, requested_by, request_note) VALUES (:iid, :src, :uid, :note)");
    $stmt->bindValue(':iid',  $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':src',  $db_source,  SQLITE3_TEXT);
    $stmt->bindValue(':uid',  $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
    $stmt->execute();
    logAction('REQUEST_SEAL', "Seal requested for invoice $invoice_id by " . ($_SESSION['username'] ?? ''));
    echo json_encode(['success'=>true, 'message'=>'Seal request submitted to admin.']);
    exit();
}

function approveSealRequest() {
    global $db;
    if (!isAdmin()) { $_SESSION['error']='Admin only.'; header('Location: ?page=pending_deletions'); exit(); }
    $req_id     = intval($_POST['request_id'] ?? 0);
    $req        = $db->prepare("SELECT * FROM seal_requests WHERE id=:id");
    $req->bindValue(':id', $req_id, SQLITE3_INTEGER);
    $request    = $req->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$request) { $_SESSION['error']='Request not found.'; header('Location: ?page=pending_deletions'); exit(); }

    // Mark approved
    $upd = $db->prepare("UPDATE seal_requests SET status='approved', reviewed_by=:uid, reviewed_at=CURRENT_TIMESTAMP, admin_note=:note WHERE id=:id");
    $upd->bindValue(':uid',  $_SESSION['user_id'], SQLITE3_INTEGER);
    $upd->bindValue(':note', trim($_POST['admin_note'] ?? ''), SQLITE3_TEXT);
    $upd->bindValue(':id',   $req_id, SQLITE3_INTEGER);
    $upd->execute();
    $_SESSION['success'] = 'Seal request approved. Please now view the invoice to affix the seal.';
    header('Location: ?page=view_invoice&id=' . $request['invoice_id'] . ($request['db_source']==='nongst'?'&db=nongst':''));
    exit();
}

function rejectSealRequest() {
    global $db;
    if (!isAdmin()) { $_SESSION['error']='Admin only.'; header('Location: ?page=pending_deletions'); exit(); }
    $req_id = intval($_POST['request_id'] ?? 0);
    $upd = $db->prepare("UPDATE seal_requests SET status='rejected', reviewed_by=:uid, reviewed_at=CURRENT_TIMESTAMP, admin_note=:note WHERE id=:id");
    $upd->bindValue(':uid',  $_SESSION['user_id'], SQLITE3_INTEGER);
    $upd->bindValue(':note', trim($_POST['admin_note'] ?? ''), SQLITE3_TEXT);
    $upd->bindValue(':id',   $req_id, SQLITE3_INTEGER);
    $upd->execute();
    $_SESSION['success'] = 'Seal request rejected.';
    header('Location: ?page=pending_deletions'); exit();
}

function getPendingSealRequests() {
    global $db;
    if (!isAdmin()) return [];
    $result = $db->query("SELECT sr.*, u.username as requested_by_name, u.full_name, i.invoice_number
        FROM seal_requests sr
        LEFT JOIN users u ON sr.requested_by=u.id
        LEFT JOIN invoices i ON sr.invoice_id=i.id
        WHERE sr.status='pending'
        ORDER BY sr.created_at DESC");
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getInvoiceSignatureData($invoice_id, $db_source = 'gst') {
    global $db;
    $stmt = $db->prepare("SELECT s.*, u.signature_path as signed_by_sig_path, u.username FROM invoice_signatures s LEFT JOIN users u ON s.signed_by=u.id WHERE s.invoice_id=:iid AND s.db_source=:src");
    $stmt->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':src', $db_source, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

// ============================================================
// DB CSV EXPORT / IMPORT  +  PDF INVOICE EXPORT
// ============================================================

function getExportableTables($which = 'gst') {
    global $db;
    $useDb = ($which === 'nongst') ? getNonGSTDb() : $db;
    if (!$useDb) return [];
    $result = $useDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $tables[] = $row['name'];
    return $tables;
}

function exportTableCSV() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=export'); exit(); }

    $table    = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table_name'] ?? '');
    $which    = ($_POST['db_source'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $start    = $_POST['start_date'] ?? '';
    $end      = $_POST['end_date'] ?? '';
    $useDb    = ($which === 'nongst') ? getNonGSTDb() : $db;
    if (!$useDb || !$table) { $_SESSION['error'] = 'Invalid table.'; header('Location: ?page=export'); exit(); }

    // Map tables to their date columns for filtering
    $dateColMap = [
        'invoices'       => 'invoice_date',
        'payments'       => 'payment_date',
        'staff_expenses' => 'expense_date',
        'bookings'       => 'booking_date',
        'users'          => 'created_at',
        'user_logs'      => 'created_at',
    ];

    $query = "SELECT * FROM `$table` WHERE 1=1";
    $params = [];
    if (($start || $end) && isset($dateColMap[$table])) {
        $dc = $dateColMap[$table];
        if ($start) { $query .= " AND $dc >= :start"; $params[':start'] = $start; }
        if ($end)   { $query .= " AND $dc <= :end";   $params[':end']   = $end;   }
    }

    try {
        $stmt = $useDb->prepare($query);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v, SQLITE3_TEXT);
        $result = $stmt->execute();
    } catch (Exception $e) { $_SESSION['error'] = 'Export failed: ' . $e->getMessage(); header('Location: ?page=export'); exit(); }

    $filename = "{$which}_{$table}_" . date('Ymd') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=$filename");
    $out = fopen('php://output', 'w');
    $headers_written = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!$headers_written) { fputcsv($out, array_keys($row)); $headers_written = true; }
        fputcsv($out, $row);
    }
    if (!$headers_written) fputcsv($out, ['No data found']);
    fclose($out);
    exit();
}

function importTableCSV() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=export'); exit(); }

    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['import_table'] ?? '');
    $which = ($_POST['import_db_source'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $mode  = ($_POST['import_mode'] ?? 'append') === 'replace' ? 'replace' : 'append';
    $useDb = ($which === 'nongst') ? getNonGSTDb() : $db;

    if (!$useDb || !$table || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== 0) {
        $_SESSION['error'] = 'Upload error or invalid table.'; header('Location: ?page=export'); exit();
    }

    $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $headers = fgetcsv($file);
    if (!$headers) { $_SESSION['error'] = 'Empty CSV.'; header('Location: ?page=export'); exit(); }

    $safeHeaders = array_map(function($h) { return preg_replace('/[^a-zA-Z0-9_]/', '', $h); }, $headers);
    $placeholders = implode(',', array_map(function($h) { return ":$h"; }, $safeHeaders));
    $cols = implode(',', array_map(function($h) { return "`$h`"; }, $safeHeaders));

    if ($mode === 'replace') {
        try { $useDb->exec("DELETE FROM `$table`"); } catch (Exception $e) {}
    }

    $inserted = $skipped = 0;
    $useDb->exec('BEGIN TRANSACTION');
    try {
        while (($row = fgetcsv($file)) !== false) {
            if (count($row) !== count($safeHeaders)) { $skipped++; continue; }
            $stmt = $useDb->prepare("INSERT OR IGNORE INTO `$table` ($cols) VALUES ($placeholders)");
            foreach ($safeHeaders as $i => $h) $stmt->bindValue(":$h", $row[$i], SQLITE3_TEXT);
            $stmt->execute();
            $inserted++;
        }
        $useDb->exec('COMMIT');
    } catch (Exception $e) {
        $useDb->exec('ROLLBACK');
        $_SESSION['error'] = 'Import failed: ' . $e->getMessage();
        header('Location: ?page=export'); exit();
    }
    fclose($file);
    $_SESSION['success'] = "Imported $inserted rows into $table ($skipped skipped).";
    header('Location: ?page=export'); exit();
}

function exportInvoicesPDF() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=export'); exit(); }

    $start  = $_POST['pdf_start_date'] ?? '';
    $end    = $_POST['pdf_end_date'] ?? '';
    $which  = ($_POST['pdf_db_source'] ?? 'gst') === 'nongst' ? 'nongst' : 'gst';
    $useDb  = ($which === 'nongst') ? getNonGSTDb() : $db;
    if (!$useDb) { $_SESSION['error'] = 'DB error.'; header('Location: ?page=export'); exit(); }

    $query = "SELECT * FROM invoices WHERE 1=1";
    $params = [];
    if ($start) { $query .= " AND invoice_date >= :start"; $params[':start'] = $start; }
    if ($end)   { $query .= " AND invoice_date <= :end";   $params[':end']   = $end;   }
    $query .= " ORDER BY invoice_date ASC";

    $stmt = $useDb->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, SQLITE3_TEXT);
    $result = $stmt->execute();
    $invoices = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $inv = getInvoiceData($row['id'], $useDb);
        if ($inv) $invoices[] = $inv;
    }

    if (empty($invoices)) { $_SESSION['error'] = 'No invoices found in range.'; header('Location: ?page=export'); exit(); }

    $isNonGST  = ($which === 'nongst');
    $template  = getInvoiceTemplate();
    $cur       = getSetting('currency_symbol', '₹');
    $company   = getSetting('company_name', 'D K ASSOCIATES');
    $logo      = getSetting('logo_path', '');
    $gstNum    = getSetting('company_gst_number', '');
    $dateRange = ($start || $end) ? (($start ?: '–') . ' to ' . ($end ?: '–')) : 'All Dates';

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Invoice Export – <?php echo htmlspecialchars($company); ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size: 11px; color: #222; background: #fff; }
.page-break { page-break-after: always; break-after: page; }
.inv-page { padding: 12mm; max-width: 210mm; }
.inv-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #2c3e50; padding-bottom: 8px; margin-bottom: 8px; }
.inv-header .left { flex:1; }
.inv-header .right { text-align:right; font-size:10px; }
.company-name { font-size: 15px; font-weight: bold; color: #2c3e50; }
.inv-meta { font-size:10px; color:#555; }
.bill-row { display:flex; justify-content:space-between; margin:6px 0; font-size:10.5px; }
.bill-to { background:#f0f4f8; padding:6px 10px; border-radius:4px; border-left:3px solid #3498db; flex:1; margin-right:8px; }
.pay-summary { background:#f0f4f8; padding:6px 10px; border-radius:4px; text-align:right; min-width:130px; }
table.items { width:100%; border-collapse:collapse; margin:6px 0; font-size:10px; }
table.items th { background:#2c3e50; color:#fff; padding:4px 6px; text-align:left; }
table.items td { padding:3px 6px; border-bottom:1px solid #eee; }
table.items tfoot td { font-weight:bold; background:#f8f9fa; border-top:2px solid #ddd; }
.breakdown { background:#f8f9fa; padding:8px 10px; border-radius:4px; font-size:10px; margin:6px 0; }
.breakdown table { width:100%; }
.breakdown td { padding:2px 4px; }
.breakdown .total-row td { font-size:12px; font-weight:bold; border-top:2px solid #2c3e50; color:#2c3e50; padding-top:4px; }
.gst-row td { color:#555; }
.status-badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:9px; font-weight:bold; }
.paid { background:#d4edda; color:#155724; }
.unpaid { background:#f8d7da; color:#721c24; }
.partial_paid { background:#fff3cd; color:#856404; }
.report-header { text-align:center; margin-bottom:12px; padding:10px; background:#2c3e50; color:#fff; border-radius:4px; }
.report-header h2 { font-size:14px; }
.report-header p { font-size:10px; margin-top:3px; opacity:.8; }
@media print {
  body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .no-print { display: none !important; }
}
</style>
</head>
<body>
<!-- Print button -->
<div class="no-print" style="position:fixed;top:10px;right:10px;z-index:999;display:flex;gap:8px;">
    <button onclick="window.print()" style="padding:8px 18px;background:#2c3e50;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;">🖨️ Print / Save PDF</button>
    <button onclick="window.close()" style="padding:8px 14px;background:#e74c3c;color:#fff;border:none;border-radius:4px;cursor:pointer;">✕ Close</button>
</div>

<div style="padding:15px 15px 0;" class="no-print">
    <div class="report-header">
        <h2><?php echo htmlspecialchars($company); ?> — Invoice Export (<?php echo $isNonGST ? 'Non-GST' : 'GST'; ?>)</h2>
        <p>Period: <?php echo htmlspecialchars($dateRange); ?> | Generated: <?php echo date('d-m-Y H:i'); ?> | Total: <?php echo count($invoices); ?> invoices</p>
    </div>
</div>

<?php foreach ($invoices as $idx => $invoice):
    $totals   = $invoice['totals'];
    $hasGST   = ($totals['gst_amount'] > 0);
    $isLast   = ($idx === count($invoices) - 1);
?>
<div class="inv-page<?php echo !$isLast ? ' page-break' : ''; ?>">
    <!-- Header -->
    <div class="inv-header">
        <div class="left">
            <?php if ($logo && file_exists($logo)): ?>
            <img src="<?php echo $logo; ?>" style="max-height:38px;max-width:110px;margin-bottom:4px;"><br>
            <?php endif; ?>
            <div class="company-name"><?php echo htmlspecialchars($company); ?></div>
            <div class="inv-meta"><?php echo htmlspecialchars(getSetting('office_address','')); ?></div>
            <?php if (!$isNonGST && $gstNum): ?><div class="inv-meta"><strong>GSTIN:</strong> <?php echo htmlspecialchars($gstNum); ?></div><?php endif; ?>
        </div>
        <div class="right">
            <div style="font-size:13px;font-weight:bold;color:#2c3e50;">INVOICE</div>
            <div><strong>#</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
            <div><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($invoice['invoice_date'])); ?></div>
            <div><span class="status-badge <?php echo $invoice['payment_status']; ?>"><?php echo ucfirst(str_replace('_',' ',$invoice['payment_status'])); ?></span></div>
        </div>
    </div>

    <!-- Bill To + Summary -->
    <div class="bill-row">
        <div class="bill-to">
            <strong>BILL TO:</strong><br>
            <strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong><br>
            <?php if ($invoice['customer_phone']): ?><?php echo htmlspecialchars($invoice['customer_phone']); ?><br><?php endif; ?>
            <?php if ($invoice['customer_address']): ?><?php echo htmlspecialchars($invoice['customer_address']); ?><br><?php endif; ?>
            <?php $cg = $invoice['customer_gst_number'] ?? ''; if (!$isNonGST && $cg && strtoupper($cg) !== 'NA'): ?><strong>GSTIN:</strong> <?php echo htmlspecialchars($cg); ?><?php endif; ?>
        </div>
        <div class="pay-summary">
            <strong>Total:</strong> <?php echo $cur . ' ' . number_format($totals['rounded_total'], 2); ?><br>
            <strong>Paid:</strong> <span style="color:#27ae60;"><?php echo $cur . ' ' . number_format($totals['paid_amount'], 2); ?></span><br>
            <strong>Balance:</strong> <span style="color:#e74c3c;"><?php echo $cur . ' ' . number_format($totals['balance'], 2); ?></span>
        </div>
    </div>

    <!-- Items -->
    <?php if (!empty($invoice['parsed_items'])): ?>
    <table class="items">
        <thead><tr><th>#</th><th>Particulars</th><th style="text-align:right">Amount</th><th style="text-align:right">S.Charge</th><th style="text-align:right">Disc.</th><th>Remark</th></tr></thead>
        <tbody>
        <?php foreach ($invoice['parsed_items'] as $i => $item): ?>
        <tr>
            <td><?php echo $i+1; ?></td>
            <td><?php echo htmlspecialchars($item['particulars']); ?></td>
            <td style="text-align:right"><?php echo number_format($item['amount'],2); ?></td>
            <td style="text-align:right"><?php echo number_format($item['service_charge'],2); ?></td>
            <td style="text-align:right"><?php echo number_format($item['discount'],2); ?></td>
            <td><?php echo htmlspecialchars($item['remark']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td colspan="2" style="text-align:right">Totals:</td>
            <td style="text-align:right"><?php echo number_format($totals['amount_total'],2); ?></td>
            <td style="text-align:right"><?php echo number_format($totals['service_total'],2); ?></td>
            <td style="text-align:right"><?php echo number_format($totals['discount_total'],2); ?></td>
            <td></td>
        </tr></tfoot>
    </table>
    <?php endif; ?>

    <!-- GST Breakdown -->
    <div class="breakdown" style="max-width:260px;margin-left:auto;">
        <table>
            <?php if ($hasGST): ?>
            <tr class="gst-row"><td>Taxable Amount:</td><td style="text-align:right"><?php echo $cur . ' ' . number_format($totals['taxable_base'],2); ?></td></tr>
            <?php if ($totals['gst_rate'] > 0): $hr = $totals['gst_rate']/2; $ha = $totals['gst_amount']/2; ?>
            <tr class="gst-row"><td>CGST (<?php echo number_format($hr,1); ?>%):</td><td style="text-align:right"><?php echo $cur . ' ' . number_format($ha,2); ?></td></tr>
            <tr class="gst-row"><td>SGST (<?php echo number_format($hr,1); ?>%):</td><td style="text-align:right"><?php echo $cur . ' ' . number_format($ha,2); ?></td></tr>
            <?php else: ?>
            <tr class="gst-row"><td>GST:</td><td style="text-align:right"><?php echo $cur . ' ' . number_format($totals['gst_amount'],2); ?></td></tr>
            <?php endif; ?>
            <?php endif; ?>
            <tr class="total-row"><td><?php echo $hasGST ? 'Total (incl. GST):' : 'Total Payable:'; ?></td><td style="text-align:right"><?php echo $cur . ' ' . number_format($totals['total_payable'],2); ?></td></tr>
            <?php if ($totals['rounded_total'] != $totals['total_payable']): ?>
            <tr><td style="color:#666;font-style:italic">Rounded:</td><td style="text-align:right;color:#666;font-style:italic"><?php echo $cur . ' ' . number_format($totals['rounded_total'],2); ?></td></tr>
            <?php endif; ?>
            <?php if ($totals['paid_amount'] > 0): ?>
            <tr><td style="color:#27ae60">Paid:</td><td style="text-align:right;color:#27ae60"><?php echo $cur . ' ' . number_format($totals['paid_amount'],2); ?></td></tr>
            <?php endif; ?>
            <tr style="border-top:1px solid #ddd;"><td style="color:#e74c3c;font-weight:bold">Balance:</td><td style="text-align:right;color:#e74c3c;font-weight:bold"><?php echo $cur . ' ' . number_format($totals['balance'],2); ?></td></tr>
        </table>
    </div>
</div>
<?php endforeach; ?>
<script>setTimeout(function(){ window.print(); }, 600);</script>
</body></html>
<?php
    exit();
}

function exportInvoices() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = "Only admin can export!"; header('Location: ?page=dashboard'); exit(); }
    // Legacy CSV export redirects to new export page
    header('Location: ?page=export'); exit();
}

function includeExport() {
    if (!isAdmin()) { echo '<div class="message error">Admin only.</div>'; return; }
    global $db;
    $gstTables   = getExportableTables('gst');
    $nongstTables = getExportableTables('nongst');
    ?>
    <h2>📤 Database Export / Import</h2>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="message success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
    <div class="message error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- CSV Export -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:24px;">
        <h3 style="margin-bottom:14px;">📥 Export Table as CSV</h3>
        <form method="POST">
            <input type="hidden" name="action" value="export_table_csv">
            <div class="row">
                <div class="form-group">
                    <label>Database:</label>
                    <select name="db_source" id="exportDb" onchange="updateExportTables()">
                        <option value="gst">GST DB (invoices.db)</option>
                        <option value="nongst">Non-GST DB (nongst_invoices.db)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Table:</label>
                    <select name="table_name" id="exportTable">
                        <?php foreach ($gstTables as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Date (optional):</label>
                    <input type="date" name="start_date">
                </div>
                <div class="form-group">
                    <label>To Date (optional):</label>
                    <input type="date" name="end_date">
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit">⬇️ Download CSV</button>
            </div>
        </form>
    </div>

    <!-- CSV Import -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:24px;">
        <h3 style="margin-bottom:14px;">📤 Import CSV into Table</h3>
        <div style="background:#fff3cd;border:1px solid #ffc107;padding:10px 15px;border-radius:4px;margin-bottom:12px;font-size:13px;">
            ⚠️ <strong>Warning:</strong> Importing will add rows (or replace all rows in replace mode). CSV headers must match column names exactly.
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_table_csv">
            <div class="row">
                <div class="form-group">
                    <label>Database:</label>
                    <select name="import_db_source" id="importDb" onchange="updateImportTables()">
                        <option value="gst">GST DB (invoices.db)</option>
                        <option value="nongst">Non-GST DB (nongst_invoices.db)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Table:</label>
                    <select name="import_table" id="importTable">
                        <?php foreach ($gstTables as $t): ?>
                        <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Import Mode:</label>
                    <select name="import_mode">
                        <option value="append">Append (add rows, skip duplicates)</option>
                        <option value="replace">Replace (delete all then insert)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>CSV File:</label>
                    <input type="file" name="csv_file" accept=".csv" required>
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit" class="btn-warning" onclick="return confirm('Import data into database? This cannot be undone.')">📤 Import CSV</button>
            </div>
        </form>
    </div>

    <!-- PDF Export -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:24px;">
        <h3 style="margin-bottom:14px;">🖨️ Export Invoices as PDF</h3>
        <form method="POST" target="_blank">
            <input type="hidden" name="action" value="export_invoices_pdf">
            <div class="row">
                <div class="form-group">
                    <label>Database:</label>
                    <select name="pdf_db_source">
                        <option value="gst">GST Invoices</option>
                        <option value="nongst">Non-GST Invoices</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>From Date:</label>
                    <input type="date" name="pdf_start_date">
                </div>
                <div class="form-group">
                    <label>To Date:</label>
                    <input type="date" name="pdf_end_date">
                </div>
            </div>
            <small>Each invoice renders on its own page (condensed). Opens in new tab → use browser Print → Save as PDF.</small>
            <div class="action-buttons">
                <button type="submit">🖨️ Generate PDF</button>
            </div>
        </form>
    </div>

    <script>
    var gstTables   = <?php echo json_encode($gstTables); ?>;
    var nongstTables = <?php echo json_encode($nongstTables); ?>;

    function updateExportTables() {
        var db = document.getElementById('exportDb').value;
        var sel = document.getElementById('exportTable');
        sel.innerHTML = '';
        (db === 'nongst' ? nongstTables : gstTables).forEach(function(t) {
            var o = document.createElement('option');
            o.value = o.textContent = t;
            sel.appendChild(o);
        });
    }
    function updateImportTables() {
        var db = document.getElementById('importDb').value;
        var sel = document.getElementById('importTable');
        sel.innerHTML = '';
        (db === 'nongst' ? nongstTables : gstTables).forEach(function(t) {
            var o = document.createElement('option');
            o.value = o.textContent = t;
            sel.appendChild(o);
        });
    }
    </script>
    <?php
}

// ============================================================
// EXPENSES & PURCHASES (Manager + Admin)
// ============================================================

function addStaffExpense() {
    global $db;
    if (!in_array(getUserRole(), ['admin','manager','accountant'])) {
        $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=expenses'); exit();
    }
    $date     = $_POST['expense_date'] ?? date('Y-m-d');
    $cat      = trim($_POST['category'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $amount   = floatval($_POST['amount'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $receipt  = '';

    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf','webp'])) {
            $fn = 'receipt_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], 'uploads/' . $fn)) $receipt = 'uploads/' . $fn;
        }
    }

    $stmt = $db->prepare("INSERT INTO staff_expenses (expense_date, category, description, amount, receipt_path, submitted_by, notes) VALUES (:d,:c,:desc,:a,:r,:u,:n)");
    $stmt->bindValue(':d', $date, SQLITE3_TEXT);
    $stmt->bindValue(':c', $cat, SQLITE3_TEXT);
    $stmt->bindValue(':desc', $desc, SQLITE3_TEXT);
    $stmt->bindValue(':a', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':r', $receipt, SQLITE3_TEXT);
    $stmt->bindValue(':u', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':n', $notes, SQLITE3_TEXT);
    $stmt->execute();
    logAction('ADD_EXPENSE', "Added expense: $desc, ₹$amount");
    $_SESSION['success'] = 'Expense recorded.';
    header('Location: ?page=expenses'); exit();
}

function updateStaffExpense() {
    global $db;
    $id = intval($_POST['expense_id'] ?? 0);
    $row = $db->prepare("SELECT * FROM staff_expenses WHERE id = :id");
    $row->bindValue(':id', $id, SQLITE3_INTEGER);
    $expense = $row->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$expense) { $_SESSION['error'] = 'Not found.'; header('Location: ?page=expenses'); exit(); }
    if (!isAdmin() && !isManager() && $expense['submitted_by'] != $_SESSION['user_id']) {
        $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=expenses'); exit();
    }
    $receipt = $expense['receipt_path'];
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf','webp'])) {
            $fn = 'receipt_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], 'uploads/' . $fn)) $receipt = 'uploads/' . $fn;
        }
    }
    $stmt = $db->prepare("UPDATE staff_expenses SET expense_date=:d,category=:c,description=:desc,amount=:a,receipt_path=:r,notes=:n WHERE id=:id");
    $stmt->bindValue(':d', $_POST['expense_date'] ?? $expense['expense_date'], SQLITE3_TEXT);
    $stmt->bindValue(':c', trim($_POST['category'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':desc', trim($_POST['description'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':a', floatval($_POST['amount'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':r', $receipt, SQLITE3_TEXT);
    $stmt->bindValue(':n', trim($_POST['notes'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Expense updated.';
    header('Location: ?page=expenses'); exit();
}

function deleteStaffExpense() {
    global $db;
    $id = intval($_POST['expense_id'] ?? 0);
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=expenses'); exit(); }
    $stmt = $db->prepare("DELETE FROM staff_expenses WHERE id = :id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Expense deleted.';
    header('Location: ?page=expenses'); exit();
}

function approveExpense() {
    global $db;
    if (!isAdmin() && !isManager()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=expenses'); exit(); }
    $id     = intval($_POST['expense_id'] ?? 0);
    $status = ($_POST['new_status'] ?? '') === 'approved' ? 'approved' : 'rejected';
    $stmt   = $db->prepare("UPDATE staff_expenses SET status=:s, approved_by=:u WHERE id=:id");
    $stmt->bindValue(':s', $status, SQLITE3_TEXT);
    $stmt->bindValue(':u', $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Expense ' . $status . '.';
    header('Location: ?page=expenses'); exit();
}

function addPurchaseRecord() {
    global $db;
    if (!isAdmin() && !isManager()) { $_SESSION['error'] = 'Manager/Admin only.'; header('Location: ?page=expenses'); exit(); }
    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    if (!$invoice_id) { $_SESSION['error'] = 'Invoice ID required.'; header('Location: ?page=expenses'); exit(); }

    $receipt = '';
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    if (isset($_FILES['purchase_receipt']) && $_FILES['purchase_receipt']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['purchase_receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','pdf','webp'])) {
            $fn = 'purchase_receipt_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['purchase_receipt']['tmp_name'], 'uploads/' . $fn)) $receipt = 'uploads/' . $fn;
        }
    }

    $stmt = $db->prepare("INSERT INTO purchases (invoice_id, s_no, particulars, qty, rate, purchase_amount, amount_received, receipt_path) VALUES (:iid, (SELECT COALESCE(MAX(s_no),0)+1 FROM purchases WHERE invoice_id=:iid2), :p, :q, :r, :pa, :ar, :rp)");
    $stmt->bindValue(':iid', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':iid2', $invoice_id, SQLITE3_INTEGER);
    $stmt->bindValue(':p', trim($_POST['particulars'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':q', floatval($_POST['qty'] ?? 1), SQLITE3_FLOAT);
    $stmt->bindValue(':r', floatval($_POST['rate'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':pa', floatval($_POST['purchase_amount'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':ar', floatval($_POST['amount_received'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':rp', $receipt, SQLITE3_TEXT);
    $stmt->execute();
    logAction('ADD_PURCHASE', "Added purchase for invoice $invoice_id");
    $_SESSION['success'] = 'Purchase record added.';
    header('Location: ?page=view_invoice&id=' . $invoice_id); exit();
}

function includeExpenses() {
    global $db;
    $role = getUserRole();
    if (!in_array($role, ['admin','manager','accountant'])) {
        echo '<div class="message error">Access denied.</div>'; return;
    }
    $cur = getSetting('currency_symbol', '₹');

    // Expense list
    $allQuery = "SELECT e.*, u.username as submitted_by_name, a.username as approved_by_name FROM staff_expenses e LEFT JOIN users u ON e.submitted_by=u.id LEFT JOIN users a ON e.approved_by=a.id ORDER BY e.expense_date DESC";
    if (isAdmin() || isManager()) {
        $result = $db->query($allQuery);
    } else {
        $stmt = $db->prepare("SELECT e.*, u.username as submitted_by_name, null as approved_by_name FROM staff_expenses e LEFT JOIN users u ON e.submitted_by=u.id WHERE e.submitted_by=:uid ORDER BY e.expense_date DESC");
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $result = $stmt->execute();
    }
    $expenses = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $expenses[] = $row;

    $expenseCategories = ['Travel','Food','Stationery','Utilities','Equipment','Software','Misc'];
    ?>
    <h2>🧾 Expenses & Purchases</h2>

    <?php if (isset($_SESSION['success'])): ?><div class="message success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="message error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <!-- Record New Expense -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:22px;">
        <h3 style="margin-bottom:14px;">➕ Record Expense</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_staff_expense">
            <div class="row">
                <div class="form-group">
                    <label>Date: *</label>
                    <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Category: *</label>
                    <select name="category" required>
                        <?php foreach ($expenseCategories as $c): ?><option><?php echo $c; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Amount (<?php echo $cur; ?>): *</label>
                    <input type="number" name="amount" step="0.01" min="0" required>
                </div>
            </div>
            <div class="form-group">
                <label>Description: *</label>
                <input type="text" name="description" required placeholder="Brief description of expense">
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Notes:</label>
                    <input type="text" name="notes" placeholder="Optional notes">
                </div>
                <div class="form-group">
                    <label>Receipt (optional):</label>
                    <input type="file" name="receipt" accept=".jpg,.jpeg,.png,.pdf,.webp">
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit">Save Expense</button>
            </div>
        </form>
    </div>

    <!-- Manager: Quick Purchase Record -->
    <?php if (isAdmin() || isManager()): ?>
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:22px;">
        <h3 style="margin-bottom:14px;">🛒 Add Purchase to Invoice</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_purchase_record">
            <div class="row">
                <div class="form-group">
                    <label>Invoice ID: *</label>
                    <input type="number" name="invoice_id" min="1" required placeholder="Invoice ID number">
                </div>
                <div class="form-group">
                    <label>Particulars: *</label>
                    <input type="text" name="particulars" required>
                </div>
                <div class="form-group">
                    <label>Qty:</label>
                    <input type="number" name="qty" step="0.01" value="1" min="0">
                </div>
                <div class="form-group">
                    <label>Rate (<?php echo $cur; ?>):</label>
                    <input type="number" name="rate" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Purchase Amount (<?php echo $cur; ?>):</label>
                    <input type="number" name="purchase_amount" step="0.01" min="0" value="0">
                </div>
                <div class="form-group">
                    <label>Amount Received (<?php echo $cur; ?>):</label>
                    <input type="number" name="amount_received" step="0.01" min="0" value="0">
                </div>
                <div class="form-group">
                    <label>Purchase Receipt (optional):</label>
                    <input type="file" name="purchase_receipt" accept=".jpg,.jpeg,.png,.pdf,.webp">
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit">Add Purchase Record</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Expense List -->
    <h3 style="margin-bottom:10px;">All Expenses</h3>
    <?php
    $totalApproved = 0; $totalPending = 0;
    foreach ($expenses as $e) {
        if ($e['status'] === 'approved') $totalApproved += $e['amount'];
        if ($e['status'] === 'pending')  $totalPending  += $e['amount'];
    }
    ?>
    <div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
        <div style="background:#d4edda;padding:8px 16px;border-radius:5px;font-size:13px;"><strong>Approved:</strong> <?php echo $cur . ' ' . number_format($totalApproved, 2); ?></div>
        <div style="background:#fff3cd;padding:8px 16px;border-radius:5px;font-size:13px;"><strong>Pending:</strong> <?php echo $cur . ' ' . number_format($totalPending, 2); ?></div>
    </div>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Amount</th>
                <th>By</th>
                <th>Status</th>
                <th>Receipt</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($expenses)): ?>
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:24px;">No expenses recorded</td></tr>
        <?php else: foreach ($expenses as $exp): ?>
        <tr>
            <td><?php echo date('d-m-Y', strtotime($exp['expense_date'])); ?></td>
            <td><?php echo htmlspecialchars($exp['category']); ?></td>
            <td><?php echo htmlspecialchars($exp['description']); ?><?php if ($exp['notes']): ?><br><small style="color:#888;"><?php echo htmlspecialchars($exp['notes']); ?></small><?php endif; ?></td>
            <td><strong><?php echo $cur . ' ' . number_format($exp['amount'], 2); ?></strong></td>
            <td style="font-size:12px;"><?php echo htmlspecialchars($exp['submitted_by_name'] ?? '—'); ?></td>
            <td>
                <span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;background:<?php echo $exp['status']==='approved'?'#d4edda':($exp['status']==='rejected'?'#f8d7da':'#fff3cd'); ?>;color:<?php echo $exp['status']==='approved'?'#155724':($exp['status']==='rejected'?'#721c24':'#856404'); ?>;">
                    <?php echo ucfirst($exp['status']); ?>
                </span>
            </td>
            <td>
                <?php if ($exp['receipt_path'] && file_exists($exp['receipt_path'])): ?>
                <a href="<?php echo htmlspecialchars($exp['receipt_path']); ?>" target="_blank" style="font-size:12px;">📎 View</a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="actions-cell">
                <?php if ((isAdmin() || isManager()) && $exp['status'] === 'pending'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="approve_expense">
                    <input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>">
                    <input type="hidden" name="new_status" value="approved">
                    <button type="submit" class="action-btn view-btn" style="font-size:11px;padding:3px 8px;">✓ Approve</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="approve_expense">
                    <input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>">
                    <input type="hidden" name="new_status" value="rejected">
                    <button type="submit" class="action-btn delete-btn" style="font-size:11px;padding:3px 8px;">✗ Reject</button>
                </form>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this expense?')">
                    <input type="hidden" name="action" value="delete_staff_expense">
                    <input type="hidden" name="expense_id" value="<?php echo $exp['id']; ?>">
                    <button type="submit" class="action-btn delete-btn" style="font-size:11px;padding:3px 8px;">🗑</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}

function updateAcademyAccess() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=users'); exit(); }
    $uid = intval($_POST['user_id'] ?? 0);
    $val = intval($_POST['has_academy_access'] ?? 0);
    $stmt = $db->prepare("UPDATE users SET has_academy_access=:v WHERE id=:id");
    $stmt->bindValue(':v', $val, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $uid, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Academy access updated.';
    header('Location: ?page=users');
    exit();
}

// ============================================================
// ACADEMY MODULE - Courses, Enrollments, Payments, Reminders
// ============================================================

function generateEnrollmentId() {
    global $db;
    $prefix = getSetting('enrollment_prefix', 'ENR');
    $year   = date('Y');
    $month  = date('m');
    $stmt   = $db->prepare("SELECT COUNT(*) as cnt FROM academy_enrollments WHERE strftime('%Y%m', created_at) = :ym");
    $stmt->bindValue(':ym', $year.$month, SQLITE3_TEXT);
    $row    = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $seq    = str_pad(($row['cnt'] + 1), 4, '0', STR_PAD_LEFT);
    return $prefix . $year . $month . $seq;
}

function getAcademyCourses($activeOnly = false) {
    global $db;
    $q = "SELECT c.*, u.username as created_by_name FROM academy_courses c LEFT JOIN users u ON c.created_by=u.id";
    if ($activeOnly) $q .= " WHERE c.is_active=1";
    $q .= " ORDER BY c.course_name ASC";
    $result = $db->query($q);
    $courses = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $courses[] = $row;
    return $courses;
}

function getAcademyEnrollments($search = '', $status = '', $course_id = 0) {
    global $db;
    $q = "SELECT e.*, c.course_name, c.course_code, u.username as created_by_name
          FROM academy_enrollments e
          LEFT JOIN academy_courses c ON e.course_id=c.id
          LEFT JOIN users u ON e.created_by=u.id
          WHERE 1=1";
    $params = [];
    if ($search) {
        $q .= " AND (e.candidate_name LIKE :s OR e.enrollment_id LIKE :s2 OR e.phone LIKE :s3)";
        $params[':s'] = $params[':s2'] = $params[':s3'] = '%'.$search.'%';
    }
    if ($status) { $q .= " AND e.status=:status"; $params[':status'] = $status; }
    if ($course_id) { $q .= " AND e.course_id=:cid"; $params[':cid'] = $course_id; }
    // Accountant sees only if they have academy access (filtered at page level already)
    $q .= " ORDER BY e.created_at DESC";
    $stmt = $db->prepare($q);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, SQLITE3_TEXT);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getEnrollmentById($id) {
    global $db;
    $stmt = $db->prepare("SELECT e.*, c.course_name, c.course_code, c.duration_months,
        c.admission_fee as course_admission_fee, c.course_fee_total, c.course_fee_monthly,
        c.exam_fee as course_exam_fee, c.certificate_fee, c.shifts
        FROM academy_enrollments e
        LEFT JOIN academy_courses c ON e.course_id=c.id
        WHERE e.id=:id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function getEnrollmentPayments($enrollment_id) {
    global $db;
    $stmt = $db->prepare("SELECT p.*, u.username as paid_by FROM academy_payments p LEFT JOIN users u ON p.created_by=u.id WHERE p.enrollment_id=:id ORDER BY p.payment_date ASC, p.created_at ASC");
    $stmt->bindValue(':id', $enrollment_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getInstallmentSchedule($enrollment_id) {
    global $db;
    $stmt = $db->prepare("SELECT * FROM academy_installments WHERE enrollment_id=:id ORDER BY due_date ASC, installment_number ASC");
    $stmt->bindValue(':id', $enrollment_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function getDueReminders() {
    global $db;
    $today = date('Y-m-d');
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $stmt = $db->prepare("SELECT i.*, e.candidate_name, e.enrollment_id as enr_id, e.phone, e.id as enr_db_id,
        c.course_name FROM academy_installments i
        LEFT JOIN academy_enrollments e ON i.enrollment_id=e.id
        LEFT JOIN academy_courses c ON e.course_id=c.id
        WHERE i.status='pending' AND i.due_date <= :nw AND e.status='active'
        ORDER BY i.due_date ASC");
    $stmt->bindValue(':nw', $nextWeek, SQLITE3_TEXT);
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function saveAcademyCourse() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=academy_courses'); exit(); }
    $id          = intval($_POST['course_id'] ?? 0);
    $name        = trim($_POST['course_name'] ?? '');
    $code        = strtoupper(trim($_POST['course_code'] ?? ''));
    $desc        = trim($_POST['description'] ?? '');
    $duration    = intval($_POST['duration_months'] ?? 1);
    $adm_fee     = floatval($_POST['admission_fee'] ?? 0);
    $fee_total   = floatval($_POST['course_fee_total'] ?? 0);
    $fee_monthly = floatval($_POST['course_fee_monthly'] ?? 0);
    $exam_fee    = floatval($_POST['exam_fee'] ?? 0);
    $cert_fee    = floatval($_POST['certificate_fee'] ?? 0);
    $shifts      = trim($_POST['shifts'] ?? 'Morning,Evening');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if (!$name) { $_SESSION['error'] = 'Course name required.'; header('Location: ?page=academy_courses'); exit(); }

    if ($id) {
        $stmt = $db->prepare("UPDATE academy_courses SET course_name=:n,course_code=:c,description=:d,duration_months=:dur,admission_fee=:af,course_fee_total=:ft,course_fee_monthly=:fm,exam_fee=:ef,certificate_fee=:cf,shifts=:sh,is_active=:ia WHERE id=:id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    } else {
        $stmt = $db->prepare("INSERT INTO academy_courses (course_name,course_code,description,duration_months,admission_fee,course_fee_total,course_fee_monthly,exam_fee,certificate_fee,shifts,is_active,created_by) VALUES (:n,:c,:d,:dur,:af,:ft,:fm,:ef,:cf,:sh,:ia,:uid)");
        $stmt->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
    }
    $stmt->bindValue(':n',   $name,        SQLITE3_TEXT);
    $stmt->bindValue(':c',   $code,        SQLITE3_TEXT);
    $stmt->bindValue(':d',   $desc,        SQLITE3_TEXT);
    $stmt->bindValue(':dur', $duration,    SQLITE3_INTEGER);
    $stmt->bindValue(':af',  $adm_fee,     SQLITE3_FLOAT);
    $stmt->bindValue(':ft',  $fee_total,   SQLITE3_FLOAT);
    $stmt->bindValue(':fm',  $fee_monthly, SQLITE3_FLOAT);
    $stmt->bindValue(':ef',  $exam_fee,    SQLITE3_FLOAT);
    $stmt->bindValue(':cf',  $cert_fee,    SQLITE3_FLOAT);
    $stmt->bindValue(':sh',  $shifts,      SQLITE3_TEXT);
    $stmt->bindValue(':ia',  $is_active,   SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = $id ? 'Course updated.' : 'Course added.';
    header('Location: ?page=academy_courses'); exit();
}

function deleteAcademyCourse() {
    global $db;
    if (!isAdmin()) { $_SESSION['error'] = 'Admin only.'; header('Location: ?page=academy_courses'); exit(); }
    $id = intval($_POST['course_id'] ?? 0);
    $stmt = $db->prepare("UPDATE academy_courses SET is_active=0 WHERE id=:id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Course deactivated.';
    header('Location: ?page=academy_courses'); exit();
}

function createEnrollment() {
    global $db;
    if (!hasAcademyAccess()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=dashboard'); exit(); }

    $enrollment_id = generateEnrollmentId();
    $course_id     = intval($_POST['course_id'] ?? 0);
    $fee_type      = $_POST['fee_type'] ?? 'monthly';

    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    $photo_path = '';
    $id_proof_path = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            $fn = 'acad_photo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], 'uploads/' . $fn)) $photo_path = 'uploads/' . $fn;
        }
    }
    if (isset($_FILES['id_proof']) && $_FILES['id_proof']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['id_proof']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp','pdf'])) {
            $fn = 'acad_id_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['id_proof']['tmp_name'], 'uploads/' . $fn)) $id_proof_path = 'uploads/' . $fn;
        }
    }

    $dob = trim($_POST['dob'] ?? '');
    $age = intval($_POST['age'] ?? 0);
    if ($dob && !$age) {
        $diff = (new DateTime())->diff(new DateTime($dob));
        $age = $diff->y;
    }

    $adm_fee  = floatval($_POST['admission_fee'] ?? 0);
    $exam_fee = floatval($_POST['exam_fee'] ?? 0);
    $cert_fee = floatval($_POST['certificate_fee'] ?? 0);
    $total    = floatval($_POST['total_fee'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $paid_now = floatval($_POST['paid_now'] ?? 0);
    $balance  = $total - $discount - $paid_now;

    $stmt = $db->prepare("INSERT INTO academy_enrollments
        (enrollment_id,candidate_name,relative_name,relation,dob,age,phone,alternate_phone,email,address,qualification,
         course_id,shift,batch_start_date,batch_end_date,fee_type,total_fee,admission_fee,exam_fee,certificate_fee,
         discount,amount_paid,balance,status,photo_path,id_proof_path,notes,created_by)
        VALUES (:eid,:cn,:rn,:rel,:dob,:age,:ph,:aph,:em,:addr,:qual,:cid,:sh,:bsd,:bed,:ft,:tf,:af,:ef,:cf,:disc,:paid,:bal,'active',:pp,:ip,:notes,:uid)");

    $fields = [
        ':eid'   => $enrollment_id,
        ':cn'    => trim($_POST['candidate_name'] ?? ''),
        ':rn'    => trim($_POST['relative_name'] ?? 'NA'),
        ':rel'   => trim($_POST['relation'] ?? 'F/o'),
        ':dob'   => $dob,
        ':age'   => $age,
        ':ph'    => trim($_POST['phone'] ?? ''),
        ':aph'   => trim($_POST['alternate_phone'] ?? ''),
        ':em'    => trim($_POST['email'] ?? ''),
        ':addr'  => trim($_POST['address'] ?? ''),
        ':qual'  => trim($_POST['qualification'] ?? ''),
        ':cid'   => $course_id,
        ':sh'    => trim($_POST['shift'] ?? ''),
        ':bsd'   => trim($_POST['batch_start_date'] ?? ''),
        ':bed'   => trim($_POST['batch_end_date'] ?? ''),
        ':ft'    => $fee_type,
        ':tf'    => $total,
        ':af'    => $adm_fee,
        ':ef'    => $exam_fee,
        ':cf'    => $cert_fee,
        ':disc'  => $discount,
        ':paid'  => $paid_now,
        ':bal'   => $balance,
        ':pp'    => $photo_path,
        ':ip'    => $id_proof_path,
        ':notes' => trim($_POST['notes'] ?? ''),
        ':uid'   => $_SESSION['user_id'],
    ];
    foreach ($fields as $k => $v) {
        if (is_int($v) || is_float($v)) $stmt->bindValue($k, $v, SQLITE3_FLOAT);
        else $stmt->bindValue($k, $v, SQLITE3_TEXT);
    }
    $stmt->bindValue(':age', $age, SQLITE3_INTEGER);
    $stmt->bindValue(':cid', $course_id, SQLITE3_INTEGER);
    $stmt->execute();

    $new_id = $db->lastInsertRowID();

    // Record initial payment if any
    if ($paid_now > 0) {
        $pr = $db->prepare("INSERT INTO academy_payments (enrollment_id,payment_date,amount,fee_type,payment_method,receipt_number,installment_number,created_by) VALUES (:eid,:pd,:amt,:ft,:pm,:rn,1,:uid)");
        $pr->bindValue(':eid', $new_id, SQLITE3_INTEGER);
        $pr->bindValue(':pd',  date('Y-m-d'), SQLITE3_TEXT);
        $pr->bindValue(':amt', $paid_now, SQLITE3_FLOAT);
        $pr->bindValue(':ft',  'admission', SQLITE3_TEXT);
        $pr->bindValue(':pm',  $_POST['payment_method'] ?? 'Cash', SQLITE3_TEXT);
        $pr->bindValue(':rn',  'RCP-'.$enrollment_id.'-1', SQLITE3_TEXT);
        $pr->bindValue(':uid', $_SESSION['user_id'], SQLITE3_INTEGER);
        $pr->execute();
    }

    // Auto-generate installment schedule for monthly fee type
    if ($fee_type === 'monthly' && $course_id) {
        $cs = $db->prepare("SELECT * FROM academy_courses WHERE id=:id");
        $cs->bindValue(':id', $course_id, SQLITE3_INTEGER);
        $course = $cs->execute()->fetchArray(SQLITE3_ASSOC);
        if ($course && $course['course_fee_monthly'] > 0 && $course['duration_months'] > 0) {
            $start = trim($_POST['batch_start_date'] ?? date('Y-m-d'));
            for ($i = 1; $i <= $course['duration_months']; $i++) {
                $due = date('Y-m-d', strtotime($start . ' +' . ($i-1) . ' months'));
                $is = $db->prepare("INSERT INTO academy_installments (enrollment_id,installment_number,due_date,amount,fee_type) VALUES (:eid,:num,:due,:amt,'course')");
                $is->bindValue(':eid', $new_id, SQLITE3_INTEGER);
                $is->bindValue(':num', $i, SQLITE3_INTEGER);
                $is->bindValue(':due', $due, SQLITE3_TEXT);
                $is->bindValue(':amt', $course['course_fee_monthly'], SQLITE3_FLOAT);
                $is->execute();
            }
        }
    }

    logAction('CREATE_ENROLLMENT', "New enrollment: {$enrollment_id} for " . trim($_POST['candidate_name'] ?? ''));
    $_SESSION['success'] = "Enrollment created! ID: <strong>{$enrollment_id}</strong>";
    header('Location: ?page=view_enrollment&id=' . $new_id);
    exit();
}

function updateEnrollment() {
    global $db;
    if (!hasAcademyAccess()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=academy'); exit(); }
    $id = intval($_POST['enrollment_id'] ?? 0);
    $total   = floatval($_POST['total_fee'] ?? 0);
    $disc    = floatval($_POST['discount'] ?? 0);
    $paid    = floatval($_POST['amount_paid'] ?? 0);
    $balance = $total - $disc - $paid;
    $stmt = $db->prepare("UPDATE academy_enrollments SET
        candidate_name=:cn, relative_name=:rn, relation=:rel, dob=:dob, age=:age,
        phone=:ph, alternate_phone=:aph, email=:em, address=:addr, qualification=:qual,
        course_id=:cid, shift=:sh, batch_start_date=:bsd, batch_end_date=:bed,
        fee_type=:ft, total_fee=:tf, admission_fee=:af, exam_fee=:ef, certificate_fee=:cf,
        discount=:disc, balance=:bal, status=:sts, notes=:notes, updated_at=CURRENT_TIMESTAMP
        WHERE id=:id");
    $stmt->bindValue(':cn',   trim($_POST['candidate_name'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':rn',   trim($_POST['relative_name'] ?? 'NA'), SQLITE3_TEXT);
    $stmt->bindValue(':rel',  trim($_POST['relation'] ?? 'F/o'), SQLITE3_TEXT);
    $stmt->bindValue(':dob',  trim($_POST['dob'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':age',  intval($_POST['age'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':ph',   trim($_POST['phone'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':aph',  trim($_POST['alternate_phone'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':em',   trim($_POST['email'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':addr', trim($_POST['address'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':qual', trim($_POST['qualification'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':cid',  intval($_POST['course_id'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':sh',   trim($_POST['shift'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':bsd',  trim($_POST['batch_start_date'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':bed',  trim($_POST['batch_end_date'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':ft',   trim($_POST['fee_type'] ?? 'monthly'), SQLITE3_TEXT);
    $stmt->bindValue(':tf',   $total, SQLITE3_FLOAT);
    $stmt->bindValue(':af',   floatval($_POST['admission_fee'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':ef',   floatval($_POST['exam_fee'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':cf',   floatval($_POST['certificate_fee'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':disc', $disc, SQLITE3_FLOAT);
    $stmt->bindValue(':bal',  $balance, SQLITE3_FLOAT);
    $stmt->bindValue(':sts',  trim($_POST['status'] ?? 'active'), SQLITE3_TEXT);
    $stmt->bindValue(':notes',trim($_POST['notes'] ?? ''), SQLITE3_TEXT);
    $stmt->bindValue(':id',   $id, SQLITE3_INTEGER);
    $stmt->execute();
    $_SESSION['success'] = 'Enrollment updated.';
    header('Location: ?page=view_enrollment&id=' . $id); exit();
}

function addAcademyPayment() {
    global $db;
    if (!hasAcademyAccess()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=academy'); exit(); }
    $enr_id    = intval($_POST['enrollment_id'] ?? 0);
    $amount    = floatval($_POST['amount'] ?? 0);
    $fee_type  = trim($_POST['fee_type'] ?? 'course');
    $method    = trim($_POST['payment_method'] ?? 'Cash');
    $txn       = trim($_POST['transaction_id'] ?? '');
    $date      = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $notes     = trim($_POST['notes'] ?? '');

    // Get current installment number
    $ic = $db->prepare("SELECT COUNT(*)+1 as next FROM academy_payments WHERE enrollment_id=:id");
    $ic->bindValue(':id', $enr_id, SQLITE3_INTEGER);
    $instNum = $ic->execute()->fetchArray(SQLITE3_ASSOC)['next'];
    $receipt = 'RCP-' . date('Ymd') . '-' . str_pad($instNum, 3, '0', STR_PAD_LEFT);

    $stmt = $db->prepare("INSERT INTO academy_payments (enrollment_id,payment_date,amount,fee_type,payment_method,transaction_id,receipt_number,installment_number,notes,created_by) VALUES (:eid,:pd,:amt,:ft,:pm,:txn,:rn,:inst,:notes,:uid)");
    $stmt->bindValue(':eid',  $enr_id, SQLITE3_INTEGER);
    $stmt->bindValue(':pd',   $date, SQLITE3_TEXT);
    $stmt->bindValue(':amt',  $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':ft',   $fee_type, SQLITE3_TEXT);
    $stmt->bindValue(':pm',   $method, SQLITE3_TEXT);
    $stmt->bindValue(':txn',  $txn, SQLITE3_TEXT);
    $stmt->bindValue(':rn',   $receipt, SQLITE3_TEXT);
    $stmt->bindValue(':inst', $instNum, SQLITE3_INTEGER);
    $stmt->bindValue(':notes',$notes, SQLITE3_TEXT);
    $stmt->bindValue(':uid',  $_SESSION['user_id'], SQLITE3_INTEGER);
    $stmt->execute();

    // Update enrollment paid/balance
    $db->exec("UPDATE academy_enrollments SET amount_paid=amount_paid+{$amount}, balance=balance-{$amount}, updated_at=CURRENT_TIMESTAMP WHERE id={$enr_id}");

    // Mark matching installment as paid
    $ip = $db->prepare("SELECT id FROM academy_installments WHERE enrollment_id=:eid AND status='pending' AND fee_type=:ft ORDER BY due_date ASC LIMIT 1");
    $ip->bindValue(':eid', $enr_id, SQLITE3_INTEGER);
    $ip->bindValue(':ft',  $fee_type, SQLITE3_TEXT);
    $instRow = $ip->execute()->fetchArray(SQLITE3_ASSOC);
    if ($instRow) {
        $up = $db->prepare("UPDATE academy_installments SET status='paid', paid_on=:pd WHERE id=:id");
        $up->bindValue(':pd', $date, SQLITE3_TEXT);
        $up->bindValue(':id', $instRow['id'], SQLITE3_INTEGER);
        $up->execute();
    }

    logAction('ACADEMY_PAYMENT', "Payment ₹{$amount} for enrollment ID {$enr_id}");
    $_SESSION['success'] = "Payment of ₹" . number_format($amount,2) . " recorded. Receipt: {$receipt}";
    header('Location: ?page=view_enrollment&id=' . $enr_id); exit();
}

function deleteAcademyPayment() {
    global $db;
    if (!isAdmin() && !isManager()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=academy'); exit(); }
    $pid    = intval($_POST['payment_id'] ?? 0);
    $enr_id = intval($_POST['enrollment_id'] ?? 0);
    $stmt   = $db->prepare("SELECT amount FROM academy_payments WHERE id=:id");
    $stmt->bindValue(':id', $pid, SQLITE3_INTEGER);
    $row    = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $db->exec("UPDATE academy_enrollments SET amount_paid=amount_paid-{$row['amount']}, balance=balance+{$row['amount']} WHERE id={$enr_id}");
        $stmt2 = $db->prepare("DELETE FROM academy_payments WHERE id=:id");
        $stmt2->bindValue(':id', $pid, SQLITE3_INTEGER);
        $stmt2->execute();
    }
    $_SESSION['success'] = 'Payment deleted.';
    header('Location: ?page=view_enrollment&id=' . $enr_id); exit();
}

function generateInstallmentSchedule() {
    global $db;
    if (!isAdmin() && !isManager()) { $_SESSION['error'] = 'Permission denied.'; header('Location: ?page=academy'); exit(); }
    $enr_id = intval($_POST['enrollment_id'] ?? 0);
    $enr    = getEnrollmentById($enr_id);
    if (!$enr) { $_SESSION['error'] = 'Enrollment not found.'; header('Location: ?page=academy'); exit(); }

    // Clear existing pending installments
    $db->exec("DELETE FROM academy_installments WHERE enrollment_id={$enr_id} AND status='pending'");

    $monthly = floatval($enr['course_fee_monthly']);
    $months  = intval($enr['duration_months']);
    $start   = $enr['batch_start_date'] ?: date('Y-m-d');

    if ($monthly > 0 && $months > 0) {
        for ($i = 1; $i <= $months; $i++) {
            $due = date('Y-m-d', strtotime($start . ' +' . ($i-1) . ' months'));
            $is  = $db->prepare("INSERT INTO academy_installments (enrollment_id,installment_number,due_date,amount,fee_type) VALUES (:eid,:num,:due,:amt,'course')");
            $is->bindValue(':eid', $enr_id, SQLITE3_INTEGER);
            $is->bindValue(':num', $i, SQLITE3_INTEGER);
            $is->bindValue(':due', $due, SQLITE3_TEXT);
            $is->bindValue(':amt', $monthly, SQLITE3_FLOAT);
            $is->execute();
        }
    }

    // Add exam fee installment if set
    if ($enr['exam_fee'] > 0) {
        $examDue = $enr['batch_end_date'] ?: date('Y-m-d', strtotime($start . ' +' . ($months-1) . ' months'));
        $es = $db->prepare("INSERT INTO academy_installments (enrollment_id,installment_number,due_date,amount,fee_type) VALUES (:eid,1,:due,:amt,'exam')");
        $es->bindValue(':eid', $enr_id, SQLITE3_INTEGER);
        $es->bindValue(':due', $examDue, SQLITE3_TEXT);
        $es->bindValue(':amt', floatval($enr['exam_fee']), SQLITE3_FLOAT);
        $es->execute();
    }

    $_SESSION['success'] = "Installment schedule generated: {$months} installments of ₹" . number_format($monthly,2) . " each.";
    header('Location: ?page=view_enrollment&id=' . $enr_id); exit();
}

// ============================================================
// ACADEMY PAGE FUNCTIONS
// ============================================================

function includeAcademy() {
    if (!hasAcademyAccess()) { echo '<div class="message error">Academy access required.</div>'; return; }
    $cur = getSetting('currency_symbol', '₹');
    $search = trim($_GET['search'] ?? '');
    $statusF = trim($_GET['status'] ?? '');
    $courseF = intval($_GET['course_id'] ?? 0);
    $enrollments = getAcademyEnrollments($search, $statusF, $courseF);
    $courses = getAcademyCourses(true);
    $reminders = getDueReminders();
    $totalActive = 0; $totalRevenue = 0; $totalBalance = 0;
    foreach ($enrollments as $e) {
        if ($e['status'] === 'active') $totalActive++;
        $totalRevenue += $e['amount_paid'];
        $totalBalance += $e['balance'];
    }
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <h2 style="margin:0;">🎓 Academy — Enrollments</h2>
        <a href="?page=create_enrollment" style="padding:8px 18px;background:#2980b9;color:#fff;border-radius:4px;text-decoration:none;font-size:13px;">➕ New Enrollment</a>
    </div>

    <!-- Stats -->
    <div class="stats-grid" style="margin-bottom:18px;">
        <div class="stat-card-v2"><div class="sc-label">Total Enrollments</div><div class="sc-value"><?php echo count($enrollments); ?></div></div>
        <div class="stat-card-v2"><div class="sc-label">Active Students</div><div class="sc-value" style="color:#27ae60;"><?php echo $totalActive; ?></div></div>
        <?php if (!isAccountant()): ?>
        <div class="stat-card-v2 green"><div class="sc-label">Fees Collected</div><div class="sc-value" style="font-size:18px;"><?php echo $cur . ' ' . number_format($totalRevenue, 0); ?></div></div>
        <div class="stat-card-v2 orange"><div class="sc-label">Outstanding Balance</div><div class="sc-value" style="font-size:18px;"><?php echo $cur . ' ' . number_format($totalBalance, 0); ?></div></div>
        <?php endif; ?>
        <?php if (count($reminders) > 0): ?>
        <div class="stat-card-v2 red" style="cursor:pointer;" onclick="window.location='?page=academy_reminders'">
            <div class="sc-label">Due / Overdue</div>
            <div class="sc-value" style="color:#e74c3c;"><?php echo count($reminders); ?></div>
            <a href="?page=academy_reminders" class="sc-link">View reminders →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
        <input type="hidden" name="page" value="academy">
        <div class="form-group" style="margin:0;min-width:180px;">
            <label style="font-size:12px;">Search</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name / Enrollment ID / Phone">
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Status</label>
            <select name="status">
                <option value="">All</option>
                <?php foreach (['active','completed','cancelled','on_hold'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $statusF===$st?'selected':''; ?>><?php echo ucfirst(str_replace('_',' ',$st)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label style="font-size:12px;">Course</label>
            <select name="course_id">
                <option value="0">All Courses</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $courseF==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['course_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="padding:8px 16px;background:#3498db;color:#fff;border:none;border-radius:4px;cursor:pointer;">🔍 Filter</button>
        <a href="?page=academy" style="padding:8px 14px;background:#95a5a6;color:#fff;border-radius:4px;text-decoration:none;font-size:13px;">✕ Clear</a>
    </form>

    <!-- Enrollment List -->
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Enrollment ID</th>
                <th>Candidate</th>
                <th>Course</th>
                <th>Shift</th>
                <th>Batch Start</th>
                <?php if (!isAccountant()): ?><th>Total Fee</th><th>Paid</th><th>Balance</th><?php endif; ?>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($enrollments)): ?>
        <tr><td colspan="10" style="text-align:center;color:#aaa;padding:28px;">No enrollments found</td></tr>
        <?php else: foreach ($enrollments as $e): ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($e['enrollment_id']); ?></strong></td>
            <td>
                <?php echo htmlspecialchars($e['candidate_name']); ?>
                <?php if ($e['relative_name'] && $e['relative_name'] !== 'NA'): ?><br><small style="color:#888;"><?php echo htmlspecialchars($e['relation'].' '.$e['relative_name']); ?></small><?php endif; ?>
                <?php if ($e['phone']): ?><br><small>📞 <?php echo htmlspecialchars($e['phone']); ?></small><?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($e['course_name'] ?? '—'); ?></td>
            <td><?php echo htmlspecialchars($e['shift'] ?? '—'); ?></td>
            <td><?php echo $e['batch_start_date'] ? date('d-m-Y', strtotime($e['batch_start_date'])) : '—'; ?></td>
            <?php if (!isAccountant()): ?>
            <td><?php echo $cur . ' ' . number_format($e['total_fee'], 0); ?></td>
            <td style="color:#27ae60;"><?php echo $cur . ' ' . number_format($e['amount_paid'], 0); ?></td>
            <td style="color:<?php echo $e['balance'] > 0 ? '#e74c3c' : '#27ae60'; ?>;"><?php echo $cur . ' ' . number_format($e['balance'], 0); ?></td>
            <?php endif; ?>
            <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;background:<?php echo $e['status']==='active'?'#d4edda':($e['status']==='completed'?'#d1ecf1':($e['status']==='cancelled'?'#f8d7da':'#fff3cd')); ?>;color:<?php echo $e['status']==='active'?'#155724':($e['status']==='completed'?'#0c5460':($e['status']==='cancelled'?'#721c24':'#856404')); ?>;"><?php echo ucfirst(str_replace('_',' ',$e['status'])); ?></span></td>
            <td class="actions-cell">
                <a href="?page=view_enrollment&id=<?php echo $e['id']; ?>" class="action-btn view-btn">View</a>
                <a href="?page=edit_enrollment&id=<?php echo $e['id']; ?>" class="action-btn edit-btn">Edit</a>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}

function includeAcademyCourses() {
    if (!isAdmin()) { echo '<div class="message error">Admin only.</div>'; return; }
    $courses = getAcademyCourses();
    $cur = getSetting('currency_symbol', '₹');
    $editCourse = null;
    if (isset($_GET['edit'])) {
        $s = $GLOBALS['db']->prepare("SELECT * FROM academy_courses WHERE id=:id");
        $s->bindValue(':id', intval($_GET['edit']), SQLITE3_INTEGER);
        $editCourse = $s->execute()->fetchArray(SQLITE3_ASSOC);
    }
    ?>
    <h2>📚 Course Management</h2>

    <?php if (isset($_SESSION['success'])): ?><div class="message success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <!-- Add/Edit Course Form -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:22px;">
        <h3 style="margin-bottom:14px;"><?php echo $editCourse ? '✏️ Edit Course' : '➕ Add New Course'; ?></h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_academy_course">
            <?php if ($editCourse): ?><input type="hidden" name="course_id" value="<?php echo $editCourse['id']; ?>"><?php endif; ?>
            <div class="row">
                <div class="form-group">
                    <label>Course Name: *</label>
                    <input type="text" name="course_name" value="<?php echo htmlspecialchars($editCourse['course_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Course Code:</label>
                    <input type="text" name="course_code" value="<?php echo htmlspecialchars($editCourse['course_code'] ?? ''); ?>" placeholder="e.g. DCA, PGDCA">
                </div>
                <div class="form-group">
                    <label>Duration (months):</label>
                    <input type="number" name="duration_months" min="1" value="<?php echo $editCourse['duration_months'] ?? 1; ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="2"><?php echo htmlspecialchars($editCourse['description'] ?? ''); ?></textarea>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Admission Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="admission_fee" step="0.01" min="0" value="<?php echo $editCourse['admission_fee'] ?? 0; ?>">
                </div>
                <div class="form-group">
                    <label>Total Course Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="course_fee_total" step="0.01" min="0" value="<?php echo $editCourse['course_fee_total'] ?? 0; ?>">
                </div>
                <div class="form-group">
                    <label>Monthly Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="course_fee_monthly" step="0.01" min="0" value="<?php echo $editCourse['course_fee_monthly'] ?? 0; ?>">
                </div>
                <div class="form-group">
                    <label>Exam Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="exam_fee" step="0.01" min="0" value="<?php echo $editCourse['exam_fee'] ?? 0; ?>">
                </div>
                <div class="form-group">
                    <label>Certificate Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="certificate_fee" step="0.01" min="0" value="<?php echo $editCourse['certificate_fee'] ?? 0; ?>">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Available Shifts (comma-separated):</label>
                    <input type="text" name="shifts" value="<?php echo htmlspecialchars($editCourse['shifts'] ?? 'Morning,Evening'); ?>" placeholder="Morning,Evening,Afternoon">
                </div>
                <div class="form-group" style="align-self:flex-end;padding-bottom:6px;">
                    <label><input type="checkbox" name="is_active" value="1" <?php echo (!$editCourse || $editCourse['is_active']) ? 'checked' : ''; ?>> &nbsp;Active</label>
                </div>
            </div>
            <div class="action-buttons">
                <button type="submit"><?php echo $editCourse ? 'Update Course' : 'Add Course'; ?></button>
                <?php if ($editCourse): ?><a href="?page=academy_courses" class="btn-secondary" style="padding:9px 16px;text-decoration:none;">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Course List -->
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr><th>Code</th><th>Course Name</th><th>Duration</th><th>Adm. Fee</th><th>Course Fee</th><th>Monthly</th><th>Exam Fee</th><th>Shifts</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($courses)): ?>
        <tr><td colspan="10" style="text-align:center;color:#aaa;padding:24px;">No courses yet. Add one above.</td></tr>
        <?php else: foreach ($courses as $c): ?>
        <tr>
            <td><code><?php echo htmlspecialchars($c['course_code'] ?: '—'); ?></code></td>
            <td><strong><?php echo htmlspecialchars($c['course_name']); ?></strong><?php if ($c['description']): ?><br><small style="color:#888;"><?php echo htmlspecialchars(substr($c['description'],0,50)); ?></small><?php endif; ?></td>
            <td><?php echo $c['duration_months']; ?> mo.</td>
            <td><?php echo $cur . ' ' . number_format($c['admission_fee'], 0); ?></td>
            <td><?php echo $cur . ' ' . number_format($c['course_fee_total'], 0); ?></td>
            <td><?php echo $cur . ' ' . number_format($c['course_fee_monthly'], 0); ?>/mo</td>
            <td><?php echo $cur . ' ' . number_format($c['exam_fee'], 0); ?></td>
            <td style="font-size:11px;"><?php echo htmlspecialchars($c['shifts']); ?></td>
            <td><span style="color:<?php echo $c['is_active'] ? '#27ae60' : '#e74c3c'; ?>;font-weight:bold;"><?php echo $c['is_active'] ? '✅ Active' : '❌ Inactive'; ?></span></td>
            <td class="actions-cell">
                <a href="?page=academy_courses&edit=<?php echo $c['id']; ?>" class="action-btn edit-btn">Edit</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this course?')">
                    <input type="hidden" name="action" value="delete_academy_course">
                    <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                    <button type="submit" class="action-btn delete-btn" style="border:none;cursor:pointer;">Deactivate</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <?php
}

function includeCreateEnrollment() {
    if (!hasAcademyAccess()) { echo '<div class="message error">Academy access required.</div>'; return; }
    $courses = getAcademyCourses(true);
    $cur = getSetting('currency_symbol', '₹');
    ?>
    <h2>🎓 New Student Enrollment</h2>

    <?php if (isset($_SESSION['error'])): ?><div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="enrollForm">
        <input type="hidden" name="action" value="create_enrollment">

        <!-- Candidate Details -->
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:18px;">
            <h3 style="margin-bottom:14px;color:#2c3e50;border-bottom:2px solid #3498db;padding-bottom:6px;">👤 Candidate Details</h3>
            <div class="row">
                <div class="form-group">
                    <label>Candidate Full Name: *</label>
                    <input type="text" name="candidate_name" required placeholder="As per documents">
                </div>
                <div class="form-group">
                    <label>Relation:</label>
                    <select name="relation">
                        <option value="F/o">Father (F/o)</option>
                        <option value="S/o">Son (S/o)</option>
                        <option value="D/o">Daughter (D/o)</option>
                        <option value="W/o">Wife (W/o)</option>
                        <option value="H/o">Husband (H/o)</option>
                        <option value="M/o">Mother (M/o)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Relative's Name: *</label>
                    <input type="text" name="relative_name" required placeholder="Father's / Guardian's name">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Date of Birth:</label>
                    <input type="date" name="dob" id="dobField" onchange="calcAge()">
                </div>
                <div class="form-group">
                    <label>Age (auto-calculated):</label>
                    <input type="number" name="age" id="ageField" min="1" max="120" placeholder="Age in years">
                </div>
                <div class="form-group">
                    <label>Phone: *</label>
                    <input type="tel" name="phone" required placeholder="10-digit mobile">
                </div>
                <div class="form-group">
                    <label>Alternate Phone:</label>
                    <input type="tel" name="alternate_phone" placeholder="Optional">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Highest Qualification:</label>
                    <select name="qualification">
                        <option value="Below 8th">Below 8th</option>
                        <option value="8th Pass">8th Pass</option>
                        <option value="10th Pass">10th Pass (High School)</option>
                        <option value="12th Pass">12th Pass (Intermediate)</option>
                        <option value="Graduate">Graduate</option>
                        <option value="Post Graduate">Post Graduate</option>
                        <option value="Diploma">Diploma</option>
                        <option value="ITI">ITI</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Address:</label>
                <textarea name="address" rows="2" placeholder="Full residential address"></textarea>
            </div>
        </div>

        <!-- Course Details -->
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:18px;">
            <h3 style="margin-bottom:14px;color:#2c3e50;border-bottom:2px solid #27ae60;padding-bottom:6px;">📚 Course & Batch Details</h3>
            <div class="row">
                <div class="form-group">
                    <label>Course: *</label>
                    <select name="course_id" id="courseSelect" required onchange="fillCourseFees()">
                        <option value="">-- Select Course --</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?php echo $c['id']; ?>"
                            data-adm="<?php echo $c['admission_fee']; ?>"
                            data-total="<?php echo $c['course_fee_total']; ?>"
                            data-monthly="<?php echo $c['course_fee_monthly']; ?>"
                            data-exam="<?php echo $c['exam_fee']; ?>"
                            data-cert="<?php echo $c['certificate_fee']; ?>"
                            data-months="<?php echo $c['duration_months']; ?>"
                            data-shifts="<?php echo htmlspecialchars($c['shifts']); ?>">
                            <?php echo htmlspecialchars($c['course_name']); ?>
                            <?php if ($c['course_code']): ?> (<?php echo $c['course_code']; ?>)<?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Shift Preference:</label>
                    <select name="shift" id="shiftSelect">
                        <option value="">-- Select after choosing course --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fee Type:</label>
                    <select name="fee_type" id="feeTypeSelect" onchange="toggleFeeType()">
                        <option value="monthly">Monthly Installments</option>
                        <option value="total">Full Payment</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Batch Start Date:</label>
                    <input type="date" name="batch_start_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label>Batch End Date:</label>
                    <input type="date" name="batch_end_date" id="batchEndDate">
                </div>
                <div class="form-group">
                    <label>Duration (months):</label>
                    <input type="number" name="duration_months_display" id="durationDisplay" readonly style="background:#f8f9fa;">
                </div>
            </div>
        </div>

        <!-- Fee Details -->
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:18px;">
            <h3 style="margin-bottom:14px;color:#2c3e50;border-bottom:2px solid #e74c3c;padding-bottom:6px;">💰 Fee Details</h3>
            <div class="row">
                <div class="form-group">
                    <label>Admission / Enrollment Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="admission_fee" id="admissionFee" step="0.01" min="0" value="0">
                </div>
                <div class="form-group" id="totalFeeGroup">
                    <label>Total Course Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="course_fee_display" id="totalCourseFee" step="0.01" min="0" value="0" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group" id="monthlyFeeGroup">
                    <label>Monthly Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="monthly_fee_display" id="monthlyFee" step="0.01" min="0" value="0" readonly style="background:#f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Exam Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="exam_fee" id="examFee" step="0.01" min="0" value="0">
                </div>
                <div class="form-group">
                    <label>Certificate Fee (<?php echo $cur; ?>):</label>
                    <input type="number" name="certificate_fee" id="certFee" step="0.01" min="0" value="0">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Discount (<?php echo $cur; ?>):</label>
                    <input type="number" name="discount" id="discountField" step="0.01" min="0" value="0" onchange="calcTotal()">
                </div>
                <div class="form-group">
                    <label>Total Fee Payable (<?php echo $cur; ?>): *</label>
                    <input type="number" name="total_fee" id="totalFeeField" step="0.01" min="0" required onchange="calcTotal()">
                </div>
                <div class="form-group">
                    <label>Amount Paid Now (<?php echo $cur; ?>):</label>
                    <input type="number" name="paid_now" id="paidNow" step="0.01" min="0" value="0" onchange="calcTotal()">
                </div>
                <div class="form-group">
                    <label>Balance (<?php echo $cur; ?>):</label>
                    <input type="number" name="balance_display" id="balanceDisplay" readonly style="background:#f8f9fa;color:#e74c3c;font-weight:bold;">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Payment Method:</label>
                    <select name="payment_method">
                        <?php foreach (explode(',', getSetting('payment_methods','Cash,UPI,Bank Transfer')) as $pm): ?>
                        <option><?php echo trim($pm); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:18px;">
            <h3 style="margin-bottom:14px;color:#2c3e50;border-bottom:2px solid #9b59b6;padding-bottom:6px;">📎 Documents (Optional)</h3>
            <div class="row">
                <div class="form-group">
                    <label>Candidate Photo:</label>
                    <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
                    <small>JPG/PNG, max 2MB</small>
                </div>
                <div class="form-group">
                    <label>ID Proof:</label>
                    <input type="file" name="id_proof" accept=".jpg,.jpeg,.png,.webp,.pdf">
                    <small>Aadhaar / Marksheet etc.</small>
                </div>
            </div>
            <div class="form-group">
                <label>Notes:</label>
                <textarea name="notes" rows="2" placeholder="Any additional notes..."></textarea>
            </div>
        </div>

        <div class="action-buttons">
            <button type="submit" style="background:#27ae60;font-size:15px;padding:12px 28px;">🎓 Create Enrollment &amp; Generate ID</button>
            <a href="?page=academy" class="btn-secondary" style="padding:12px 20px;text-decoration:none;">Cancel</a>
        </div>
    </form>

    <script>
    var courseData = {};
    document.querySelectorAll('#courseSelect option[data-adm]').forEach(function(opt) {
        courseData[opt.value] = {
            adm: parseFloat(opt.dataset.adm)||0,
            total: parseFloat(opt.dataset.total)||0,
            monthly: parseFloat(opt.dataset.monthly)||0,
            exam: parseFloat(opt.dataset.exam)||0,
            cert: parseFloat(opt.dataset.cert)||0,
            months: parseInt(opt.dataset.months)||1,
            shifts: opt.dataset.shifts||''
        };
    });

    function fillCourseFees() {
        var cid = document.getElementById('courseSelect').value;
        var d = courseData[cid];
        if (!d) return;
        document.getElementById('admissionFee').value  = d.adm;
        document.getElementById('totalCourseFee').value = d.total;
        document.getElementById('monthlyFee').value    = d.monthly;
        document.getElementById('examFee').value       = d.exam;
        document.getElementById('certFee').value       = d.cert;
        document.getElementById('durationDisplay').value = d.months;

        // Fill shifts
        var shiftSel = document.getElementById('shiftSelect');
        shiftSel.innerHTML = '';
        d.shifts.split(',').forEach(function(sh) {
            var o = document.createElement('option');
            o.value = o.textContent = sh.trim();
            shiftSel.appendChild(o);
        });

        // Set batch end date
        var startEl = document.querySelector('[name="batch_start_date"]');
        if (startEl.value) {
            var end = new Date(startEl.value);
            end.setMonth(end.getMonth() + d.months);
            document.getElementById('batchEndDate').value = end.toISOString().split('T')[0];
        }

        toggleFeeType();
        calcTotal();
    }

    function toggleFeeType() {
        var cid = document.getElementById('courseSelect').value;
        var d = courseData[cid] || {};
        var ft = document.getElementById('feeTypeSelect').value;
        var total = (ft === 'monthly') ? (d.monthly||0) * (d.months||1) : (d.total||0);
        if (ft !== 'custom') {
            document.getElementById('totalFeeField').value = total + (d.adm||0) + (d.exam||0);
        }
        calcTotal();
    }

    function calcTotal() {
        var total  = parseFloat(document.getElementById('totalFeeField').value)||0;
        var disc   = parseFloat(document.getElementById('discountField').value)||0;
        var paid   = parseFloat(document.getElementById('paidNow').value)||0;
        var bal    = total - disc - paid;
        document.getElementById('balanceDisplay').value = bal.toFixed(2);
    }

    function calcAge() {
        var dob = document.getElementById('dobField').value;
        if (!dob) return;
        var today = new Date();
        var bdate = new Date(dob);
        var age = today.getFullYear() - bdate.getFullYear();
        var m = today.getMonth() - bdate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < bdate.getDate())) age--;
        document.getElementById('ageField').value = age;
    }
    </script>
    <?php
}

function includeViewEnrollment() {
    global $db;
    if (!hasAcademyAccess()) { echo '<div class="message error">Academy access required.</div>'; return; }
    $id = intval($_GET['id'] ?? 0);
    $enr = getEnrollmentById($id);
    if (!$enr) { echo '<div class="message error">Enrollment not found.</div>'; return; }
    $payments    = getEnrollmentPayments($id);
    $installments = getInstallmentSchedule($id);
    $cur = getSetting('currency_symbol', '₹');
    $today = date('Y-m-d');

    if (isset($_SESSION['success'])): ?><div class="message success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif;
    if (isset($_SESSION['error'])): ?><div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif;
    ?>

    <!-- Header Card -->
    <div style="background:linear-gradient(135deg,#2c3e50,#3498db);color:#fff;border-radius:8px;padding:20px 24px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:22px;font-weight:bold;"><?php echo htmlspecialchars($enr['candidate_name']); ?></div>
            <div style="opacity:.85;font-size:13px;"><?php echo htmlspecialchars($enr['relation'].' '.$enr['relative_name']); ?> | <?php echo $enr['age'] ? $enr['age'].' yrs' : ''; ?></div>
            <div style="margin-top:6px;font-size:13px;">📞 <?php echo htmlspecialchars($enr['phone']); ?><?php if ($enr['alternate_phone']): ?> / <?php echo htmlspecialchars($enr['alternate_phone']); ?><?php endif; ?></div>
            <div style="font-size:13px;opacity:.85;"><?php echo htmlspecialchars($enr['qualification']); ?><?php if ($enr['address']): ?> | <?php echo htmlspecialchars(substr($enr['address'],0,60)); ?><?php endif; ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;opacity:.7;margin-bottom:4px;">ENROLLMENT ID</div>
            <div style="font-size:20px;font-weight:bold;letter-spacing:2px;background:rgba(255,255,255,.15);padding:6px 14px;border-radius:4px;"><?php echo htmlspecialchars($enr['enrollment_id']); ?></div>
            <div style="margin-top:8px;font-size:12px;"><?php echo htmlspecialchars($enr['course_name'] ?? '—'); ?><?php if ($enr['course_code']): ?> (<?php echo $enr['course_code']; ?>)<?php endif; ?></div>
            <div style="font-size:12px;">Shift: <?php echo htmlspecialchars($enr['shift'] ?? '—'); ?> | Status: <strong><?php echo ucfirst($enr['status']); ?></strong></div>
        </div>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
        <!-- Fee Summary -->
        <div style="flex:1;min-width:220px;background:#fff;border:1px solid #dde;border-radius:6px;padding:16px;">
            <div style="font-weight:bold;margin-bottom:10px;color:#2c3e50;">💰 Fee Summary</div>
            <table style="width:100%;font-size:13px;">
                <tr><td style="color:#666;padding:3px 0;">Admission Fee:</td><td style="text-align:right;"><?php echo $cur . ' ' . number_format($enr['admission_fee'],2); ?></td></tr>
                <tr><td style="color:#666;padding:3px 0;">Course Fee:</td><td style="text-align:right;"><?php echo $cur . ' ' . number_format($enr['total_fee'] - $enr['admission_fee'] - $enr['exam_fee'] - $enr['certificate_fee'],2); ?></td></tr>
                <?php if ($enr['exam_fee'] > 0): ?><tr><td style="color:#666;padding:3px 0;">Exam Fee:</td><td style="text-align:right;"><?php echo $cur . ' ' . number_format($enr['exam_fee'],2); ?></td></tr><?php endif; ?>
                <?php if ($enr['certificate_fee'] > 0): ?><tr><td style="color:#666;padding:3px 0;">Certificate Fee:</td><td style="text-align:right;"><?php echo $cur . ' ' . number_format($enr['certificate_fee'],2); ?></td></tr><?php endif; ?>
                <?php if ($enr['discount'] > 0): ?><tr><td style="color:#27ae60;padding:3px 0;">Discount:</td><td style="text-align:right;color:#27ae60;">-<?php echo $cur . ' ' . number_format($enr['discount'],2); ?></td></tr><?php endif; ?>
                <tr style="border-top:2px solid #eee;"><td style="font-weight:bold;padding:5px 0;">Total Payable:</td><td style="text-align:right;font-weight:bold;"><?php echo $cur . ' ' . number_format($enr['total_fee'] - $enr['discount'],2); ?></td></tr>
                <tr><td style="color:#27ae60;padding:3px 0;">Amount Paid:</td><td style="text-align:right;color:#27ae60;font-weight:bold;"><?php echo $cur . ' ' . number_format($enr['amount_paid'],2); ?></td></tr>
                <tr><td style="color:<?php echo $enr['balance'] > 0 ? '#e74c3c' : '#27ae60'; ?>;font-weight:bold;padding:3px 0;">Balance Due:</td><td style="text-align:right;color:<?php echo $enr['balance'] > 0 ? '#e74c3c' : '#27ae60'; ?>;font-weight:bold;"><?php echo $cur . ' ' . number_format($enr['balance'],2); ?></td></tr>
            </table>
        </div>
        <!-- Batch Info -->
        <div style="flex:1;min-width:220px;background:#fff;border:1px solid #dde;border-radius:6px;padding:16px;">
            <div style="font-weight:bold;margin-bottom:10px;color:#2c3e50;">📅 Batch Details</div>
            <div style="font-size:13px;line-height:2;">
                <div><strong>Batch Start:</strong> <?php echo $enr['batch_start_date'] ? date('d M Y', strtotime($enr['batch_start_date'])) : '—'; ?></div>
                <div><strong>Batch End:</strong> <?php echo $enr['batch_end_date'] ? date('d M Y', strtotime($enr['batch_end_date'])) : '—'; ?></div>
                <div><strong>Duration:</strong> <?php echo $enr['duration_months'] ?? '—'; ?> months</div>
                <div><strong>Fee Type:</strong> <?php echo ucfirst($enr['fee_type']); ?></div>
                <div><strong>Enrollment Date:</strong> <?php echo date('d M Y', strtotime($enr['created_at'])); ?></div>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                <a href="?page=edit_enrollment&id=<?php echo $id; ?>" class="action-btn edit-btn" style="font-size:12px;">✏️ Edit</a>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="generate_installment_schedule">
                    <input type="hidden" name="enrollment_id" value="<?php echo $id; ?>">
                    <button type="submit" class="action-btn view-btn" style="font-size:12px;" onclick="return confirm('Regenerate installment schedule?')">📅 Regen Schedule</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Payment -->
    <?php if ($enr['balance'] > 0): ?>
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:18px;margin-bottom:18px;">
        <h3 style="margin-bottom:12px;">➕ Record Payment</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_academy_payment">
            <input type="hidden" name="enrollment_id" value="<?php echo $id; ?>">
            <div class="row">
                <div class="form-group">
                    <label>Date: *</label>
                    <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Amount (<?php echo $cur; ?>): *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="<?php echo $enr['balance']; ?>" value="<?php echo $enr['balance']; ?>" required>
                </div>
                <div class="form-group">
                    <label>Fee Type:</label>
                    <select name="fee_type">
                        <option value="course">Course Fee</option>
                        <option value="admission">Admission Fee</option>
                        <option value="exam">Exam Fee</option>
                        <option value="certificate">Certificate Fee</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Method:</label>
                    <select name="payment_method">
                        <?php foreach (explode(',', getSetting('payment_methods','Cash,UPI,Bank Transfer')) as $pm): ?>
                        <option><?php echo trim($pm); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Transaction ID:</label>
                    <input type="text" name="transaction_id" placeholder="UPI Ref / Receipt No.">
                </div>
                <div class="form-group">
                    <label>Notes:</label>
                    <input type="text" name="notes" placeholder="Optional">
                </div>
            </div>
            <button type="submit" style="background:#27ae60;">💳 Record Payment</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Payment History -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:18px;margin-bottom:18px;">
        <h3 style="margin-bottom:12px;">💳 Payment History</h3>
        <?php if (empty($payments)): ?>
        <p style="color:#aaa;text-align:center;padding:16px 0;">No payments recorded yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;"><table>
            <thead><tr><th>#</th><th>Date</th><th>Amount</th><th>Fee Type</th><th>Method</th><th>Receipt</th><th>By</th><?php if (isAdmin() || isManager()): ?><th>Del</th><?php endif; ?></tr></thead>
            <tbody>
            <?php $sn=1; foreach ($payments as $p): ?>
            <tr>
                <td><?php echo $sn++; ?></td>
                <td><?php echo date('d-m-Y', strtotime($p['payment_date'])); ?></td>
                <td style="font-weight:bold;color:#27ae60;"><?php echo $cur . ' ' . number_format($p['amount'],2); ?></td>
                <td><?php echo ucfirst($p['fee_type']); ?></td>
                <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                <td><small><?php echo htmlspecialchars($p['receipt_number']); ?></small></td>
                <td style="font-size:12px;color:#888;"><?php echo htmlspecialchars($p['paid_by'] ?? '—'); ?></td>
                <?php if (isAdmin() || isManager()): ?>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this payment?')">
                        <input type="hidden" name="action" value="delete_academy_payment">
                        <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="enrollment_id" value="<?php echo $id; ?>">
                        <button type="submit" class="action-btn delete-btn" style="border:none;cursor:pointer;font-size:11px;padding:2px 6px;">🗑</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <!-- Installment Schedule -->
    <?php if (!empty($installments)): ?>
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:18px;margin-bottom:18px;">
        <h3 style="margin-bottom:12px;">📅 Installment Schedule</h3>
        <div style="overflow-x:auto;"><table>
            <thead><tr><th>#</th><th>Due Date</th><th>Amount</th><th>Type</th><th>Status</th><th>Paid On</th></tr></thead>
            <tbody>
            <?php foreach ($installments as $inst): 
                $isOverdue = $inst['status'] === 'pending' && $inst['due_date'] < $today;
                $isDueSoon = $inst['status'] === 'pending' && $inst['due_date'] >= $today && $inst['due_date'] <= date('Y-m-d', strtotime('+7 days'));
            ?>
            <tr style="<?php echo $isOverdue ? 'background:#fff5f5;' : ($isDueSoon ? 'background:#fffbf0;' : ''); ?>">
                <td><?php echo $inst['installment_number']; ?></td>
                <td><?php echo date('d M Y', strtotime($inst['due_date'])); ?><?php if ($isOverdue): ?> <span style="color:#e74c3c;font-size:11px;">OVERDUE</span><?php elseif ($isDueSoon): ?> <span style="color:#f39c12;font-size:11px;">DUE SOON</span><?php endif; ?></td>
                <td><strong><?php echo $cur . ' ' . number_format($inst['amount'],2); ?></strong></td>
                <td><?php echo ucfirst($inst['fee_type']); ?></td>
                <td>
                    <span style="padding:2px 8px;border-radius:8px;font-size:11px;font-weight:bold;background:<?php echo $inst['status']==='paid'?'#d4edda':($isOverdue?'#f8d7da':'#fff3cd'); ?>;color:<?php echo $inst['status']==='paid'?'#155724':($isOverdue?'#721c24':'#856404'); ?>;">
                        <?php echo ucfirst($inst['status']); ?>
                    </span>
                </td>
                <td><?php echo $inst['paid_on'] ? date('d-m-Y', strtotime($inst['paid_on'])) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <div style="margin-top:12px;">
        <a href="?page=academy" class="btn-secondary" style="padding:8px 16px;text-decoration:none;">← Back to Enrollments</a>
    </div>
    <?php
}

function includeEditEnrollment() {
    if (!hasAcademyAccess()) { echo '<div class="message error">Access denied.</div>'; return; }
    $id = intval($_GET['id'] ?? 0);
    $enr = getEnrollmentById($id);
    if (!$enr) { echo '<div class="message error">Not found.</div>'; return; }
    $courses = getAcademyCourses(true);
    $cur = getSetting('currency_symbol', '₹');
    ?>
    <h2>✏️ Edit Enrollment: <?php echo htmlspecialchars($enr['enrollment_id']); ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="update_enrollment">
        <input type="hidden" name="enrollment_id" value="<?php echo $id; ?>">
        <input type="hidden" name="amount_paid" value="<?php echo $enr['amount_paid']; ?>">
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:16px;">
            <h3 style="margin-bottom:14px;">👤 Candidate Details</h3>
            <div class="row">
                <div class="form-group"><label>Candidate Name: *</label><input type="text" name="candidate_name" value="<?php echo htmlspecialchars($enr['candidate_name']); ?>" required></div>
                <div class="form-group"><label>Relation:</label><select name="relation">
                    <?php foreach (['F/o','S/o','D/o','W/o','H/o','M/o'] as $r): ?><option value="<?php echo $r; ?>" <?php echo $enr['relation']===$r?'selected':''; ?>><?php echo $r; ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label>Relative's Name:</label><input type="text" name="relative_name" value="<?php echo htmlspecialchars($enr['relative_name']); ?>"></div>
            </div>
            <div class="row">
                <div class="form-group"><label>DOB:</label><input type="date" name="dob" value="<?php echo $enr['dob']; ?>"></div>
                <div class="form-group"><label>Age:</label><input type="number" name="age" value="<?php echo $enr['age']; ?>"></div>
                <div class="form-group"><label>Phone: *</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($enr['phone']); ?>" required></div>
                <div class="form-group"><label>Alt Phone:</label><input type="tel" name="alternate_phone" value="<?php echo htmlspecialchars($enr['alternate_phone']); ?>"></div>
            </div>
            <div class="row">
                <div class="form-group"><label>Email:</label><input type="email" name="email" value="<?php echo htmlspecialchars($enr['email']); ?>"></div>
                <div class="form-group"><label>Qualification:</label><select name="qualification">
                    <?php foreach (['Below 8th','8th Pass','10th Pass','12th Pass','Graduate','Post Graduate','Diploma','ITI','Other'] as $q): ?><option value="<?php echo $q; ?>" <?php echo $enr['qualification']===$q?'selected':''; ?>><?php echo $q; ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label>Status:</label><select name="status">
                    <?php foreach (['active','completed','cancelled','on_hold'] as $st): ?><option value="<?php echo $st; ?>" <?php echo $enr['status']===$st?'selected':''; ?>><?php echo ucfirst($st); ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="form-group"><label>Address:</label><textarea name="address" rows="2"><?php echo htmlspecialchars($enr['address']); ?></textarea></div>
        </div>
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:16px;">
            <h3 style="margin-bottom:14px;">📚 Course & Batch</h3>
            <div class="row">
                <div class="form-group"><label>Course:</label><select name="course_id">
                    <?php foreach ($courses as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo $enr['course_id']==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['course_name']); ?></option><?php endforeach; ?>
                </select></div>
                <div class="form-group"><label>Shift:</label><input type="text" name="shift" value="<?php echo htmlspecialchars($enr['shift']); ?>"></div>
                <div class="form-group"><label>Fee Type:</label><select name="fee_type">
                    <?php foreach (['monthly','total','custom'] as $ft): ?><option value="<?php echo $ft; ?>" <?php echo $enr['fee_type']===$ft?'selected':''; ?>><?php echo ucfirst($ft); ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="row">
                <div class="form-group"><label>Batch Start:</label><input type="date" name="batch_start_date" value="<?php echo $enr['batch_start_date']; ?>"></div>
                <div class="form-group"><label>Batch End:</label><input type="date" name="batch_end_date" value="<?php echo $enr['batch_end_date']; ?>"></div>
            </div>
        </div>
        <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:16px;">
            <h3 style="margin-bottom:14px;">💰 Fee Details</h3>
            <div class="row">
                <div class="form-group"><label>Admission Fee (<?php echo $cur; ?>):</label><input type="number" name="admission_fee" step="0.01" value="<?php echo $enr['admission_fee']; ?>"></div>
                <div class="form-group"><label>Exam Fee (<?php echo $cur; ?>):</label><input type="number" name="exam_fee" step="0.01" value="<?php echo $enr['exam_fee']; ?>"></div>
                <div class="form-group"><label>Certificate Fee (<?php echo $cur; ?>):</label><input type="number" name="certificate_fee" step="0.01" value="<?php echo $enr['certificate_fee']; ?>"></div>
                <div class="form-group"><label>Discount (<?php echo $cur; ?>):</label><input type="number" name="discount" step="0.01" value="<?php echo $enr['discount']; ?>"></div>
                <div class="form-group"><label>Total Fee Payable (<?php echo $cur; ?>):</label><input type="number" name="total_fee" step="0.01" value="<?php echo $enr['total_fee']; ?>" required></div>
            </div>
        </div>
        <div class="form-group"><label>Notes:</label><textarea name="notes" rows="2"><?php echo htmlspecialchars($enr['notes']); ?></textarea></div>
        <div class="action-buttons">
            <button type="submit">Save Changes</button>
            <a href="?page=view_enrollment&id=<?php echo $id; ?>" class="btn-secondary" style="padding:9px 16px;text-decoration:none;">Cancel</a>
        </div>
    </form>
    <?php
}

function includeAcademyReminders() {
    if (!hasAcademyAccess()) { echo '<div class="message error">Access denied.</div>'; return; }
    $reminders = getDueReminders();
    $cur = getSetting('currency_symbol', '₹');
    $today = date('Y-m-d');
    ?>
    <h2>🔔 Payment Reminders</h2>
    <p style="color:#666;margin-bottom:16px;">Showing installments due within the next 7 days and overdue payments.</p>

    <?php if (empty($reminders)): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:24px;text-align:center;color:#155724;">
        <div style="font-size:32px;margin-bottom:8px;">✅</div>
        <strong>All clear! No overdue or upcoming payments.</strong>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;"><table>
        <thead>
            <tr><th>Enrollment ID</th><th>Candidate</th><th>Phone</th><th>Course</th><th>Inst #</th><th>Due Date</th><th>Amount</th><th>Type</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($reminders as $r):
            $isOverdue = $r['due_date'] < $today;
        ?>
        <tr style="background:<?php echo $isOverdue ? '#fff5f5' : '#fffef0'; ?>;">
            <td><strong><?php echo htmlspecialchars($r['enr_id']); ?></strong></td>
            <td><?php echo htmlspecialchars($r['candidate_name']); ?></td>
            <td><?php echo htmlspecialchars($r['phone']); ?></td>
            <td style="font-size:12px;"><?php echo htmlspecialchars($r['course_name'] ?? '—'); ?></td>
            <td style="text-align:center;"><?php echo $r['installment_number']; ?></td>
            <td>
                <?php echo date('d M Y', strtotime($r['due_date'])); ?>
                <br><span style="font-size:11px;font-weight:bold;color:<?php echo $isOverdue ? '#e74c3c' : '#f39c12'; ?>;">
                    <?php echo $isOverdue ? '⚠ OVERDUE by '.abs((int)((strtotime($today)-strtotime($r['due_date']))/86400)).' days' : '⏰ Due in '.abs((int)((strtotime($r['due_date'])-strtotime($today))/86400)).' days'; ?>
                </span>
            </td>
            <td style="font-weight:bold;color:#e74c3c;"><?php echo $cur . ' ' . number_format($r['amount'],2); ?></td>
            <td><?php echo ucfirst($r['fee_type']); ?></td>
            <td><span style="padding:2px 8px;border-radius:8px;font-size:11px;background:#fff3cd;color:#856404;font-weight:bold;"><?php echo ucfirst($r['status']); ?></span></td>
            <td>
                <a href="?page=view_enrollment&id=<?php echo $r['enr_db_id']; ?>" class="action-btn view-btn" style="font-size:11px;">View</a>
                <?php if ($r['phone']): ?>
                <a href="https://wa.me/91<?php echo preg_replace('/[^0-9]/','',$r['phone']); ?>?text=<?php echo urlencode('Dear '.$r['candidate_name'].', your installment #'.$r['installment_number'].' of '.$cur.number_format($r['amount'],2).' is '.($isOverdue?'OVERDUE':'due on '.date('d M Y',strtotime($r['due_date']))).'. Enrollment: '.$r['enr_id'].'. Please visit the academy to make the payment.'); ?>" target="_blank" class="action-btn" style="background:#25D366;color:#fff;font-size:11px;">WhatsApp</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?php
}

function includeUsers() {
    if (!isAdmin()) {
        echo '<div class="message error">Only admin can manage users!</div>';
        return;
    }
    $users = getAllUsers();
    ?>
    <h2>👥 User Management</h2>

    <?php if (isset($_SESSION['success'])): ?><div class="message success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?><div class="message error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div><?php endif; ?>

    <!-- Create New User -->
    <div style="background:#fff;border:1px solid #dde;border-radius:6px;padding:20px;margin-bottom:22px;">
        <h3 style="margin-bottom:16px;color:#2c3e50;border-bottom:2px solid #3498db;padding-bottom:6px;">➕ Create New User</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_user">
            <div class="row">
                <div class="form-group">
                    <label>User ID / Username: *</label>
                    <input type="text" name="username" required placeholder="Login username (no spaces)" pattern="[a-zA-Z0-9_\-\.]+">
                    <small>Letters, numbers, underscore, hyphen only.</small>
                </div>
                <div class="form-group">
                    <label>Full Name: *</label>
                    <input type="text" name="full_name" required placeholder="Staff member's full name">
                </div>
                <div class="form-group">
                    <label>Designation / Role Title:</label>
                    <input type="text" name="designation" placeholder="e.g. Account Executive, Branch Manager">
                </div>
            </div>
            <div class="row">
                <div class="form-group">
                    <label>Email:</label>
                    <input type="email" name="email" placeholder="staff@example.com">
                </div>
                <div class="form-group">
                    <label>Access Level (Role): *</label>
                    <select name="role" id="createRole" required onchange="toggleCreatePerms(this.value)">
                        <option value="accountant">Accountant — Invoice entry only</option>
                        <option value="manager">Manager — Finance & operations</option>
                        <option value="admin">Admin — Full access</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Password: *</label>
                    <input type="password" name="password" required minlength="6" placeholder="Min. 6 characters">
                </div>
            </div>
            <!-- Permission toggles -->
            <div id="createPermsRow" style="display:none;background:#f0f7ff;border:1px solid #cce0ff;border-radius:5px;padding:12px 16px;margin-top:6px;">
                <div style="font-size:13px;font-weight:bold;color:#2c3e50;margin-bottom:8px;">Additional Permissions:</div>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <label id="createNonGSTLabel" style="display:none;font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="has_nongst_access" value="1">
                        &nbsp;Non-GST Invoice Access
                        <small style="color:#888;display:block;margin-left:18px;">View/manage Non-GST invoices</small>
                    </label>
                    <label style="font-size:13px;cursor:pointer;">
                        <input type="checkbox" name="has_academy_access" value="1" id="createAcademyChk">
                        &nbsp;Academy Module Access
                        <small style="color:#888;display:block;margin-left:18px;">Enroll students, record payments</small>
                    </label>
                </div>
            </div>
            <div class="action-buttons" style="margin-top:14px;">
                <button type="submit" style="background:#27ae60;">✅ Create User</button>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <h3 style="margin-bottom:12px;">All Staff Accounts</h3>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>User ID</th>
                <th>Full Name / Designation</th>
                <th>Email</th>
                <th>Access Level</th>
                <th style="text-align:center;">Non-GST</th>
                <th style="text-align:center;">Academy</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
            <tr><td colspan="8" style="text-align:center;color:#aaa;padding:24px;">No users found</td></tr>
            <?php else: foreach ($users as $user): ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                    <?php if ($user['id'] == $_SESSION['user_id']): ?>
                    <span class="user-badge admin" style="font-size:10px;">You</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div><?php echo htmlspecialchars($user['full_name'] ?? 'NA'); ?></div>
                    <small style="color:#888;"><?php echo htmlspecialchars($user['designation'] ?? 'NA'); ?></small>
                </td>
                <td style="font-size:12px;"><?php echo htmlspecialchars($user['email'] ?? '—'); ?></td>
                <td>
                    <span class="user-badge <?php echo $user['role']; ?>">
                        <?php echo ucfirst($user['role']); ?>
                    </span>
                </td>
                <!-- Non-GST toggle -->
                <td style="text-align:center;">
                    <?php if ($user['role'] === 'admin'): ?>
                    <span style="color:#3498db;font-size:11px;">Full</span>
                    <?php elseif ($user['role'] === 'manager'): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="update_nongst_access">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="has_nongst_access" value="<?php echo $user['has_nongst_access'] ? '0' : '1'; ?>">
                        <button type="submit" title="Toggle Non-GST access" style="background:<?php echo $user['has_nongst_access'] ? '#27ae60' : '#bdc3c7'; ?>;color:#fff;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;">
                            <?php echo $user['has_nongst_access'] ? '✅' : '❌'; ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span style="color:#ccc;font-size:11px;">—</span>
                    <?php endif; ?>
                </td>
                <!-- Academy toggle -->
                <td style="text-align:center;">
                    <?php if ($user['role'] === 'admin'): ?>
                    <span style="color:#3498db;font-size:11px;">Full</span>
                    <?php else: ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="update_academy_access">
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                        <input type="hidden" name="has_academy_access" value="<?php echo ($user['has_academy_access'] ?? 0) ? '0' : '1'; ?>">
                        <button type="submit" title="Toggle Academy access" style="background:<?php echo ($user['has_academy_access'] ?? 0) ? '#8e44ad' : '#bdc3c7'; ?>;color:#fff;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;">
                            <?php echo ($user['has_academy_access'] ?? 0) ? '🎓' : '❌'; ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#888;"><?php echo date('d-m-Y', strtotime($user['created_at'])); ?></td>
                <td class="actions-cell">
                    <a href="javascript:void(0)" onclick="editUser(
                        <?php echo $user['id']; ?>,
                        '<?php echo addslashes($user['username']); ?>',
                        '<?php echo addslashes($user['full_name'] ?? 'NA'); ?>',
                        '<?php echo addslashes($user['designation'] ?? 'NA'); ?>',
                        '<?php echo addslashes($user['email'] ?? ''); ?>',
                        '<?php echo $user['role']; ?>',
                        <?php echo intval($user['has_nongst_access'] ?? 0); ?>,
                        <?php echo intval($user['has_academy_access'] ?? 0); ?>
                    )" class="action-btn edit-btn">Edit</a>
                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                    <a href="javascript:void(0)" onclick="confirmDeleteUser(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>')" class="action-btn delete-btn">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;">
        <div style="background:#fff;padding:28px;border-radius:8px;width:560px;max-width:95%;margin:20px auto;">
            <h3 style="margin-bottom:16px;">✏️ Edit User</h3>
            <form id="editUserForm" method="POST">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="editUserId">
                <div class="row">
                    <div class="form-group">
                        <label>User ID / Username: *</label>
                        <input type="text" name="username" id="editUsername" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name: *</label>
                        <input type="text" name="full_name" id="editFullName" required>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>Designation:</label>
                        <input type="text" name="designation" id="editDesignation">
                    </div>
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" id="editEmail">
                    </div>
                </div>
                <div class="form-group">
                    <label>Access Level (Role): *</label>
                    <select name="role" id="editRole" required onchange="toggleEditPerms(this.value)">
                        <option value="accountant">Accountant</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div id="editPermsRow" style="background:#f0f7ff;border:1px solid #cce0ff;border-radius:5px;padding:12px 16px;margin-bottom:10px;">
                    <div style="font-size:13px;font-weight:bold;color:#2c3e50;margin-bottom:8px;">Permissions:</div>
                    <div style="display:flex;gap:24px;flex-wrap:wrap;">
                        <label id="editNonGSTLabel" style="font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="has_nongst_access" id="editNonGSTAccess" value="1">
                            &nbsp;Non-GST Invoice Access
                        </label>
                        <label style="font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="has_academy_access" id="editAcademyAccess" value="1">
                            &nbsp;Academy Module Access
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>New Password <small style="color:#888;">(leave blank to keep current)</small>:</label>
                    <input type="password" name="password" id="editPassword" minlength="6" placeholder="Min. 6 characters">
                </div>
                <div class="action-buttons">
                    <button type="submit">Update User</button>
                    <button type="button" onclick="document.getElementById('editUserModal').style.display='none'" class="btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function toggleCreatePerms(role) {
        var row = document.getElementById('createPermsRow');
        var ngLabel = document.getElementById('createNonGSTLabel');
        row.style.display = (role === 'admin') ? 'none' : 'block';
        ngLabel.style.display = (role === 'manager') ? 'block' : 'none';
        if (role !== 'manager') document.querySelector('[name="has_nongst_access"]').checked = false;
        if (role === 'admin') document.getElementById('createAcademyChk').checked = false;
    }
    function toggleEditPerms(role) {
        var ngLabel = document.getElementById('editNonGSTLabel');
        ngLabel.style.display = (role === 'manager') ? 'block' : 'none';
        document.getElementById('editPermsRow').style.display = (role === 'admin') ? 'none' : 'flex';
        if (role !== 'manager') document.getElementById('editNonGSTAccess').checked = false;
    }
    function editUser(id, username, fullname, designation, email, role, nongst, academy) {
        document.getElementById('editUserId').value      = id;
        document.getElementById('editUsername').value    = username;
        document.getElementById('editFullName').value    = fullname;
        document.getElementById('editDesignation').value = designation;
        document.getElementById('editEmail').value       = email;
        document.getElementById('editRole').value        = role;
        document.getElementById('editNonGSTAccess').checked  = (nongst == 1);
        document.getElementById('editAcademyAccess').checked = (academy == 1);
        document.getElementById('editPassword').value   = '';
        toggleEditPerms(role);
        document.getElementById('editUserModal').style.display = 'flex';
    }
    // Init create permissions
    toggleCreatePerms(document.getElementById('createRole').value);
    </script>
    <?php
}

function getPendingDeleteRequests() {
    global $db;
    
    if (!isAdmin()) return [];
    
    $result = $db->query("
        SELECT dr.*, u.username as requested_by_name 
        FROM delete_requests dr 
        LEFT JOIN users u ON dr.requested_by = u.id 
        WHERE dr.status = 'pending' 
        ORDER BY dr.created_at DESC
    ");
    
    $requests = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $requests[] = $row;
    
    return $requests;
}

function getAllUsers() {
    global $db;
    
    if (!isAdmin()) return [];
    
    $result = $db->query("SELECT * FROM users ORDER BY created_at DESC");
    
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $users[] = $row;
    
    return $users;
}

function getStatistics() {
    global $db;
    
    $stats = [];
    
    $where_clause = "";
    $params = [];
    
    if (!isAdmin()) {
        $where_clause = " WHERE i.created_by = :user_id";
        $params[':user_id'] = $_SESSION['user_id'];
    }
    
    $query = "SELECT COUNT(*) as count FROM invoices i" . $where_clause;
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $stats['total_invoices'] = $result['count'];
    
    $query = "SELECT COUNT(*) as count FROM invoices i WHERE strftime('%Y-%m', invoice_date) = strftime('%Y-%m', 'now')" . 
             (empty($where_clause) ? "" : str_replace('WHERE', 'AND', $where_clause));
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $stats['this_month_invoices'] = $result['count'];
    
    $query = "SELECT i.id FROM invoices i" . $where_clause;
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) $stmt->bindValue($key, $value, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $total_revenue = 0;
    $total_paid = 0;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $invoiceData = getInvoiceData($row['id']);
        if ($invoiceData && isset($invoiceData['totals'])) {
            $total_revenue += $invoiceData['totals']['rounded_total'];
            $total_paid += $invoiceData['totals']['paid_amount'];
        }
    }
    $stats['total_revenue'] = $total_revenue;
    $stats['total_paid'] = $total_paid;
    $stats['total_pending'] = $total_revenue - $total_paid;
    
    if (isAdmin()) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM delete_requests WHERE status = 'pending'");
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $stats['pending_deletions'] = $result['count'];

        // GST Filing Period: 11th prev month → 10th current month
        $today = (int)date('j');
        if ($today >= 11) {
            $period_start = date('Y-m-11');                         // 11th this month
            $period_end   = date('Y-m-10', strtotime('+1 month'));  // 10th next month
        } else {
            $period_start = date('Y-m-11', strtotime('first day of last month'));
            $period_end   = date('Y-m-10');                         // 10th this month
        }

        // Sum GST from invoices in the filing period
        $gst_total = 0;
        $res = $db->query("SELECT id FROM invoices WHERE invoice_date >= '$period_start' AND invoice_date <= '$period_end'");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $inv = getInvoiceData($row['id']);
            if ($inv && isset($inv['totals']['gst_amount'])) {
                $gst_total += $inv['totals']['gst_amount'];
            }
        }
        $stats['gst_filing_period']  = date('d M Y', strtotime($period_start)) . ' – ' . date('d M Y', strtotime($period_end));
        $stats['gst_collected']      = $gst_total;
    }
    
    return $stats;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_template' && isset($_GET['id'])) {
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    
    global $db;
    $template_id = intval($_GET['id']);
    
    $stmt = $db->prepare("SELECT * FROM invoice_templates WHERE id = :id");
    $stmt->bindValue(':id', $template_id, SQLITE3_INTEGER);
    $template = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($template) {
        echo json_encode([
            'success' => true,
            'template' => $template
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Template not found'
        ]);
    }
    exit();
}

// ── CHAT AJAX HANDLERS (must be before HTML output) ──
if (isset($_GET['ajax']) && isLoggedIn()) {
    header('Content-Type: application/json');
    chatHeartbeat();
    $chatAjax = $_GET['ajax'];

    if ($chatAjax === 'chat_users') {
        echo json_encode(['users' => chatGetOnlineUsers()]);
        exit();
    }
    if ($chatAjax === 'chat_messages') {
        $with   = intval($_GET['with'] ?? 0);
        $sinceId= intval($_GET['since'] ?? 0);
        if ($with) {
            $msgs = chatGetMessages($with, $sinceId);
            $me   = $_SESSION['user_id'];
            echo json_encode(['messages' => $msgs, 'my_id' => $me]);
        } else {
            echo json_encode(['messages' => [], 'my_id' => $_SESSION['user_id']]);
        }
        exit();
    }
    if ($chatAjax === 'chat_send') {
        $to  = intval($_POST['to'] ?? 0);
        $msg = trim($_POST['msg'] ?? '');
        $id  = chatSendMessage($to, $msg);
        echo json_encode(['ok' => (bool)$id, 'id' => $id]);
        exit();
    }
    if ($chatAjax === 'chat_heartbeat') {
        echo json_encode(['ok' => true, 'unread' => chatTotalUnread(), 'ts' => time()]);
        exit();
    }
    if ($chatAjax === 'stats_poll') {
        // Real-time stats for dashboard polling
        $stats = getStatistics();
        echo json_encode($stats);
        exit();
    }
    if ($chatAjax === 'get_yatra_share') {
        $bid = intval($_GET['id']??0);
        $tok = generateYatraShareToken($bid);
        $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
        $host=$_SERVER['HTTP_HOST']??'localhost';
        $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
        echo json_encode(['url'=>$proto.$host.$dir.'/view.php?UniqueToken='.$tok]);
        exit();
    }
    if ($chatAjax === 'get_enrollment_share') {
        global $db;
        $eid=intval($_GET['id']??0); $type=$_GET['type']??'enrollment';
        $pfx=$type==='certificate'?'cer':'enr';
        $tok=$pfx.bin2hex(random_bytes(16));
        $db->exec("UPDATE academy_enrollment_shares SET is_active=0 WHERE enrollment_id=".intval($eid)." AND share_type='".SQLite3::escapeString($type)."'");
        $s=$db->prepare("INSERT OR IGNORE INTO academy_enrollment_shares(enrollment_id,share_token,share_type,created_by,expires_at) VALUES(:id,:t,:st,:uid,datetime('now','+30 days'))");
        $s->bindValue(':id',$eid,SQLITE3_INTEGER); $s->bindValue(':t',$tok,SQLITE3_TEXT);
        $s->bindValue(':st',$type,SQLITE3_TEXT); $s->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
        $s->execute();
        $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
        $host=$_SERVER['HTTP_HOST']??'localhost';
        $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
        echo json_encode(['url'=>$proto.$host.$dir.'/view.php?UniqueToken='.$tok]);
        exit();
    }
}

$page = $_GET['page'] ?? 'dashboard';
if (!isLoggedIn() && $page !== 'login') $page = 'login';
if (isLoggedIn()) chatHeartbeat();

if ($page !== 'create_invoice' && isset($_SESSION['last_invoice_id'])) {
    unset($_SESSION['last_invoice_id']);
}


// ═══════════════════════════════════════════════════════
// YATRA HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════

function generatePNR() {
    global $db;
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $pnr = '';
        for($i=0;$i<5;$i++) $pnr .= $chars[random_int(0,strlen($chars)-1)];
        $ex = $db->querySingle("SELECT id FROM yatra_bookings WHERE pnr='".SQLite3::escapeString($pnr)."'");
    } while($ex);
    return $pnr;
}

function generateYatraRef() {
    global $db;
    $cnt = $db->querySingle("SELECT COUNT(*) FROM yatra_bookings WHERE strftime('%Y%m',created_at)='".date('Ym')."'");
    return 'YTR'.date('Ym').str_pad($cnt+1,4,'0',STR_PAD_LEFT);
}

function getAllYatras($archived=false) {
    global $db;
    $a=$archived?1:0;
    $q="SELECT y.*,(SELECT COUNT(*) FROM yatra_bookings yb WHERE yb.yatra_id=y.id AND yb.status!='cancelled') as booking_count FROM yatras y WHERE y.is_archived=$a ORDER BY y.departure_date ASC";
    $res=$db->query($q); $rows=[];
    while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$r;
    return $rows;
}

function getYatraById($id) {
    global $db;
    $s=$db->prepare("SELECT * FROM yatras WHERE id=:id");
    $s->bindValue(':id',$id,SQLITE3_INTEGER);
    return $s->execute()->fetchArray(SQLITE3_ASSOC);
}

function getAllYatraBookings($yid=0,$search='') {
    global $db;
    $q="SELECT yb.*,y.departure_date FROM yatra_bookings yb LEFT JOIN yatras y ON yb.yatra_id=y.id WHERE 1=1";
    $p=[];
    if($yid){$q.=" AND yb.yatra_id=:yid";$p[':yid']=$yid;}
    if($search){$q.=" AND (yb.lead_passenger_name LIKE :s OR yb.booking_ref LIKE :s2 OR yb.phone LIKE :s3 OR yb.pnr LIKE :s4)";$p[':s']=$p[':s2']=$p[':s3']=$p[':s4']="%$search%";}
    $q.=" ORDER BY yb.created_at DESC";
    $stmt=$db->prepare($q);
    foreach($p as $k=>$v) $stmt->bindValue($k,$v,SQLITE3_TEXT);
    $res=$stmt->execute(); $rows=[];
    while($r=$res->fetchArray(SQLITE3_ASSOC)) $rows[]=$r;
    return $rows;
}

function getYatraBookingById($id) {
    global $db;
    $s=$db->prepare("SELECT yb.*,y.departure_date,y.return_date,y.bus_details,y.destination FROM yatra_bookings yb LEFT JOIN yatras y ON yb.yatra_id=y.id WHERE yb.id=:id");
    $s->bindValue(':id',$id,SQLITE3_INTEGER);
    $bk=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$bk) return null;
    $sp=$db->prepare("SELECT * FROM yatra_passengers WHERE booking_id=:id ORDER BY id");
    $sp->bindValue(':id',$id,SQLITE3_INTEGER);
    $res=$sp->execute(); $bk['passengers']=[];
    while($r=$res->fetchArray(SQLITE3_ASSOC)) $bk['passengers'][]=$r;
    $spm=$db->prepare("SELECT * FROM yatra_payments WHERE booking_id=:id ORDER BY payment_date ASC");
    $spm->bindValue(':id',$id,SQLITE3_INTEGER);
    $res=$spm->execute(); $bk['payments']=[];
    while($r=$res->fetchArray(SQLITE3_ASSOC)) $bk['payments'][]=$r;
    return $bk;
}

function generateYatraShareToken($bid) {
    global $db;
    $tok='yat'.bin2hex(random_bytes(16));
    $s=$db->prepare("INSERT INTO yatra_booking_shares(yatra_booking_id,share_token,created_by,expires_at) VALUES(:id,:t,:uid,datetime('now','+30 days'))");
    $s->bindValue(':id',$bid,SQLITE3_INTEGER);
    $s->bindValue(':t',$tok,SQLITE3_TEXT);
    $s->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
    $s->execute();
    return $tok;
}

// ═══════════════════════════════════════════════════════
// QR VERIFICATION FUNCTIONS
// ═══════════════════════════════════════════════════════

function generateVerifyToken($docType,$docId,$docNumber,$docTitle='') {
    global $db;
    $db->exec("UPDATE qr_verifications SET is_active=0 WHERE doc_type='".SQLite3::escapeString($docType)."' AND doc_id=".intval($docId));
    $tok = strtoupper(bin2hex(random_bytes(12)));
    $s=$db->prepare("INSERT INTO qr_verifications(token,doc_type,doc_id,doc_number,doc_title) VALUES(:t,:dt,:di,:dn,:dtl)");
    $s->bindValue(':t',$tok,SQLITE3_TEXT);
    $s->bindValue(':dt',$docType,SQLITE3_TEXT);
    $s->bindValue(':di',$docId,SQLITE3_INTEGER);
    $s->bindValue(':dn',$docNumber,SQLITE3_TEXT);
    $s->bindValue(':dtl',$docTitle,SQLITE3_TEXT);
    $s->execute();
    return $tok;
}

function getVerifyUrl($tok) {
    $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
    return $proto.$host.$dir.'/view.php?verify='.$tok;
}

// ═══════════════════════════════════════════════════════
// YATRA ACTION HANDLERS
// ═══════════════════════════════════════════════════════

function saveYatra() {
    global $db;
    if(!isAdmin()&&!isManager()){$_SESSION['error']='Access denied';header('Location: ?page=yatra');exit();}
    $id=intval($_POST['yatra_id']??0);
    $yn=trim($_POST['yatra_name']??''); $dest=trim($_POST['destination']??'');
    if(!$yn||!$dest){$_SESSION['error']='Name and destination required';header('Location: ?page=yatra');exit();}
    $ppa=floatval($_POST['per_person_amount']??0);
    $dep=trim($_POST['departure_date']??''); $ret=trim($_POST['return_date']??'');
    $cl=trim($_POST['closing_date']??''); $bus=trim($_POST['bus_details']??'');
    $seats=intval($_POST['total_seats']??0); $desc=trim($_POST['description']??'');
    if($id) {
        $s=$db->prepare("UPDATE yatras SET yatra_name=:yn,destination=:dest,departure_date=:dep,return_date=:ret,closing_date=:cl,bus_details=:bus,per_person_amount=:ppa,total_seats=:seats,description=:desc,updated_at=CURRENT_TIMESTAMP WHERE id=:id");
        $s->bindValue(':id',$id,SQLITE3_INTEGER);
    } else {
        $s=$db->prepare("INSERT INTO yatras(yatra_name,destination,departure_date,return_date,closing_date,bus_details,per_person_amount,total_seats,description,created_by) VALUES(:yn,:dest,:dep,:ret,:cl,:bus,:ppa,:seats,:desc,:uid)");
        $s->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
    }
    $s->bindValue(':yn',$yn,SQLITE3_TEXT); $s->bindValue(':dest',$dest,SQLITE3_TEXT);
    $s->bindValue(':dep',$dep,SQLITE3_TEXT); $s->bindValue(':ret',$ret,SQLITE3_TEXT);
    $s->bindValue(':cl',$cl,SQLITE3_TEXT); $s->bindValue(':bus',$bus,SQLITE3_TEXT);
    $s->bindValue(':ppa',$ppa,SQLITE3_FLOAT); $s->bindValue(':seats',$seats,SQLITE3_INTEGER);
    $s->bindValue(':desc',$desc,SQLITE3_TEXT);
    $s->execute();
    $_SESSION['success']=$id?'Yatra updated!':'Yatra created!';
    header('Location: ?page=yatra'); exit();
}

function deleteYatra() {
    global $db;
    if(!isAdmin()){$_SESSION['error']='Admin only';header('Location: ?page=yatra');exit();}
    $id=intval($_POST['yatra_id']??0);
    $db->exec("DELETE FROM yatras WHERE id=$id");
    $_SESSION['success']='Yatra deleted.'; header('Location: ?page=yatra'); exit();
}

function archiveYatra() {
    global $db;
    $id=intval($_POST['yatra_id']??0);
    $db->exec("UPDATE yatras SET is_archived=1,status='archived',updated_at=CURRENT_TIMESTAMP WHERE id=$id");
    $_SESSION['success']='Yatra archived.'; header('Location: ?page=yatra'); exit();
}

function unarchiveYatra() {
    global $db;
    $id=intval($_POST['yatra_id']??0);
    $db->exec("UPDATE yatras SET is_archived=0,status='active',updated_at=CURRENT_TIMESTAMP WHERE id=$id");
    $_SESSION['success']='Yatra restored.'; header('Location: ?page=yatra'); exit();
}

function createYatraBooking() {
    global $db;
    $yid=intval($_POST['yatra_id']??0);
    $yatra=getYatraById($yid);
    if(!$yatra){$_SESSION['error']='Invalid yatra';header('Location: ?page=create_yatra_booking');exit();}
    $ref=generateYatraRef(); $pnr=generatePNR();
    $pass=json_decode($_POST['passengers_json']??'[]',true);
    $cnt=max(1,count($pass));
    $ta=floatval($_POST['total_amount']??0);
    $ba=floatval($_POST['booking_amount']??0);
    $bal=$ta-$ba;
    $ps=$ba>=$ta?'paid':($ba>0?'partial':'unpaid');
    try {
        $db->exec('BEGIN');
        $s=$db->prepare("INSERT INTO yatra_bookings(booking_ref,pnr,yatra_id,yatra_name,lead_passenger_name,phone,email,address,emergency_contact,emergency_contact_name,total_passengers,booking_amount,total_amount,amount_paid,balance,payment_status,booking_date,notes,created_by) VALUES(:ref,:pnr,:yid,:yn,:lpn,:ph,:em,:ad,:ec,:ecn,:cnt,:ba,:ta,:ba,:bal,:ps,:bd,:n,:uid)");
        $s->bindValue(':ref',$ref,SQLITE3_TEXT); $s->bindValue(':pnr',$pnr,SQLITE3_TEXT);
        $s->bindValue(':yid',$yid,SQLITE3_INTEGER); $s->bindValue(':yn',$yatra['yatra_name'],SQLITE3_TEXT);
        $s->bindValue(':lpn',trim($_POST['lead_passenger_name']??''),SQLITE3_TEXT);
        $s->bindValue(':ph',trim($_POST['phone']??''),SQLITE3_TEXT);
        $s->bindValue(':em',trim($_POST['email']??''),SQLITE3_TEXT);
        $s->bindValue(':ad',trim($_POST['address']??''),SQLITE3_TEXT);
        $s->bindValue(':ec',trim($_POST['emergency_contact']??''),SQLITE3_TEXT);
        $s->bindValue(':ecn',trim($_POST['emergency_contact_name']??''),SQLITE3_TEXT);
        $s->bindValue(':cnt',$cnt,SQLITE3_INTEGER); $s->bindValue(':ba',$ba,SQLITE3_FLOAT);
        $s->bindValue(':ta',$ta,SQLITE3_FLOAT); $s->bindValue(':bal',$bal,SQLITE3_FLOAT);
        $s->bindValue(':ps',$ps,SQLITE3_TEXT);
        $s->bindValue(':bd',trim($_POST['booking_date']??date('Y-m-d')),SQLITE3_TEXT);
        $s->bindValue(':n',trim($_POST['notes']??''),SQLITE3_TEXT);
        $s->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
        $s->execute();
        $bid=$db->lastInsertRowID();
        foreach($pass as $p) {
            $sp=$db->prepare("INSERT INTO yatra_passengers(booking_id,name,age,gender,id_proof_type,id_proof_number) VALUES(:bid,:n,:a,:g,:ipt,:ipn)");
            $sp->bindValue(':bid',$bid,SQLITE3_INTEGER);
            $sp->bindValue(':n',trim($p['name']??''),SQLITE3_TEXT);
            $sp->bindValue(':a',intval($p['age']??0),SQLITE3_INTEGER);
            $sp->bindValue(':g',trim($p['gender']??'Male'),SQLITE3_TEXT);
            $sp->bindValue(':ipt',trim($p['id_proof_type']??''),SQLITE3_TEXT);
            $sp->bindValue(':ipn',trim($p['id_proof_number']??''),SQLITE3_TEXT);
            $sp->execute();
        }
        if($ba>0) {
            $spm=$db->prepare("INSERT INTO yatra_payments(booking_id,payment_date,amount,payment_method,transaction_id,created_by) VALUES(:bid,:pd,:a,:pm,:tid,:uid)");
            $spm->bindValue(':bid',$bid,SQLITE3_INTEGER);
            $spm->bindValue(':pd',date('Y-m-d'),SQLITE3_TEXT);
            $spm->bindValue(':a',$ba,SQLITE3_FLOAT);
            $spm->bindValue(':pm',trim($_POST['payment_method']??'Cash'),SQLITE3_TEXT);
            $spm->bindValue(':tid',trim($_POST['transaction_id']??''),SQLITE3_TEXT);
            $spm->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
            $spm->execute();
        }
        $db->exec('COMMIT');
        generateVerifyToken('yatra',$bid,$pnr,'Yatra Booking '.$ref);
        $_SESSION['success']="Booking created! PNR: $pnr";
        header("Location: ?page=view_yatra_booking&id=$bid"); exit();
    } catch(Exception $e) {
        $db->exec('ROLLBACK');
        $_SESSION['error']='Error: '.$e->getMessage();
        header('Location: ?page=create_yatra_booking'); exit();
    }
}

function updateYatraBooking() {
    global $db;
    $id=intval($_POST['booking_id']??0);
    $pass=json_decode($_POST['passengers_json']??'[]',true);
    $ta=floatval($_POST['total_amount']??0);
    $ba=floatval($_POST['booking_amount']??0);
    $paid=$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM yatra_payments WHERE booking_id=$id");
    $bal=$ta-$paid;
    $ps=$paid>=$ta?'paid':($paid>0?'partial':'unpaid');
    $db->exec('BEGIN');
    $s=$db->prepare("UPDATE yatra_bookings SET lead_passenger_name=:lpn,phone=:ph,email=:em,address=:ad,emergency_contact=:ec,emergency_contact_name=:ecn,total_passengers=:cnt,booking_amount=:ba,total_amount=:ta,balance=:bal,payment_status=:ps,notes=:n,updated_at=CURRENT_TIMESTAMP WHERE id=:id");
    $s->bindValue(':lpn',trim($_POST['lead_passenger_name']??''),SQLITE3_TEXT);
    $s->bindValue(':ph',trim($_POST['phone']??''),SQLITE3_TEXT);
    $s->bindValue(':em',trim($_POST['email']??''),SQLITE3_TEXT);
    $s->bindValue(':ad',trim($_POST['address']??''),SQLITE3_TEXT);
    $s->bindValue(':ec',trim($_POST['emergency_contact']??''),SQLITE3_TEXT);
    $s->bindValue(':ecn',trim($_POST['emergency_contact_name']??''),SQLITE3_TEXT);
    $s->bindValue(':cnt',max(1,count($pass)),SQLITE3_INTEGER);
    $s->bindValue(':ba',$ba,SQLITE3_FLOAT); $s->bindValue(':ta',$ta,SQLITE3_FLOAT);
    $s->bindValue(':bal',$bal,SQLITE3_FLOAT); $s->bindValue(':ps',$ps,SQLITE3_TEXT);
    $s->bindValue(':n',trim($_POST['notes']??''),SQLITE3_TEXT);
    $s->bindValue(':id',$id,SQLITE3_INTEGER);
    $s->execute();
    $db->exec("DELETE FROM yatra_passengers WHERE booking_id=$id");
    foreach($pass as $p) {
        $sp=$db->prepare("INSERT INTO yatra_passengers(booking_id,name,age,gender,id_proof_type,id_proof_number) VALUES(:bid,:n,:a,:g,:ipt,:ipn)");
        $sp->bindValue(':bid',$id,SQLITE3_INTEGER);
        $sp->bindValue(':n',trim($p['name']??''),SQLITE3_TEXT);
        $sp->bindValue(':a',intval($p['age']??0),SQLITE3_INTEGER);
        $sp->bindValue(':g',trim($p['gender']??''),SQLITE3_TEXT);
        $sp->bindValue(':ipt',trim($p['id_proof_type']??''),SQLITE3_TEXT);
        $sp->bindValue(':ipn',trim($p['id_proof_number']??''),SQLITE3_TEXT);
        $sp->execute();
    }
    $db->exec('COMMIT');
    $_SESSION['success']='Booking updated!'; header("Location: ?page=view_yatra_booking&id=$id"); exit();
}

function addYatraPayment() {
    global $db;
    $bid=intval($_POST['booking_id']??0);
    $amt=floatval($_POST['amount']??0);
    if($amt<=0){$_SESSION['error']='Invalid amount';header("Location: ?page=view_yatra_booking&id=$bid");exit();}
    $s=$db->prepare("INSERT INTO yatra_payments(booking_id,payment_date,amount,payment_method,transaction_id,notes,created_by) VALUES(:bid,:pd,:a,:pm,:tid,:n,:uid)");
    $s->bindValue(':bid',$bid,SQLITE3_INTEGER);
    $s->bindValue(':pd',trim($_POST['payment_date']??date('Y-m-d')),SQLITE3_TEXT);
    $s->bindValue(':a',$amt,SQLITE3_FLOAT);
    $s->bindValue(':pm',trim($_POST['payment_method']??'Cash'),SQLITE3_TEXT);
    $s->bindValue(':tid',trim($_POST['transaction_id']??''),SQLITE3_TEXT);
    $s->bindValue(':n',trim($_POST['notes']??''),SQLITE3_TEXT);
    $s->bindValue(':uid',$_SESSION['user_id']??0,SQLITE3_INTEGER);
    $s->execute();
    $total=$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM yatra_payments WHERE booking_id=$bid");
    $ta=$db->querySingle("SELECT total_amount FROM yatra_bookings WHERE id=$bid");
    $bal=$ta-$total; $ps=$total>=$ta?'paid':($total>0?'partial':'unpaid');
    $db->exec("UPDATE yatra_bookings SET amount_paid=$total,balance=$bal,payment_status='$ps',updated_at=CURRENT_TIMESTAMP WHERE id=$bid");
    $_SESSION['success']='Payment recorded.'; header("Location: ?page=view_yatra_booking&id=$bid"); exit();
}

function deleteYatraPayment() {
    global $db;
    if(!isAdmin()&&!isManager()){$_SESSION['error']='Access denied';header('Location: ?page=yatra_bookings');exit();}
    $pid=intval($_POST['payment_id']??0); $bid=intval($_POST['booking_id']??0);
    $db->exec("DELETE FROM yatra_payments WHERE id=$pid");
    $total=$db->querySingle("SELECT COALESCE(SUM(amount),0) FROM yatra_payments WHERE booking_id=$bid");
    $ta=$db->querySingle("SELECT total_amount FROM yatra_bookings WHERE id=$bid");
    $bal=$ta-$total; $ps=$total>=$ta?'paid':($total>0?'partial':'unpaid');
    $db->exec("UPDATE yatra_bookings SET amount_paid=$total,balance=$bal,payment_status='$ps',updated_at=CURRENT_TIMESTAMP WHERE id=$bid");
    $_SESSION['success']='Payment deleted.'; header("Location: ?page=view_yatra_booking&id=$bid"); exit();
}

function cancelYatraBooking() {
    global $db;
    $id=intval($_POST['booking_id']??0);
    $db->exec("UPDATE yatra_bookings SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=$id");
    $_SESSION['success']='Booking cancelled.'; header("Location: ?page=view_yatra_booking&id=$id"); exit();
}
</script>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Security-Policy" content="img-src * data:;">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(getSetting('company_name','Invoice System')); ?> — Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════
       RESET & BASE
    ═══════════════════════════════════════════════════════ */
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root {
        --primary: #4f46e5;
        --primary-dark: #3730a3;
        --primary-light: #eef2ff;
        --accent: #06b6d4;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --purple: #8b5cf6;
        --bg: #f1f5f9;
        --surface: #ffffff;
        --surface2: #f8fafc;
        --border: #e2e8f0;
        --text: #1e293b;
        --text2: #64748b;
        --text3: #94a3b8;
        --nav-bg: #1e1b4b;
        --nav-text: #c7d2fe;
        --nav-hover: #3730a3;
        --nav-active: #4f46e5;
        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    }
    html { scroll-behavior: smooth; }
    body {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.6;
        min-height: 100vh;
        font-size: 14px;
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* ═══════════════════════════════════════════════════════
       LAYOUT — top-nav horizontal bar (.app-nav)
    ═══════════════════════════════════════════════════════ */
    /* NAV ITEMS — inside .app-nav horizontal bar */
    .nav-item {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 14px;
        color: rgba(255,255,255,0.78);
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background .18s, color .18s;
        text-decoration: none;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .nav-item:hover { background: rgba(255,255,255,0.12); color: #fff; text-decoration: none; }
    .nav-item.active { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,0.5); }
    .nav-item-right { margin-left: auto; }
    .nav-icon { font-size: 15px; line-height: 1; flex-shrink: 0; }
    .nav-label { line-height: 1; }
    .nav-badge {
        background: var(--danger);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 5px;
        border-radius: 9px;
        min-width: 16px;
        text-align: center;
    }

    /* DROPDOWN GROUPS — absolute-positioned flyout */
    .nav-group { position: relative; flex-shrink: 0; display: inline-block; }
    .nav-group-trigger {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 14px;
        color: rgba(255,255,255,0.78);
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background .18s, color .18s;
        user-select: none;
        white-space: nowrap;
    }
    .nav-group-trigger:hover { background: rgba(255,255,255,0.12); color: #fff; }
    .nav-group-trigger.active { background: rgba(79,70,229,0.45); color: #fff; }
    .nav-arrow { font-size: 10px; margin-left: 2px; transition: transform .2s; }
    .nav-group.open .nav-arrow { transform: rotate(180deg); }
    .nav-group.open .nav-group-trigger { background: rgba(255,255,255,0.1); }

    .nav-dropdown {
        display: none;
        position: fixed; /* JS sets top/left; fixed escapes all overflow contexts */
        min-width: 200px;
        background: #1e1b4b;
        border: 1px solid rgba(255,255,255,0.14);
        border-radius: 10px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        padding: 6px;
        z-index: 99999; /* above everything */
    }
    .nav-group.open .nav-dropdown { display: block; }
    .nav-sub {
        display: block;
        padding: 9px 13px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        border-radius: 6px;
        transition: background .15s, color .15s;
        white-space: nowrap;
        border-left: 3px solid transparent;
    }
    .nav-sub:hover { background: rgba(255,255,255,0.1); color: #fff; text-decoration: none; }
    .nav-sub.active { background: rgba(79,70,229,0.4); color: #fff; border-left-color: var(--primary); }
    .container { width: 100%; min-height: 100vh; display: flex; flex-direction: column; }

    /* Mobile hamburger */
    .mob-menu-btn {
        display: none;
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--text);
        padding: 4px;
    }
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 199;
    }

    /* ═══════════════════════════════════════════════════════
       CARDS & PANELS
    ═══════════════════════════════════════════════════════ */
    .card {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .card-title { font-size: 15px; font-weight: 700; color: var(--text); }
    .card-body { padding: 20px; }

    .main-content {
        background: var(--bg);
        padding: 24px;
        flex: 1;
        max-width: 1280px;
        margin: 0 auto;
        width: 100%;
    }
    .main-content > .card, .main-content > form, .main-content > div:not(.message) {
        background: var(--surface);
    }

    /* ═══════════════════════════════════════════════════════
       STATS GRID
    ═══════════════════════════════════════════════════════ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .stat-card-v2 {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        border-left: 4px solid var(--primary);
        padding: 18px 16px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 4px;
        transition: transform .2s, box-shadow .2s;
        cursor: default;
    }
    .stat-card-v2:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .stat-card-v2.green  { border-left-color: var(--success); }
    .stat-card-v2.orange { border-left-color: var(--warning); }
    .stat-card-v2.red    { border-left-color: var(--danger); }
    .stat-card-v2.purple { border-left-color: var(--purple); }
    .stat-card-v2 .sc-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--text3); }
    .stat-card-v2 .sc-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1.1; }
    .stat-card-v2 .sc-link { font-size: 12px; color: var(--primary); text-decoration: none; margin-top: 2px; }
    .stat-card-v2 .sc-link:hover { text-decoration: underline; }

    /* ═══════════════════════════════════════════════════════
       FORMS & INPUTS
    ═══════════════════════════════════════════════════════ */
    .form-group { margin-bottom: 18px; }
    label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: var(--text2);
        font-size: 13px;
    }
    input[type="text"], input[type="tel"], input[type="number"],
    input[type="date"], input[type="password"], input[type="email"],
    input[type="url"], textarea, select {
        width: 100%;
        padding: 9px 12px;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: 16px; /* prevents iOS auto-zoom on focus */
        font-family: inherit;
        color: var(--text);
        background: var(--surface);
        transition: border-color .2s, box-shadow .2s;
        outline: none;
        -webkit-appearance: none;
        appearance: none;
    }
    select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:30px; }
    input:focus, textarea:focus, select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,0.12);
    }
    @media (min-width: 769px) {
        input[type="text"], input[type="tel"], input[type="number"],
        input[type="date"], input[type="password"], input[type="email"],
        input[type="url"], textarea, select { font-size: 14px; }
    }
    input[readonly], input[disabled] { background: var(--surface2); color: var(--text3); }
    textarea { resize: vertical; min-height: 80px; }
    .row { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
    .row > .form-group { flex: 1; min-width: 160px; margin-bottom: 0; }
    small { font-size: 11.5px; color: var(--text3); display: block; margin-top: 4px; }

    /* ═══════════════════════════════════════════════════════
       BUTTONS
    ═══════════════════════════════════════════════════════ */
    button, .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: var(--radius-sm);
        border: none;
        font-size: 13.5px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: all .18s;
        text-decoration: none;
        white-space: nowrap;
        background: var(--primary);
        color: #fff;
    }
    button:hover, .btn:hover { opacity: .88; transform: translateY(-1px); box-shadow: var(--shadow-md); text-decoration: none; }
    button:active { transform: translateY(0); }
    .btn-secondary { background: var(--surface2); color: var(--text2); border: 1.5px solid var(--border); }
    .btn-secondary:hover { background: var(--border); color: var(--text); }
    .btn-danger, .btn-danger:hover { background: var(--danger); color: #fff; }
    .btn-warning, .btn-warning:hover { background: var(--warning); color: #fff; }
    .btn-success, .btn-success:hover { background: var(--success); color: #fff; }
    .btn-print { background: var(--success); color: #fff; }
    .btn-whatsapp { background: #25D366; color: #fff; }
    .btn-purple { background: var(--purple); color: #fff; }
    .action-buttons { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; align-items: center; }
    .action-btn {
        padding: 5px 12px;
        font-size: 12px;
        border-radius: 6px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .15s;
        gap: 4px;
    }
    .action-btn:hover { opacity: .85; transform: translateY(-1px); text-decoration: none; }
    .view-btn { background: #dbeafe; color: #1d4ed8; }
    .edit-btn { background: #fef3c7; color: #d97706; }
    .delete-btn { background: #fee2e2; color: #dc2626; }
    .actions-cell { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }

    /* ═══════════════════════════════════════════════════════
       TABLES
    ═══════════════════════════════════════════════════════ */
    table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    th {
        background: var(--surface2);
        font-weight: 700;
        color: var(--text2);
        border: 1px solid var(--border);
        padding: 10px 12px;
        text-align: left;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .4px;
        white-space: nowrap;
    }
    td { border: 1px solid var(--border); padding: 10px 12px; color: var(--text); vertical-align: middle; }
    tr:hover > td { background: var(--surface2); }
    tfoot td { font-weight: 700; background: var(--surface2); }

    /* ═══════════════════════════════════════════════════════
       MESSAGES / ALERTS
    ═══════════════════════════════════════════════════════ */
    .message {
        padding: 12px 16px;
        margin-bottom: 16px;
        border-radius: var(--radius-sm);
        font-size: 13.5px;
        border-left: 4px solid;
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }
    .success { background: #ecfdf5; color: #065f46; border-color: var(--success); }
    .error   { background: #fef2f2; color: #991b1b; border-color: var(--danger); }
    .info    { background: #eff6ff; color: #1e40af; border-color: var(--accent); }
    .warning { background: #fffbeb; color: #92400e; border-color: var(--warning); }

    /* ═══════════════════════════════════════════════════════
       BADGES & PILLS
    ═══════════════════════════════════════════════════════ */
    .user-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .3px;
    }
    .user-badge.admin { background: #fce7f3; color: #be185d; }
    .user-badge.manager { background: #fef3c7; color: #d97706; }
    .user-badge.accountant { background: #dcfce7; color: #15803d; }
    .payment-badge { padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .payment-badge.unpaid { background: #fee2e2; color: #dc2626; }
    .payment-badge.partially_paid { background: #fef3c7; color: #d97706; }
    .payment-badge.paid { background: #dcfce7; color: #15803d; }
    .payment-badge.settled { background: #dbeafe; color: #1d4ed8; }

    /* ═══════════════════════════════════════════════════════
       DASHBOARD SPECIFICS
    ═══════════════════════════════════════════════════════ */
    .dash-welcome {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .dash-welcome h2 { margin: 0; font-size: 22px; font-weight: 800; color: var(--text); }
    .dash-role-badge { padding: 5px 14px; border-radius: 20px; font-size: 11.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
    .role-admin     { background: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
    .role-manager   { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .role-accountant{ background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .dash-section-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dash-quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: var(--radius-sm);
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: all .18s;
        color: #fff;
        box-shadow: var(--shadow);
    }
    .quick-btn:hover { opacity: .88; transform: translateY(-2px); box-shadow: var(--shadow-md); text-decoration: none; }
    .qb-blue   { background: linear-gradient(135deg, var(--primary), #6366f1); }
    .qb-green  { background: linear-gradient(135deg, var(--success), #34d399); }
    .qb-orange { background: linear-gradient(135deg, var(--warning), #fbbf24); }
    .qb-purple { background: linear-gradient(135deg, var(--purple), #a78bfa); }

    /* ═══════════════════════════════════════════════════════
       HEADER (legacy compat)
    ═══════════════════════════════════════════════════════ */
    /* Top info bar above nav (company name + logout) */
    .header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 16px;
        background: var(--primary-dark);
        color: rgba(255,255,255,0.9);
        font-size: 12px;
        gap: 10px;
    }
    .header h1 { display: none; } /* hide big h1, company name shown separately */
    .header .user-info { display: flex; align-items: center; gap: 10px; margin-left: auto; }
    .logout-btn {
        background: rgba(255,255,255,0.15);
        color: #fff;
        padding: 4px 12px;
        border-radius: 14px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s;
        border: 1px solid rgba(255,255,255,0.2);
    }
    .logout-btn:hover { background: rgba(255,255,255,0.28); text-decoration: none; }
    .logout-btn { background: var(--danger); color: white; border: none; padding: 7px 14px; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .logout-btn:hover { opacity: .9; text-decoration: none; }

    /* ═══════════════════════════════════════════════════════
       SEARCH BOX
    ═══════════════════════════════════════════════════════ */
    .search-box { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); padding: 16px 20px; margin-bottom: 16px; box-shadow: var(--shadow); }
    .search-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .search-form .form-group { margin-bottom: 0; flex: 1; min-width: 200px; }

    /* ═══════════════════════════════════════════════════════
       LOGIN PAGE
    ═══════════════════════════════════════════════════════ */
    .login-container {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4f46e5 100%);
        padding: 20px;
        z-index: 9998;
        overflow-y: auto;
    }
    .login-box {
        background: var(--surface);
        border-radius: 20px;
        padding: 40px;
        width: 100%;
        max-width: 400px;
        box-shadow: var(--shadow-xl);
        position: relative;
        overflow: hidden;
    }
    .login-box::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 120px; height: 120px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        border-radius: 50%;
        opacity: .1;
    }
    .login-logo {
        width: 56px; height: 56px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 26px;
        margin: 0 auto 18px;
        box-shadow: 0 8px 20px rgba(79,70,229,0.3);
    }
    .login-box h2 { text-align: center; margin-bottom: 8px; color: var(--text); font-size: 22px; font-weight: 800; }
    .login-sub { text-align: center; color: var(--text3); font-size: 13px; margin-bottom: 28px; }

    /* ═══════════════════════════════════════════════════════
       MISC COMPONENTS
    ═══════════════════════════════════════════════════════ */
    .invoice-preview { background: white; padding: 24px; border-radius: var(--radius); box-shadow: var(--shadow); margin: 16px 0; border: 1px solid var(--border); }
    .payment-section { background: var(--surface2); padding: 16px; border-radius: var(--radius-sm); margin: 16px 0; border-left: 4px solid var(--primary); }
    .payment-form { background: var(--surface); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-top: 16px; }
    .send-invoice-section { background: var(--surface2); padding: 16px; border-radius: var(--radius-sm); margin: 16px 0; }
    .status-form { background: #eff6ff; padding: 14px; border-radius: var(--radius-sm); margin: 14px 0; }

    .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
    .company-info { flex: 2; }
    .invoice-meta { text-align: right; flex: 1; font-size: 14px; }
    .customer-info { background: var(--surface2); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 16px; border-left: 4px solid var(--primary); font-size: 13.5px; }
    .logo-preview { max-width: 100px; max-height: 100px; border: 1px solid var(--border); padding: 4px; }
    .qr-preview { max-width: 100px; max-height: 100px; border: 1px solid var(--border); padding: 4px; cursor: pointer; }
    .payment-note { background: #fffbeb; border-left: 4px solid var(--warning); padding: 12px 16px; margin: 14px 0; border-radius: var(--radius-sm); font-size: 13px; }
    .currency { font-weight: 700; color: var(--text); }
    .total-amount { font-size: 18px; font-weight: 800; color: var(--text); margin-top: 8px; }
    .rounded-note { font-size: 11.5px; color: var(--text3); margin-top: 4px; font-style: italic; }
    .role-select { padding: 8px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); font-size: 13.5px; width: 100%; }
    .amount-display { font-size: 18px; font-weight: 700; margin: 8px 0; }
    .stat-card { background: var(--surface); padding: 20px; border-radius: var(--radius-sm); box-shadow: var(--shadow); text-align: center; border-top: 4px solid var(--primary); }
    .stat-card h3 { color: var(--text3); margin-bottom: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
    .stat-card .value { font-size: 28px; font-weight: 800; color: var(--text); }
    .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }

    /* ═══════════════════════════════════════════════════════
       NAV TABS (legacy compat - hidden, replaced by sidebar)
    ═══════════════════════════════════════════════════════ */
    .nav-tabs { display: none; }

    /* ═══════════════════════════════════════════════════════
       TOP NAV BAR (.app-nav) — horizontal sticky navigation
    ═══════════════════════════════════════════════════════ */
    .app-nav {
        background: #1e1b4b;
        position: sticky;
        top: 0;
        z-index: 300;
        box-shadow: 0 2px 12px rgba(0,0,0,0.25);
        width: 100%;
        overflow: visible; /* critical: dropdowns must not be clipped */
    }
    .nav-inner {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        /* overflow must be visible so dropdowns are not clipped */
        overflow: visible;
        gap: 2px;
        padding: 4px 8px;
        max-width: 1280px;
        margin: 0 auto;
        min-height: 48px;
    }
    /* Horizontal scroll wrapper - overflow visible needed for fixed dropdowns */
    .app-nav { overflow: visible; }

    /* ═══════════════════════════════════════════════════════
       REALTIME INDICATOR
    ═══════════════════════════════════════════════════════ */
    .realtime-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--success);
        display: inline-block;
        animation: pulse-green 2s infinite;
    }
    @keyframes pulse-green {
        0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.4); }
        50% { box-shadow: 0 0 0 5px rgba(16,185,129,0); }
    }

    /* ═══════════════════════════════════════════════════════
       TOAST NOTIFICATION
    ═══════════════════════════════════════════════════════ */
    .toast-container {
        position: fixed;
        bottom: 90px;
        right: 20px;
        z-index: 50000;
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-width: 320px;
    }
    .toast {
        background: var(--text);
        color: #fff;
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 500;
        box-shadow: var(--shadow-lg);
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideInRight .3s ease;
    }
    .toast.success { background: var(--success); }
    .toast.error   { background: var(--danger); }
    .toast.info    { background: var(--primary); }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    /* ═══════════════════════════════════════════════════════
       CHAT WIDGET — Facebook Messenger style
    ═══════════════════════════════════════════════════════ */
    #chatWidget {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 10px;
    }
    .chat-fab {
        width: 52px; height: 52px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0866ff, #0099ff);
        color: #fff;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        box-shadow: 0 4px 20px rgba(8,102,255,0.4);
        transition: transform .2s;
        position: relative;
    }
    .chat-fab:hover { transform: scale(1.08); }
    .chat-fab-badge {
        position: absolute;
        top: -3px; right: -3px;
        background: var(--danger);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        min-width: 18px; height: 18px;
        border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid #fff;
        padding: 0 3px;
    }
    .chat-panel {
        width: 320px;
        max-height: 480px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 8px 40px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: chatSlideUp .25s ease;
    }
    @keyframes chatSlideUp { from { opacity:0; transform:translateY(20px) scale(.95); } to { opacity:1; transform:translateY(0) scale(1); } }
    .chat-panel.minimized { max-height: 54px; }
    .chat-panel-header {
        background: linear-gradient(135deg, #0866ff, #0099ff);
        color: #fff;
        padding: 0 14px;
        height: 54px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        flex-shrink: 0;
    }
    .chat-panel-header-info { display: flex; align-items: center; gap: 10px; }
    .chat-panel-avatar {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: rgba(255,255,255,0.3);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        font-weight: 700;
        flex-shrink: 0;
        border: 2px solid rgba(255,255,255,0.5);
    }
    .chat-header-name { font-weight: 700; font-size: 13.5px; }
    .chat-header-status { font-size: 11px; opacity: .85; }
    .chat-header-btns { display: flex; gap: 6px; }
    .chat-hbtn {
        background: rgba(255,255,255,0.2);
        border: none;
        border-radius: 50%;
        width: 28px; height: 28px;
        color: #fff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        transition: background .15s;
    }
    .chat-hbtn:hover { background: rgba(255,255,255,0.35); }

    /* User list view */
    .chat-users-list {
        flex: 1;
        overflow-y: auto;
        padding: 8px;
    }
    .chat-user-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 10px;
        border-radius: 10px;
        cursor: pointer;
        transition: background .15s;
    }
    .chat-user-item:hover { background: #f0f2f5; }
    .chat-user-avatar {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
        position: relative;
    }
    .chat-online-dot {
        position: absolute;
        bottom: 0; right: 0;
        width: 10px; height: 10px;
        border-radius: 50%;
        border: 2px solid #fff;
    }
    .chat-user-info { flex: 1; min-width: 0; }
    .chat-user-name { font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .chat-user-role { font-size: 11px; color: var(--text3); }
    .chat-unread-pill {
        background: #0866ff;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 10px;
        min-width: 18px;
        text-align: center;
    }

    /* Message view */
    .chat-messages-area {
        flex: 1;
        overflow-y: auto;
        padding: 12px;
        background: #f0f2f5;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .chat-msg-row { display: flex; align-items: flex-end; gap: 6px; }
    .chat-msg-row.mine { flex-direction: row-reverse; }
    .chat-bubble {
        max-width: 75%;
        padding: 8px 12px;
        border-radius: 18px;
        font-size: 13.5px;
        line-height: 1.4;
        word-break: break-word;
    }
    .chat-bubble.theirs {
        background: #fff;
        color: var(--text);
        border-bottom-left-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .chat-bubble.mine {
        background: #0866ff;
        color: #fff;
        border-bottom-right-radius: 4px;
    }
    .chat-time { font-size: 10px; color: var(--text3); margin: 0 4px 2px; }
    .chat-date-sep { text-align: center; font-size: 11px; color: var(--text3); margin: 8px 0; }

    /* Chat input */
    .chat-input-area {
        padding: 10px;
        border-top: 1px solid var(--border);
        background: #fff;
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }
    .chat-input {
        flex: 1;
        border: 1.5px solid var(--border);
        border-radius: 20px;
        padding: 8px 14px;
        font-size: 13.5px;
        font-family: inherit;
        outline: none;
        resize: none;
        max-height: 80px;
        overflow-y: auto;
        line-height: 1.4;
        color: var(--text);
    }
    .chat-input:focus { border-color: #0866ff; }
    .chat-send-btn {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: #0866ff;
        border: none;
        color: #fff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
        transition: background .15s;
    }
    .chat-send-btn:hover { background: #0052cc; }
    .chat-typing { font-size: 11px; color: var(--text3); padding: 4px 12px; font-style: italic; }
    .chat-empty { text-align: center; padding: 30px 16px; color: var(--text3); font-size: 13px; }

    /* ═══════════════════════════════════════════════════════
       PRINT STYLES — only invoice prints, not app chrome
    ═══════════════════════════════════════════════════════ */
    @media print {
        .header, .app-nav, #signatureSystem, .send-invoice-section,
        .status-form, .payment-form, .action-buttons, .message,
        .no-print, #chatWidget, #chatUsersPanel, #chatConvPanel,
        .toast-container, #passcodeModal { display: none !important; }
        .main-content { box-shadow: none !important; border: none !important; padding: 0 !important; }
        body * { visibility: hidden; }
        .invoice-preview, .invoice-preview * { visibility: visible; }
        .invoice-preview {
            position: absolute; left: 0; top: 0;
            width: 100%; margin: 0; padding: 16px;
            box-shadow: none; border: none;
        }
        table { page-break-inside: auto; font-size: 12px; }
        th, td { padding: 7px !important; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        img[src*="qr"] { max-width: 100px; display: inline-block !important; }
        img[src*="logo"] { max-width: 120px; display: inline-block !important; }
        #affixedSealImg, #affixedSigImg, .affixed-seal-abs { visibility: visible !important; display: block !important; }
    }

    /* ═══════════════════════════════════════════════════════
       RESPONSIVE
    ═══════════════════════════════════════════════════════ */
    @media (max-width: 768px) {
        .nav-inner { padding: 4px; gap: 1px; }
        .nav-item, .nav-group-trigger { padding: 8px 10px; font-size: 12px; }
        .nav-item .nav-label { display: none; }  /* icons only on mobile */
        .nav-group-trigger .nav-label { display: none; }
        .nav-item-right .nav-label { display: none; }
        .row { flex-direction: column; gap: 8px; }
        .row > .form-group { min-width: 100%; }
        .main-content { padding: 14px; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .dash-quick-actions { gap: 8px; }
        .quick-btn { padding: 8px 10px; font-size: 12.5px; }
        .invoice-header { flex-direction: column; }
        .invoice-meta { text-align: left; margin-top: 12px; }
        #chatUsersPanel, #chatConvPanel { width: 100vw; right: 0; bottom: 0; border-radius: 16px 16px 0 0; max-height: 70vh; bottom: 70px; }
        #chatWidget { right: 16px; bottom: 16px; }
        table { font-size: 12px; }
        th, td { padding: 6px 8px; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-card-v2 .sc-value { font-size: 20px; }
        .main-content { padding: 10px; }
        .nav-item, .nav-group-trigger { padding: 7px 8px; font-size: 11px; }
    }
.nav-tab { 
    padding: 12px 15px; 
    cursor: pointer; 
    background: #ecf0f1; 
    border: none; 
    text-align: center; 
    font-weight: bold; 
    color: #555; 
    text-decoration: none; 
    display: inline-block; 
    transition: all 0.3s; 
    border-right: 1px solid #ddd;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    max-width: none;
    word-wrap: break-word;
    line-height: 1.2;
}

/* [nav styles defined in main style block above] */

/* ── DASHBOARD IMPROVEMENTS ── */
.dash-welcome {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.dash-welcome h2 { margin: 0; font-size: 22px; color: #2c3e50; }
.dash-role-badge {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
}
.role-admin    { background: #fdecea; color: #c0392b; border: 1px solid #f5b7b1; }
.role-manager  { background: #fef9e7; color: #d4a017; border: 1px solid #f9e79f; }
.role-accountant { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card-v2 {
    background: white;
    border-radius: 10px;
    padding: 20px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    border-left: 4px solid #3498db;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: box-shadow 0.2s, transform 0.2s;
}
.stat-card-v2:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.12); transform: translateY(-2px); }
.stat-card-v2.green  { border-color: #27ae60; }
.stat-card-v2.orange { border-color: #e67e22; }
.stat-card-v2.red    { border-color: #e74c3c; }
.stat-card-v2.purple { border-color: #8e44ad; }
.stat-card-v2 .sc-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #95a5a6; }
.stat-card-v2 .sc-value { font-size: 26px; font-weight: 700; color: #2c3e50; line-height: 1.1; }
.stat-card-v2 .sc-link { font-size: 11.5px; color: #3498db; text-decoration: none; margin-top: 2px; }
.stat-card-v2 .sc-link:hover { text-decoration: underline; }

.dash-quick-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}
.quick-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    border-radius: 7px;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: opacity 0.2s, transform 0.15s;
    color: #fff;
}
.quick-btn:hover { opacity: .88; transform: translateY(-1px); }
.qb-blue   { background: #3498db; }
.qb-green  { background: #27ae60; }
.qb-orange { background: #e67e22; }
.qb-purple { background: #8e44ad; }

.dash-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Mobile nav */
@media (max-width: 768px) {
    .nav-inner { gap: 1px; padding: 3px; }
    .nav-item, .nav-group-trigger { padding: 9px 10px; font-size: 12.5px; }

    .nav-item-right { margin-left: 0; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* For mobile screens */

    </style>
    <script>
        var itemCounter = 1;
        var purchaseCounter = 1;
        
        function addItem(containerId) {
    var container = document.getElementById(containerId);
    var index = container.querySelectorAll('.item-row').length;
    
    var html = `
        <div class="item-row">
            <div class="row">
                <input type="hidden" name="items[${index}][id]" value="0">
                <div class="form-group">
                    <label>S.No.</label>
                    <input type="number" name="items[${index}][s_no]" value="${index + 1}" min="1" readonly>
                </div>
                <div class="form-group">
                    <label>Particulars *</label>
                    <input type="text" name="items[${index}][particulars]" placeholder="Enter particulars" required>
                </div>
                <div class="form-group">
                    <label>Amount (₹)</label>
                    <input type="number" name="items[${index}][amount]" step="0.01" value="0" min="0" required>
                </div>
                <div class="form-group">
                    <label>Service Charge (₹)</label>
                    <input type="number" name="items[${index}][service_charge]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Discount (₹)</label>
                    <input type="number" name="items[${index}][discount]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Remark</label>
                    <input type="text" name="items[${index}][remark]" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removeItem(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
    itemCounter++;
}

function addPurchase(containerId) {
    var container = document.getElementById(containerId);
    var index = container.querySelectorAll('.purchase-row').length;
    
    var html = `
        <div class="purchase-row">
            <div class="row">
                <input type="hidden" name="purchases[${index}][id]" value="0">
                <div class="form-group">
                    <label>S.No.</label>
                    <input type="number" name="purchases[${index}][s_no]" value="${index + 1}" min="1" readonly>
                </div>
                <div class="form-group">
                    <label>Particulars</label>
                    <input type="text" name="purchases[${index}][particulars]" placeholder="Enter particulars">
                </div>
                <div class="form-group">
                    <label>Qty</label>
                    <input type="number" name="purchases[${index}][qty]" step="0.01" value="1" min="0">
                </div>
                <div class="form-group">
                    <label>Rate (₹)</label>
                    <input type="number" name="purchases[${index}][rate]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Amount Received (₹)</label>
                    <input type="number" name="purchases[${index}][amount_received]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removePurchase(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
    purchaseCounter++;
}
        
        function removeItem(button) {
            var itemRow = button.closest('.item-row');
            if (itemRow && document.querySelectorAll('.item-row').length > 1) {
                itemRow.remove();
                renumberItems();
            }
        }
        
        function removePurchase(button) {
            var purchaseRow = button.closest('.purchase-row');
            if (purchaseRow && document.querySelectorAll('.purchase-row').length > 1) {
                purchaseRow.remove();
                renumberPurchases();
            }
        }
        
        function renumberItems() {
            var items = document.querySelectorAll('.item-row');
            items.forEach(function(item, index) {
                item.querySelector('input[name*="[s_no]"]').value = index + 1;
                var name = item.querySelector('input[name*="[particulars]"]').name;
                var baseName = name.match(/(items\[\d+\])\[particulars\]/)[1];
                item.querySelectorAll('input').forEach(function(input) {
                    var oldName = input.name;
                    var field = oldName.match(/\[(\w+)\]/)[1];
                    input.name = `items[${index}][${field}]`;
                });
            });
        }
        
        function renumberPurchases() {
            var purchases = document.querySelectorAll('.purchase-row');
            purchases.forEach(function(purchase, index) {
                purchase.querySelector('input[name*="[s_no]"]').value = index + 1;
                var name = purchase.querySelector('input[name*="[particulars]"]').name;
                var baseName = name.match(/(purchases\[\d+\])\[particulars\]/)[1];
                purchase.querySelectorAll('input').forEach(function(input) {
                    var oldName = input.name;
                    var field = oldName.match(/\[(\w+)\]/)[1];
                    input.name = `purchases[${index}][${field}]`;
                });
            });
        }
        
        function confirmDelete(invoiceId, invoiceNumber) {
            if (confirm('Are you sure you want to delete invoice: ' + invoiceNumber + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_invoice';
                form.appendChild(actionInput);
                
                var invoiceInput = document.createElement('input');
                invoiceInput.type = 'hidden';
                invoiceInput.name = 'invoice_id';
                invoiceInput.value = invoiceId;
                form.appendChild(invoiceInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function requestDelete(invoiceId, invoiceNumber) {
            document.getElementById('deleteRequestModal').style.display = 'flex';
            document.getElementById('deleteRequestText').innerHTML = 'Request deletion for invoice: <strong>' + invoiceNumber + '</strong>';
            document.getElementById('requestInvoiceId').value = invoiceId;
        }
        
        function approveDelete(requestId, invoiceNumber) {
            if (confirm('Approve deletion request for invoice: ' + invoiceNumber + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve_delete_request';
                form.appendChild(actionInput);
                
                var requestInput = document.createElement('input');
                requestInput.type = 'hidden';
                requestInput.name = 'request_id';
                requestInput.value = requestId;
                form.appendChild(requestInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function rejectDelete(requestId, invoiceNumber) {
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectText').innerHTML = 'Reject deletion request for invoice: <strong>' + invoiceNumber + '</strong>';
            document.getElementById('rejectRequestId').value = requestId;
        }
        
        function confirmDeleteUser(userId, username) {
            if (confirm('Are you sure you want to delete user: ' + username + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
                form.appendChild(actionInput);
                
                var userInput = document.createElement('input');
                userInput.type = 'hidden';
                userInput.name = 'user_id';
                userInput.value = userId;
                form.appendChild(userInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function sendEmail(invoiceId, email) {
            var modalHtml = `
                <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                        <h3>Send Invoice via Email</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_email">
                            <input type="hidden" name="invoice_id" value="${invoiceId}">
                            
                            <div class="form-group">
                                <label>To Email:</label>
                                <input type="email" name="email" value="${email}" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Subject:</label>
                                <input type="text" name="subject" value="Invoice from <?php echo getSetting('company_name', 'D K ASSOCIATES'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Message:</label>
                                <textarea name="message" rows="5"></textarea>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit">Send Email</button>
                                <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            var div = document.createElement('div');
            div.innerHTML = modalHtml;
            document.body.appendChild(div.firstElementChild);
        }
        
        function sendWhatsApp(invoiceId, phone) {
            var modalHtml = `
                <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                        <h3>Send Invoice via WhatsApp</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_whatsapp">
                            <input type="hidden" name="invoice_id" value="${invoiceId}">
                            
                            <div class="form-group">
                                <label>Phone Number:</label>
                                <input type="tel" name="phone" value="${phone}" required>
                                <small>Include country code (e.g., 91 for India)</small>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" style="background: #25D366;">Send WhatsApp</button>
                                <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            var div = document.createElement('div');
            div.innerHTML = modalHtml;
            document.body.appendChild(div.firstElementChild);
        }
        
        function printInvoice() {
            window.print();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['success'])): ?>
            setTimeout(function() {
                var message = document.querySelector('.message.success');
                if (message) message.style.display = 'none';
            }, 5000);
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="container">
        <?php if (isLoggedIn()): ?>
        <div class="header">
            <h1>Invoice System</h1>
            <div class="user-info">
                <span style="font-size:13px;color:#888;"><?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES')); ?></span>
                <a href="?logout" class="logout-btn">⏏ Logout</a>
            </div>
        </div>
        
<nav class="app-nav">
    <div class="nav-inner">

        <?php /* ── PRIMARY: always visible ── */ ?>
        <a href="?page=dashboard" class="nav-item <?php echo $page==='dashboard'?'active':''; ?>">
            <span class="nav-icon">📊</span><span class="nav-label">Dashboard</span>
        </a>

        <a href="?page=create_invoice" class="nav-item <?php echo $page==='create_invoice'?'active':''; ?>">
            <span class="nav-icon">➕</span><span class="nav-label">New Invoice</span>
        </a>

        <a href="?page=invoices" class="nav-item <?php echo in_array($page,['invoices','view_invoice','edit_invoice'])?'active':''; ?>">
            <span class="nav-icon">📋</span><span class="nav-label">Invoices</span>
        </a>

        <?php /* ── BOOKINGS group ── */ ?>
        <div class="nav-group <?php echo in_array($page,['bookings','create_booking','view_booking','edit_booking'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['bookings','create_booking','view_booking','edit_booking'])?'active':''; ?>">
                <span class="nav-icon">📅</span><span class="nav-label">Bookings</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=bookings"       class="nav-sub <?php echo $page==='bookings'?'active':''; ?>">📋 All Bookings</a>
                <a href="?page=create_booking" class="nav-sub <?php echo $page==='create_booking'?'active':''; ?>">➕ New Booking</a>
            </div>
        </div>

        <?php /* ── ACADEMY group: users with academy access ── */ ?>
        <?php if (hasAcademyAccess()): ?>
        <?php $acRem = count(getDueReminders()); ?>
        <div class="nav-group <?php echo in_array($page,['academy','academy_courses','create_enrollment','view_enrollment','edit_enrollment','academy_reminders'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['academy','academy_courses','create_enrollment','view_enrollment','edit_enrollment','academy_reminders'])?'active':''; ?>">
                <span class="nav-icon">🎓</span><span class="nav-label">Academy</span>
                <?php if ($acRem > 0): ?><span class="nav-badge" style="background:#e74c3c;"><?php echo $acRem; ?></span><?php endif; ?>
                <span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=academy"           class="nav-sub <?php echo $page==='academy'?'active':''; ?>">📋 Enrollments</a>
                <a href="?page=create_enrollment" class="nav-sub <?php echo $page==='create_enrollment'?'active':''; ?>">➕ New Enrollment</a>
                <?php if ($acRem > 0): ?>
                <a href="?page=academy_reminders" class="nav-sub <?php echo $page==='academy_reminders'?'active':''; ?>" style="color:#e74c3c;">🔔 Reminders (<?php echo $acRem; ?>)</a>
                <?php else: ?>
                <a href="?page=academy_reminders" class="nav-sub <?php echo $page==='academy_reminders'?'active':''; ?>">🔔 Reminders</a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <a href="?page=academy_courses"   class="nav-sub <?php echo $page==='academy_courses'?'active':''; ?>">📚 Manage Courses</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!isAccountant()): ?>
        <?php $yatraPages = ['yatra','yatra_bookings','create_yatra_booking','view_yatra_booking','edit_yatra_booking']; ?>
        <div class="nav-group <?php echo in_array($page,$yatraPages)?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,$yatraPages)?'active':''; ?>">
                <span class="nav-icon">🚌</span><span class="nav-label">Yatra</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=yatra" class="nav-sub <?php echo $page==='yatra'?'active':''; ?>">🕌 Manage Yatras</a>
                <a href="?page=yatra_bookings" class="nav-sub <?php echo $page==='yatra_bookings'?'active':''; ?>">📋 All Bookings</a>
                <a href="?page=create_yatra_booking" class="nav-sub <?php echo $page==='create_yatra_booking'?'active':''; ?>">➕ New Booking</a>
            </div>
        </div>
        <?php endif; ?>

                <?php /* ── FINANCE group: manager + admin only ── */ ?>
        <?php if (!isAccountant()): ?>
        <div class="nav-group <?php echo in_array($page,['payment_link','export','expenses'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['payment_link','export','expenses'])?'active':''; ?>">
                <span class="nav-icon">💰</span><span class="nav-label">Finance</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=payment_link" class="nav-sub <?php echo $page==='payment_link'?'active':''; ?>">🔗 Payment Link</a>
                <a href="?page=expenses" class="nav-sub <?php echo $page==='expenses'?'active':''; ?>">🧾 Expenses</a>
                <?php if (isAdmin()): ?>
                <a href="?page=export" class="nav-sub <?php echo $page==='export'?'active':''; ?>">📤 DB Export/Import</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── ADMIN group: admin only ── */ ?>
        <?php if (isAdmin()): ?>
        <div class="nav-group <?php echo in_array($page,['pending_deletions','invoice_templates','users','settings'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['pending_deletions','invoice_templates','users','settings'])?'active':''; ?>">
                <span class="nav-icon">⚙️</span><span class="nav-label">Admin</span>
                <?php if (($stats_pd = getStatistics()) && $stats_pd['pending_deletions'] > 0): ?>
                <span class="nav-badge"><?php echo $stats_pd['pending_deletions']; ?></span>
                <?php endif; ?>
                <span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=pending_deletions" class="nav-sub <?php echo $page==='pending_deletions'?'active':''; ?>">⏳ Pending Deletions</a>
                <a href="?page=invoice_templates" class="nav-sub <?php echo $page==='invoice_templates'?'active':''; ?>">🎨 Templates</a>
                <a href="?page=users"             class="nav-sub <?php echo $page==='users'?'active':''; ?>">👥 Users</a>
                <a href="?page=settings"          class="nav-sub <?php echo $page==='settings'?'active':''; ?>">⚙️ Settings</a>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── PROFILE: always visible ── */ ?>
        <a href="?page=profile" class="nav-item nav-item-right <?php echo $page==='profile'?'active':''; ?>">
            <span class="nav-icon">👤</span><span class="nav-label"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
        </a>

    </div>
</nav>
<script>
(function() {
    // Use fixed positioning for dropdowns to escape any overflow clipping context
    function positionDropdown(group) {
        var trigger = group.querySelector('.nav-group-trigger');
        var dropdown = group.querySelector('.nav-dropdown');
        if (!trigger || !dropdown) return;
        var r = trigger.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top = (r.bottom + 4) + 'px';
        dropdown.style.left = r.left + 'px';
        dropdown.style.right = 'auto';
        // Prevent going off-screen right
        var dw = dropdown.offsetWidth || 210;
        if (r.left + dw > window.innerWidth - 8) {
            dropdown.style.left = 'auto';
            dropdown.style.right = (window.innerWidth - r.right) + 'px';
        }
    }

    document.querySelectorAll('.nav-group-trigger').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var group = trigger.closest('.nav-group');
            var isOpen = group.classList.contains('open');
            // Close all others
            document.querySelectorAll('.nav-group').forEach(function(g) {
                g.classList.remove('open');
            });
            if (!isOpen) {
                group.classList.add('open');
                positionDropdown(group);
            }
        });
    });

    // Click outside closes all non-active groups
    document.addEventListener('click', function() {
        document.querySelectorAll('.nav-group').forEach(function(g) {
            if (!g.classList.contains('page-active-group')) {
                g.classList.remove('open');
            }
        });
    });

    // Mark groups containing the active page — keep them open
    document.querySelectorAll('.nav-group').forEach(function(g) {
        if (g.querySelector('.nav-sub.active')) {
            g.classList.add('open', 'page-active-group');
            positionDropdown(g);
        }
    });

    // Reposition on scroll/resize
    window.addEventListener('scroll', function() {
        document.querySelectorAll('.nav-group.open').forEach(positionDropdown);
    }, {passive: true});
    window.addEventListener('resize', function() {
        document.querySelectorAll('.nav-group.open').forEach(positionDropdown);
    }, {passive: true});
})();
</script>

        <?php endif; ?>
        
        <div class="main-content">
            <?php if (isset($_SESSION['success'])): ?>
            <div class="message success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['warning'])): ?>
            <div class="message warning"><?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['info'])): ?>
            <div class="message info"><?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
            <?php endif; ?>
            
            
            <?php
            
function includePaymentLink() {
    ?>
    <h2>Generate Payment Link</h2>
    
    <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 30px;">
        <form method="POST" id="paymentLinkForm">
            <input type="hidden" name="action" value="generate_link">
            
            <div class="row">
                <div class="form-group">
                    <label>Amount (₹): *</label>
                    <input type="number" name="amount" step="0.01" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Payment Description/Note: *</label>
                    <input type="text" name="purpose" placeholder="e.g., Product Payment, Service Fee, Order #123" value="Payment Request" required>
                    <small>This text will appear in the payment app when customer pays</small>
                </div>
            </div>
            
            <div class="row">
                <div class="form-group">
                    <label>Customer Name:</label>
                    <input type="text" name="customer_name" placeholder="Optional">
                </div>
                
                <div class="form-group">
                    <label>Customer Phone (for WhatsApp):</label>
                    <input type="tel" name="customer_phone" placeholder="Include country code (e.g., 91XXXXXXXXXX)">
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit">Generate Payment Link</button>
                <button type="button" onclick="resetForm()" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>
    
    <?php if (isset($_SESSION['generated_payment_link'])): ?>
    <div style="background: #d4edda; padding: 20px; border-radius: 5px; margin-top: 20px;">
        <h3 style="color: #155724; margin-bottom: 15px;">Payment Link Generated</h3>
        
        <div style="background: white; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <div style="margin-bottom: 10px;">
                <strong>Amount:</strong> <?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($_SESSION['generated_payment_link']['amount'], 2); ?>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Description:</strong> <?php echo htmlspecialchars($_SESSION['generated_payment_link']['purpose']); ?>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Payment Link (Unique):</strong><br>
                <input type="text" value="<?php echo htmlspecialchars($_SESSION['generated_payment_link']['url']); ?>" 
                       style="width: 100%; padding: 10px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px; font-size: 12px;" 
                       readonly id="paymentLinkField">
            </div>
            <div style="margin-top: 5px; font-size: 11px; color: #666;">
                <strong>Note:</strong> This link is unique and will expire in 7 days
            </div>
        </div>
        
        <div class="action-buttons">
            <button type="button" onclick="copyPaymentLink()" style="background: #3498db;">Copy Link</button>
            
            <?php if (!empty($_SESSION['generated_payment_link']['customer_phone'])): ?>
            <a href="javascript:void(0)" onclick="sendPaymentLinkWhatsApp()" style="background: #25D366; color: white; text-decoration: none; padding: 12px 24px; border-radius: 4px; display: inline-block; font-weight: bold;">
                Send via WhatsApp
            </a>
            <?php endif; ?>
            
            <button type="button" onclick="window.location.reload()" class="btn-secondary">Generate Another</button>
        </div>
    </div>
    
    <script>
    function copyPaymentLink() {
        var linkField = document.getElementById('paymentLinkField');
        linkField.select();
        document.execCommand('copy');
        alert('Payment link copied to clipboard!');
    }
    
    function sendPaymentLinkWhatsApp() {
        var phone = '<?php echo $_SESSION['generated_payment_link']['customer_phone']; ?>';
        var amount = '<?php echo number_format($_SESSION['generated_payment_link']['amount'], 2); ?>';
        var purpose = '<?php echo addslashes($_SESSION['generated_payment_link']['purpose']); ?>';
        var link = '<?php echo $_SESSION['generated_payment_link']['url']; ?>';
        
        var message = encodeURIComponent(
            'Payment request for: ' + purpose + '\n' +
            'Amount: ₹' + amount + '\n' +
            'Please click here to pay: ' + link
        );
        
        window.open('https://wa.me/' + phone + '?text=' + message, '_blank');
    }
    </script>
    <?php unset($_SESSION['generated_payment_link']); ?>
    <?php endif; ?>
    
    <script>
    function resetForm() {
        document.getElementById('paymentLinkForm').reset();
    }
    </script>
    <?php
}



if (isLoggedIn()) {
    switch ($page) {
        case 'dashboard':
            includeDashboard();
            break;
        case 'create_invoice':
            includeCreateInvoice();
            break;
        case 'invoices':
            includeInvoices();
            break;
        case 'bookings':
            includeBookings();
            break;
        case 'create_booking':
            includeCreateBooking();
            break;
        case 'view_booking':
            includeViewBooking();
            break;
        case 'edit_booking':
            includeEditBooking();
            break;
        case 'view_invoice':
            includeViewInvoice();
            break;
        case 'edit_invoice':
            includeEditInvoice();
            break;
        case 'pending_deletions':
            includePendingDeletions();
            break;
        case 'payment_link':
            includePaymentLink();
            break;
        case 'export':
            includeExport();
            break;
        case 'expenses':
            includeExpenses();
            break;
        case 'profile':
            includeProfile();
            break;
        case 'users':
            includeUsers();
            break;
        case 'settings':
            includeSettings();
            break;
        case 'invoice_templates':
            includeInvoiceTemplates();
            break;
        case 'academy':
            includeAcademy();
            break;
        case 'academy_courses':
            includeAcademyCourses();
            break;
        case 'create_enrollment':
            includeCreateEnrollment();
            break;
        case 'view_enrollment':
            includeViewEnrollment();
            break;
        case 'edit_enrollment':
            includeEditEnrollment();
            break;
        case 'academy_reminders':
            includeAcademyReminders();
            break;
        case 'yatra':
            includeYatra();
            break;
        case 'yatra_bookings':
            includeYatraBookings();
            break;
        case 'create_yatra_booking':
            includeCreateYatraBooking();
            break;
        case 'view_yatra_booking':
            includeViewYatraBooking();
            break;
        case 'edit_yatra_booking':
            includeEditYatraBooking();
            break;
        default:
            includeDashboard();
    }
} else {
    includeLogin();
}
            ?>
        </div>
    </div>

    <?php if (isLoggedIn()): ?>
    <!-- ═══════════════════════════════════════════════
         CHAT WIDGET — Facebook Messenger style (HTML)
    ═══════════════════════════════════════════════════ -->
    <div id="chatWidget">
        <!-- FAB button (always visible) -->
        <button class="chat-fab" id="chatFab" onclick="chatTogglePanel()" title="Messages">
            💬
            <span class="chat-fab-badge" id="chatFabBadge" style="display:none;">0</span>
        </button>
    </div>

    <!-- User List Panel -->
    <div id="chatUsersPanel" style="display:none;position:fixed;bottom:80px;right:20px;z-index:9999;width:300px;max-height:420px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.22);overflow:hidden;flex-direction:column;animation:chatSlideUp .2s ease;">
        <div style="background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:700;font-size:15px;">💬 Messages</div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span id="chatOnlineCount" style="font-size:11px;background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;"></span>
                <button onclick="chatTogglePanel()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0;">×</button>
            </div>
        </div>
        <div style="overflow-y:auto;flex:1;">
            <div id="chatUsersList" style="padding:8px 0;"></div>
        </div>
    </div>

    <!-- Individual Chat Panel (per user) -->
    <div id="chatConvPanel" style="display:none;position:fixed;bottom:80px;right:20px;z-index:10000;width:320px;max-height:480px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.22);flex-direction:column;animation:chatSlideUp .2s ease;overflow:hidden;">
        <!-- Header -->
        <div id="chatConvHeader" style="background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;padding:0 14px;height:54px;display:flex;align-items:center;gap:10px;cursor:pointer;flex-shrink:0;">
            <button onclick="chatBackToUsers()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;">←</button>
            <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:14px;" id="chatConvAvatar">👤</div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="chatConvName">User</div>
                <div style="font-size:11px;opacity:.85;" id="chatConvStatus">Online</div>
            </div>
            <button onclick="chatMinimizeConv()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;padding:0;opacity:.8;" id="chatConvMinBtn" title="Minimize">−</button>
            <button onclick="chatCloseConv()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;padding:0;opacity:.8;" title="Close">×</button>
        </div>
        <!-- Messages -->
        <div id="chatMessages" style="flex:1;overflow-y:auto;padding:12px 10px;background:#f0f2f5;display:flex;flex-direction:column;gap:4px;min-height:200px;max-height:300px;"></div>
        <!-- Typing indicator -->
        <div id="chatTypingIndicator" style="display:none;padding:4px 14px;font-size:11px;color:#888;background:#f0f2f5;">typing...</div>
        <!-- Input -->
        <div style="padding:10px 12px;background:#fff;border-top:1px solid #e9ecef;display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <input type="text" id="chatInput" placeholder="Aa" maxlength="500" style="flex:1;border:none;background:#f0f2f5;border-radius:20px;padding:9px 14px;font-size:13px;outline:none;"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){chatSendMsg();event.preventDefault();}">
            <button onclick="chatSendMsg()" style="background:#0866ff;border:none;color:#fff;width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">➤</button>
        </div>
    </div>

    <script>
    // ─── CHAT WIDGET — Realtime with 2s polling ───
    (function() {
        var myId = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;
        var myName = '<?php echo addslashes($_SESSION['username'] ?? ''); ?>';
        var activeWith = null; // user id of open chat
        var activeWithName = '';
        var lastMsgId = 0;
        var panelOpen = false;
        var convMinimized = false;
        var msgPollTimer = null;
        var userPollTimer = null;
        var heartbeatTimer = null;
        var totalUnread = 0;
        var userUnread = {}; // uid -> unread count

        // ── Heartbeat every 30s ──
        function doHeartbeat() {
            fetch('?ajax=chat_heartbeat', {method:'POST'})
            .then(function(r){return r.json();})
            .then(function(d){
                totalUnread = d.unread || 0;
                updateFabBadge();
            }).catch(function(){});
        }
        doHeartbeat();
        heartbeatTimer = setInterval(doHeartbeat, 30000);

        function updateFabBadge() {
            var b = document.getElementById('chatFabBadge');
            if (!b) return;
            if (totalUnread > 0) {
                b.textContent = totalUnread > 99 ? '99+' : totalUnread;
                b.style.display = 'flex';
                document.getElementById('chatFab').style.animation = totalUnread > 0 ? 'chatBounce 2s ease infinite' : '';
            } else {
                b.style.display = 'none';
                document.getElementById('chatFab').style.animation = '';
            }
        }

        // ── Toggle user list panel ──
        window.chatTogglePanel = function() {
            var uPanel = document.getElementById('chatUsersPanel');
            var convPanel = document.getElementById('chatConvPanel');
            if (convPanel.style.display !== 'none' && !convMinimized) {
                // Close conv
                chatCloseConv();
                return;
            }
            panelOpen = !panelOpen;
            uPanel.style.display = panelOpen ? 'flex' : 'none';
            if (panelOpen) loadUserList();
        };

        function loadUserList() {
            fetch('?ajax=chat_users')
            .then(function(r){return r.json();})
            .then(function(d){
                var list = document.getElementById('chatUsersList');
                if (!list) return;
                var online = 0;
                var html = '';
                (d.users || []).forEach(function(u) {
                    if (u.is_online) online++;
                    var badge = (u.unread > 0) ? '<span style="background:#e74c3c;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 6px;min-width:16px;text-align:center;">' + u.unread + '</span>' : '';
                    var onlineDot = u.is_online ? '<span style="width:9px;height:9px;border-radius:50%;background:#4caf50;display:inline-block;border:1.5px solid #fff;flex-shrink:0;"></span>' : '<span style="width:9px;height:9px;border-radius:50%;background:#ccc;display:inline-block;border:1.5px solid #fff;flex-shrink:0;"></span>';
                    var displayName = (u.full_name && u.full_name !== 'NA') ? u.full_name : u.username;
                    html += '<div class="chat-user-item" onclick="openChatWith('+u.id+',\''+escHtml(displayName)+'\')" style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;transition:background .15s;" onmouseover="this.style.background=\'#f0f2f5\'" onmouseout="this.style.background=\'transparent\'">'
                        + '<div style="position:relative;flex-shrink:0;">'
                        + '<div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;">'
                        + escHtml(displayName.charAt(0).toUpperCase())
                        + '</div>'
                        + '<span style="position:absolute;bottom:1px;right:1px;">' + onlineDot + '</span>'
                        + '</div>'
                        + '<div style="flex:1;min-width:0;">'
                        + '<div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(displayName) + '</div>'
                        + '<div style="font-size:11px;color:#888;">' + escHtml(u.role) + (u.designation && u.designation !== 'NA' ? ' · ' + escHtml(u.designation) : '') + '</div>'
                        + '</div>'
                        + badge
                        + '</div>';
                });
                if (!html) html = '<div style="text-align:center;padding:24px;color:#aaa;font-size:13px;">No other users yet</div>';
                list.innerHTML = html;
                var cnt = document.getElementById('chatOnlineCount');
                if (cnt) cnt.textContent = online + ' online';
                // Update total unread
                totalUnread = (d.users || []).reduce(function(s,u){return s+(u.unread||0);},0);
                updateFabBadge();
            }).catch(function(){});
        }

        // ── Open chat with specific user ──
        window.openChatWith = function(uid, name) {
            activeWith = uid;
            activeWithName = name;
            lastMsgId = 0;
            convMinimized = false;

            document.getElementById('chatConvName').textContent = name;
            document.getElementById('chatConvAvatar').textContent = name.charAt(0).toUpperCase();
            document.getElementById('chatMessages').innerHTML = '';
            document.getElementById('chatUsersPanel').style.display = 'none';
            panelOpen = false;

            var convPanel = document.getElementById('chatConvPanel');
            convPanel.style.display = 'flex';
            convPanel.style.maxHeight = '480px';
            document.getElementById('chatConvMinBtn').textContent = '−';
            document.getElementById('chatInput').focus();

            // Load messages immediately
            pollMessages();
            clearInterval(msgPollTimer);
            msgPollTimer = setInterval(pollMessages, 2000);
        };

        function pollMessages() {
            if (!activeWith) return;
            fetch('?ajax=chat_messages&with='+activeWith+'&since='+lastMsgId)
            .then(function(r){return r.json();})
            .then(function(d){
                var msgs = d.messages || [];
                if (msgs.length === 0) return;
                var box = document.getElementById('chatMessages');
                msgs.forEach(function(m) {
                    if (m.id <= lastMsgId) return;
                    lastMsgId = m.id;
                    var mine = (m.from_user == myId);
                    var bubble = document.createElement('div');
                    bubble.style.cssText = 'display:flex;justify-content:'+(mine?'flex-end':'flex-start')+';margin:'+(mine?'1px 0':'1px 0')+';';
                    var time = new Date(m.created_at.replace(' ','T'));
                    var timeStr = time.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
                    bubble.innerHTML = '<div style="max-width:78%;background:'+(mine?'#0866ff':'#fff')+';color:'+(mine?'#fff':'#050505')+';border-radius:'+(mine?'18px 18px 4px 18px':'18px 18px 18px 4px')+';padding:8px 12px;font-size:13px;box-shadow:0 1px 2px rgba(0,0,0,.1);word-break:break-word;">'
                        + escHtml(m.message)
                        + '<div style="font-size:10px;opacity:.6;margin-top:3px;text-align:right;">'+timeStr+'</div>'
                        + '</div>';
                    box.appendChild(bubble);
                });
                box.scrollTop = box.scrollHeight;
                // Reduce unread for this user
                totalUnread = Math.max(0, totalUnread - msgs.filter(function(m){return m.from_user==activeWith;}).length);
                updateFabBadge();
            }).catch(function(){});
        }

        // ── Send message ──
        window.chatSendMsg = function() {
            var inp = document.getElementById('chatInput');
            var msg = (inp ? inp.value.trim() : '');
            if (!msg || !activeWith) return;
            inp.value = '';
            var fd = new FormData();
            fd.append('to', activeWith);
            fd.append('msg', msg);
            fetch('?ajax=chat_send', {method:'POST', body: new URLSearchParams({to: activeWith, msg: msg})})
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.ok) pollMessages();
            }).catch(function(){});
        };

        window.chatBackToUsers = function() {
            clearInterval(msgPollTimer);
            activeWith = null;
            document.getElementById('chatConvPanel').style.display = 'none';
            document.getElementById('chatUsersPanel').style.display = 'flex';
            panelOpen = true;
            loadUserList();
        };

        window.chatCloseConv = function() {
            clearInterval(msgPollTimer);
            activeWith = null;
            document.getElementById('chatConvPanel').style.display = 'none';
        };

        window.chatMinimizeConv = function() {
            convMinimized = !convMinimized;
            var p = document.getElementById('chatConvPanel');
            if (convMinimized) {
                p.style.maxHeight = '54px';
                document.getElementById('chatConvMinBtn').textContent = '+';
            } else {
                p.style.maxHeight = '480px';
                document.getElementById('chatConvMinBtn').textContent = '−';
                pollMessages();
                clearInterval(msgPollTimer);
                msgPollTimer = setInterval(pollMessages, 2000);
            }
        };

        // ── Auto-minimize: minimize chat after 3min inactivity ──
        var autoMinTimer = null;
        function resetAutoMin() {
            clearTimeout(autoMinTimer);
            autoMinTimer = setTimeout(function() {
                if (activeWith && !convMinimized) chatMinimizeConv();
            }, 180000); // 3 minutes
        }
        document.addEventListener('mousemove', resetAutoMin, {passive:true});
        document.addEventListener('touchstart', resetAutoMin, {passive:true});
        document.addEventListener('keydown', resetAutoMin, {passive:true});

        // Focus/blur: poll faster when tab focused, stop when blurred, auto-minimize on hide
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Tab hidden: stop polling, auto-minimize after 30s away
                clearInterval(msgPollTimer);
                clearTimeout(autoMinTimer);
                autoMinTimer = setTimeout(function() {
                    if (activeWith && !convMinimized) chatMinimizeConv();
                }, 30000);
            } else {
                // Tab visible: resume polling
                clearTimeout(autoMinTimer);
                if (activeWith && !convMinimized) {
                    clearInterval(msgPollTimer);
                    msgPollTimer = setInterval(pollMessages, 2000);
                    pollMessages();
                }
                resetAutoMin();
            }
        });

        function escHtml(s) {
            return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Periodic user list refresh (every 15s if panel open)
        setInterval(function() {
            if (panelOpen) loadUserList();
        }, 15000);

        // Add bounce animation CSS
        var style = document.createElement('style');
        style.textContent = '@keyframes chatBounce{0%,100%{transform:scale(1);}50%{transform:scale(1.12);}}';
        document.head.appendChild(style);
    })();
    </script>
    <?php endif; ?>

</body>
</html>
<?php
function includeLogin() {
    ?>
    <div class="login-container">
        <h2>Invoice System Login</h2>
        <form method="POST" class="login-form">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="login">Login</button>
            </div>
        </form>
    </div>
    <?php
}

function includeBookings() {
    global $db;
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $bookings = getAllBookings($search, $status, $date_from, $date_to);
    ?>
    <h2>Service Bookings</h2>
    
    <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?page=create_booking" class="btn" style="background: #27ae60;">➕ New Booking</a>
        <a href="?page=bookings" class="btn-secondary">📋 All Bookings</a>
    </div>
    
    <div class="search-box">
        <form method="GET" class="search-form">
            <input type="hidden" name="page" value="bookings">
            <div class="form-group">
                <label>Search:</label>
                <input type="text" name="search" placeholder="Customer, Booking #, Phone, Service..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="form-group">
                <label>Status:</label>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="converted" <?php echo $status === 'converted' ? 'selected' : ''; ?>>Converted to Invoice</option>
                </select>
            </div>
            <div class="form-group">
                <label>From Date:</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            <div class="form-group">
                <label>To Date:</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            <div class="form-group">
                <button type="submit">Search</button>
                <button type="button" onclick="window.location.href='?page=bookings'" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Booking #</th>
                <th>Customer</th>
                <th>Service</th>
                <th>Booking Date</th>
                <th>Est. Cost</th>
                <th>Advance</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
            <tr>
                <td colspan="9" style="text-align: center;">No bookings found</td>
            </tr>
            <?php else: ?>
            <?php foreach ($bookings as $booking): 
                $bookingData = getBookingData($booking['id']);
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($booking['booking_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($booking['customer_name']); ?><br>
                    <small><?php echo htmlspecialchars($booking['customer_phone']); ?></small>
                </td>
                <td><?php echo htmlspecialchars(substr($booking['service_description'], 0, 30)) . '...'; ?></td>
                <td><?php echo date('d-m-Y', strtotime($booking['booking_date'])); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['total_estimated_cost'], 2); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['advance_fees'], 2); ?></td>
                <td>
                    <span class="payment-badge <?php 
                        echo $booking['status'] === 'pending' ? 'unpaid' : 
                            ($booking['status'] === 'in_progress' ? 'partially_paid' : 
                            ($booking['status'] === 'completed' ? 'paid' : 'settled')); 
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                    </span>
                </td>
                <td>
                    <span class="payment-badge <?php echo $booking['payment_status']; ?>">
                        <?php 
                        if ($booking['payment_status'] === 'paid') echo 'Paid';
                        elseif ($booking['payment_status'] === 'partial') echo 'Partial';
                        else echo 'Pending';
                        ?>
                    </span>
                </td>
                <td class="actions-cell">
                    <a href="?page=view_booking&id=<?php echo $booking['id']; ?>" class="action-btn view-btn">View</a>
                    <?php if ($booking['status'] !== 'converted' && $booking['status'] !== 'cancelled'): ?>
                    <a href="?page=edit_booking&id=<?php echo $booking['id']; ?>" class="action-btn edit-btn">Edit</a>
                    <?php endif; ?>
                    <?php if ($booking['status'] === 'completed' && !$booking['converted_to_invoice']): ?>
                    <a href="javascript:void(0)" onclick="convertToInvoice(<?php echo $booking['id']; ?>, '<?php echo $booking['booking_number']; ?>')" class="action-btn" style="background: #8e44ad;">Convert</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function includeCreateBooking() {
    ?>
    <h2>Create New Service Booking</h2>
    <form method="POST" id="bookingForm">
        <input type="hidden" name="action" value="create_booking">
        
        <h3>Customer Details</h3>
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" required>
            </div>
            <div class="form-group">
                <label>Phone Number: *</label>
                <input type="tel" name="customer_phone" required>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email">
            </div>
            <div class="form-group">
                <label>Booking Date: *</label>
                <input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"></textarea>
        </div>
        
        <h3>Service Details</h3>
        <div class="form-group">
            <label>Service Description: *</label>
            <textarea name="service_description" rows="3" required placeholder="Describe the service required..."></textarea>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Expected Completion Date:</label>
                <input type="date" name="expected_completion_date">
            </div>
            <div class="form-group">
                <label>Total Estimated Cost (₹): *</label>
                <input type="number" name="total_estimated_cost" step="0.01" min="0" required>
            </div>
        </div>
        
        <h3>Advance Payment</h3>
        <div class="row">
            <div class="form-group">
                <label>Advance Fees Required (₹):</label>
                <input type="number" name="advance_fees" step="0.01" min="0" value="0" id="advanceFees">
            </div>
            <div class="form-group">
                <label>Payment Method:</label>
                <select name="payment_method" id="paymentMethod">
                    <option value="">Select (if paying now)</option>
                    <?php foreach (getPaymentMethods() as $method): ?>
                    <option value="<?php echo htmlspecialchars(trim($method)); ?>"><?php echo htmlspecialchars(trim($method)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Transaction ID (if applicable):</label>
                <input type="text" name="transaction_id" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>Notes:</label>
                <input type="text" name="notes" placeholder="Optional notes">
            </div>
        </div>
        
        <h3>Service Items (Optional)</h3>
        <div id="booking-items-container">
            <div class="item-row">
                <div class="row">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[0][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[0][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="button" onclick="addBookingItem()" class="btn-secondary">Add Item</button>
        
        <div class="action-buttons" style="margin-top: 30px;">
            <button type="submit">Create Booking</button>
            <a href="?page=bookings" class="btn-secondary">Cancel</a>
        </div>
    </form>
    
    <script>
    function addBookingItem() {
    var container = document.getElementById('booking-items-container');
    if (!container) {
        container = document.getElementById('edit-booking-items-container');
    }
    if (!container) return;
    
    var index = container.querySelectorAll('.item-row').length;
    
    var html = `
        <div class="item-row">
            <div class="row">
                <input type="hidden" name="items[${index}][id]" value="0">
                <div class="form-group" style="flex: 2;">
                    <label>Description</label>
                    <input type="text" name="items[${index}][description]" placeholder="Item description">
                </div>
                <div class="form-group">
                    <label>Est. Amount (₹)</label>
                    <input type="number" name="items[${index}][estimated_amount]" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
}

function removeBookingItem(button) {
    var itemRow = button.closest('.item-row');
    if (itemRow) {
        var container = itemRow.closest('#booking-items-container, #edit-booking-items-container');
        if (container && container.querySelectorAll('.item-row').length > 1) {
            itemRow.remove();
        }
    }
}
    </script>
    <?php
}

function includeViewBooking() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Booking ID not specified!</div>';
        return;
    }
    
    $booking_id = $_GET['id'];
    $booking = getBookingData($booking_id);
    
    if (!$booking) {
        echo '<div class="message error">Booking not found!</div>';
        return;
    }
    
    $share_token = null;
    if ($booking['status'] !== 'cancelled') {
        $stmt = $db->prepare("SELECT share_token FROM booking_shares WHERE booking_id = :booking_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($existing) {
            $share_token = $existing['share_token'];
        } else {
            $share_token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO booking_shares (booking_id, share_token, created_by, expires_at) VALUES (:booking_id, :token, :user_id, datetime('now', '+30 days'))");
            $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
            $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
    
    $share_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/booking_receipt.php?token=' . $share_token;
    
    $canEdit = isAdmin() || $booking['created_by'] == $_SESSION['user_id'];
    ?>
    
    <h2>Booking Details: <?php echo htmlspecialchars($booking['booking_number']); ?></h2>
    
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 2;">
            <div class="invoice-preview" style="margin-top: 0;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                    <div>
                        <?php 
                        $logo_path = getSetting('logo_path');
                        if ($logo_path && file_exists($logo_path)): ?>
                            <img src="<?php echo $logo_path; ?>" style="max-width: 130px; max-height: 130px; margin-bottom: 5px;" alt="Logo">
                        <?php endif; ?>
                        <h3 style="color: #2c3e50;"><?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?></h3>
                        <div style="color: #666; font-size: 13px;">Service Booking Receipt</div>
                    </div>
                    <div style="text-align: right;">
                        <div><strong>Booking #:</strong> <?php echo htmlspecialchars($booking['booking_number']); ?></div>
                        <div><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($booking['booking_date'])); ?></div>
                        <div>
                            <span class="payment-badge <?php 
                                echo $booking['status'] === 'pending' ? 'unpaid' : 
                                    ($booking['status'] === 'in_progress' ? 'partially_paid' : 
                                    ($booking['status'] === 'completed' ? 'paid' : 'settled')); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0;">Customer Details</h4>
                    <div><strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong></div>
                    <?php if (!empty($booking['customer_phone'])): ?>
                    <div>📞 <?php echo htmlspecialchars($booking['customer_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($booking['customer_email'])): ?>
                    <div>📧 <?php echo htmlspecialchars($booking['customer_email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($booking['customer_address'])): ?>
                    <div>📍 <?php echo nl2br(htmlspecialchars($booking['customer_address'])); ?></div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4>Service Description</h4>
                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                        <?php echo nl2br(htmlspecialchars($booking['service_description'])); ?>
                    </div>
                    <?php if (!empty($booking['expected_completion_date'])): ?>
                    <div style="margin-top: 10px;"><strong>Expected Completion:</strong> <?php echo date('d-m-Y', strtotime($booking['expected_completion_date'])); ?></div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($booking['parsed_items'])): ?>
                <h4>Service Items</h4>
                <table style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>Est. Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($booking['parsed_items'] as $index => $item): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($item['estimated_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                    <table style="width: 300px;">
                        <tr>
                            <td><strong>Total Estimated Cost:</strong></td>
                            <td style="text-align: right;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['total_estimated_cost'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Advance Paid:</strong></td>
                            <td style="text-align: right; color: #27ae60;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['advance_fees'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Balance Due:</strong></td>
                            <td style="text-align: right; color: #e74c3c; font-weight: bold;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['totals']['balance'], 2); ?></td>
                        </tr>
                    </table>
                </div>
                
                <?php 
                // Dynamic QR Code for pending payment
                if ($booking['totals']['balance'] > 0): 
                    $upi_link = generateUPILink($booking['totals']['balance'], $booking['booking_number'], "Booking: " . $booking['booking_number']);
                    $qr_code_url = generateQRCode($upi_link, 140);
                ?>
                <div style="text-align: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                    <h4>Pay Balance Due: <?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['totals']['balance'], 2); ?></h4>
                    <?php if (!empty($qr_code_url)): ?>
                    <a href="<?php echo htmlspecialchars($upi_link); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($qr_code_url); ?>" style="max-width: 140px; border: 1px solid #ddd; padding: 5px; background: white;" alt="Payment QR Code">
                    </a>
                    <div style="margin-top: 10px;">
                        <a href="<?php echo htmlspecialchars($upi_link); ?>" target="_blank" style="background: #25D366; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none;">Pay Now</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($booking['parsed_payments'])): ?>
                <h4>Payment History</h4>
                <table style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Transaction ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($booking['parsed_payments'] as $payment): ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo strpos($payment['notes'], 'Installment') !== false ? 'Installment' : 'Advance'; ?></td>
                            <td><?php echo htmlspecialchars($payment['transaction_id']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($booking['notes'])): ?>
                <div style="padding: 10px; background: #fff8e1; border-radius: 5px; border-left: 4px solid #ffb300;">
                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($booking['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; padding-top: 15px;">
                    <?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?><br>
                    <?php echo nl2br(htmlspecialchars(getSetting('office_address', ''))); ?><br>
                    Phone: <?php echo htmlspecialchars(getSetting('office_phone', '')); ?>
                </div>
            </div>
        </div>
        
        <div style="flex: 1;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 5px;">
                <h4 style="margin: 0 0 15px 0;">Actions</h4>
                
                <?php if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'converted'): ?>
                <div style="margin-bottom: 20px;">
                    <label><strong>Update Status:</strong></label>
                    <select id="statusSelect" onchange="updateBookingStatus(<?php echo $booking_id; ?>, this.value)" style="width: 100%; margin: 10px 0;">
                        <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $booking['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['totals']['balance'] > 0 && $booking['status'] !== 'cancelled' && $booking['status'] !== 'converted'): ?>
                <div style="margin-bottom: 20px;">
                    <h5 style="margin: 0 0 10px 0;">Add Payment</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_booking_payment">
                        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                        
                        <div class="form-group">
                            <label>Amount (₹):</label>
                            <input type="number" name="amount" step="0.01" min="1" max="<?php echo $booking['totals']['balance']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Payment Method:</label>
                            <select name="payment_method" required>
                                <?php foreach (getPaymentMethods() as $method): ?>
                                <option value="<?php echo htmlspecialchars(trim($method)); ?>"><?php echo htmlspecialchars(trim($method)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Transaction ID:</label>
                            <input type="text" name="transaction_id">
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center;">
                                <input type="checkbox" name="is_installment" value="1" style="width: auto; margin-right: 8px;">
                                Mark as Installment Payment
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label>Notes:</label>
                            <input type="text" name="notes" placeholder="Optional notes">
                        </div>
                        
                        <button type="submit" style="width: 100%;">Record Payment</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['status'] === 'completed' && !$booking['converted_to_invoice']): ?>
                <div style="margin-bottom: 20px;">
                    <button onclick="convertToInvoice(<?php echo $booking_id; ?>, '<?php echo $booking['booking_number']; ?>')" style="width: 100%; background: #8e44ad;">Convert to Invoice</button>
                </div>
                <?php endif; ?>
                
                <?php if ($share_token): ?>
                <div style="margin-bottom: 20px;">
                    <label><strong>Customer Receipt Link:</strong></label>
                    <div style="display: flex; margin-top: 5px;">
                        <input type="text" value="<?php echo htmlspecialchars($share_url); ?>" readonly style="flex: 1; font-size: 11px;" id="shareLink">
                        <button onclick="copyShareLink()" style="padding: 8px 12px; margin-left: 5px;">Copy</button>
                    </div>
                    <small style="color: #666;">Share this link with customer</small>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['converted_to_invoice'] && $booking['converted_invoice_id']): ?>
                <div style="margin-bottom: 20px;">
                    <a href="?page=view_invoice&id=<?php echo $booking['converted_invoice_id']; ?>" class="btn" style="width: 100%; text-align: center;">View Converted Invoice</a>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($booking['customer_email']) || !empty($booking['customer_phone'])): ?>
                <div style="margin-bottom: 20px;">
                    <h5 style="margin: 0 0 10px 0;">Share Receipt</h5>
                    <div style="display: flex; gap: 10px;">
                        <?php if (!empty($booking['customer_email'])): ?>
                        <button onclick="sendBookingEmail(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($booking['customer_email']); ?>')" style="flex: 1; background: #3498db;">
                            📧 Email
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($booking['customer_phone'])): ?>
                        <button onclick="sendBookingWhatsApp(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($booking['customer_phone']); ?>')" style="flex: 1; background: #25D366;">
                            📱 WhatsApp
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isAdmin()): ?>
                <div style="margin-bottom: 20px;">
                    <button onclick="confirmDeleteBooking(<?php echo $booking_id; ?>, '<?php echo $booking['booking_number']; ?>')" style="width: 100%; background: #e74c3c;">
                        🗑️ Delete Booking
                    </button>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px;">
                    <button onclick="window.print()" class="btn-print" style="flex: 1;">Print</button>
                    <a href="?page=bookings" class="btn-secondary" style="flex: 1; text-align: center;">Back</a>
                </div>
            </div>
        </div>
    </div>
    
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="update_booking_status">
        <input type="hidden" name="booking_id" id="statusBookingId">
        <input type="hidden" name="status" id="statusValue">
    </form>
    
    <form id="convertForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="convert_booking">
        <input type="hidden" name="booking_id" id="convertBookingId">
    </form>
    
    <script>
    function updateBookingStatus(bookingId, status) {
        if (confirm('Update booking status to ' + status.replace('_', ' ') + '?')) {
            document.getElementById('statusBookingId').value = bookingId;
            document.getElementById('statusValue').value = status;
            document.getElementById('statusForm').submit();
        } else {
            document.getElementById('statusSelect').value = '<?php echo $booking['status']; ?>';
        }
    }
    
    function convertToInvoice(bookingId, bookingNumber) {
        if (confirm('Convert booking ' + bookingNumber + ' to invoice?')) {
            document.getElementById('convertBookingId').value = bookingId;
            document.getElementById('convertForm').submit();
        }
    }
    
    function copyShareLink() {
        var linkField = document.getElementById('shareLink');
        linkField.select();
        document.execCommand('copy');
        alert('Receipt link copied to clipboard!');
    }
    </script>
    <?php
}

function includeEditBooking() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Booking ID not specified!</div>';
        return;
    }
    
    $booking_id = $_GET['id'];
    $booking = getBookingData($booking_id);
    
    if (!$booking) {
        echo '<div class="message error">Booking not found!</div>';
        return;
    }
    
    $canEdit = isAdmin() || $booking['created_by'] == $_SESSION['user_id'];
    if (!$canEdit) {
        echo '<div class="message error">You don\'t have permission to edit this booking!</div>';
        return;
    }
    
    if ($booking['status'] === 'converted' || $booking['status'] === 'cancelled') {
        echo '<div class="message warning">This booking cannot be edited as it is ' . $booking['status'] . '.</div>';
        return;
    }
    ?>
    
    <h2>Edit Booking: <?php echo htmlspecialchars($booking['booking_number']); ?></h2>
    
    <form method="POST">
        <input type="hidden" name="action" value="update_booking">
        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
        
        <h3>Customer Details</h3>
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" value="<?php echo htmlspecialchars($booking['customer_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Phone Number: *</label>
                <input type="tel" name="customer_phone" value="<?php echo htmlspecialchars($booking['customer_phone']); ?>" required>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email" value="<?php echo htmlspecialchars($booking['customer_email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Booking Date: *</label>
                <input type="date" name="booking_date" value="<?php echo $booking['booking_date']; ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"><?php echo htmlspecialchars($booking['customer_address'] ?? ''); ?></textarea>
        </div>
        
        <h3>Service Details</h3>
        <div class="form-group">
            <label>Service Description: *</label>
            <textarea name="service_description" rows="3" required><?php echo htmlspecialchars($booking['service_description']); ?></textarea>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Expected Completion Date:</label>
                <input type="date" name="expected_completion_date" value="<?php echo $booking['expected_completion_date'] ?? ''; ?>">
            </div>
            <div class="form-group">
                <label>Total Estimated Cost (₹): *</label>
                <input type="number" name="total_estimated_cost" step="0.01" min="0" value="<?php echo $booking['total_estimated_cost']; ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Notes:</label>
            <textarea name="notes" rows="2"><?php echo htmlspecialchars($booking['notes'] ?? ''); ?></textarea>
        </div>
        
        <h3>Service Items</h3>
        <div id="edit-booking-items-container">
            <?php if (!empty($booking['parsed_items'])): ?>
                <?php foreach ($booking['parsed_items'] as $index => $item): ?>
                <div class="item-row">
                    <div class="row">
                        <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                        <div class="form-group" style="flex: 2;">
                            <label>Description</label>
                            <input type="text" name="items[<?php echo $index; ?>][description]" value="<?php echo htmlspecialchars($item['description']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Est. Amount (₹)</label>
                            <input type="number" name="items[<?php echo $index; ?>][estimated_amount]" step="0.01" value="<?php echo $item['estimated_amount']; ?>">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="item-row">
                <div class="row">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[0][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[0][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <button type="button" onclick="addBookingItem()" class="btn-secondary">Add Item</button>
        
        <div class="action-buttons" style="margin-top: 30px;">
            <button type="submit">Update Booking</button>
            <a href="?page=view_booking&id=<?php echo $booking_id; ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
    
    <script>
    function addBookingItem() {
        var container = document.getElementById('edit-booking-items-container');
        var index = container.querySelectorAll('.item-row').length;
        
        var html = `
            <div class="item-row">
                <div class="row">
                    <input type="hidden" name="items[${index}][id]" value="0">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[${index}][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[${index}][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
        `;
        
        var div = document.createElement('div');
        div.innerHTML = html;
        container.appendChild(div.firstElementChild);
    }
    
    function removeBookingItem(button) {
        var itemRow = button.closest('.item-row');
        if (itemRow && document.querySelectorAll('#edit-booking-items-container .item-row').length > 1) {
            itemRow.remove();
        }
    }
    
    
    // Add these functions after the existing ones, before the closing </script> tag

function sendBookingEmail(bookingId, email) {
    var modalHtml = `
        <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Send Booking Receipt via Email</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="send_booking_email">
                    <input type="hidden" name="booking_id" value="${bookingId}">
                    
                    <div class="form-group">
                        <label>To Email:</label>
                        <input type="email" name="email" value="${email}" required>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit">Send Email</button>
                        <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = modalHtml;
    document.body.appendChild(div.firstElementChild);
}

function sendBookingWhatsApp(bookingId, phone) {
    var modalHtml = `
        <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Send Booking Receipt via WhatsApp</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="send_booking_whatsapp">
                    <input type="hidden" name="booking_id" value="${bookingId}">
                    
                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="tel" name="phone" value="${phone}" required>
                        <small>Include country code (e.g., 91 for India)</small>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" style="background: #25D366;">Send WhatsApp</button>
                        <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div> ';
    
    var div = document.createElement('div');
    div.innerHTML = modalHtml;
    document.body.appendChild(div.firstElementChild);
}

function confirmDeleteBooking(bookingId, bookingNumber) {
    if (confirm('Are you sure you want to delete booking: ' + bookingNumber + '? This action cannot be undone.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_booking';
        form.appendChild(actionInput);
        
        var bookingInput = document.createElement('input');
        bookingInput.type = 'hidden';
        bookingInput.name = 'booking_id';
        bookingInput.value = bookingId;
        form.appendChild(bookingInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
    
    </script>
    <?php
}
?>

// ═══════════════════════════════════════════════════════════
// YATRA PAGE RENDERING FUNCTIONS
// ═══════════════════════════════════════════════════════════

function includeYatra() {
    global $db;
    if(isAccountant()){echo '<div class="message error">Access denied.</div>';return;}
    $archived = isset($_GET['archived']);
    $yatras = getAllYatras($archived);
    $cur = getSetting('currency_symbol','₹');
    $edit_id = intval($_GET['edit']??0);
    $ey = $edit_id ? getYatraById($edit_id) : null;
    ?>
<h2 style="margin-bottom:16px;font-size:20px;font-weight:700">🕌 Teerth Yatra Management</h2>

<div class="action-buttons" style="margin-bottom:16px">
    <a href="?page=yatra" class="btn <?php echo !$archived?'':'btn-secondary';?>">🕌 Active Yatras</a>
    <a href="?page=yatra&archived=1" class="btn <?php echo $archived?'':'btn-secondary';?>">📦 Archived</a>
    <a href="?page=create_yatra_booking" class="btn" style="background:#10b981">➕ New Booking</a>
</div>

<div class="card" style="margin-bottom:20px">
<div class="card-header"><div class="card-title"><?php echo $ey?'Edit Yatra':'Add New Yatra';?></div></div>
<div class="card-body">
<form method="POST">
<input type="hidden" name="action" value="save_yatra">
<?php if($ey): ?><input type="hidden" name="yatra_id" value="<?php echo $ey['id'];?>"><?php endif;?>
<div class="row">
    <div class="form-group"><label>Yatra Name *</label><input type="text" name="yatra_name" value="<?php echo htmlspecialchars($ey['yatra_name']??'');?>" required></div>
    <div class="form-group"><label>Destination *</label><input type="text" name="destination" value="<?php echo htmlspecialchars($ey['destination']??'');?>" required></div>
</div>
<div class="row">
    <div class="form-group"><label>Departure Date</label><input type="date" name="departure_date" value="<?php echo $ey['departure_date']??'';?>"></div>
    <div class="form-group"><label>Return Date</label><input type="date" name="return_date" value="<?php echo $ey['return_date']??'';?>"></div>
    <div class="form-group"><label>Closing Date <small style="display:inline">(auto-archives after)</small></label><input type="date" name="closing_date" value="<?php echo $ey['closing_date']??'';?>"></div>
</div>
<div class="row">
    <div class="form-group"><label>Per Person Amount (<?php echo htmlspecialchars($cur);?>) *</label><input type="number" step="0.01" name="per_person_amount" value="<?php echo $ey['per_person_amount']??0;?>" required></div>
    <div class="form-group"><label>Total Seats</label><input type="number" name="total_seats" value="<?php echo $ey['total_seats']??0;?>"></div>
    <div class="form-group"><label>Bus Details</label><input type="text" name="bus_details" value="<?php echo htmlspecialchars($ey['bus_details']??'');?>" placeholder="Bus no., type, operator..."></div>
</div>
<div class="form-group"><label>Description / Notes</label><textarea name="description" rows="2"><?php echo htmlspecialchars($ey['description']??'');?></textarea></div>
<div class="action-buttons">
    <button type="submit" class="btn btn-print">💾 Save Yatra</button>
    <?php if($ey): ?><a href="?page=yatra" class="btn-secondary btn">Cancel</a><?php endif;?>
</div>
</form>
</div></div>

<div class="card">
<div class="card-header"><div class="card-title"><?php echo $archived?'Archived Yatras':'Active Yatras';?> (<?php echo count($yatras);?>)</div></div>
<div class="table-responsive">
<table>
<thead><tr><th>Yatra</th><th>Destination</th><th>Departure</th><th>Per Person</th><th>Seats</th><th>Bookings</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($yatras)): ?>
<tr><td colspan="8" style="text-align:center;padding:30px;color:#999">No yatras found.</td></tr>
<?php else: foreach($yatras as $y): ?>
<tr>
<td><strong><?php echo htmlspecialchars($y['yatra_name']);?></strong>
    <?php if($y['bus_details']): ?><br><small style="color:#888"><?php echo htmlspecialchars($y['bus_details']);?></small><?php endif;?></td>
<td><?php echo htmlspecialchars($y['destination']);?></td>
<td><?php echo $y['departure_date']?date('d-m-Y',strtotime($y['departure_date'])):'—';?></td>
<td><?php echo htmlspecialchars($cur).' '.number_format($y['per_person_amount'],2);?></td>
<td><?php echo $y['total_seats']>0?$y['total_seats']:'—';?></td>
<td><a href="?page=yatra_bookings&yatra_id=<?php echo $y['id'];?>"><?php echo $y['booking_count']??0;?> bookings</a></td>
<td><span class="user-badge" style="<?php echo $y['is_archived']?'background:#f3f4f6;color:#6b7280':'background:#dcfce7;color:#15803d';?>"><?php echo $y['is_archived']?'Archived':'Active';?></span></td>
<td class="actions-cell">
    <a href="?page=create_yatra_booking&yatra_id=<?php echo $y['id'];?>" class="action-btn view-btn">+ Book</a>
    <a href="?page=yatra&edit=<?php echo $y['id'];?>" class="action-btn edit-btn">Edit</a>
    <?php if(!$y['is_archived']): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Archive this yatra?')">
        <input type="hidden" name="action" value="archive_yatra">
        <input type="hidden" name="yatra_id" value="<?php echo $y['id'];?>">
        <button type="submit" class="action-btn edit-btn">Archive</button>
    </form>
    <?php else: ?>
    <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="unarchive_yatra">
        <input type="hidden" name="yatra_id" value="<?php echo $y['id'];?>">
        <button type="submit" class="action-btn view-btn">Restore</button>
    </form>
    <?php endif;?>
    <?php if(isAdmin()): ?>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete yatra permanently?')">
        <input type="hidden" name="action" value="delete_yatra">
        <input type="hidden" name="yatra_id" value="<?php echo $y['id'];?>">
        <button type="submit" class="action-btn delete-btn">Del</button>
    </form>
    <?php endif;?>
</td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
</div></div>
<?php
}

function includeYatraBookings() {
    global $db;
    if(isAccountant()){echo '<div class="message error">Access denied.</div>';return;}
    $yid = intval($_GET['yatra_id']??0);
    $search = $_GET['search']??'';
    $bookings = getAllYatraBookings($yid,$search);
    $cur = getSetting('currency_symbol','₹');
    $yatra = $yid ? getYatraById($yid) : null;
    ?>
<h2 style="margin-bottom:16px;font-size:20px;font-weight:700">🚌 Yatra Bookings<?php if($yatra) echo ': '.htmlspecialchars($yatra['yatra_name']);?></h2>
<div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="?page=create_yatra_booking<?php echo $yid?'&yatra_id='.$yid:'';?>" class="btn" style="background:#10b981">➕ New Booking</a>
    <a href="?page=yatra" class="btn-secondary btn">← Yatras</a>
</div>
<div class="search-box">
<form method="GET" class="search-form">
    <input type="hidden" name="page" value="yatra_bookings">
    <?php if($yid): ?><input type="hidden" name="yatra_id" value="<?php echo $yid;?>"><?php endif;?>
    <div class="form-group"><label>Search</label><input type="text" name="search" placeholder="Name, PNR, ref, phone..." value="<?php echo htmlspecialchars($search);?>"></div>
    <div class="form-group" style="align-self:flex-end"><button type="submit" class="btn">🔍 Search</button></div>
</form>
</div>
<div class="card">
<div class="table-responsive"><table>
<thead><tr><th>PNR</th><th>Ref #</th><th>Lead Passenger</th><th>Yatra</th><th>Pax</th><th>Total</th><th>Paid</th><th>Payment</th><th>Actions</th></tr></thead>
<tbody>
<?php if(empty($bookings)): ?>
<tr><td colspan="9" style="text-align:center;padding:30px;color:#999">No bookings found.</td></tr>
<?php else: foreach($bookings as $bk): ?>
<tr>
<td><strong style="font-family:monospace;font-size:15px;letter-spacing:2px;color:#4f46e5"><?php echo htmlspecialchars($bk['pnr']??'—');?></strong></td>
<td><?php echo htmlspecialchars($bk['booking_ref']);?></td>
<td><?php echo htmlspecialchars($bk['lead_passenger_name']);?><br><small><?php echo htmlspecialchars($bk['phone']??'');?></small></td>
<td><?php echo htmlspecialchars($bk['yatra_name']??'');?></td>
<td><?php echo $bk['total_passengers'];?></td>
<td><?php echo htmlspecialchars($cur).' '.number_format($bk['total_amount'],2);?></td>
<td><?php echo htmlspecialchars($cur).' '.number_format($bk['amount_paid'],2);?></td>
<td><span class="payment-badge <?php echo $bk['payment_status'];?>"><?php echo ucfirst($bk['payment_status']);?></span></td>
<td class="actions-cell">
    <a href="?page=view_yatra_booking&id=<?php echo $bk['id'];?>" class="action-btn view-btn">View</a>
    <a href="?page=edit_yatra_booking&id=<?php echo $bk['id'];?>" class="action-btn edit-btn">Edit</a>
    <button onclick="shareYatraBooking(<?php echo $bk['id'];?>)" class="action-btn" style="background:#dcfce7;color:#15803d;border:none;cursor:pointer">Share</button>
</td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table></div></div>
<script>
function shareYatraBooking(id){
    fetch('?ajax=get_yatra_share&id='+id)
    .then(r=>r.json()).then(d=>{
        if(d.url){navigator.clipboard.writeText(d.url).then(()=>alert('Share link copied!\n\n'+d.url)).catch(()=>prompt('Copy link:',d.url));}
    });
}
</script>
<?php
}

function includeCreateYatraBooking() {
    global $db;
    if(isAccountant()){echo '<div class="message error">Access denied.</div>';return;}
    $pref_yid = intval($_GET['yatra_id']??0);
    $yatras = getAllYatras(false);
    $cur = getSetting('currency_symbol','₹');
    $methods = getPaymentMethods();
    ?>
<h2 style="margin-bottom:16px;font-size:20px;font-weight:700">🚌 New Yatra Booking</h2>
<form method="POST" id="yatraBookingForm">
<input type="hidden" name="action" value="create_yatra_booking">
<input type="hidden" name="passengers_json" id="passengers_json" value="[]">
<input type="hidden" name="total_passengers_count" id="total_passengers_count" value="1">

<div class="card" style="margin-bottom:16px">
<div class="card-header"><div class="card-title">Yatra & Lead Passenger Details</div></div>
<div class="card-body">
<div class="row">
    <div class="form-group">
        <label>Select Yatra *</label>
        <select name="yatra_id" id="yatra_sel" required onchange="fillYatraData(this)">
            <option value="">Choose yatra...</option>
            <?php foreach($yatras as $y): ?>
            <option value="<?php echo $y['id'];?>"
                data-ppa="<?php echo floatval($y['per_person_amount']);?>"
                data-dep="<?php echo htmlspecialchars($y['departure_date']??'');?>"
                data-name="<?php echo htmlspecialchars($y['yatra_name']);?>"
                <?php echo $pref_yid==$y['id']?' selected':'';?>>
                <?php echo htmlspecialchars($y['yatra_name'].' — '.$y['destination']);?>
            </option>
            <?php endforeach;?>
        </select>
    </div>
    <div class="form-group"><label>Booking Date</label><input type="date" name="booking_date" value="<?php echo date('Y-m-d');?>"></div>
</div>
<div class="row">
    <div class="form-group"><label>Lead Passenger Name *</label><input type="text" name="lead_passenger_name" required></div>
    <div class="form-group"><label>Phone *</label><input type="tel" name="phone" required></div>
    <div class="form-group"><label>Email</label><input type="email" name="email"></div>
</div>
<div class="row">
    <div class="form-group"><label>Address</label><input type="text" name="address"></div>
    <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name"></div>
    <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact"></div>
</div>
</div></div>

<div class="card" style="margin-bottom:16px">
<div class="card-header">
    <div class="card-title">Passengers</div>
    <button type="button" onclick="addPassenger()" class="btn" style="padding:5px 12px;font-size:13px">+ Add Passenger</button>
</div>
<div class="card-body">
<div class="table-responsive"><table>
<thead><tr><th>#</th><th>Name *</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th><th></th></tr></thead>
<tbody id="passengers_tbody"></tbody>
</table></div>
</div></div>

<div class="card" style="margin-bottom:16px">
<div class="card-header"><div class="card-title">Payment Details</div></div>
<div class="card-body">
<div class="row">
    <div class="form-group"><label>Per Person Amount (<?php echo htmlspecialchars($cur);?>)</label><input type="number" step="0.01" name="per_person_amount" id="per_person_amount" value="0" oninput="recalcYatraTotal()"></div>
    <div class="form-group"><label>Total Amount</label><input type="number" step="0.01" name="total_amount" id="total_amount_field" value="0"></div>
    <div class="form-group"><label>Advance/Booking Amount</label><input type="number" step="0.01" name="booking_amount" value="0"></div>
</div>
<div class="row">
    <div class="form-group"><label>Payment Method</label>
        <select name="payment_method"><?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>';?></select>
    </div>
    <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
<div class="action-buttons">
    <button type="submit" class="btn btn-print">💾 Create Booking</button>
    <a href="?page=yatra_bookings" class="btn-secondary btn">Cancel</a>
</div>
</div></div>
</form>
<script>
var yatraPassengers = [{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
renderPassengers();
function fillYatraData(sel){
    var opt = sel.options[sel.selectedIndex];
    if(opt.value){
        document.getElementById('per_person_amount').value = opt.dataset.ppa||0;
        recalcYatraTotal();
    }
}
function recalcYatraTotal(){
    var ppa = parseFloat(document.getElementById('per_person_amount').value)||0;
    var cnt = yatraPassengers.length||1;
    document.getElementById('total_passengers_count').value = cnt;
    document.getElementById('total_amount_field').value = (ppa*cnt).toFixed(2);
}
function addPassenger(){
    yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});
    renderPassengers(); recalcYatraTotal();
}
function removePassenger(i){
    if(yatraPassengers.length>1){yatraPassengers.splice(i,1);renderPassengers();recalcYatraTotal();}
}
function renderPassengers(){
    var tb=document.getElementById('passengers_tbody'); if(!tb)return;
    tb.innerHTML='';
    yatraPassengers.forEach(function(p,i){
        var tr=document.createElement('tr');
        tr.innerHTML='<td>'+(i+1)+'</td>'
        +'<td><input style="width:100%;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
        +'<td><input type="number" style="width:60px;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.a)+'" oninput="yatraPassengers['+i+'].a=this.value"></td>'
        +'<td><select style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value">'
        +'<option'+(p.g==='Male'?' selected':'')+'>Male</option><option'+(p.g==='Female'?' selected':'')+'>Female</option><option'+(p.g==='Other'?' selected':'')+'>Other</option>'
        +'</select></td>'
        +'<td><select style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value">'
        +'<option'+(p.ipt==='Aadhaar'?' selected':'')+'>Aadhaar</option>'
        +'<option'+(p.ipt==='PAN'?' selected':'')+'>PAN</option>'
        +'<option'+(p.ipt==='Voter ID'?' selected':'')+'>Voter ID</option>'
        +'<option'+(p.ipt==='Passport'?' selected':'')+'>Passport</option>'
        +'</select></td>'
        +'<td><input style="width:100%;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
        +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
        tb.appendChild(tr);
    });
    document.getElementById('passengers_json').value = JSON.stringify(yatraPassengers.map(function(p){return{name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};}));
    document.getElementById('total_passengers_count').value = yatraPassengers.length;
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
<?php if($pref_yid): ?>
document.addEventListener('DOMContentLoaded',function(){
    var sel=document.getElementById('yatra_sel');
    if(sel) fillYatraData(sel);
});
<?php endif;?>
</script>
<?php
}

function includeViewYatraBooking() {
    global $db;
    if(isAccountant()){echo '<div class="message error">Access denied.</div>';return;}
    $id = intval($_GET['id']??0);
    $bk = getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Booking not found.</div>';return;}
    $cur = getSetting('currency_symbol','₹');
    $bal = floatval($bk['total_amount']) - floatval($bk['amount_paid']);
    $methods = getPaymentMethods();
    $upi = generateUPILink($bal,$bk['booking_ref'],'Yatra '.$bk['booking_ref']);
    $qr_url = $bal>0 ? generateQRCode($upi,150) : '';
    // QR verify token
    $vtok = $db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='yatra' AND doc_id=$id AND is_active=1 LIMIT 1");
    if(!$vtok) $vtok = generateVerifyToken('yatra',$id,$bk['pnr']??$bk['booking_ref'],'Yatra Booking '.$bk['booking_ref']);
    $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
    $vurl=$proto.$host.$dir.'/view.php?verify='.$vtok;
    ?>
<div class="action-buttons no-print" style="margin-bottom:16px">
    <a href="?page=yatra_bookings" class="btn-secondary btn btn-sm">← Bookings</a>
    <a href="?page=edit_yatra_booking&id=<?php echo $id;?>" class="btn btn-sm" style="background:#f59e0b;color:#fff">✏️ Edit</a>
    <button onclick="window.print()" class="btn btn-print btn-sm">🖨️ Print Ticket</button>
    <button onclick="shareYatraBooking(<?php echo $id;?>)" class="btn btn-whatsapp btn-sm">📤 Share</button>
    <button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($vurl);?>').then(()=>alert('Verify link copied!'))" class="btn-secondary btn btn-sm">🔍 QR Verify Link</button>
</div>

<div class="invoice-preview">
<!-- Ticket Header -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #eee;flex-wrap:wrap;gap:12px">
    <div>
        <?php $lp=getSetting('logo_path'); if($lp&&file_exists($lp)): ?>
        <img src="<?php echo htmlspecialchars($lp);?>" style="max-height:60px;max-width:140px;margin-bottom:6px;display:block" alt="Logo">
        <?php endif;?>
        <h2 style="font-size:18px;font-weight:800;color:#2c3e50;margin:0"><?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES'));?></h2>
        <div style="font-size:12px;color:#666;margin-top:4px"><?php echo htmlspecialchars(getSetting('office_phone',''));?></div>
    </div>
    <div style="text-align:right">
        <div style="font-size:18px;font-weight:800;margin-bottom:8px">🚌 YATRA TICKET</div>
        <?php if(!empty($bk['pnr'])): ?>
        <div style="background:#1e1b4b;color:#fff;padding:8px 16px;border-radius:8px;display:inline-block;margin-bottom:8px">
            <div style="font-size:10px;letter-spacing:1px;opacity:.8;margin-bottom:2px">PNR NUMBER</div>
            <div style="font-family:monospace;font-size:24px;font-weight:800;letter-spacing:5px"><?php echo htmlspecialchars($bk['pnr']);?></div>
        </div>
        <?php endif;?>
        <div style="font-size:13px"><strong>Booking Ref:</strong> <?php echo htmlspecialchars($bk['booking_ref']);?></div>
        <div style="font-size:13px"><strong>Booking Date:</strong> <?php echo $bk['booking_date']?date('d-m-Y',strtotime($bk['booking_date'])):'';?></div>
        <div style="margin-top:8px;text-align:right">
            <img src="<?php echo htmlspecialchars(generateQRCode($vurl,80));?>" width="80" alt="Verify QR" title="Scan to verify authenticity">
            <div style="font-size:9px;color:#888;margin-top:2px">Scan to verify</div>
        </div>
    </div>
</div>

<!-- Passenger & Yatra Info -->
<div style="background:#f8fafc;padding:12px 16px;border-radius:8px;margin-bottom:16px;border-left:4px solid #4f46e5">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px">
        <div>
            <div style="font-weight:700;color:#4f46e5;margin-bottom:6px">LEAD PASSENGER</div>
            <strong><?php echo htmlspecialchars($bk['lead_passenger_name']);?></strong><br>
            <?php if($bk['phone']): ?>📞 <?php echo htmlspecialchars($bk['phone']);?><br><?php endif;?>
            <?php if($bk['email']): ?>✉️ <?php echo htmlspecialchars($bk['email']);?><br><?php endif;?>
            <?php if($bk['address']): ?>📍 <?php echo htmlspecialchars($bk['address']);?><br><?php endif;?>
            <?php if($bk['emergency_contact']): ?><span style="color:#ef4444">⚠️ Emergency: <?php echo htmlspecialchars($bk['emergency_contact_name']??'');?> <?php echo htmlspecialchars($bk['emergency_contact']);?></span><?php endif;?>
        </div>
        <div>
            <div style="font-weight:700;color:#4f46e5;margin-bottom:6px">YATRA DETAILS</div>
            <strong><?php echo htmlspecialchars($bk['yatra_name']??'');?></strong><br>
            <?php if($bk['departure_date']??false): ?>🚀 Departure: <?php echo date('d-m-Y',strtotime($bk['departure_date']));?><br><?php endif;?>
            <?php if($bk['return_date']??false): ?>🏁 Return: <?php echo date('d-m-Y',strtotime($bk['return_date']));?><br><?php endif;?>
            <?php if($bk['bus_details']??false): ?>🚌 <?php echo htmlspecialchars($bk['bus_details']);?><br><?php endif;?>
            Total Passengers: <strong><?php echo count($bk['passengers']);?></strong>
        </div>
    </div>
</div>

<!-- Passengers Table -->
<?php if(!empty($bk['passengers'])): ?>
<h3 style="font-size:14px;font-weight:700;margin-bottom:8px">👥 Passenger List (<?php echo count($bk['passengers']);?>)</h3>
<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
<thead><tr style="background:#f1f5f9">
    <th style="border:1px solid #e2e8f0;padding:8px 10px;text-align:left">#</th>
    <th style="border:1px solid #e2e8f0;padding:8px 10px;text-align:left">Name</th>
    <th style="border:1px solid #e2e8f0;padding:8px 10px;text-align:left">Age</th>
    <th style="border:1px solid #e2e8f0;padding:8px 10px;text-align:left">Gender</th>
    <th style="border:1px solid #e2e8f0;padding:8px 10px;text-align:left">ID Proof</th>
</tr></thead>
<tbody>
<?php foreach($bk['passengers'] as $i=>$p): ?>
<tr>
    <td style="border:1px solid #e2e8f0;padding:7px 10px"><?php echo $i+1;?></td>
    <td style="border:1px solid #e2e8f0;padding:7px 10px;font-weight:600"><?php echo htmlspecialchars($p['name']);?></td>
    <td style="border:1px solid #e2e8f0;padding:7px 10px"><?php echo $p['age']??'—';?></td>
    <td style="border:1px solid #e2e8f0;padding:7px 10px"><?php echo htmlspecialchars($p['gender']??'');?></td>
    <td style="border:1px solid #e2e8f0;padding:7px 10px"><?php echo htmlspecialchars($p['id_proof_type']??'');?> <?php echo htmlspecialchars($p['id_proof_number']??'');?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
<?php endif;?>

<!-- Payment Summary -->
<div style="display:flex;justify-content:flex-end;margin-bottom:16px">
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;min-width:220px;font-size:13px">
        <table style="width:100%">
        <tr><td style="padding:3px 12px 3px 0;color:#64748b">Total Amount</td><td style="font-weight:700;text-align:right"><?php echo htmlspecialchars($cur).' '.number_format($bk['total_amount'],2);?></td></tr>
        <tr><td style="padding:3px 12px 3px 0;color:#64748b">Amount Paid</td><td style="font-weight:700;text-align:right;color:#10b981"><?php echo htmlspecialchars($cur).' '.number_format($bk['amount_paid'],2);?></td></tr>
        <?php if($bal>0): ?>
        <tr style="border-top:2px solid #e2e8f0"><td style="padding:6px 12px 3px 0;font-weight:800;font-size:16px">Balance Due</td><td style="font-weight:800;text-align:right;font-size:16px;color:#ef4444;padding-top:6px"><?php echo htmlspecialchars($cur).' '.number_format($bal,2);?></td></tr>
        <?php endif;?>
        </table>
    </div>
</div>

<?php if($bal>0 && $qr_url): ?>
<div style="text-align:center;margin:16px 0" class="no-print">
    <p style="font-size:13px;font-weight:600;margin-bottom:8px">Scan to Pay <?php echo htmlspecialchars($cur).' '.number_format($bal,2);?></p>
    <img src="<?php echo htmlspecialchars($qr_url);?>" width="150" alt="Payment QR">
    <?php if($upi): ?><br><a href="<?php echo htmlspecialchars($upi);?>" class="btn btn-print btn-sm" style="margin-top:8px">Pay Now</a><?php endif;?>
</div>
<?php endif;?>

<div style="text-align:center;font-size:12px;color:#94a3b8;margin-top:16px;padding-top:12px;border-top:1px solid #eee">
    <?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES'));?> | <?php echo htmlspecialchars(getSetting('office_phone',''));?> | <?php echo htmlspecialchars(getSetting('company_website',''));?>
</div>
</div><!-- /invoice-preview -->

<!-- Payment History -->
<?php if(!empty($bk['payments'])): ?>
<div class="card no-print" style="margin-top:16px">
<div class="card-header"><div class="card-title">💳 Payment History</div></div>
<div class="table-responsive"><table>
<thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Transaction ID</th><?php if(isAdmin()||isManager()):?><th>Actions</th><?php endif;?></tr></thead>
<tbody>
<?php foreach($bk['payments'] as $p): ?>
<tr>
    <td><?php echo date('d-m-Y',strtotime($p['payment_date']));?></td>
    <td><?php echo htmlspecialchars($cur).' '.number_format($p['amount'],2);?></td>
    <td><?php echo htmlspecialchars($p['payment_method']??'');?></td>
    <td><?php echo htmlspecialchars($p['transaction_id']??'');?></td>
    <?php if(isAdmin()||isManager()):?><td>
    <form method="POST" style="display:inline" onsubmit="return confirm('Delete payment?')">
        <input type="hidden" name="action" value="delete_yatra_payment">
        <input type="hidden" name="payment_id" value="<?php echo $p['id'];?>">
        <input type="hidden" name="booking_id" value="<?php echo $id;?>">
        <button type="submit" class="action-btn delete-btn">Del</button>
    </form>
    </td><?php endif;?>
</tr>
<?php endforeach;?>
</tbody>
</table></div></div>
<?php endif;?>

<!-- Add Payment Form -->
<div class="card no-print" style="margin-top:16px">
<div class="card-header"><div class="card-title">Add Payment</div></div>
<div class="card-body">
<form method="POST">
    <input type="hidden" name="action" value="add_yatra_payment">
    <input type="hidden" name="booking_id" value="<?php echo $id;?>">
    <div class="row">
        <div class="form-group"><label>Payment Date</label><input type="date" name="payment_date" value="<?php echo date('Y-m-d');?>"></div>
        <div class="form-group"><label>Amount</label><input type="number" step="0.01" name="amount" value="<?php echo max(0,$bal);?>"></div>
        <div class="form-group"><label>Method</label><select name="payment_method"><?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>';?></select></div>
        <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
        <div class="form-group" style="align-self:flex-end"><button type="submit" class="btn btn-print">Record Payment</button></div>
    </div>
</form>
<?php if($bk['status']!=='cancelled'&&(isAdmin()||isManager())):?>
<hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0">
<form method="POST" onsubmit="return confirm('Cancel this booking?')">
    <input type="hidden" name="action" value="cancel_yatra_booking">
    <input type="hidden" name="booking_id" value="<?php echo $id;?>">
    <button type="submit" class="btn btn-danger btn-sm">Cancel Booking</button>
</form>
<?php endif;?>
</div></div>

<script>
function shareYatraBooking(id){
    fetch('?ajax=get_yatra_share&id='+id)
    .then(r=>r.json()).then(d=>{
        if(d.url){navigator.clipboard.writeText(d.url).then(()=>alert('Share link copied!\n\n'+d.url)).catch(()=>prompt('Copy link:',d.url));}
    });
}
</script>
<?php
}

function includeEditYatraBooking() {
    global $db;
    if(isAccountant()){echo '<div class="message error">Access denied.</div>';return;}
    $id = intval($_GET['id']??0);
    $bk = getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Not found.</div>';return;}
    $methods = getPaymentMethods();
    $passengerJson = json_encode(array_map(function($p){
        return ['n'=>$p['name'],'a'=>$p['age']??'','g'=>$p['gender']??'Male','ipt'=>$p['id_proof_type']??'Aadhaar','ipn'=>$p['id_proof_number']??''];
    },$bk['passengers']));
    ?>
<h2 style="margin-bottom:16px;font-size:20px;font-weight:700">✏️ Edit Yatra Booking: <?php echo htmlspecialchars($bk['pnr']??$bk['booking_ref']);?></h2>
<form method="POST">
<input type="hidden" name="action" value="update_yatra_booking">
<input type="hidden" name="booking_id" value="<?php echo $id;?>">
<input type="hidden" name="passengers_json" id="passengers_json" value="<?php echo htmlspecialchars($passengerJson);?>">
<input type="hidden" name="total_passengers_count" id="total_passengers_count" value="<?php echo count($bk['passengers']);?>">

<div class="card" style="margin-bottom:16px"><div class="card-header"><div class="card-title">Booking Details</div></div>
<div class="card-body">
<div class="row">
    <div class="form-group"><label>Lead Passenger Name</label><input type="text" name="lead_passenger_name" value="<?php echo htmlspecialchars($bk['lead_passenger_name']);?>"></div>
    <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($bk['phone']??'');?>"></div>
    <div class="form-group"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($bk['email']??'');?>"></div>
</div>
<div class="row">
    <div class="form-group"><label>Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($bk['address']??'');?>"></div>
    <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($bk['emergency_contact_name']??'');?>"></div>
    <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($bk['emergency_contact']??'');?>"></div>
</div>
<div class="row">
    <div class="form-group"><label>Total Amount</label><input type="number" step="0.01" name="total_amount" value="<?php echo $bk['total_amount'];?>"></div>
    <div class="form-group"><label>Booking/Advance Amount</label><input type="number" step="0.01" name="booking_amount" value="<?php echo $bk['booking_amount']??0;?>"></div>
</div>
<div class="form-group"><label>Notes</label><textarea name="notes" rows="2"><?php echo htmlspecialchars($bk['notes']??'');?></textarea></div>
</div></div>

<div class="card" style="margin-bottom:16px"><div class="card-header">
    <div class="card-title">Passengers</div>
    <button type="button" onclick="addPassenger()" class="btn" style="padding:5px 12px;font-size:13px">+ Add</button>
</div>
<div class="card-body">
<div class="table-responsive"><table>
<thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Type</th><th>ID No.</th><th></th></tr></thead>
<tbody id="passengers_tbody"></tbody>
</table></div>
</div></div>

<div class="action-buttons">
    <button type="submit" class="btn btn-print">💾 Save Changes</button>
    <a href="?page=view_yatra_booking&id=<?php echo $id;?>" class="btn-secondary btn">Cancel</a>
</div>
</form>
<script>
var yatraPassengers = <?php echo $passengerJson;?>;
if(!yatraPassengers.length) yatraPassengers=[{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
function addPassenger(){yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});renderPassengers();}
function removePassenger(i){if(yatraPassengers.length>1){yatraPassengers.splice(i,1);renderPassengers();}}
function renderPassengers(){
    var tb=document.getElementById('passengers_tbody'); if(!tb)return;
    tb.innerHTML='';
    yatraPassengers.forEach(function(p,i){
        var tr=document.createElement('tr');
        tr.innerHTML='<td>'+(i+1)+'</td>'
        +'<td><input style="width:100%;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
        +'<td><input type="number" style="width:60px;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.a)+'" oninput="yatraPassengers['+i+'].a=this.value"></td>'
        +'<td><select style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value"><option'+(p.g==='Male'?' selected':'')+'>Male</option><option'+(p.g==='Female'?' selected':'')+'>Female</option><option'+(p.g==='Other'?' selected':'')+'>Other</option></select></td>'
        +'<td><select style="padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value"><option'+(p.ipt==='Aadhaar'?' selected':'')+'>Aadhaar</option><option'+(p.ipt==='PAN'?' selected':'')+'>PAN</option><option'+(p.ipt==='Voter ID'?' selected':'')+'>Voter ID</option><option'+(p.ipt==='Passport'?' selected':'')+'>Passport</option></select></td>'
        +'<td><input style="width:100%;padding:5px 8px;border:1px solid #e2e8f0;border-radius:4px" value="'+esc(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
        +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
        tb.appendChild(tr);
    });
    document.getElementById('passengers_json').value=JSON.stringify(yatraPassengers.map(function(p){return{name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};}));
    document.getElementById('total_passengers_count').value=yatraPassengers.length;
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
renderPassengers();
</script>
<?php
}



<?php

// ═══════════════════════════════════════════════════════
// YATRA PAGE FUNCTIONS
// ═══════════════════════════════════════════════════════

function includeYatra() {
    global $db;
    if(!isAdmin()&&!isManager()){echo '<div class="message error">Access denied.</div>';return;}
    // Auto-archive past-closing-date yatras
    $today=date('Y-m-d');
    try{$db->exec("UPDATE yatras SET is_archived=1,status='archived' WHERE closing_date<'$today' AND closing_date!='' AND is_archived=0");}catch(Exception $e){}
    $archived=isset($_GET['archived']);
    $yatras=getAllYatras($archived);
    $cur=getSetting('currency_symbol','₹');
    $edit_id=intval($_GET['edit']??0);
    $ey=$edit_id?getYatraById($edit_id):null;
    ?>
    <h2 class="page-title-h2" style="font-size:22px;font-weight:800;margin-bottom:20px">🕌 Teerth Yatra Management</h2>
    <div style="display:flex;gap:10px;margin-bottom:16px">
        <a href="?page=yatra" class="btn <?php echo !$archived?'':' btn-secondary'; ?>" style="<?php echo !$archived?'background:var(--success);color:#fff':''; ?>">🕌 Active Yatras</a>
        <a href="?page=yatra&archived=1" class="btn-secondary btn" style="<?php echo $archived?'background:var(--warning);color:#fff':''; ?>">📦 Archived</a>
        <a href="?page=create_yatra_booking" class="btn" style="background:var(--primary);color:#fff">➕ New Booking</a>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><div class="card-title"><?php echo $ey?'Edit Yatra':'Add New Yatra'; ?></div></div>
        <div class="card-body">
        <form method="POST">
        <input type="hidden" name="action" value="save_yatra">
        <?php if($ey): ?><input type="hidden" name="yatra_id" value="<?php echo $ey['id']; ?>"><?php endif; ?>
        <div class="row">
            <div class="form-group"><label>Yatra Name *</label><input type="text" name="yatra_name" value="<?php echo htmlspecialchars($ey['yatra_name']??''); ?>" required></div>
            <div class="form-group"><label>Destination *</label><input type="text" name="destination" value="<?php echo htmlspecialchars($ey['destination']??''); ?>" required></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Departure Date</label><input type="date" name="departure_date" value="<?php echo $ey['departure_date']??''; ?>"></div>
            <div class="form-group"><label>Return Date</label><input type="date" name="return_date" value="<?php echo $ey['return_date']??''; ?>"></div>
            <div class="form-group"><label>Closing Date</label><input type="date" name="closing_date" value="<?php echo $ey['closing_date']??''; ?>"><small>Auto-archives after this date</small></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Per Person Amount (<?php echo $cur; ?>) *</label><input type="number" step="0.01" name="per_person_amount" value="<?php echo floatval($ey['per_person_amount']??0); ?>" required></div>
            <div class="form-group"><label>Total Seats</label><input type="number" name="total_seats" value="<?php echo intval($ey['total_seats']??0); ?>"></div>
            <div class="form-group"><label>Bus Details</label><input type="text" name="bus_details" value="<?php echo htmlspecialchars($ey['bus_details']??''); ?>"></div>
        </div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2"><?php echo htmlspecialchars($ey['description']??''); ?></textarea></div>
        <div class="action-buttons">
            <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Save Yatra</button>
            <?php if($ey): ?><a href="?page=yatra" class="btn-secondary btn">Cancel</a><?php endif; ?>
        </div>
        </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title"><?php echo $archived?'Archived Yatras':'Active Yatras'; ?> (<?php echo count($yatras); ?>)</div></div>
        <div style="overflow-x:auto"><table>
        <thead><tr><th>Yatra Name</th><th>Destination</th><th>Departure</th><th>Per Person</th><th>Seats</th><th>Bookings</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($yatras)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text2)">No yatras found.</td></tr>
        <?php else: foreach($yatras as $y): ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($y['yatra_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($y['destination']); ?></td>
            <td><?php echo $y['departure_date']?date('d-m-Y',strtotime($y['departure_date'])):'—'; ?></td>
            <td><?php echo $cur.' '.number_format($y['per_person_amount'],2); ?></td>
            <td><?php echo $y['total_seats']?$y['total_seats']:'—'; ?></td>
            <td><a href="?page=yatra_bookings&yatra_id=<?php echo $y['id']; ?>"><?php echo intval($y['booking_count']); ?> bookings</a></td>
            <td><span class="user-badge <?php echo $y['is_archived']?'accountant':'manager'; ?>"><?php echo $y['is_archived']?'Archived':'Active'; ?></span></td>
            <td class="actions-cell">
                <a href="?page=create_yatra_booking&yatra_id=<?php echo $y['id']; ?>" class="action-btn view-btn">+ Book</a>
                <a href="?page=yatra_bookings&yatra_id=<?php echo $y['id']; ?>" class="action-btn" style="background:#ede9fe;color:#7c3aed">Bookings</a>
                <a href="?page=yatra&edit=<?php echo $y['id']; ?>" class="action-btn edit-btn">Edit</a>
                <?php if(!$y['is_archived']): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Archive this yatra?')">
                    <input type="hidden" name="action" value="archive_yatra"><input type="hidden" name="yatra_id" value="<?php echo $y['id']; ?>">
                    <button type="submit" class="action-btn" style="background:#fef3c7;color:#d97706">Archive</button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="unarchive_yatra"><input type="hidden" name="yatra_id" value="<?php echo $y['id']; ?>">
                    <button type="submit" class="action-btn view-btn">Restore</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        </table></div>
    </div>
<?php }

function includeYatraBookings() {
    global $db;
    $yid=intval($_GET['yatra_id']??0);
    $s=$_GET['search']??'';
    $bookings=getAllYatraBookings($yid,$s);
    $cur=getSetting('currency_symbol','₹');
    $yatra=$yid?getYatraById($yid):null;
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">🚌 Yatra Bookings<?php echo $yatra?' — '.htmlspecialchars($yatra['yatra_name']):''; ?></h2>
    <div style="background:var(--surface);border-radius:var(--radius-sm);border:1px solid var(--border);padding:16px 20px;margin-bottom:16px;box-shadow:var(--shadow)">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="yatra_bookings">
            <?php if($yid): ?><input type="hidden" name="yatra_id" value="<?php echo $yid; ?>"><?php endif; ?>
            <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0"><label>Search (Name, PNR, Ref, Phone)</label><input type="text" name="search" value="<?php echo htmlspecialchars($s); ?>" placeholder="Search..."></div>
            <button type="submit" class="btn">🔍 Search</button>
        </form>
    </div>
    <div class="action-buttons" style="margin-bottom:16px">
        <a href="?page=create_yatra_booking<?php echo $yid?'&yatra_id='.$yid:''; ?>" class="btn" style="background:var(--success);color:#fff">➕ New Booking</a>
        <a href="?page=yatra" class="btn-secondary btn">← Yatras</a>
    </div>
    <div class="card"><div style="overflow-x:auto"><table>
    <thead><tr><th>PNR</th><th>Ref #</th><th>Lead Passenger</th><th>Yatra</th><th>Pax</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($bookings)): ?>
    <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text2)">No bookings found.</td></tr>
    <?php else: foreach($bookings as $bk): ?>
    <tr>
        <td><span style="font-family:monospace;font-size:15px;font-weight:800;letter-spacing:3px;color:var(--primary)"><?php echo htmlspecialchars($bk['pnr']??'—'); ?></span></td>
        <td><?php echo htmlspecialchars($bk['booking_ref']); ?></td>
        <td><?php echo htmlspecialchars($bk['lead_passenger_name']); ?><br><small style="color:var(--text2)"><?php echo htmlspecialchars($bk['phone']??''); ?></small></td>
        <td><?php echo htmlspecialchars($bk['yatra_name']??''); ?></td>
        <td style="text-align:center"><?php echo $bk['total_passengers']; ?></td>
        <td><?php echo $cur.' '.number_format($bk['total_amount'],2); ?></td>
        <td style="color:var(--success)"><?php echo $cur.' '.number_format($bk['amount_paid'],2); ?></td>
        <td style="color:<?php echo floatval($bk['balance'])>0?'var(--danger)':'var(--success)'; ?>"><?php echo $cur.' '.number_format($bk['balance'],2); ?></td>
        <td><span class="payment-badge <?php echo $bk['payment_status']; ?>"><?php echo ucfirst(str_replace('_',' ',$bk['payment_status'])); ?></span></td>
        <td class="actions-cell">
            <a href="?page=view_yatra_booking&id=<?php echo $bk['id']; ?>" class="action-btn view-btn">View</a>
            <a href="?page=edit_yatra_booking&id=<?php echo $bk['id']; ?>" class="action-btn edit-btn">Edit</a>
            <button type="button" onclick="shareYatraBooking(<?php echo $bk['id']; ?>)" class="action-btn" style="background:#dcfce7;color:#15803d">📤 Share</button>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
    </table></div></div>
    <script>
    function shareYatraBooking(id) {
        fetch('?ajax=get_yatra_share&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){if(d.url){navigator.clipboard.writeText(d.url).then(function(){alert('Link copied!\n'+d.url);}).catch(function(){prompt('Copy:',d.url);});}});
    }
    </script>
<?php }

function includeCreateYatraBooking() {
    global $db;
    $pref_yid=intval($_GET['yatra_id']??0);
    $yatras=getAllYatras(false);
    $cur=getSetting('currency_symbol','₹');
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">🚌 New Yatra Booking</h2>
    <form method="POST" id="yatraBookingForm">
    <input type="hidden" name="action" value="create_yatra_booking">
    <input type="hidden" name="passengers_json" id="passengers_json" value="[]">
    <input type="hidden" name="total_passengers_count" id="total_passengers_count" value="1">

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Yatra & Lead Passenger</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Select Yatra *</label>
            <select name="yatra_id" id="yatra_sel" required onchange="fillYatraDefaults(this)">
                <option value="">Choose yatra...</option>
                <?php foreach($yatras as $y): ?>
                <option value="<?php echo $y['id']; ?>" data-ppa="<?php echo floatval($y['per_person_amount']); ?>" data-dep="<?php echo $y['departure_date']??''; ?>"<?php echo $pref_yid==$y['id']?' selected':''; ?>><?php echo htmlspecialchars($y['yatra_name'].' — '.$y['destination']); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-group"><label>Booking Date</label><input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Lead Passenger Name *</label><input type="text" name="lead_passenger_name" required></div>
            <div class="form-group"><label>Phone *</label><input type="tel" name="phone" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Address</label><input type="text" name="address"></div>
            <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name"></div>
            <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact"></div>
        </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <div class="card-title">Passenger Details</div>
            <button type="button" onclick="addPassenger()" class="btn" style="padding:6px 14px;font-size:12px;background:var(--success);color:#fff">+ Add Passenger</button>
        </div>
        <div class="card-body" style="padding:0">
        <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>Name *</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th><th></th></tr></thead>
        <tbody id="passengers_tbody"></tbody>
        </table></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Payment Details</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Per Person Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="per_person_amount" id="per_person_amount" value="0" oninput="updateYatraTotal()"></div>
            <div class="form-group"><label>Total Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="total_amount" id="total_amount_field" value="0"></div>
            <div class="form-group"><label>Advance / Booking Amount</label><input type="number" step="0.01" name="booking_amount" value="0"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
                <?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>'; ?>
            </select></div>
            <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
        <div class="action-buttons">
            <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Create Booking</button>
            <a href="?page=yatra_bookings" class="btn-secondary btn">Cancel</a>
        </div>
        </div>
    </div>
    </form>
    <script>
    var yatraPassengers = [{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
    renderYatraPassengers();

    function addPassenger() {
        yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});
        renderYatraPassengers();
    }
    function removePassenger(i) {
        if(yatraPassengers.length > 1) { yatraPassengers.splice(i,1); renderYatraPassengers(); }
    }
    function renderYatraPassengers() {
        var tb = document.getElementById('passengers_tbody');
        if(!tb) return;
        tb.innerHTML = '';
        yatraPassengers.forEach(function(p, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>'+(i+1)+'</td>'
                +'<td><input style="width:140px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
                +'<td><input type="number" style="width:60px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.a||'')+'\x22 oninput="yatraPassengers['+i+'].a=this.value"></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value">'
                    +['Male','Female','Other'].map(function(g){return '<option'+(p.g===g?' selected':'')+'>'+g+'</option>';}).join('')
                +'</select></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value">'
                    +['Aadhaar','PAN','Voter ID','Passport','Other'].map(function(t){return '<option'+(p.ipt===t?' selected':'')+'>'+t+'</option>';}).join('')
                +'</select></td>'
                +'<td><input style="width:130px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
                +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
            tb.appendChild(tr);
        });
        document.getElementById('passengers_json').value = JSON.stringify(yatraPassengers.map(function(p){
            return {name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};
        }));
        document.getElementById('total_passengers_count').value = yatraPassengers.length;
        updateYatraTotal();
    }
    function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||'\x27));return d.innerHTML;}
    function fillYatraDefaults(sel) {
        var opt = sel.options[sel.selectedIndex];
        if(opt.value) {
            document.getElementById('per_person_amount').value = opt.dataset.ppa||0;
            updateYatraTotal();
        }
    }
    function updateYatraTotal() {
        var ppa = parseFloat(document.getElementById('per_person_amount').value)||0;
        var cnt = yatraPassengers.length||1;
        document.getElementById('total_amount_field').value = (ppa*cnt).toFixed(2);
    }
    <?php if($pref_yid): ?>
    document.addEventListener('DOMContentLoaded',function(){
        var sel=document.getElementById('yatra_sel');if(sel)fillYatraDefaults(sel);
    });
    <?php endif; ?>
    </script>
<?php }

function includeViewYatraBooking() {
    global $db;
    $id=intval($_GET['id']??0);
    $bk=getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Booking not found.</div>';return;}
    $cur=getSetting('currency_symbol','₹');
    $bal=floatval($bk['total_amount'])-floatval($bk['amount_paid']);
    $upi=generateUPILink($bal,$bk['booking_ref'],'Yatra: '.$bk['booking_ref']);
    $qr_url=$bal>0?generateQRCode($upi,160):'';
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    // QR verify token
    $vtok=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='yatra' AND doc_id=$id AND is_active=1 LIMIT 1");
    if(!$vtok) $vtok=generateVerifyToken('yatra',$id,$bk['pnr']??$bk['booking_ref'],'Yatra Booking '.$bk['booking_ref']);
    $vurl=getVerifyUrl($vtok);
    ?>
    <div class="action-buttons no-print" style="margin-bottom:16px">
        <a href="?page=yatra_bookings" class="btn-secondary btn">← Back</a>
        <a href="?page=edit_yatra_booking&id=<?php echo $id; ?>" class="btn" style="background:var(--warning);color:#fff">✏️ Edit</a>
        <button onclick="window.print()" class="btn" style="background:var(--success);color:#fff">🖨️ Print Ticket</button>
        <button onclick="shareYatraBk(<?php echo $id; ?>)" class="btn" style="background:#25D366;color:#fff">📤 Share</button>
        <button onclick="copyLink('<?php echo htmlspecialchars($vurl); ?>')" class="btn btn-secondary" style="font-size:12px">🔍 Verify QR Link</button>
    </div>

    <div class="invoice-preview" id="yatra_print_area">
        <div class="invoice-header">
            <div class="company-info">
                <?php $lp=getSetting('logo_path'); if($lp&&file_exists($lp)): ?>
                <img src="<?php echo htmlspecialchars($lp); ?>" style="max-height:70px;max-width:150px;margin-bottom:6px"><br>
                <?php endif; ?>
                <h2 style="font-size:18px;font-weight:800;color:#1e293b"><?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES')); ?></h2>
                <div style="font-size:12px;color:#555;line-height:1.7;margin-top:4px">
                    <?php if($ph=getSetting('office_phone')) echo '📞 '.htmlspecialchars($ph).'<br>'; ?>
                    <?php if($em=getSetting('company_email')) echo '✉️ '.htmlspecialchars($em).'<br>'; ?>
                </div>
            </div>
            <div class="invoice-meta">
                <div style="font-size:20px;font-weight:800;margin-bottom:8px">🚌 YATRA TICKET</div>
                <?php if(!empty($bk['pnr'])): ?>
                <div style="background:#1e1b4b;color:#fff;padding:10px 16px;border-radius:10px;display:inline-block;margin:8px 0;text-align:center">
                    <div style="font-size:10px;letter-spacing:1px;opacity:.8;margin-bottom:2px">PNR</div>
                    <div style="font-family:monospace;font-size:24px;font-weight:800;letter-spacing:5px"><?php echo htmlspecialchars($bk['pnr']); ?></div>
                </div><br>
                <?php endif; ?>
                <div><strong>Ref #:</strong> <?php echo htmlspecialchars($bk['booking_ref']); ?></div>
                <div><strong>Booking Date:</strong> <?php echo $bk['booking_date']?date('d-m-Y',strtotime($bk['booking_date'])):''; ?></div>
                <?php if(!empty($bk['departure_date'])): ?>
                <div><strong>Departure:</strong> <?php echo date('d-m-Y',strtotime($bk['departure_date'])); ?></div>
                <?php endif; ?>
                <div style="margin-top:8px;text-align:center">
                    <img src="<?php echo htmlspecialchars(generateQRCode($vurl,80)); ?>" width="80" alt="Verify QR">
                    <div style="font-size:9px;color:#888;margin-top:2px">Scan to verify</div>
                </div>
            </div>
        </div>

        <div class="customer-info">
            <div style="display:flex;gap:24px;flex-wrap:wrap">
                <div>
                    <strong style="display:block;margin-bottom:4px">Lead Passenger:</strong>
                    <strong><?php echo htmlspecialchars($bk['lead_passenger_name']); ?></strong><br>
                    <?php if($bk['phone']) echo '📞 '.htmlspecialchars($bk['phone']).' <br>'; ?>
                    <?php if($bk['email']) echo '✉️ '.htmlspecialchars($bk['email']).' <br>'; ?>
                    <?php if($bk['address']) echo '📍 '.htmlspecialchars($bk['address']); ?>
                </div>
                <div>
                    <strong style="display:block;margin-bottom:4px">Yatra Details:</strong>
                    <strong><?php echo htmlspecialchars($bk['yatra_name']??''); ?></strong><br>
                    <?php if($bk['destination']) echo '📍 '.$bk['destination'].'<br>'; ?>
                    <?php if(!empty($bk['bus_details'])) echo '🚌 '.htmlspecialchars($bk['bus_details']).' <br>'; ?>
                    <?php if($bk['return_date']) echo 'Return: '.date('d-m-Y',strtotime($bk['return_date'])); ?>
                </div>
                <?php if(!empty($bk['emergency_contact'])): ?>
                <div>
                    <strong>Emergency Contact:</strong><br>
                    <?php echo htmlspecialchars($bk['emergency_contact_name']??''); ?><br>
                    <?php echo htmlspecialchars($bk['emergency_contact']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if(!empty($bk['passengers'])): ?>
        <h3 style="font-size:14px;font-weight:700;margin:16px 0 8px">👥 Passengers (<?php echo count($bk['passengers']); ?>)</h3>
        <table style="margin-bottom:16px">
            <thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th></tr></thead>
            <tbody>
            <?php foreach($bk['passengers'] as $i=>$p): ?>
            <tr>
                <td><?php echo $i+1; ?></td>
                <td><?php echo htmlspecialchars($p['name']); ?></td>
                <td><?php echo $p['age']?$p['age']:'—'; ?></td>
                <td><?php echo htmlspecialchars($p['gender']??''); ?></td>
                <td><?php echo htmlspecialchars($p['id_proof_type']??''); ?></td>
                <td><?php echo htmlspecialchars($p['id_proof_number']??''); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <table style="width:260px;font-size:13px">
            <tr><td style="padding:3px 8px;color:var(--text2)">Total Amount</td><td style="padding:3px 8px;font-weight:700;text-align:right"><?php echo $cur.' '.number_format($bk['total_amount'],2); ?></td></tr>
            <tr><td style="padding:3px 8px;color:var(--success)">Amount Paid</td><td style="padding:3px 8px;font-weight:700;text-align:right;color:var(--success)"><?php echo $cur.' '.number_format($bk['amount_paid'],2); ?></td></tr>
            <?php if($bal>0): ?>
            <tr style="border-top:2px solid var(--border)"><td style="padding:5px 8px;font-size:15px;font-weight:800;color:var(--danger)">Balance Due</td><td style="padding:5px 8px;font-size:15px;font-weight:800;text-align:right;color:var(--danger)"><?php echo $cur.' '.number_format($bal,2); ?></td></tr>
            <?php endif; ?>
        </table>
        </div>

        <?php if($bal>0&&$qr_url): ?>
        <div style="text-align:center;margin:16px 0" class="no-print">
            <p style="font-size:12px;font-weight:600;margin-bottom:6px">Scan to Pay <?php echo $cur.' '.number_format($bal,2); ?></p>
            <img src="<?php echo htmlspecialchars($qr_url); ?>" width="150" alt="Payment QR">
        </div>
        <?php endif; ?>

        <?php $pn=getSetting('payment_note'); if($pn): ?>
        <div style="background:#fffbeb;border-left:4px solid var(--warning);padding:10px 14px;border-radius:6px;font-size:12px;margin:12px 0"><?php echo htmlspecialchars($pn); ?></div>
        <?php endif; ?>
    </div>

    <?php if(!empty($bk['payments'])): ?>
    <div class="card no-print" style="margin-top:16px">
        <div class="card-header"><div class="card-title">Payment History</div></div>
        <div class="card-body" style="padding:0"><table>
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Txn ID</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($bk['payments'] as $p): ?>
        <tr>
            <td><?php echo date('d-m-Y',strtotime($p['payment_date'])); ?></td>
            <td><?php echo $cur.' '.number_format($p['amount'],2); ?></td>
            <td><?php echo htmlspecialchars($p['payment_method']??''); ?></td>
            <td><?php echo htmlspecialchars($p['transaction_id']??''); ?></td>
            <td>
                <?php if(isAdmin()||isManager()): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete_yatra_payment">
                    <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                    <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
                    <button type="submit" class="action-btn delete-btn">Del</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="card no-print" style="margin-top:16px">
        <div class="card-header"><div class="card-title">Add Payment</div></div>
        <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_yatra_payment">
            <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
            <div class="row">
                <div class="form-group"><label>Date</label><input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group"><label>Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="amount" value="<?php echo max(0,$bal); ?>"></div>
                <div class="form-group"><label>Method</label><select name="payment_method"><?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>'; ?></select></div>
                <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
                <div class="form-group"><label>Notes</label><input type="text" name="notes"></div>
                <div class="form-group" style="display:flex;align-items:flex-end"><button type="submit" class="btn" style="background:var(--success);color:#fff">Add Payment</button></div>
            </div>
        </form>
        </div>
    </div>
    <script>
    function shareYatraBk(id) {
        fetch('?ajax=get_yatra_share&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){if(d.url){navigator.clipboard.writeText(d.url).then(function(){alert('Link copied!\n'+d.url);}).catch(function(){prompt('Copy:',d.url);});}});
    }
    function copyLink(url){navigator.clipboard.writeText(url).then(function(){alert('Link copied!\n'+url);}).catch(function(){prompt('Copy:',url);});}
    </script>
<?php }

function includeEditYatraBooking() {
    global $db;
    $id=intval($_GET['id']??0);
    $bk=getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Not found.</div>';return;}
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    $passJson=json_encode(array_map(function($p){return['n'=>$p['name'],'a'=>$p['age']??'','g'=>$p['gender']??'Male','ipt'=>$p['id_proof_type']??'Aadhaar','ipn'=>$p['id_proof_number']??''];},$bk['passengers']));
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">✏️ Edit Yatra Booking: <?php echo htmlspecialchars($bk['booking_ref']); ?></h2>
    <form method="POST">
    <input type="hidden" name="action" value="update_yatra_booking">
    <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
    <input type="hidden" name="passengers_json" id="passengers_json" value="<?php echo htmlspecialchars($passJson); ?>">
    <input type="hidden" name="total_passengers_count" id="total_passengers_count" value="<?php echo count($bk['passengers']); ?>">

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Booking Details</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Lead Passenger</label><input type="text" name="lead_passenger_name" value="<?php echo htmlspecialchars($bk['lead_passenger_name']); ?>"></div>
            <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($bk['phone']??''); ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($bk['email']??''); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($bk['address']??''); ?>"></div>
            <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($bk['emergency_contact_name']??''); ?>"></div>
            <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($bk['emergency_contact']??''); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Total Amount</label><input type="number" step="0.01" name="total_amount" value="<?php echo $bk['total_amount']; ?>"></div>
            <div class="form-group"><label>Booking Amount</label><input type="number" step="0.01" name="booking_amount" value="<?php echo $bk['booking_amount']??0; ?>"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"><?php echo htmlspecialchars($bk['notes']??''); ?></textarea></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <div class="card-title">Passengers</div>
            <button type="button" onclick="addPassenger()" class="btn" style="padding:6px 14px;font-size:12px;background:var(--success);color:#fff">+ Add</button>
        </div>
        <div class="card-body" style="padding:0">
        <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th><th></th></tr></thead>
        <tbody id="passengers_tbody"></tbody>
        </table></div>
        </div>
    </div>

    <div class="action-buttons">
        <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Save Changes</button>
        <a href="?page=view_yatra_booking&id=<?php echo $id; ?>" class="btn-secondary btn">Cancel</a>
    </div>
    </form>
    <script>
    var rawPass = <?php echo $passJson; ?>;
    var yatraPassengers = rawPass.map(function(p){return{n:p.n,a:p.a||'',g:p.g||'Male',ipt:p.ipt||'Aadhaar',ipn:p.ipn||''};});
    if(!yatraPassengers.length) yatraPassengers=[{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
    renderYatraPassengers();

    function addPassenger(){yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});renderYatraPassengers();}
    function removePassenger(i){if(yatraPassengers.length>1){yatraPassengers.splice(i,1);renderYatraPassengers();}}
    function renderYatraPassengers(){
        var tb=document.getElementById('passengers_tbody');if(!tb)return;
        tb.innerHTML='';
        yatraPassengers.forEach(function(p,i){
            var tr=document.createElement('tr');
            tr.innerHTML='<td>'+(i+1)+'</td>'
                +'<td><input style="width:130px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
                +'<td><input type="number" style="width:55px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.a||'')+'\x22 oninput="yatraPassengers['+i+'].a=this.value"></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value">'
                    +['Male','Female','Other'].map(function(g){return '<option'+(p.g===g?' selected':'')+'>'+g+'</option>';}).join('')
                +'</select></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value">'
                    +['Aadhaar','PAN','Voter ID','Passport','Other'].map(function(t){return '<option'+(p.ipt===t?' selected':'')+'>'+t+'</option>';}).join('')
                +'</select></td>'
                +'<td><input style="width:120px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
                +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
            tb.appendChild(tr);
        });
        document.getElementById('passengers_json').value=JSON.stringify(yatraPassengers.map(function(p){return{name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};}));
        document.getElementById('total_passengers_count').value=yatraPassengers.length;
    }
    function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||'')); return d.innerHTML;}
    </script>
<?php }
?>

<?php ob_end_flush(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Security-Policy" content="img-src * data:;">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(getSetting('company_name','Invoice System')); ?> — Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ═══════════════════════════════════════════════════════
       RESET & BASE
    ═══════════════════════════════════════════════════════ */
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
    :root {
        --primary: #4f46e5;
        --primary-dark: #3730a3;
        --primary-light: #eef2ff;
        --accent: #06b6d4;
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --purple: #8b5cf6;
        --bg: #f1f5f9;
        --surface: #ffffff;
        --surface2: #f8fafc;
        --border: #e2e8f0;
        --text: #1e293b;
        --text2: #64748b;
        --text3: #94a3b8;
        --nav-bg: #1e1b4b;
        --nav-text: #c7d2fe;
        --nav-hover: #3730a3;
        --nav-active: #4f46e5;
        --radius: 12px;
        --radius-sm: 8px;
        --shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
        --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
    }
    html { scroll-behavior: smooth; }
    body {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        line-height: 1.6;
        min-height: 100vh;
        font-size: 14px;
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* ═══════════════════════════════════════════════════════
       LAYOUT — top-nav horizontal bar (.app-nav)
    ═══════════════════════════════════════════════════════ */
    /* NAV ITEMS — inside .app-nav horizontal bar */
    .nav-item {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 14px;
        color: rgba(255,255,255,0.78);
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background .18s, color .18s;
        text-decoration: none;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .nav-item:hover { background: rgba(255,255,255,0.12); color: #fff; text-decoration: none; }
    .nav-item.active { background: var(--primary); color: #fff; box-shadow: 0 2px 8px rgba(79,70,229,0.5); }
    .nav-item-right { margin-left: auto; }
    .nav-icon { font-size: 15px; line-height: 1; flex-shrink: 0; }
    .nav-label { line-height: 1; }
    .nav-badge {
        background: var(--danger);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 5px;
        border-radius: 9px;
        min-width: 16px;
        text-align: center;
    }

    /* DROPDOWN GROUPS — absolute-positioned flyout */
    .nav-group { position: relative; flex-shrink: 0; display: inline-block; }
    .nav-group-trigger {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 14px;
        color: rgba(255,255,255,0.78);
        border-radius: 7px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background .18s, color .18s;
        user-select: none;
        white-space: nowrap;
    }
    .nav-group-trigger:hover { background: rgba(255,255,255,0.12); color: #fff; }
    .nav-group-trigger.active { background: rgba(79,70,229,0.45); color: #fff; }
    .nav-arrow { font-size: 10px; margin-left: 2px; transition: transform .2s; }
    .nav-group.open .nav-arrow { transform: rotate(180deg); }
    .nav-group.open .nav-group-trigger { background: rgba(255,255,255,0.1); }

    .nav-dropdown {
        display: none;
        position: fixed; /* JS sets top/left; fixed escapes all overflow contexts */
        min-width: 200px;
        background: #1e1b4b;
        border: 1px solid rgba(255,255,255,0.14);
        border-radius: 10px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        padding: 6px;
        z-index: 99999; /* above everything */
    }
    .nav-group.open .nav-dropdown { display: block; }
    .nav-sub {
        display: block;
        padding: 9px 13px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        border-radius: 6px;
        transition: background .15s, color .15s;
        white-space: nowrap;
        border-left: 3px solid transparent;
    }
    .nav-sub:hover { background: rgba(255,255,255,0.1); color: #fff; text-decoration: none; }
    .nav-sub.active { background: rgba(79,70,229,0.4); color: #fff; border-left-color: var(--primary); }
    .container { width: 100%; min-height: 100vh; display: flex; flex-direction: column; }

    /* Mobile hamburger */
    .mob-menu-btn {
        display: none;
        background: none;
        border: none;
        font-size: 22px;
        cursor: pointer;
        color: var(--text);
        padding: 4px;
    }
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 199;
    }

    /* ═══════════════════════════════════════════════════════
       CARDS & PANELS
    ═══════════════════════════════════════════════════════ */
    .card {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .card-title { font-size: 15px; font-weight: 700; color: var(--text); }
    .card-body { padding: 20px; }

    .main-content {
        background: var(--bg);
        padding: 24px;
        flex: 1;
        max-width: 1280px;
        margin: 0 auto;
        width: 100%;
    }
    .main-content > .card, .main-content > form, .main-content > div:not(.message) {
        background: var(--surface);
    }

    /* ═══════════════════════════════════════════════════════
       STATS GRID
    ═══════════════════════════════════════════════════════ */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .stat-card-v2 {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        border-left: 4px solid var(--primary);
        padding: 18px 16px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        gap: 4px;
        transition: transform .2s, box-shadow .2s;
        cursor: default;
    }
    .stat-card-v2:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
    .stat-card-v2.green  { border-left-color: var(--success); }
    .stat-card-v2.orange { border-left-color: var(--warning); }
    .stat-card-v2.red    { border-left-color: var(--danger); }
    .stat-card-v2.purple { border-left-color: var(--purple); }
    .stat-card-v2 .sc-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: var(--text3); }
    .stat-card-v2 .sc-value { font-size: 24px; font-weight: 800; color: var(--text); line-height: 1.1; }
    .stat-card-v2 .sc-link { font-size: 12px; color: var(--primary); text-decoration: none; margin-top: 2px; }
    .stat-card-v2 .sc-link:hover { text-decoration: underline; }

    /* ═══════════════════════════════════════════════════════
       FORMS & INPUTS
    ═══════════════════════════════════════════════════════ */
    .form-group { margin-bottom: 18px; }
    label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: var(--text2);
        font-size: 13px;
    }
    input[type="text"], input[type="tel"], input[type="number"],
    input[type="date"], input[type="password"], input[type="email"],
    input[type="url"], textarea, select {
        width: 100%;
        padding: 9px 12px;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: 16px; /* prevents iOS auto-zoom on focus */
        font-family: inherit;
        color: var(--text);
        background: var(--surface);
        transition: border-color .2s, box-shadow .2s;
        outline: none;
        -webkit-appearance: none;
        appearance: none;
    }
    select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; padding-right:30px; }
    input:focus, textarea:focus, select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(79,70,229,0.12);
    }
    @media (min-width: 769px) {
        input[type="text"], input[type="tel"], input[type="number"],
        input[type="date"], input[type="password"], input[type="email"],
        input[type="url"], textarea, select { font-size: 14px; }
    }
    input[readonly], input[disabled] { background: var(--surface2); color: var(--text3); }
    textarea { resize: vertical; min-height: 80px; }
    .row { display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap; }
    .row > .form-group { flex: 1; min-width: 160px; margin-bottom: 0; }
    small { font-size: 11.5px; color: var(--text3); display: block; margin-top: 4px; }

    /* ═══════════════════════════════════════════════════════
       BUTTONS
    ═══════════════════════════════════════════════════════ */
    button, .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 9px 18px;
        border-radius: var(--radius-sm);
        border: none;
        font-size: 13.5px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: all .18s;
        text-decoration: none;
        white-space: nowrap;
        background: var(--primary);
        color: #fff;
    }
    button:hover, .btn:hover { opacity: .88; transform: translateY(-1px); box-shadow: var(--shadow-md); text-decoration: none; }
    button:active { transform: translateY(0); }
    .btn-secondary { background: var(--surface2); color: var(--text2); border: 1.5px solid var(--border); }
    .btn-secondary:hover { background: var(--border); color: var(--text); }
    .btn-danger, .btn-danger:hover { background: var(--danger); color: #fff; }
    .btn-warning, .btn-warning:hover { background: var(--warning); color: #fff; }
    .btn-success, .btn-success:hover { background: var(--success); color: #fff; }
    .btn-print { background: var(--success); color: #fff; }
    .btn-whatsapp { background: #25D366; color: #fff; }
    .btn-purple { background: var(--purple); color: #fff; }
    .action-buttons { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; align-items: center; }
    .action-btn {
        padding: 5px 12px;
        font-size: 12px;
        border-radius: 6px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .15s;
        gap: 4px;
    }
    .action-btn:hover { opacity: .85; transform: translateY(-1px); text-decoration: none; }
    .view-btn { background: #dbeafe; color: #1d4ed8; }
    .edit-btn { background: #fef3c7; color: #d97706; }
    .delete-btn { background: #fee2e2; color: #dc2626; }
    .actions-cell { display: flex; gap: 5px; flex-wrap: wrap; align-items: center; }

    /* ═══════════════════════════════════════════════════════
       TABLES
    ═══════════════════════════════════════════════════════ */
    table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    th {
        background: var(--surface2);
        font-weight: 700;
        color: var(--text2);
        border: 1px solid var(--border);
        padding: 10px 12px;
        text-align: left;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .4px;
        white-space: nowrap;
    }
    td { border: 1px solid var(--border); padding: 10px 12px; color: var(--text); vertical-align: middle; }
    tr:hover > td { background: var(--surface2); }
    tfoot td { font-weight: 700; background: var(--surface2); }

    /* ═══════════════════════════════════════════════════════
       MESSAGES / ALERTS
    ═══════════════════════════════════════════════════════ */
    .message {
        padding: 12px 16px;
        margin-bottom: 16px;
        border-radius: var(--radius-sm);
        font-size: 13.5px;
        border-left: 4px solid;
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }
    .success { background: #ecfdf5; color: #065f46; border-color: var(--success); }
    .error   { background: #fef2f2; color: #991b1b; border-color: var(--danger); }
    .info    { background: #eff6ff; color: #1e40af; border-color: var(--accent); }
    .warning { background: #fffbeb; color: #92400e; border-color: var(--warning); }

    /* ═══════════════════════════════════════════════════════
       BADGES & PILLS
    ═══════════════════════════════════════════════════════ */
    .user-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .3px;
    }
    .user-badge.admin { background: #fce7f3; color: #be185d; }
    .user-badge.manager { background: #fef3c7; color: #d97706; }
    .user-badge.accountant { background: #dcfce7; color: #15803d; }
    .payment-badge { padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .payment-badge.unpaid { background: #fee2e2; color: #dc2626; }
    .payment-badge.partially_paid { background: #fef3c7; color: #d97706; }
    .payment-badge.paid { background: #dcfce7; color: #15803d; }
    .payment-badge.settled { background: #dbeafe; color: #1d4ed8; }

    /* ═══════════════════════════════════════════════════════
       DASHBOARD SPECIFICS
    ═══════════════════════════════════════════════════════ */
    .dash-welcome {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .dash-welcome h2 { margin: 0; font-size: 22px; font-weight: 800; color: var(--text); }
    .dash-role-badge { padding: 5px 14px; border-radius: 20px; font-size: 11.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; }
    .role-admin     { background: #fce7f3; color: #be185d; border: 1px solid #fbcfe8; }
    .role-manager   { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .role-accountant{ background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .dash-section-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid var(--border);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dash-quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 18px;
        border-radius: var(--radius-sm);
        font-size: 13.5px;
        font-weight: 600;
        text-decoration: none;
        transition: all .18s;
        color: #fff;
        box-shadow: var(--shadow);
    }
    .quick-btn:hover { opacity: .88; transform: translateY(-2px); box-shadow: var(--shadow-md); text-decoration: none; }
    .qb-blue   { background: linear-gradient(135deg, var(--primary), #6366f1); }
    .qb-green  { background: linear-gradient(135deg, var(--success), #34d399); }
    .qb-orange { background: linear-gradient(135deg, var(--warning), #fbbf24); }
    .qb-purple { background: linear-gradient(135deg, var(--purple), #a78bfa); }

    /* ═══════════════════════════════════════════════════════
       HEADER (legacy compat)
    ═══════════════════════════════════════════════════════ */
    /* Top info bar above nav (company name + logout) */
    .header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 16px;
        background: var(--primary-dark);
        color: rgba(255,255,255,0.9);
        font-size: 12px;
        gap: 10px;
    }
    .header h1 { display: none; } /* hide big h1, company name shown separately */
    .header .user-info { display: flex; align-items: center; gap: 10px; margin-left: auto; }
    .logout-btn {
        background: rgba(255,255,255,0.15);
        color: #fff;
        padding: 4px 12px;
        border-radius: 14px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s;
        border: 1px solid rgba(255,255,255,0.2);
    }
    .logout-btn:hover { background: rgba(255,255,255,0.28); text-decoration: none; }
    .logout-btn { background: var(--danger); color: white; border: none; padding: 7px 14px; border-radius: var(--radius-sm); cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .logout-btn:hover { opacity: .9; text-decoration: none; }

    /* ═══════════════════════════════════════════════════════
       SEARCH BOX
    ═══════════════════════════════════════════════════════ */
    .search-box { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); padding: 16px 20px; margin-bottom: 16px; box-shadow: var(--shadow); }
    .search-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .search-form .form-group { margin-bottom: 0; flex: 1; min-width: 200px; }

    /* ═══════════════════════════════════════════════════════
       LOGIN PAGE
    ═══════════════════════════════════════════════════════ */
    .login-container {
        position: fixed;
        inset: 0;
        width: 100vw;
        height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4f46e5 100%);
        padding: 20px;
        z-index: 9998;
        overflow-y: auto;
    }
    .login-box {
        background: var(--surface);
        border-radius: 20px;
        padding: 40px;
        width: 100%;
        max-width: 400px;
        box-shadow: var(--shadow-xl);
        position: relative;
        overflow: hidden;
    }
    .login-box::before {
        content: '';
        position: absolute;
        top: -40px; right: -40px;
        width: 120px; height: 120px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        border-radius: 50%;
        opacity: .1;
    }
    .login-logo {
        width: 56px; height: 56px;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 26px;
        margin: 0 auto 18px;
        box-shadow: 0 8px 20px rgba(79,70,229,0.3);
    }
    .login-box h2 { text-align: center; margin-bottom: 8px; color: var(--text); font-size: 22px; font-weight: 800; }
    .login-sub { text-align: center; color: var(--text3); font-size: 13px; margin-bottom: 28px; }

    /* ═══════════════════════════════════════════════════════
       MISC COMPONENTS
    ═══════════════════════════════════════════════════════ */
    .invoice-preview { background: white; padding: 24px; border-radius: var(--radius); box-shadow: var(--shadow); margin: 16px 0; border: 1px solid var(--border); }
    .payment-section { background: var(--surface2); padding: 16px; border-radius: var(--radius-sm); margin: 16px 0; border-left: 4px solid var(--primary); }
    .payment-form { background: var(--surface); padding: 16px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-top: 16px; }
    .send-invoice-section { background: var(--surface2); padding: 16px; border-radius: var(--radius-sm); margin: 16px 0; }
    .status-form { background: #eff6ff; padding: 14px; border-radius: var(--radius-sm); margin: 14px 0; }

    .invoice-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
    .company-info { flex: 2; }
    .invoice-meta { text-align: right; flex: 1; font-size: 14px; }
    .customer-info { background: var(--surface2); padding: 14px; border-radius: var(--radius-sm); margin-bottom: 16px; border-left: 4px solid var(--primary); font-size: 13.5px; }
    .logo-preview { max-width: 100px; max-height: 100px; border: 1px solid var(--border); padding: 4px; }
    .qr-preview { max-width: 100px; max-height: 100px; border: 1px solid var(--border); padding: 4px; cursor: pointer; }
    .payment-note { background: #fffbeb; border-left: 4px solid var(--warning); padding: 12px 16px; margin: 14px 0; border-radius: var(--radius-sm); font-size: 13px; }
    .currency { font-weight: 700; color: var(--text); }
    .total-amount { font-size: 18px; font-weight: 800; color: var(--text); margin-top: 8px; }
    .rounded-note { font-size: 11.5px; color: var(--text3); margin-top: 4px; font-style: italic; }
    .role-select { padding: 8px 12px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); font-size: 13.5px; width: 100%; }
    .amount-display { font-size: 18px; font-weight: 700; margin: 8px 0; }
    .stat-card { background: var(--surface); padding: 20px; border-radius: var(--radius-sm); box-shadow: var(--shadow); text-align: center; border-top: 4px solid var(--primary); }
    .stat-card h3 { color: var(--text3); margin-bottom: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
    .stat-card .value { font-size: 28px; font-weight: 800; color: var(--text); }
    .stats-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }

    /* ═══════════════════════════════════════════════════════
       NAV TABS (legacy compat - hidden, replaced by sidebar)
    ═══════════════════════════════════════════════════════ */
    .nav-tabs { display: none; }

    /* ═══════════════════════════════════════════════════════
       TOP NAV BAR (.app-nav) — horizontal sticky navigation
    ═══════════════════════════════════════════════════════ */
    .app-nav {
        background: #1e1b4b;
        position: sticky;
        top: 0;
        z-index: 300;
        box-shadow: 0 2px 12px rgba(0,0,0,0.25);
        width: 100%;
        overflow: visible; /* critical: dropdowns must not be clipped */
    }
    .nav-inner {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        /* overflow must be visible so dropdowns are not clipped */
        overflow: visible;
        gap: 2px;
        padding: 4px 8px;
        max-width: 1280px;
        margin: 0 auto;
        min-height: 48px;
    }
    /* Horizontal scroll wrapper - overflow visible needed for fixed dropdowns */
    .app-nav { overflow: visible; }

    /* ═══════════════════════════════════════════════════════
       REALTIME INDICATOR
    ═══════════════════════════════════════════════════════ */
    .realtime-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--success);
        display: inline-block;
        animation: pulse-green 2s infinite;
    }
    @keyframes pulse-green {
        0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.4); }
        50% { box-shadow: 0 0 0 5px rgba(16,185,129,0); }
    }

    /* ═══════════════════════════════════════════════════════
       TOAST NOTIFICATION
    ═══════════════════════════════════════════════════════ */
    .toast-container {
        position: fixed;
        bottom: 90px;
        right: 20px;
        z-index: 50000;
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-width: 320px;
    }
    .toast {
        background: var(--text);
        color: #fff;
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 13.5px;
        font-weight: 500;
        box-shadow: var(--shadow-lg);
        display: flex;
        align-items: center;
        gap: 10px;
        animation: slideInRight .3s ease;
    }
    .toast.success { background: var(--success); }
    .toast.error   { background: var(--danger); }
    .toast.info    { background: var(--primary); }
    @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    /* ═══════════════════════════════════════════════════════
       CHAT WIDGET — Facebook Messenger style
    ═══════════════════════════════════════════════════════ */
    #chatWidget {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 10px;
    }
    .chat-fab {
        width: 52px; height: 52px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0866ff, #0099ff);
        color: #fff;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        box-shadow: 0 4px 20px rgba(8,102,255,0.4);
        transition: transform .2s;
        position: relative;
    }
    .chat-fab:hover { transform: scale(1.08); }
    .chat-fab-badge {
        position: absolute;
        top: -3px; right: -3px;
        background: var(--danger);
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        min-width: 18px; height: 18px;
        border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid #fff;
        padding: 0 3px;
    }
    .chat-panel {
        width: 320px;
        max-height: 480px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 8px 40px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: chatSlideUp .25s ease;
    }
    @keyframes chatSlideUp { from { opacity:0; transform:translateY(20px) scale(.95); } to { opacity:1; transform:translateY(0) scale(1); } }
    .chat-panel.minimized { max-height: 54px; }
    .chat-panel-header {
        background: linear-gradient(135deg, #0866ff, #0099ff);
        color: #fff;
        padding: 0 14px;
        height: 54px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        flex-shrink: 0;
    }
    .chat-panel-header-info { display: flex; align-items: center; gap: 10px; }
    .chat-panel-avatar {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: rgba(255,255,255,0.3);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        font-weight: 700;
        flex-shrink: 0;
        border: 2px solid rgba(255,255,255,0.5);
    }
    .chat-header-name { font-weight: 700; font-size: 13.5px; }
    .chat-header-status { font-size: 11px; opacity: .85; }
    .chat-header-btns { display: flex; gap: 6px; }
    .chat-hbtn {
        background: rgba(255,255,255,0.2);
        border: none;
        border-radius: 50%;
        width: 28px; height: 28px;
        color: #fff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        transition: background .15s;
    }
    .chat-hbtn:hover { background: rgba(255,255,255,0.35); }

    /* User list view */
    .chat-users-list {
        flex: 1;
        overflow-y: auto;
        padding: 8px;
    }
    .chat-user-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 10px;
        border-radius: 10px;
        cursor: pointer;
        transition: background .15s;
    }
    .chat-user-item:hover { background: #f0f2f5; }
    .chat-user-avatar {
        width: 38px; height: 38px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary), var(--accent));
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700;
        font-size: 14px;
        flex-shrink: 0;
        position: relative;
    }
    .chat-online-dot {
        position: absolute;
        bottom: 0; right: 0;
        width: 10px; height: 10px;
        border-radius: 50%;
        border: 2px solid #fff;
    }
    .chat-user-info { flex: 1; min-width: 0; }
    .chat-user-name { font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .chat-user-role { font-size: 11px; color: var(--text3); }
    .chat-unread-pill {
        background: #0866ff;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 10px;
        min-width: 18px;
        text-align: center;
    }

    /* Message view */
    .chat-messages-area {
        flex: 1;
        overflow-y: auto;
        padding: 12px;
        background: #f0f2f5;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .chat-msg-row { display: flex; align-items: flex-end; gap: 6px; }
    .chat-msg-row.mine { flex-direction: row-reverse; }
    .chat-bubble {
        max-width: 75%;
        padding: 8px 12px;
        border-radius: 18px;
        font-size: 13.5px;
        line-height: 1.4;
        word-break: break-word;
    }
    .chat-bubble.theirs {
        background: #fff;
        color: var(--text);
        border-bottom-left-radius: 4px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .chat-bubble.mine {
        background: #0866ff;
        color: #fff;
        border-bottom-right-radius: 4px;
    }
    .chat-time { font-size: 10px; color: var(--text3); margin: 0 4px 2px; }
    .chat-date-sep { text-align: center; font-size: 11px; color: var(--text3); margin: 8px 0; }

    /* Chat input */
    .chat-input-area {
        padding: 10px;
        border-top: 1px solid var(--border);
        background: #fff;
        display: flex;
        gap: 8px;
        align-items: flex-end;
    }
    .chat-input {
        flex: 1;
        border: 1.5px solid var(--border);
        border-radius: 20px;
        padding: 8px 14px;
        font-size: 13.5px;
        font-family: inherit;
        outline: none;
        resize: none;
        max-height: 80px;
        overflow-y: auto;
        line-height: 1.4;
        color: var(--text);
    }
    .chat-input:focus { border-color: #0866ff; }
    .chat-send-btn {
        width: 36px; height: 36px;
        border-radius: 50%;
        background: #0866ff;
        border: none;
        color: #fff;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        font-size: 16px;
        flex-shrink: 0;
        transition: background .15s;
    }
    .chat-send-btn:hover { background: #0052cc; }
    .chat-typing { font-size: 11px; color: var(--text3); padding: 4px 12px; font-style: italic; }
    .chat-empty { text-align: center; padding: 30px 16px; color: var(--text3); font-size: 13px; }

    /* ═══════════════════════════════════════════════════════
       PRINT STYLES — only invoice prints, not app chrome
    ═══════════════════════════════════════════════════════ */
    @media print {
        .header, .app-nav, #signatureSystem, .send-invoice-section,
        .status-form, .payment-form, .action-buttons, .message,
        .no-print, #chatWidget, #chatUsersPanel, #chatConvPanel,
        .toast-container, #passcodeModal { display: none !important; }
        .main-content { box-shadow: none !important; border: none !important; padding: 0 !important; }
        body * { visibility: hidden; }
        .invoice-preview, .invoice-preview * { visibility: visible; }
        .invoice-preview {
            position: absolute; left: 0; top: 0;
            width: 100%; margin: 0; padding: 16px;
            box-shadow: none; border: none;
        }
        table { page-break-inside: auto; font-size: 12px; }
        th, td { padding: 7px !important; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        img[src*="qr"] { max-width: 100px; display: inline-block !important; }
        img[src*="logo"] { max-width: 120px; display: inline-block !important; }
        #affixedSealImg, #affixedSigImg, .affixed-seal-abs { visibility: visible !important; display: block !important; }
    }

    /* ═══════════════════════════════════════════════════════
       RESPONSIVE
    ═══════════════════════════════════════════════════════ */
    @media (max-width: 768px) {
        .nav-inner { padding: 4px; gap: 1px; }
        .nav-item, .nav-group-trigger { padding: 8px 10px; font-size: 12px; }
        .nav-item .nav-label { display: none; }  /* icons only on mobile */
        .nav-group-trigger .nav-label { display: none; }
        .nav-item-right .nav-label { display: none; }
        .row { flex-direction: column; gap: 8px; }
        .row > .form-group { min-width: 100%; }
        .main-content { padding: 14px; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .dash-quick-actions { gap: 8px; }
        .quick-btn { padding: 8px 10px; font-size: 12.5px; }
        .invoice-header { flex-direction: column; }
        .invoice-meta { text-align: left; margin-top: 12px; }
        #chatUsersPanel, #chatConvPanel { width: 100vw; right: 0; bottom: 0; border-radius: 16px 16px 0 0; max-height: 70vh; bottom: 70px; }
        #chatWidget { right: 16px; bottom: 16px; }
        table { font-size: 12px; }
        th, td { padding: 6px 8px; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-card-v2 .sc-value { font-size: 20px; }
        .main-content { padding: 10px; }
        .nav-item, .nav-group-trigger { padding: 7px 8px; font-size: 11px; }
    }
.nav-tab { 
    padding: 12px 15px; 
    cursor: pointer; 
    background: #ecf0f1; 
    border: none; 
    text-align: center; 
    font-weight: bold; 
    color: #555; 
    text-decoration: none; 
    display: inline-block; 
    transition: all 0.3s; 
    border-right: 1px solid #ddd;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    max-width: none;
    word-wrap: break-word;
    line-height: 1.2;
}

/* [nav styles defined in main style block above] */

/* ── DASHBOARD IMPROVEMENTS ── */
.dash-welcome {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.dash-welcome h2 { margin: 0; font-size: 22px; color: #2c3e50; }
.dash-role-badge {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
}
.role-admin    { background: #fdecea; color: #c0392b; border: 1px solid #f5b7b1; }
.role-manager  { background: #fef9e7; color: #d4a017; border: 1px solid #f9e79f; }
.role-accountant { background: #eafaf1; color: #1e8449; border: 1px solid #a9dfbf; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card-v2 {
    background: white;
    border-radius: 10px;
    padding: 20px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    border-left: 4px solid #3498db;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: box-shadow 0.2s, transform 0.2s;
}
.stat-card-v2:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.12); transform: translateY(-2px); }
.stat-card-v2.green  { border-color: #27ae60; }
.stat-card-v2.orange { border-color: #e67e22; }
.stat-card-v2.red    { border-color: #e74c3c; }
.stat-card-v2.purple { border-color: #8e44ad; }
.stat-card-v2 .sc-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #95a5a6; }
.stat-card-v2 .sc-value { font-size: 26px; font-weight: 700; color: #2c3e50; line-height: 1.1; }
.stat-card-v2 .sc-link { font-size: 11.5px; color: #3498db; text-decoration: none; margin-top: 2px; }
.stat-card-v2 .sc-link:hover { text-decoration: underline; }

.dash-quick-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}
.quick-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 18px;
    border-radius: 7px;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: opacity 0.2s, transform 0.15s;
    color: #fff;
}
.quick-btn:hover { opacity: .88; transform: translateY(-1px); }
.qb-blue   { background: #3498db; }
.qb-green  { background: #27ae60; }
.qb-orange { background: #e67e22; }
.qb-purple { background: #8e44ad; }

.dash-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid #ecf0f1;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Mobile nav */
@media (max-width: 768px) {
    .nav-inner { gap: 1px; padding: 3px; }
    .nav-item, .nav-group-trigger { padding: 9px 10px; font-size: 12.5px; }

    .nav-item-right { margin-left: 0; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

/* For mobile screens */

    </style>
    <script>
        var itemCounter = 1;
        var purchaseCounter = 1;
        
        function addItem(containerId) {
    var container = document.getElementById(containerId);
    var index = container.querySelectorAll('.item-row').length;
    
    var html = `
        <div class="item-row">
            <div class="row">
                <input type="hidden" name="items[${index}][id]" value="0">
                <div class="form-group">
                    <label>S.No.</label>
                    <input type="number" name="items[${index}][s_no]" value="${index + 1}" min="1" readonly>
                </div>
                <div class="form-group">
                    <label>Particulars *</label>
                    <input type="text" name="items[${index}][particulars]" placeholder="Enter particulars" required>
                </div>
                <div class="form-group">
                    <label>Amount (₹)</label>
                    <input type="number" name="items[${index}][amount]" step="0.01" value="0" min="0" required>
                </div>
                <div class="form-group">
                    <label>Service Charge (₹)</label>
                    <input type="number" name="items[${index}][service_charge]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Discount (₹)</label>
                    <input type="number" name="items[${index}][discount]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Remark</label>
                    <input type="text" name="items[${index}][remark]" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removeItem(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
    itemCounter++;
}

function addPurchase(containerId) {
    var container = document.getElementById(containerId);
    var index = container.querySelectorAll('.purchase-row').length;
    
    var html = `
        <div class="purchase-row">
            <div class="row">
                <input type="hidden" name="purchases[${index}][id]" value="0">
                <div class="form-group">
                    <label>S.No.</label>
                    <input type="number" name="purchases[${index}][s_no]" value="${index + 1}" min="1" readonly>
                </div>
                <div class="form-group">
                    <label>Particulars</label>
                    <input type="text" name="purchases[${index}][particulars]" placeholder="Enter particulars">
                </div>
                <div class="form-group">
                    <label>Qty</label>
                    <input type="number" name="purchases[${index}][qty]" step="0.01" value="1" min="0">
                </div>
                <div class="form-group">
                    <label>Rate (₹)</label>
                    <input type="number" name="purchases[${index}][rate]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Amount Received (₹)</label>
                    <input type="number" name="purchases[${index}][amount_received]" step="0.01" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removePurchase(this)" class="btn-danger" style="padding: 8px 12px; font-size: 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
    purchaseCounter++;
}
        
        function removeItem(button) {
            var itemRow = button.closest('.item-row');
            if (itemRow && document.querySelectorAll('.item-row').length > 1) {
                itemRow.remove();
                renumberItems();
            }
        }
        
        function removePurchase(button) {
            var purchaseRow = button.closest('.purchase-row');
            if (purchaseRow && document.querySelectorAll('.purchase-row').length > 1) {
                purchaseRow.remove();
                renumberPurchases();
            }
        }
        
        function renumberItems() {
            var items = document.querySelectorAll('.item-row');
            items.forEach(function(item, index) {
                item.querySelector('input[name*="[s_no]"]').value = index + 1;
                var name = item.querySelector('input[name*="[particulars]"]').name;
                var baseName = name.match(/(items\[\d+\])\[particulars\]/)[1];
                item.querySelectorAll('input').forEach(function(input) {
                    var oldName = input.name;
                    var field = oldName.match(/\[(\w+)\]/)[1];
                    input.name = `items[${index}][${field}]`;
                });
            });
        }
        
        function renumberPurchases() {
            var purchases = document.querySelectorAll('.purchase-row');
            purchases.forEach(function(purchase, index) {
                purchase.querySelector('input[name*="[s_no]"]').value = index + 1;
                var name = purchase.querySelector('input[name*="[particulars]"]').name;
                var baseName = name.match(/(purchases\[\d+\])\[particulars\]/)[1];
                purchase.querySelectorAll('input').forEach(function(input) {
                    var oldName = input.name;
                    var field = oldName.match(/\[(\w+)\]/)[1];
                    input.name = `purchases[${index}][${field}]`;
                });
            });
        }
        
        function confirmDelete(invoiceId, invoiceNumber) {
            if (confirm('Are you sure you want to delete invoice: ' + invoiceNumber + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_invoice';
                form.appendChild(actionInput);
                
                var invoiceInput = document.createElement('input');
                invoiceInput.type = 'hidden';
                invoiceInput.name = 'invoice_id';
                invoiceInput.value = invoiceId;
                form.appendChild(invoiceInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function requestDelete(invoiceId, invoiceNumber) {
            document.getElementById('deleteRequestModal').style.display = 'flex';
            document.getElementById('deleteRequestText').innerHTML = 'Request deletion for invoice: <strong>' + invoiceNumber + '</strong>';
            document.getElementById('requestInvoiceId').value = invoiceId;
        }
        
        function approveDelete(requestId, invoiceNumber) {
            if (confirm('Approve deletion request for invoice: ' + invoiceNumber + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve_delete_request';
                form.appendChild(actionInput);
                
                var requestInput = document.createElement('input');
                requestInput.type = 'hidden';
                requestInput.name = 'request_id';
                requestInput.value = requestId;
                form.appendChild(requestInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function rejectDelete(requestId, invoiceNumber) {
            document.getElementById('rejectModal').style.display = 'flex';
            document.getElementById('rejectText').innerHTML = 'Reject deletion request for invoice: <strong>' + invoiceNumber + '</strong>';
            document.getElementById('rejectRequestId').value = requestId;
        }
        
        function confirmDeleteUser(userId, username) {
            if (confirm('Are you sure you want to delete user: ' + username + '?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
                form.appendChild(actionInput);
                
                var userInput = document.createElement('input');
                userInput.type = 'hidden';
                userInput.name = 'user_id';
                userInput.value = userId;
                form.appendChild(userInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function sendEmail(invoiceId, email) {
            var modalHtml = `
                <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                        <h3>Send Invoice via Email</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_email">
                            <input type="hidden" name="invoice_id" value="${invoiceId}">
                            
                            <div class="form-group">
                                <label>To Email:</label>
                                <input type="email" name="email" value="${email}" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Subject:</label>
                                <input type="text" name="subject" value="Invoice from <?php echo getSetting('company_name', 'D K ASSOCIATES'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Message:</label>
                                <textarea name="message" rows="5"></textarea>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit">Send Email</button>
                                <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            var div = document.createElement('div');
            div.innerHTML = modalHtml;
            document.body.appendChild(div.firstElementChild);
        }
        
        function sendWhatsApp(invoiceId, phone) {
            var modalHtml = `
                <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                        <h3>Send Invoice via WhatsApp</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="send_whatsapp">
                            <input type="hidden" name="invoice_id" value="${invoiceId}">
                            
                            <div class="form-group">
                                <label>Phone Number:</label>
                                <input type="tel" name="phone" value="${phone}" required>
                                <small>Include country code (e.g., 91 for India)</small>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" style="background: #25D366;">Send WhatsApp</button>
                                <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            var div = document.createElement('div');
            div.innerHTML = modalHtml;
            document.body.appendChild(div.firstElementChild);
        }
        
        function printInvoice() {
            window.print();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['success'])): ?>
            setTimeout(function() {
                var message = document.querySelector('.message.success');
                if (message) message.style.display = 'none';
            }, 5000);
            <?php endif; ?>
        });
    </script>
</head>
<body>
    <div class="container">
        <?php if (isLoggedIn()): ?>
        <div class="header">
            <h1>Invoice System</h1>
            <div class="user-info">
                <span style="font-size:13px;color:#888;"><?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES')); ?></span>
                <a href="?logout" class="logout-btn">⏏ Logout</a>
            </div>
        </div>
        
<nav class="app-nav">
    <div class="nav-inner">

        <?php /* ── PRIMARY: always visible ── */ ?>
        <a href="?page=dashboard" class="nav-item <?php echo $page==='dashboard'?'active':''; ?>">
            <span class="nav-icon">📊</span><span class="nav-label">Dashboard</span>
        </a>

        <a href="?page=create_invoice" class="nav-item <?php echo $page==='create_invoice'?'active':''; ?>">
            <span class="nav-icon">➕</span><span class="nav-label">New Invoice</span>
        </a>

        <a href="?page=invoices" class="nav-item <?php echo in_array($page,['invoices','view_invoice','edit_invoice'])?'active':''; ?>">
            <span class="nav-icon">📋</span><span class="nav-label">Invoices</span>
        </a>

        <?php /* ── BOOKINGS group ── */ ?>
        <div class="nav-group <?php echo in_array($page,['bookings','create_booking','view_booking','edit_booking'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['bookings','create_booking','view_booking','edit_booking'])?'active':''; ?>">
                <span class="nav-icon">📅</span><span class="nav-label">Bookings</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=bookings"       class="nav-sub <?php echo $page==='bookings'?'active':''; ?>">📋 All Bookings</a>
                <a href="?page=create_booking" class="nav-sub <?php echo $page==='create_booking'?'active':''; ?>">➕ New Booking</a>
            </div>
        </div>

        <?php /* ── ACADEMY group: users with academy access ── */ ?>
        <?php if (hasAcademyAccess()): ?>
        <?php $acRem = count(getDueReminders()); ?>
        <div class="nav-group <?php echo in_array($page,['academy','academy_courses','create_enrollment','view_enrollment','edit_enrollment','academy_reminders'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['academy','academy_courses','create_enrollment','view_enrollment','edit_enrollment','academy_reminders'])?'active':''; ?>">
                <span class="nav-icon">🎓</span><span class="nav-label">Academy</span>
                <?php if ($acRem > 0): ?><span class="nav-badge" style="background:#e74c3c;"><?php echo $acRem; ?></span><?php endif; ?>
                <span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=academy"           class="nav-sub <?php echo $page==='academy'?'active':''; ?>">📋 Enrollments</a>
                <a href="?page=create_enrollment" class="nav-sub <?php echo $page==='create_enrollment'?'active':''; ?>">➕ New Enrollment</a>
                <?php if ($acRem > 0): ?>
                <a href="?page=academy_reminders" class="nav-sub <?php echo $page==='academy_reminders'?'active':''; ?>" style="color:#e74c3c;">🔔 Reminders (<?php echo $acRem; ?>)</a>
                <?php else: ?>
                <a href="?page=academy_reminders" class="nav-sub <?php echo $page==='academy_reminders'?'active':''; ?>">🔔 Reminders</a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                <a href="?page=academy_courses"   class="nav-sub <?php echo $page==='academy_courses'?'active':''; ?>">📚 Manage Courses</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── YATRA group ── */ ?>
        <?php if (!isAccountant()): ?>
        <div class="nav-group <?php echo in_array($page,['yatra','yatra_bookings','create_yatra_booking','view_yatra_booking','edit_yatra_booking']) ? 'open' : ''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['yatra','yatra_bookings','create_yatra_booking','view_yatra_booking','edit_yatra_booking']) ? 'active' : ''; ?>">
                <span class="nav-icon">🚌</span><span class="nav-label">Yatra</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=yatra" class="nav-sub <?php echo $page==='yatra'?'active':''; ?>">🕌 Yatras</a>
                <a href="?page=yatra_bookings" class="nav-sub <?php echo $page==='yatra_bookings'?'active':''; ?>">📋 All Bookings</a>
                <a href="?page=create_yatra_booking" class="nav-sub <?php echo $page==='create_yatra_booking'?'active':''; ?>">➕ New Booking</a>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── FINANCE group: manager + admin only ── */ ?>
        <?php if (!isAccountant()): ?>
        <div class="nav-group <?php echo in_array($page,['payment_link','export','expenses'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['payment_link','export','expenses'])?'active':''; ?>">
                <span class="nav-icon">💰</span><span class="nav-label">Finance</span><span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=payment_link" class="nav-sub <?php echo $page==='payment_link'?'active':''; ?>">🔗 Payment Link</a>
                <a href="?page=expenses" class="nav-sub <?php echo $page==='expenses'?'active':''; ?>">🧾 Expenses</a>
                <?php if (isAdmin()): ?>
                <a href="?page=export" class="nav-sub <?php echo $page==='export'?'active':''; ?>">📤 DB Export/Import</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── ADMIN group: admin only ── */ ?>
        <?php if (isAdmin()): ?>
        <div class="nav-group <?php echo in_array($page,['pending_deletions','invoice_templates','users','settings'])?'open':''; ?>">
            <div class="nav-group-trigger <?php echo in_array($page,['pending_deletions','invoice_templates','users','settings'])?'active':''; ?>">
                <span class="nav-icon">⚙️</span><span class="nav-label">Admin</span>
                <?php if (($stats_pd = getStatistics()) && $stats_pd['pending_deletions'] > 0): ?>
                <span class="nav-badge"><?php echo $stats_pd['pending_deletions']; ?></span>
                <?php endif; ?>
                <span class="nav-arrow">▾</span>
            </div>
            <div class="nav-dropdown">
                <a href="?page=pending_deletions" class="nav-sub <?php echo $page==='pending_deletions'?'active':''; ?>">⏳ Pending Deletions</a>
                <a href="?page=invoice_templates" class="nav-sub <?php echo $page==='invoice_templates'?'active':''; ?>">🎨 Templates</a>
                <a href="?page=users"             class="nav-sub <?php echo $page==='users'?'active':''; ?>">👥 Users</a>
                <a href="?page=settings"          class="nav-sub <?php echo $page==='settings'?'active':''; ?>">⚙️ Settings</a>
            </div>
        </div>
        <?php endif; ?>

        <?php /* ── PROFILE: always visible ── */ ?>
        <a href="?page=profile" class="nav-item nav-item-right <?php echo $page==='profile'?'active':''; ?>">
            <span class="nav-icon">👤</span><span class="nav-label"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
        </a>

    </div>
</nav>
<script>
(function() {
    // Use fixed positioning for dropdowns to escape any overflow clipping context
    function positionDropdown(group) {
        var trigger = group.querySelector('.nav-group-trigger');
        var dropdown = group.querySelector('.nav-dropdown');
        if (!trigger || !dropdown) return;
        var r = trigger.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.top = (r.bottom + 4) + 'px';
        dropdown.style.left = r.left + 'px';
        dropdown.style.right = 'auto';
        // Prevent going off-screen right
        var dw = dropdown.offsetWidth || 210;
        if (r.left + dw > window.innerWidth - 8) {
            dropdown.style.left = 'auto';
            dropdown.style.right = (window.innerWidth - r.right) + 'px';
        }
    }

    document.querySelectorAll('.nav-group-trigger').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var group = trigger.closest('.nav-group');
            var isOpen = group.classList.contains('open');
            // Close all others
            document.querySelectorAll('.nav-group').forEach(function(g) {
                g.classList.remove('open');
            });
            if (!isOpen) {
                group.classList.add('open');
                positionDropdown(group);
            }
        });
    });

    // Click outside closes all non-active groups
    document.addEventListener('click', function() {
        document.querySelectorAll('.nav-group').forEach(function(g) {
            if (!g.classList.contains('page-active-group')) {
                g.classList.remove('open');
            }
        });
    });

    // Mark groups containing the active page — keep them open
    document.querySelectorAll('.nav-group').forEach(function(g) {
        if (g.querySelector('.nav-sub.active')) {
            g.classList.add('open', 'page-active-group');
            positionDropdown(g);
        }
    });

    // Reposition on scroll/resize
    window.addEventListener('scroll', function() {
        document.querySelectorAll('.nav-group.open').forEach(positionDropdown);
    }, {passive: true});
    window.addEventListener('resize', function() {
        document.querySelectorAll('.nav-group.open').forEach(positionDropdown);
    }, {passive: true});
})();
</script>

        <?php endif; ?>
        
        <div class="main-content">
            <?php if (isset($_SESSION['success'])): ?>
            <div class="message success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
            <div class="message error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['warning'])): ?>
            <div class="message warning"><?php echo htmlspecialchars($_SESSION['warning']); unset($_SESSION['warning']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['info'])): ?>
            <div class="message info"><?php echo htmlspecialchars($_SESSION['info']); unset($_SESSION['info']); ?></div>
            <?php endif; ?>
            
            
            <?php
            
function includePaymentLink() {
    ?>
    <h2>Generate Payment Link</h2>
    
    <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 30px;">
        <form method="POST" id="paymentLinkForm">
            <input type="hidden" name="action" value="generate_link">
            
            <div class="row">
                <div class="form-group">
                    <label>Amount (₹): *</label>
                    <input type="number" name="amount" step="0.01" min="1" required>
                </div>
                
                <div class="form-group">
                    <label>Payment Description/Note: *</label>
                    <input type="text" name="purpose" placeholder="e.g., Product Payment, Service Fee, Order #123" value="Payment Request" required>
                    <small>This text will appear in the payment app when customer pays</small>
                </div>
            </div>
            
            <div class="row">
                <div class="form-group">
                    <label>Customer Name:</label>
                    <input type="text" name="customer_name" placeholder="Optional">
                </div>
                
                <div class="form-group">
                    <label>Customer Phone (for WhatsApp):</label>
                    <input type="tel" name="customer_phone" placeholder="Include country code (e.g., 91XXXXXXXXXX)">
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit">Generate Payment Link</button>
                <button type="button" onclick="resetForm()" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>
    
    <?php if (isset($_SESSION['generated_payment_link'])): ?>
    <div style="background: #d4edda; padding: 20px; border-radius: 5px; margin-top: 20px;">
        <h3 style="color: #155724; margin-bottom: 15px;">Payment Link Generated</h3>
        
        <div style="background: white; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
            <div style="margin-bottom: 10px;">
                <strong>Amount:</strong> <?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($_SESSION['generated_payment_link']['amount'], 2); ?>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Description:</strong> <?php echo htmlspecialchars($_SESSION['generated_payment_link']['purpose']); ?>
            </div>
            <div style="margin-bottom: 10px;">
                <strong>Payment Link (Unique):</strong><br>
                <input type="text" value="<?php echo htmlspecialchars($_SESSION['generated_payment_link']['url']); ?>" 
                       style="width: 100%; padding: 10px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px; font-size: 12px;" 
                       readonly id="paymentLinkField">
            </div>
            <div style="margin-top: 5px; font-size: 11px; color: #666;">
                <strong>Note:</strong> This link is unique and will expire in 7 days
            </div>
        </div>
        
        <div class="action-buttons">
            <button type="button" onclick="copyPaymentLink()" style="background: #3498db;">Copy Link</button>
            
            <?php if (!empty($_SESSION['generated_payment_link']['customer_phone'])): ?>
            <a href="javascript:void(0)" onclick="sendPaymentLinkWhatsApp()" style="background: #25D366; color: white; text-decoration: none; padding: 12px 24px; border-radius: 4px; display: inline-block; font-weight: bold;">
                Send via WhatsApp
            </a>
            <?php endif; ?>
            
            <button type="button" onclick="window.location.reload()" class="btn-secondary">Generate Another</button>
        </div>
    </div>
    
    <script>
    function copyPaymentLink() {
        var linkField = document.getElementById('paymentLinkField');
        linkField.select();
        document.execCommand('copy');
        alert('Payment link copied to clipboard!');
    }
    
    function sendPaymentLinkWhatsApp() {
        var phone = '<?php echo $_SESSION['generated_payment_link']['customer_phone']; ?>';
        var amount = '<?php echo number_format($_SESSION['generated_payment_link']['amount'], 2); ?>';
        var purpose = '<?php echo addslashes($_SESSION['generated_payment_link']['purpose']); ?>';
        var link = '<?php echo $_SESSION['generated_payment_link']['url']; ?>';
        
        var message = encodeURIComponent(
            'Payment request for: ' + purpose + '\n' +
            'Amount: ₹' + amount + '\n' +
            'Please click here to pay: ' + link
        );
        
        window.open('https://wa.me/' + phone + '?text=' + message, '_blank');
    }
    </script>
    <?php unset($_SESSION['generated_payment_link']); ?>
    <?php endif; ?>
    
    <script>
    function resetForm() {
        document.getElementById('paymentLinkForm').reset();
    }
    </script>
    <?php
}



if (isLoggedIn()) {
    switch ($page) {
        case 'dashboard':
            includeDashboard();
            break;
        case 'create_invoice':
            includeCreateInvoice();
            break;
        case 'invoices':
            includeInvoices();
            break;
        case 'bookings':
            includeBookings();
            break;
        case 'create_booking':
            includeCreateBooking();
            break;
        case 'view_booking':
            includeViewBooking();
            break;
        case 'edit_booking':
            includeEditBooking();
            break;
        case 'view_invoice':
            includeViewInvoice();
            break;
        case 'edit_invoice':
            includeEditInvoice();
            break;
        case 'pending_deletions':
            includePendingDeletions();
            break;
        case 'payment_link':
            includePaymentLink();
            break;
        case 'export':
            includeExport();
            break;
        case 'expenses':
            includeExpenses();
            break;
        case 'profile':
            includeProfile();
            break;
        case 'users':
            includeUsers();
            break;
        case 'settings':
            includeSettings();
            break;
        case 'invoice_templates':
            includeInvoiceTemplates();
            break;
        case 'academy':
            includeAcademy();
            break;
        case 'academy_courses':
            includeAcademyCourses();
            break;
        case 'create_enrollment':
            includeCreateEnrollment();
            break;
        case 'view_enrollment':
            includeViewEnrollment();
            break;
        case 'edit_enrollment':
            includeEditEnrollment();
            break;
        case 'academy_reminders':
            includeAcademyReminders();
            break;
        case 'yatra':
            includeYatra();
            break;
        case 'yatra_bookings':
            includeYatraBookings();
            break;
        case 'create_yatra_booking':
            includeCreateYatraBooking();
            break;
        case 'view_yatra_booking':
            includeViewYatraBooking();
            break;
        case 'edit_yatra_booking':
            includeEditYatraBooking();
            break;
        default:
            includeDashboard();
    }
} else {
    includeLogin();
}
            ?>
        </div>
    </div>

    <?php if (isLoggedIn()): ?>
    <!-- ═══════════════════════════════════════════════
         CHAT WIDGET — Facebook Messenger style (HTML)
    ═══════════════════════════════════════════════════ -->
    <div id="chatWidget">
        <!-- FAB button (always visible) -->
        <button class="chat-fab" id="chatFab" onclick="chatTogglePanel()" title="Messages">
            💬
            <span class="chat-fab-badge" id="chatFabBadge" style="display:none;">0</span>
        </button>
    </div>

    <!-- User List Panel -->
    <div id="chatUsersPanel" style="display:none;position:fixed;bottom:80px;right:20px;z-index:9999;width:300px;max-height:420px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.22);overflow:hidden;flex-direction:column;animation:chatSlideUp .2s ease;">
        <div style="background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-weight:700;font-size:15px;">💬 Messages</div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span id="chatOnlineCount" style="font-size:11px;background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;"></span>
                <button onclick="chatTogglePanel()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0;">×</button>
            </div>
        </div>
        <div style="overflow-y:auto;flex:1;">
            <div id="chatUsersList" style="padding:8px 0;"></div>
        </div>
    </div>

    <!-- Individual Chat Panel (per user) -->
    <div id="chatConvPanel" style="display:none;position:fixed;bottom:80px;right:20px;z-index:10000;width:320px;max-height:480px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.22);flex-direction:column;animation:chatSlideUp .2s ease;overflow:hidden;">
        <!-- Header -->
        <div id="chatConvHeader" style="background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;padding:0 14px;height:54px;display:flex;align-items:center;gap:10px;cursor:pointer;flex-shrink:0;">
            <button onclick="chatBackToUsers()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:14px;">←</button>
            <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:14px;" id="chatConvAvatar">👤</div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="chatConvName">User</div>
                <div style="font-size:11px;opacity:.85;" id="chatConvStatus">Online</div>
            </div>
            <button onclick="chatMinimizeConv()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;padding:0;opacity:.8;" id="chatConvMinBtn" title="Minimize">−</button>
            <button onclick="chatCloseConv()" style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;padding:0;opacity:.8;" title="Close">×</button>
        </div>
        <!-- Messages -->
        <div id="chatMessages" style="flex:1;overflow-y:auto;padding:12px 10px;background:#f0f2f5;display:flex;flex-direction:column;gap:4px;min-height:200px;max-height:300px;"></div>
        <!-- Typing indicator -->
        <div id="chatTypingIndicator" style="display:none;padding:4px 14px;font-size:11px;color:#888;background:#f0f2f5;">typing...</div>
        <!-- Input -->
        <div style="padding:10px 12px;background:#fff;border-top:1px solid #e9ecef;display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <input type="text" id="chatInput" placeholder="Aa" maxlength="500" style="flex:1;border:none;background:#f0f2f5;border-radius:20px;padding:9px 14px;font-size:13px;outline:none;"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){chatSendMsg();event.preventDefault();}">
            <button onclick="chatSendMsg()" style="background:#0866ff;border:none;color:#fff;width:34px;height:34px;border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">➤</button>
        </div>
    </div>

    <script>
    // ─── CHAT WIDGET — Realtime with 2s polling ───
    (function() {
        var myId = <?php echo intval($_SESSION['user_id'] ?? 0); ?>;
        var myName = '<?php echo addslashes($_SESSION['username'] ?? ''); ?>';
        var activeWith = null; // user id of open chat
        var activeWithName = '';
        var lastMsgId = 0;
        var panelOpen = false;
        var convMinimized = false;
        var msgPollTimer = null;
        var userPollTimer = null;
        var heartbeatTimer = null;
        var totalUnread = 0;
        var userUnread = {}; // uid -> unread count

        // ── Heartbeat every 30s ──
        function doHeartbeat() {
            fetch('?ajax=chat_heartbeat', {method:'POST'})
            .then(function(r){return r.json();})
            .then(function(d){
                totalUnread = d.unread || 0;
                updateFabBadge();
            }).catch(function(){});
        }
        doHeartbeat();
        heartbeatTimer = setInterval(doHeartbeat, 30000);

        function updateFabBadge() {
            var b = document.getElementById('chatFabBadge');
            if (!b) return;
            if (totalUnread > 0) {
                b.textContent = totalUnread > 99 ? '99+' : totalUnread;
                b.style.display = 'flex';
                document.getElementById('chatFab').style.animation = totalUnread > 0 ? 'chatBounce 2s ease infinite' : '';
            } else {
                b.style.display = 'none';
                document.getElementById('chatFab').style.animation = '';
            }
        }

        // ── Toggle user list panel ──
        window.chatTogglePanel = function() {
            var uPanel = document.getElementById('chatUsersPanel');
            var convPanel = document.getElementById('chatConvPanel');
            if (convPanel.style.display !== 'none' && !convMinimized) {
                // Close conv
                chatCloseConv();
                return;
            }
            panelOpen = !panelOpen;
            uPanel.style.display = panelOpen ? 'flex' : 'none';
            if (panelOpen) loadUserList();
        };

        function loadUserList() {
            fetch('?ajax=chat_users')
            .then(function(r){return r.json();})
            .then(function(d){
                var list = document.getElementById('chatUsersList');
                if (!list) return;
                var online = 0;
                var html = '';
                (d.users || []).forEach(function(u) {
                    if (u.is_online) online++;
                    var badge = (u.unread > 0) ? '<span style="background:#e74c3c;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 6px;min-width:16px;text-align:center;">' + u.unread + '</span>' : '';
                    var onlineDot = u.is_online ? '<span style="width:9px;height:9px;border-radius:50%;background:#4caf50;display:inline-block;border:1.5px solid #fff;flex-shrink:0;"></span>' : '<span style="width:9px;height:9px;border-radius:50%;background:#ccc;display:inline-block;border:1.5px solid #fff;flex-shrink:0;"></span>';
                    var displayName = (u.full_name && u.full_name !== 'NA') ? u.full_name : u.username;
                    html += '<div class="chat-user-item" onclick="openChatWith('+u.id+',\''+escHtml(displayName)+'\')" style="display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;transition:background .15s;" onmouseover="this.style.background=\'#f0f2f5\'" onmouseout="this.style.background=\'transparent\'">'
                        + '<div style="position:relative;flex-shrink:0;">'
                        + '<div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#0866ff,#0099ff);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;">'
                        + escHtml(displayName.charAt(0).toUpperCase())
                        + '</div>'
                        + '<span style="position:absolute;bottom:1px;right:1px;">' + onlineDot + '</span>'
                        + '</div>'
                        + '<div style="flex:1;min-width:0;">'
                        + '<div style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(displayName) + '</div>'
                        + '<div style="font-size:11px;color:#888;">' + escHtml(u.role) + (u.designation && u.designation !== 'NA' ? ' · ' + escHtml(u.designation) : '') + '</div>'
                        + '</div>'
                        + badge
                        + '</div>';
                });
                if (!html) html = '<div style="text-align:center;padding:24px;color:#aaa;font-size:13px;">No other users yet</div>';
                list.innerHTML = html;
                var cnt = document.getElementById('chatOnlineCount');
                if (cnt) cnt.textContent = online + ' online';
                // Update total unread
                totalUnread = (d.users || []).reduce(function(s,u){return s+(u.unread||0);},0);
                updateFabBadge();
            }).catch(function(){});
        }

        // ── Open chat with specific user ──
        window.openChatWith = function(uid, name) {
            activeWith = uid;
            activeWithName = name;
            lastMsgId = 0;
            convMinimized = false;

            document.getElementById('chatConvName').textContent = name;
            document.getElementById('chatConvAvatar').textContent = name.charAt(0).toUpperCase();
            document.getElementById('chatMessages').innerHTML = '';
            document.getElementById('chatUsersPanel').style.display = 'none';
            panelOpen = false;

            var convPanel = document.getElementById('chatConvPanel');
            convPanel.style.display = 'flex';
            convPanel.style.maxHeight = '480px';
            document.getElementById('chatConvMinBtn').textContent = '−';
            document.getElementById('chatInput').focus();

            // Load messages immediately
            pollMessages();
            clearInterval(msgPollTimer);
            msgPollTimer = setInterval(pollMessages, 2000);
        };

        function pollMessages() {
            if (!activeWith) return;
            fetch('?ajax=chat_messages&with='+activeWith+'&since='+lastMsgId)
            .then(function(r){return r.json();})
            .then(function(d){
                var msgs = d.messages || [];
                if (msgs.length === 0) return;
                var box = document.getElementById('chatMessages');
                msgs.forEach(function(m) {
                    if (m.id <= lastMsgId) return;
                    lastMsgId = m.id;
                    var mine = (m.from_user == myId);
                    var bubble = document.createElement('div');
                    bubble.style.cssText = 'display:flex;justify-content:'+(mine?'flex-end':'flex-start')+';margin:'+(mine?'1px 0':'1px 0')+';';
                    var time = new Date(m.created_at.replace(' ','T'));
                    var timeStr = time.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
                    bubble.innerHTML = '<div style="max-width:78%;background:'+(mine?'#0866ff':'#fff')+';color:'+(mine?'#fff':'#050505')+';border-radius:'+(mine?'18px 18px 4px 18px':'18px 18px 18px 4px')+';padding:8px 12px;font-size:13px;box-shadow:0 1px 2px rgba(0,0,0,.1);word-break:break-word;">'
                        + escHtml(m.message)
                        + '<div style="font-size:10px;opacity:.6;margin-top:3px;text-align:right;">'+timeStr+'</div>'
                        + '</div>';
                    box.appendChild(bubble);
                });
                box.scrollTop = box.scrollHeight;
                // Reduce unread for this user
                totalUnread = Math.max(0, totalUnread - msgs.filter(function(m){return m.from_user==activeWith;}).length);
                updateFabBadge();
            }).catch(function(){});
        }

        // ── Send message ──
        window.chatSendMsg = function() {
            var inp = document.getElementById('chatInput');
            var msg = (inp ? inp.value.trim() : '');
            if (!msg || !activeWith) return;
            inp.value = '';
            var fd = new FormData();
            fd.append('to', activeWith);
            fd.append('msg', msg);
            fetch('?ajax=chat_send', {method:'POST', body: new URLSearchParams({to: activeWith, msg: msg})})
            .then(function(r){return r.json();})
            .then(function(d){
                if (d.ok) pollMessages();
            }).catch(function(){});
        };

        window.chatBackToUsers = function() {
            clearInterval(msgPollTimer);
            activeWith = null;
            document.getElementById('chatConvPanel').style.display = 'none';
            document.getElementById('chatUsersPanel').style.display = 'flex';
            panelOpen = true;
            loadUserList();
        };

        window.chatCloseConv = function() {
            clearInterval(msgPollTimer);
            activeWith = null;
            document.getElementById('chatConvPanel').style.display = 'none';
        };

        window.chatMinimizeConv = function() {
            convMinimized = !convMinimized;
            var p = document.getElementById('chatConvPanel');
            if (convMinimized) {
                p.style.maxHeight = '54px';
                document.getElementById('chatConvMinBtn').textContent = '+';
            } else {
                p.style.maxHeight = '480px';
                document.getElementById('chatConvMinBtn').textContent = '−';
                pollMessages();
                clearInterval(msgPollTimer);
                msgPollTimer = setInterval(pollMessages, 2000);
            }
        };

        // ── Auto-minimize: minimize chat after 3min inactivity ──
        var autoMinTimer = null;
        function resetAutoMin() {
            clearTimeout(autoMinTimer);
            autoMinTimer = setTimeout(function() {
                if (activeWith && !convMinimized) chatMinimizeConv();
            }, 180000); // 3 minutes
        }
        document.addEventListener('mousemove', resetAutoMin, {passive:true});
        document.addEventListener('touchstart', resetAutoMin, {passive:true});
        document.addEventListener('keydown', resetAutoMin, {passive:true});

        // Focus/blur: poll faster when tab focused, stop when blurred, auto-minimize on hide
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Tab hidden: stop polling, auto-minimize after 30s away
                clearInterval(msgPollTimer);
                clearTimeout(autoMinTimer);
                autoMinTimer = setTimeout(function() {
                    if (activeWith && !convMinimized) chatMinimizeConv();
                }, 30000);
            } else {
                // Tab visible: resume polling
                clearTimeout(autoMinTimer);
                if (activeWith && !convMinimized) {
                    clearInterval(msgPollTimer);
                    msgPollTimer = setInterval(pollMessages, 2000);
                    pollMessages();
                }
                resetAutoMin();
            }
        });

        function escHtml(s) {
            return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // Periodic user list refresh (every 15s if panel open)
        setInterval(function() {
            if (panelOpen) loadUserList();
        }, 15000);

        // Add bounce animation CSS
        var style = document.createElement('style');
        style.textContent = '@keyframes chatBounce{0%,100%{transform:scale(1);}50%{transform:scale(1.12);}}';
        document.head.appendChild(style);
    })();
    </script>
    <?php endif; ?>

</body>
</html>
<?php
function includeLogin() {
    ?>
    <div class="login-container">
        <h2>Invoice System Login</h2>
        <form method="POST" class="login-form">
            <div class="form-group">
                <label>Username:</label>
                <input type="text" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="login">Login</button>
            </div>
        </form>
    </div>
    <?php
}

function includeBookings() {
    global $db;
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $bookings = getAllBookings($search, $status, $date_from, $date_to);
    ?>
    <h2>Service Bookings</h2>
    
    <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?page=create_booking" class="btn" style="background: #27ae60;">➕ New Booking</a>
        <a href="?page=bookings" class="btn-secondary">📋 All Bookings</a>
    </div>
    
    <div class="search-box">
        <form method="GET" class="search-form">
            <input type="hidden" name="page" value="bookings">
            <div class="form-group">
                <label>Search:</label>
                <input type="text" name="search" placeholder="Customer, Booking #, Phone, Service..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="form-group">
                <label>Status:</label>
                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="converted" <?php echo $status === 'converted' ? 'selected' : ''; ?>>Converted to Invoice</option>
                </select>
            </div>
            <div class="form-group">
                <label>From Date:</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
            </div>
            <div class="form-group">
                <label>To Date:</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
            </div>
            <div class="form-group">
                <button type="submit">Search</button>
                <button type="button" onclick="window.location.href='?page=bookings'" class="btn-secondary">Clear</button>
            </div>
        </form>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Booking #</th>
                <th>Customer</th>
                <th>Service</th>
                <th>Booking Date</th>
                <th>Est. Cost</th>
                <th>Advance</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bookings)): ?>
            <tr>
                <td colspan="9" style="text-align: center;">No bookings found</td>
            </tr>
            <?php else: ?>
            <?php foreach ($bookings as $booking): 
                $bookingData = getBookingData($booking['id']);
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($booking['booking_number']); ?></strong></td>
                <td><?php echo htmlspecialchars($booking['customer_name']); ?><br>
                    <small><?php echo htmlspecialchars($booking['customer_phone']); ?></small>
                </td>
                <td><?php echo htmlspecialchars(substr($booking['service_description'], 0, 30)) . '...'; ?></td>
                <td><?php echo date('d-m-Y', strtotime($booking['booking_date'])); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['total_estimated_cost'], 2); ?></td>
                <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['advance_fees'], 2); ?></td>
                <td>
                    <span class="payment-badge <?php 
                        echo $booking['status'] === 'pending' ? 'unpaid' : 
                            ($booking['status'] === 'in_progress' ? 'partially_paid' : 
                            ($booking['status'] === 'completed' ? 'paid' : 'settled')); 
                    ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                    </span>
                </td>
                <td>
                    <span class="payment-badge <?php echo $booking['payment_status']; ?>">
                        <?php 
                        if ($booking['payment_status'] === 'paid') echo 'Paid';
                        elseif ($booking['payment_status'] === 'partial') echo 'Partial';
                        else echo 'Pending';
                        ?>
                    </span>
                </td>
                <td class="actions-cell">
                    <a href="?page=view_booking&id=<?php echo $booking['id']; ?>" class="action-btn view-btn">View</a>
                    <?php if ($booking['status'] !== 'converted' && $booking['status'] !== 'cancelled'): ?>
                    <a href="?page=edit_booking&id=<?php echo $booking['id']; ?>" class="action-btn edit-btn">Edit</a>
                    <?php endif; ?>
                    <?php if ($booking['status'] === 'completed' && !$booking['converted_to_invoice']): ?>
                    <a href="javascript:void(0)" onclick="convertToInvoice(<?php echo $booking['id']; ?>, '<?php echo $booking['booking_number']; ?>')" class="action-btn" style="background: #8e44ad;">Convert</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function includeCreateBooking() {
    ?>
    <h2>Create New Service Booking</h2>
    <form method="POST" id="bookingForm">
        <input type="hidden" name="action" value="create_booking">
        
        <h3>Customer Details</h3>
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" required>
            </div>
            <div class="form-group">
                <label>Phone Number: *</label>
                <input type="tel" name="customer_phone" required>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email">
            </div>
            <div class="form-group">
                <label>Booking Date: *</label>
                <input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"></textarea>
        </div>
        
        <h3>Service Details</h3>
        <div class="form-group">
            <label>Service Description: *</label>
            <textarea name="service_description" rows="3" required placeholder="Describe the service required..."></textarea>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Expected Completion Date:</label>
                <input type="date" name="expected_completion_date">
            </div>
            <div class="form-group">
                <label>Total Estimated Cost (₹): *</label>
                <input type="number" name="total_estimated_cost" step="0.01" min="0" required>
            </div>
        </div>
        
        <h3>Advance Payment</h3>
        <div class="row">
            <div class="form-group">
                <label>Advance Fees Required (₹):</label>
                <input type="number" name="advance_fees" step="0.01" min="0" value="0" id="advanceFees">
            </div>
            <div class="form-group">
                <label>Payment Method:</label>
                <select name="payment_method" id="paymentMethod">
                    <option value="">Select (if paying now)</option>
                    <?php foreach (getPaymentMethods() as $method): ?>
                    <option value="<?php echo htmlspecialchars(trim($method)); ?>"><?php echo htmlspecialchars(trim($method)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Transaction ID (if applicable):</label>
                <input type="text" name="transaction_id" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>Notes:</label>
                <input type="text" name="notes" placeholder="Optional notes">
            </div>
        </div>
        
        <h3>Service Items (Optional)</h3>
        <div id="booking-items-container">
            <div class="item-row">
                <div class="row">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[0][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[0][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
        </div>
        
        <button type="button" onclick="addBookingItem()" class="btn-secondary">Add Item</button>
        
        <div class="action-buttons" style="margin-top: 30px;">
            <button type="submit">Create Booking</button>
            <a href="?page=bookings" class="btn-secondary">Cancel</a>
        </div>
    </form>
    
    <script>
    function addBookingItem() {
    var container = document.getElementById('booking-items-container');
    if (!container) {
        container = document.getElementById('edit-booking-items-container');
    }
    if (!container) return;
    
    var index = container.querySelectorAll('.item-row').length;
    
    var html = `
        <div class="item-row">
            <div class="row">
                <input type="hidden" name="items[${index}][id]" value="0">
                <div class="form-group" style="flex: 2;">
                    <label>Description</label>
                    <input type="text" name="items[${index}][description]" placeholder="Item description">
                </div>
                <div class="form-group">
                    <label>Est. Amount (₹)</label>
                    <input type="number" name="items[${index}][estimated_amount]" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                </div>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
}

function removeBookingItem(button) {
    var itemRow = button.closest('.item-row');
    if (itemRow) {
        var container = itemRow.closest('#booking-items-container, #edit-booking-items-container');
        if (container && container.querySelectorAll('.item-row').length > 1) {
            itemRow.remove();
        }
    }
}
    </script>
    <?php
}

function includeViewBooking() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Booking ID not specified!</div>';
        return;
    }
    
    $booking_id = $_GET['id'];
    $booking = getBookingData($booking_id);
    
    if (!$booking) {
        echo '<div class="message error">Booking not found!</div>';
        return;
    }
    
    $share_token = null;
    if ($booking['status'] !== 'cancelled') {
        $stmt = $db->prepare("SELECT share_token FROM booking_shares WHERE booking_id = :booking_id AND is_active = 1 ORDER BY created_at DESC LIMIT 1");
        $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
        $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($existing) {
            $share_token = $existing['share_token'];
        } else {
            $share_token = bin2hex(random_bytes(32));
            $stmt = $db->prepare("INSERT INTO booking_shares (booking_id, share_token, created_by, expires_at) VALUES (:booking_id, :token, :user_id, datetime('now', '+30 days'))");
            $stmt->bindValue(':booking_id', $booking_id, SQLITE3_INTEGER);
            $stmt->bindValue(':token', $share_token, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $_SESSION['user_id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
    
    $share_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/booking_receipt.php?token=' . $share_token;
    
    $canEdit = isAdmin() || $booking['created_by'] == $_SESSION['user_id'];
    ?>
    
    <h2>Booking Details: <?php echo htmlspecialchars($booking['booking_number']); ?></h2>
    
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div style="flex: 2;">
            <div class="invoice-preview" style="margin-top: 0;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                    <div>
                        <?php 
                        $logo_path = getSetting('logo_path');
                        if ($logo_path && file_exists($logo_path)): ?>
                            <img src="<?php echo $logo_path; ?>" style="max-width: 130px; max-height: 130px; margin-bottom: 5px;" alt="Logo">
                        <?php endif; ?>
                        <h3 style="color: #2c3e50;"><?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?></h3>
                        <div style="color: #666; font-size: 13px;">Service Booking Receipt</div>
                    </div>
                    <div style="text-align: right;">
                        <div><strong>Booking #:</strong> <?php echo htmlspecialchars($booking['booking_number']); ?></div>
                        <div><strong>Date:</strong> <?php echo date('d-m-Y', strtotime($booking['booking_date'])); ?></div>
                        <div>
                            <span class="payment-badge <?php 
                                echo $booking['status'] === 'pending' ? 'unpaid' : 
                                    ($booking['status'] === 'in_progress' ? 'partially_paid' : 
                                    ($booking['status'] === 'completed' ? 'paid' : 'settled')); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0;">Customer Details</h4>
                    <div><strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong></div>
                    <?php if (!empty($booking['customer_phone'])): ?>
                    <div>📞 <?php echo htmlspecialchars($booking['customer_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($booking['customer_email'])): ?>
                    <div>📧 <?php echo htmlspecialchars($booking['customer_email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($booking['customer_address'])): ?>
                    <div>📍 <?php echo nl2br(htmlspecialchars($booking['customer_address'])); ?></div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4>Service Description</h4>
                    <div style="padding: 10px; background: #f8f9fa; border-radius: 5px;">
                        <?php echo nl2br(htmlspecialchars($booking['service_description'])); ?>
                    </div>
                    <?php if (!empty($booking['expected_completion_date'])): ?>
                    <div style="margin-top: 10px;"><strong>Expected Completion:</strong> <?php echo date('d-m-Y', strtotime($booking['expected_completion_date'])); ?></div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($booking['parsed_items'])): ?>
                <h4>Service Items</h4>
                <table style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>Est. Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($booking['parsed_items'] as $index => $item): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($item['estimated_amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
                    <table style="width: 300px;">
                        <tr>
                            <td><strong>Total Estimated Cost:</strong></td>
                            <td style="text-align: right;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['total_estimated_cost'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Advance Paid:</strong></td>
                            <td style="text-align: right; color: #27ae60;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['advance_fees'], 2); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Balance Due:</strong></td>
                            <td style="text-align: right; color: #e74c3c; font-weight: bold;"><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['totals']['balance'], 2); ?></td>
                        </tr>
                    </table>
                </div>
                
                <?php 
                // Dynamic QR Code for pending payment
                if ($booking['totals']['balance'] > 0): 
                    $upi_link = generateUPILink($booking['totals']['balance'], $booking['booking_number'], "Booking: " . $booking['booking_number']);
                    $qr_code_url = generateQRCode($upi_link, 140);
                ?>
                <div style="text-align: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                    <h4>Pay Balance Due: <?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($booking['totals']['balance'], 2); ?></h4>
                    <?php if (!empty($qr_code_url)): ?>
                    <a href="<?php echo htmlspecialchars($upi_link); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($qr_code_url); ?>" style="max-width: 140px; border: 1px solid #ddd; padding: 5px; background: white;" alt="Payment QR Code">
                    </a>
                    <div style="margin-top: 10px;">
                        <a href="<?php echo htmlspecialchars($upi_link); ?>" target="_blank" style="background: #25D366; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none;">Pay Now</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($booking['parsed_payments'])): ?>
                <h4>Payment History</h4>
                <table style="margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Type</th>
                            <th>Transaction ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($booking['parsed_payments'] as $payment): ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($payment['payment_date'])); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo getSetting('currency_symbol', '₹'); ?> <?php echo number_format($payment['amount'], 2); ?></td>
                            <td><?php echo strpos($payment['notes'], 'Installment') !== false ? 'Installment' : 'Advance'; ?></td>
                            <td><?php echo htmlspecialchars($payment['transaction_id']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php if (!empty($booking['notes'])): ?>
                <div style="padding: 10px; background: #fff8e1; border-radius: 5px; border-left: 4px solid #ffb300;">
                    <strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($booking['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 20px; font-size: 12px; color: #666; text-align: center; border-top: 1px solid #eee; padding-top: 15px;">
                    <?php echo htmlspecialchars(getSetting('company_name', 'D K ASSOCIATES')); ?><br>
                    <?php echo nl2br(htmlspecialchars(getSetting('office_address', ''))); ?><br>
                    Phone: <?php echo htmlspecialchars(getSetting('office_phone', '')); ?>
                </div>
            </div>
        </div>
        
        <div style="flex: 1;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 5px;">
                <h4 style="margin: 0 0 15px 0;">Actions</h4>
                
                <?php if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'converted'): ?>
                <div style="margin-bottom: 20px;">
                    <label><strong>Update Status:</strong></label>
                    <select id="statusSelect" onchange="updateBookingStatus(<?php echo $booking_id; ?>, this.value)" style="width: 100%; margin: 10px 0;">
                        <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $booking['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['totals']['balance'] > 0 && $booking['status'] !== 'cancelled' && $booking['status'] !== 'converted'): ?>
                <div style="margin-bottom: 20px;">
                    <h5 style="margin: 0 0 10px 0;">Add Payment</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="add_booking_payment">
                        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
                        
                        <div class="form-group">
                            <label>Amount (₹):</label>
                            <input type="number" name="amount" step="0.01" min="1" max="<?php echo $booking['totals']['balance']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Payment Method:</label>
                            <select name="payment_method" required>
                                <?php foreach (getPaymentMethods() as $method): ?>
                                <option value="<?php echo htmlspecialchars(trim($method)); ?>"><?php echo htmlspecialchars(trim($method)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Transaction ID:</label>
                            <input type="text" name="transaction_id">
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center;">
                                <input type="checkbox" name="is_installment" value="1" style="width: auto; margin-right: 8px;">
                                Mark as Installment Payment
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label>Notes:</label>
                            <input type="text" name="notes" placeholder="Optional notes">
                        </div>
                        
                        <button type="submit" style="width: 100%;">Record Payment</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['status'] === 'completed' && !$booking['converted_to_invoice']): ?>
                <div style="margin-bottom: 20px;">
                    <button onclick="convertToInvoice(<?php echo $booking_id; ?>, '<?php echo $booking['booking_number']; ?>')" style="width: 100%; background: #8e44ad;">Convert to Invoice</button>
                </div>
                <?php endif; ?>
                
                <?php if ($share_token): ?>
                <div style="margin-bottom: 20px;">
                    <label><strong>Customer Receipt Link:</strong></label>
                    <div style="display: flex; margin-top: 5px;">
                        <input type="text" value="<?php echo htmlspecialchars($share_url); ?>" readonly style="flex: 1; font-size: 11px;" id="shareLink">
                        <button onclick="copyShareLink()" style="padding: 8px 12px; margin-left: 5px;">Copy</button>
                    </div>
                    <small style="color: #666;">Share this link with customer</small>
                </div>
                <?php endif; ?>
                
                <?php if ($booking['converted_to_invoice'] && $booking['converted_invoice_id']): ?>
                <div style="margin-bottom: 20px;">
                    <a href="?page=view_invoice&id=<?php echo $booking['converted_invoice_id']; ?>" class="btn" style="width: 100%; text-align: center;">View Converted Invoice</a>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($booking['customer_email']) || !empty($booking['customer_phone'])): ?>
                <div style="margin-bottom: 20px;">
                    <h5 style="margin: 0 0 10px 0;">Share Receipt</h5>
                    <div style="display: flex; gap: 10px;">
                        <?php if (!empty($booking['customer_email'])): ?>
                        <button onclick="sendBookingEmail(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($booking['customer_email']); ?>')" style="flex: 1; background: #3498db;">
                            📧 Email
                        </button>
                        <?php endif; ?>
                        <?php if (!empty($booking['customer_phone'])): ?>
                        <button onclick="sendBookingWhatsApp(<?php echo $booking_id; ?>, '<?php echo htmlspecialchars($booking['customer_phone']); ?>')" style="flex: 1; background: #25D366;">
                            📱 WhatsApp
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (isAdmin()): ?>
                <div style="margin-bottom: 20px;">
                    <button onclick="confirmDeleteBooking(<?php echo $booking_id; ?>, '<?php echo $booking['booking_number']; ?>')" style="width: 100%; background: #e74c3c;">
                        🗑️ Delete Booking
                    </button>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px;">
                    <button onclick="window.print()" class="btn-print" style="flex: 1;">Print</button>
                    <a href="?page=bookings" class="btn-secondary" style="flex: 1; text-align: center;">Back</a>
                </div>
            </div>
        </div>
    </div>
    
    <form id="statusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="update_booking_status">
        <input type="hidden" name="booking_id" id="statusBookingId">
        <input type="hidden" name="status" id="statusValue">
    </form>
    
    <form id="convertForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="convert_booking">
        <input type="hidden" name="booking_id" id="convertBookingId">
    </form>
    
    <script>
    function updateBookingStatus(bookingId, status) {
        if (confirm('Update booking status to ' + status.replace('_', ' ') + '?')) {
            document.getElementById('statusBookingId').value = bookingId;
            document.getElementById('statusValue').value = status;
            document.getElementById('statusForm').submit();
        } else {
            document.getElementById('statusSelect').value = '<?php echo $booking['status']; ?>';
        }
    }
    
    function convertToInvoice(bookingId, bookingNumber) {
        if (confirm('Convert booking ' + bookingNumber + ' to invoice?')) {
            document.getElementById('convertBookingId').value = bookingId;
            document.getElementById('convertForm').submit();
        }
    }
    
    function copyShareLink() {
        var linkField = document.getElementById('shareLink');
        linkField.select();
        document.execCommand('copy');
        alert('Receipt link copied to clipboard!');
    }
    </script>
    <?php
}

function includeEditBooking() {
    global $db;
    
    if (!isset($_GET['id'])) {
        echo '<div class="message error">Booking ID not specified!</div>';
        return;
    }
    
    $booking_id = $_GET['id'];
    $booking = getBookingData($booking_id);
    
    if (!$booking) {
        echo '<div class="message error">Booking not found!</div>';
        return;
    }
    
    $canEdit = isAdmin() || $booking['created_by'] == $_SESSION['user_id'];
    if (!$canEdit) {
        echo '<div class="message error">You don\'t have permission to edit this booking!</div>';
        return;
    }
    
    if ($booking['status'] === 'converted' || $booking['status'] === 'cancelled') {
        echo '<div class="message warning">This booking cannot be edited as it is ' . $booking['status'] . '.</div>';
        return;
    }
    ?>
    
    <h2>Edit Booking: <?php echo htmlspecialchars($booking['booking_number']); ?></h2>
    
    <form method="POST">
        <input type="hidden" name="action" value="update_booking">
        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
        
        <h3>Customer Details</h3>
        <div class="row">
            <div class="form-group">
                <label>Customer Name: *</label>
                <input type="text" name="customer_name" value="<?php echo htmlspecialchars($booking['customer_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Phone Number: *</label>
                <input type="tel" name="customer_phone" value="<?php echo htmlspecialchars($booking['customer_phone']); ?>" required>
            </div>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="customer_email" value="<?php echo htmlspecialchars($booking['customer_email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Booking Date: *</label>
                <input type="date" name="booking_date" value="<?php echo $booking['booking_date']; ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Address:</label>
            <textarea name="customer_address" rows="2"><?php echo htmlspecialchars($booking['customer_address'] ?? ''); ?></textarea>
        </div>
        
        <h3>Service Details</h3>
        <div class="form-group">
            <label>Service Description: *</label>
            <textarea name="service_description" rows="3" required><?php echo htmlspecialchars($booking['service_description']); ?></textarea>
        </div>
        
        <div class="row">
            <div class="form-group">
                <label>Expected Completion Date:</label>
                <input type="date" name="expected_completion_date" value="<?php echo $booking['expected_completion_date'] ?? ''; ?>">
            </div>
            <div class="form-group">
                <label>Total Estimated Cost (₹): *</label>
                <input type="number" name="total_estimated_cost" step="0.01" min="0" value="<?php echo $booking['total_estimated_cost']; ?>" required>
            </div>
        </div>
        
        <div class="form-group">
            <label>Notes:</label>
            <textarea name="notes" rows="2"><?php echo htmlspecialchars($booking['notes'] ?? ''); ?></textarea>
        </div>
        
        <h3>Service Items</h3>
        <div id="edit-booking-items-container">
            <?php if (!empty($booking['parsed_items'])): ?>
                <?php foreach ($booking['parsed_items'] as $index => $item): ?>
                <div class="item-row">
                    <div class="row">
                        <input type="hidden" name="items[<?php echo $index; ?>][id]" value="<?php echo $item['id']; ?>">
                        <div class="form-group" style="flex: 2;">
                            <label>Description</label>
                            <input type="text" name="items[<?php echo $index; ?>][description]" value="<?php echo htmlspecialchars($item['description']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Est. Amount (₹)</label>
                            <input type="number" name="items[<?php echo $index; ?>][estimated_amount]" step="0.01" value="<?php echo $item['estimated_amount']; ?>">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <div class="item-row">
                <div class="row">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[0][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[0][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <button type="button" onclick="addBookingItem()" class="btn-secondary">Add Item</button>
        
        <div class="action-buttons" style="margin-top: 30px;">
            <button type="submit">Update Booking</button>
            <a href="?page=view_booking&id=<?php echo $booking_id; ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
    
    <script>
    function addBookingItem() {
        var container = document.getElementById('edit-booking-items-container');
        var index = container.querySelectorAll('.item-row').length;
        
        var html = `
            <div class="item-row">
                <div class="row">
                    <input type="hidden" name="items[${index}][id]" value="0">
                    <div class="form-group" style="flex: 2;">
                        <label>Description</label>
                        <input type="text" name="items[${index}][description]" placeholder="Item description">
                    </div>
                    <div class="form-group">
                        <label>Est. Amount (₹)</label>
                        <input type="number" name="items[${index}][estimated_amount]" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" onclick="removeBookingItem(this)" class="btn-danger" style="padding: 8px 12px;">Remove</button>
                    </div>
                </div>
            </div>
        `;
        
        var div = document.createElement('div');
        div.innerHTML = html;
        container.appendChild(div.firstElementChild);
    }
    
    function removeBookingItem(button) {
        var itemRow = button.closest('.item-row');
        if (itemRow && document.querySelectorAll('#edit-booking-items-container .item-row').length > 1) {
            itemRow.remove();
        }
    }
    
    
    // Add these functions after the existing ones, before the closing </script> tag

function sendBookingEmail(bookingId, email) {
    var modalHtml = `
        <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Send Booking Receipt via Email</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="send_booking_email">
                    <input type="hidden" name="booking_id" value="${bookingId}">
                    
                    <div class="form-group">
                        <label>To Email:</label>
                        <input type="email" name="email" value="${email}" required>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit">Send Email</button>
                        <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    
    var div = document.createElement('div');
    div.innerHTML = modalHtml;
    document.body.appendChild(div.firstElementChild);
}

function sendBookingWhatsApp(bookingId, phone) {
    var modalHtml = `
        <div style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
            <div style="background: white; padding: 30px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Send Booking Receipt via WhatsApp</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="send_booking_whatsapp">
                    <input type="hidden" name="booking_id" value="${bookingId}">
                    
                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="tel" name="phone" value="${phone}" required>
                        <small>Include country code (e.g., 91 for India)</small>
                    </div>
                    
                    <div class="action-buttons">
                        <button type="submit" style="background: #25D366;">Send WhatsApp</button>
                        <button type="button" onclick="this.closest('div[style*=\"position: fixed\"]').remove()" class="btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        </div> ';
    
    var div = document.createElement('div');
    div.innerHTML = modalHtml;
    document.body.appendChild(div.firstElementChild);
}

function confirmDeleteBooking(bookingId, bookingNumber) {
    if (confirm('Are you sure you want to delete booking: ' + bookingNumber + '? This action cannot be undone.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        var actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_booking';
        form.appendChild(actionInput);
        
        var bookingInput = document.createElement('input');
        bookingInput.type = 'hidden';
        bookingInput.name = 'booking_id';
        bookingInput.value = bookingId;
        form.appendChild(bookingInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
    
    </script>
    <?php
}
?>


<?php

// ═══════════════════════════════════════════════════════
// YATRA PAGE FUNCTIONS
// ═══════════════════════════════════════════════════════

function includeYatra() {
    global $db;
    if(!isAdmin()&&!isManager()){echo '<div class="message error">Access denied.</div>';return;}
    // Auto-archive past-closing-date yatras
    $today=date('Y-m-d');
    try{$db->exec("UPDATE yatras SET is_archived=1,status='archived' WHERE closing_date<'$today' AND closing_date!='' AND is_archived=0");}catch(Exception $e){}
    $archived=isset($_GET['archived']);
    $yatras=getAllYatras($archived);
    $cur=getSetting('currency_symbol','₹');
    $edit_id=intval($_GET['edit']??0);
    $ey=$edit_id?getYatraById($edit_id):null;
    ?>
    <h2 class="page-title-h2" style="font-size:22px;font-weight:800;margin-bottom:20px">🕌 Teerth Yatra Management</h2>
    <div style="display:flex;gap:10px;margin-bottom:16px">
        <a href="?page=yatra" class="btn <?php echo !$archived?'':' btn-secondary'; ?>" style="<?php echo !$archived?'background:var(--success);color:#fff':''; ?>">🕌 Active Yatras</a>
        <a href="?page=yatra&archived=1" class="btn-secondary btn" style="<?php echo $archived?'background:var(--warning);color:#fff':''; ?>">📦 Archived</a>
        <a href="?page=create_yatra_booking" class="btn" style="background:var(--primary);color:#fff">➕ New Booking</a>
    </div>

    <div class="card" style="margin-bottom:20px">
        <div class="card-header"><div class="card-title"><?php echo $ey?'Edit Yatra':'Add New Yatra'; ?></div></div>
        <div class="card-body">
        <form method="POST">
        <input type="hidden" name="action" value="save_yatra">
        <?php if($ey): ?><input type="hidden" name="yatra_id" value="<?php echo $ey['id']; ?>"><?php endif; ?>
        <div class="row">
            <div class="form-group"><label>Yatra Name *</label><input type="text" name="yatra_name" value="<?php echo htmlspecialchars($ey['yatra_name']??''); ?>" required></div>
            <div class="form-group"><label>Destination *</label><input type="text" name="destination" value="<?php echo htmlspecialchars($ey['destination']??''); ?>" required></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Departure Date</label><input type="date" name="departure_date" value="<?php echo $ey['departure_date']??''; ?>"></div>
            <div class="form-group"><label>Return Date</label><input type="date" name="return_date" value="<?php echo $ey['return_date']??''; ?>"></div>
            <div class="form-group"><label>Closing Date</label><input type="date" name="closing_date" value="<?php echo $ey['closing_date']??''; ?>"><small>Auto-archives after this date</small></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Per Person Amount (<?php echo $cur; ?>) *</label><input type="number" step="0.01" name="per_person_amount" value="<?php echo floatval($ey['per_person_amount']??0); ?>" required></div>
            <div class="form-group"><label>Total Seats</label><input type="number" name="total_seats" value="<?php echo intval($ey['total_seats']??0); ?>"></div>
            <div class="form-group"><label>Bus Details</label><input type="text" name="bus_details" value="<?php echo htmlspecialchars($ey['bus_details']??''); ?>"></div>
        </div>
        <div class="form-group"><label>Description</label><textarea name="description" rows="2"><?php echo htmlspecialchars($ey['description']??''); ?></textarea></div>
        <div class="action-buttons">
            <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Save Yatra</button>
            <?php if($ey): ?><a href="?page=yatra" class="btn-secondary btn">Cancel</a><?php endif; ?>
        </div>
        </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div class="card-title"><?php echo $archived?'Archived Yatras':'Active Yatras'; ?> (<?php echo count($yatras); ?>)</div></div>
        <div style="overflow-x:auto"><table>
        <thead><tr><th>Yatra Name</th><th>Destination</th><th>Departure</th><th>Per Person</th><th>Seats</th><th>Bookings</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($yatras)): ?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text2)">No yatras found.</td></tr>
        <?php else: foreach($yatras as $y): ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($y['yatra_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($y['destination']); ?></td>
            <td><?php echo $y['departure_date']?date('d-m-Y',strtotime($y['departure_date'])):'—'; ?></td>
            <td><?php echo $cur.' '.number_format($y['per_person_amount'],2); ?></td>
            <td><?php echo $y['total_seats']?$y['total_seats']:'—'; ?></td>
            <td><a href="?page=yatra_bookings&yatra_id=<?php echo $y['id']; ?>"><?php echo intval($y['booking_count']); ?> bookings</a></td>
            <td><span class="user-badge <?php echo $y['is_archived']?'accountant':'manager'; ?>"><?php echo $y['is_archived']?'Archived':'Active'; ?></span></td>
            <td class="actions-cell">
                <a href="?page=create_yatra_booking&yatra_id=<?php echo $y['id']; ?>" class="action-btn view-btn">+ Book</a>
                <a href="?page=yatra_bookings&yatra_id=<?php echo $y['id']; ?>" class="action-btn" style="background:#ede9fe;color:#7c3aed">Bookings</a>
                <a href="?page=yatra&edit=<?php echo $y['id']; ?>" class="action-btn edit-btn">Edit</a>
                <?php if(!$y['is_archived']): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Archive this yatra?')">
                    <input type="hidden" name="action" value="archive_yatra"><input type="hidden" name="yatra_id" value="<?php echo $y['id']; ?>">
                    <button type="submit" class="action-btn" style="background:#fef3c7;color:#d97706">Archive</button>
                </form>
                <?php else: ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="unarchive_yatra"><input type="hidden" name="yatra_id" value="<?php echo $y['id']; ?>">
                    <button type="submit" class="action-btn view-btn">Restore</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        </table></div>
    </div>
<?php }

function includeYatraBookings() {
    global $db;
    $yid=intval($_GET['yatra_id']??0);
    $s=$_GET['search']??'';
    $bookings=getAllYatraBookings($yid,$s);
    $cur=getSetting('currency_symbol','₹');
    $yatra=$yid?getYatraById($yid):null;
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">🚌 Yatra Bookings<?php echo $yatra?' — '.htmlspecialchars($yatra['yatra_name']):''; ?></h2>
    <div style="background:var(--surface);border-radius:var(--radius-sm);border:1px solid var(--border);padding:16px 20px;margin-bottom:16px;box-shadow:var(--shadow)">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="yatra_bookings">
            <?php if($yid): ?><input type="hidden" name="yatra_id" value="<?php echo $yid; ?>"><?php endif; ?>
            <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0"><label>Search (Name, PNR, Ref, Phone)</label><input type="text" name="search" value="<?php echo htmlspecialchars($s); ?>" placeholder="Search..."></div>
            <button type="submit" class="btn">🔍 Search</button>
        </form>
    </div>
    <div class="action-buttons" style="margin-bottom:16px">
        <a href="?page=create_yatra_booking<?php echo $yid?'&yatra_id='.$yid:''; ?>" class="btn" style="background:var(--success);color:#fff">➕ New Booking</a>
        <a href="?page=yatra" class="btn-secondary btn">← Yatras</a>
    </div>
    <div class="card"><div style="overflow-x:auto"><table>
    <thead><tr><th>PNR</th><th>Ref #</th><th>Lead Passenger</th><th>Yatra</th><th>Pax</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(empty($bookings)): ?>
    <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text2)">No bookings found.</td></tr>
    <?php else: foreach($bookings as $bk): ?>
    <tr>
        <td><span style="font-family:monospace;font-size:15px;font-weight:800;letter-spacing:3px;color:var(--primary)"><?php echo htmlspecialchars($bk['pnr']??'—'); ?></span></td>
        <td><?php echo htmlspecialchars($bk['booking_ref']); ?></td>
        <td><?php echo htmlspecialchars($bk['lead_passenger_name']); ?><br><small style="color:var(--text2)"><?php echo htmlspecialchars($bk['phone']??''); ?></small></td>
        <td><?php echo htmlspecialchars($bk['yatra_name']??''); ?></td>
        <td style="text-align:center"><?php echo $bk['total_passengers']; ?></td>
        <td><?php echo $cur.' '.number_format($bk['total_amount'],2); ?></td>
        <td style="color:var(--success)"><?php echo $cur.' '.number_format($bk['amount_paid'],2); ?></td>
        <td style="color:<?php echo floatval($bk['balance'])>0?'var(--danger)':'var(--success)'; ?>"><?php echo $cur.' '.number_format($bk['balance'],2); ?></td>
        <td><span class="payment-badge <?php echo $bk['payment_status']; ?>"><?php echo ucfirst(str_replace('_',' ',$bk['payment_status'])); ?></span></td>
        <td class="actions-cell">
            <a href="?page=view_yatra_booking&id=<?php echo $bk['id']; ?>" class="action-btn view-btn">View</a>
            <a href="?page=edit_yatra_booking&id=<?php echo $bk['id']; ?>" class="action-btn edit-btn">Edit</a>
            <button type="button" onclick="shareYatraBooking(<?php echo $bk['id']; ?>)" class="action-btn" style="background:#dcfce7;color:#15803d">📤 Share</button>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
    </table></div></div>
    <script>
    function shareYatraBooking(id) {
        fetch('?ajax=get_yatra_share&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){if(d.url){navigator.clipboard.writeText(d.url).then(function(){alert('Link copied!\n'+d.url);}).catch(function(){prompt('Copy:',d.url);});}});
    }
    </script>
<?php }

function includeCreateYatraBooking() {
    global $db;
    $pref_yid=intval($_GET['yatra_id']??0);
    $yatras=getAllYatras(false);
    $cur=getSetting('currency_symbol','₹');
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">🚌 New Yatra Booking</h2>
    <form method="POST" id="yatraBookingForm">
    <input type="hidden" name="action" value="create_yatra_booking">
    <input type="hidden" name="passengers_json" id="passengers_json" value="[]">
    <input type="hidden" name="total_passengers_count" id="total_passengers_count" value="1">

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Yatra & Lead Passenger</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Select Yatra *</label>
            <select name="yatra_id" id="yatra_sel" required onchange="fillYatraDefaults(this)">
                <option value="">Choose yatra...</option>
                <?php foreach($yatras as $y): ?>
                <option value="<?php echo $y['id']; ?>" data-ppa="<?php echo floatval($y['per_person_amount']); ?>" data-dep="<?php echo $y['departure_date']??''; ?>"<?php echo $pref_yid==$y['id']?' selected':''; ?>><?php echo htmlspecialchars($y['yatra_name'].' — '.$y['destination']); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="form-group"><label>Booking Date</label><input type="date" name="booking_date" value="<?php echo date('Y-m-d'); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Lead Passenger Name *</label><input type="text" name="lead_passenger_name" required></div>
            <div class="form-group"><label>Phone *</label><input type="tel" name="phone" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Address</label><input type="text" name="address"></div>
            <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name"></div>
            <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact"></div>
        </div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <div class="card-title">Passenger Details</div>
            <button type="button" onclick="addPassenger()" class="btn" style="padding:6px 14px;font-size:12px;background:var(--success);color:#fff">+ Add Passenger</button>
        </div>
        <div class="card-body" style="padding:0">
        <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>Name *</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th><th></th></tr></thead>
        <tbody id="passengers_tbody"></tbody>
        </table></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Payment Details</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Per Person Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="per_person_amount" id="per_person_amount" value="0" oninput="updateYatraTotal()"></div>
            <div class="form-group"><label>Total Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="total_amount" id="total_amount_field" value="0"></div>
            <div class="form-group"><label>Advance / Booking Amount</label><input type="number" step="0.01" name="booking_amount" value="0"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Payment Method</label>
            <select name="payment_method">
                <?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>'; ?>
            </select></div>
            <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
        <div class="action-buttons">
            <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Create Booking</button>
            <a href="?page=yatra_bookings" class="btn-secondary btn">Cancel</a>
        </div>
        </div>
    </div>
    </form>
    <script>
    var yatraPassengers = [{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
    renderYatraPassengers();

    function addPassenger() {
        yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});
        renderYatraPassengers();
    }
    function removePassenger(i) {
        if(yatraPassengers.length > 1) { yatraPassengers.splice(i,1); renderYatraPassengers(); }
    }
    function renderYatraPassengers() {
        var tb = document.getElementById('passengers_tbody');
        if(!tb) return;
        tb.innerHTML = '';
        yatraPassengers.forEach(function(p, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>'+(i+1)+'</td>'
                +'<td><input style="width:140px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
                +'<td><input type="number" style="width:60px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.a||'')+'\x22 oninput="yatraPassengers['+i+'].a=this.value"></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value">'
                    +['Male','Female','Other'].map(function(g){return '<option'+(p.g===g?' selected':'')+'>'+g+'</option>';}).join('')
                +'</select></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value">'
                    +['Aadhaar','PAN','Voter ID','Passport','Other'].map(function(t){return '<option'+(p.ipt===t?' selected':'')+'>'+t+'</option>';}).join('')
                +'</select></td>'
                +'<td><input style="width:130px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
                +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
            tb.appendChild(tr);
        });
        document.getElementById('passengers_json').value = JSON.stringify(yatraPassengers.map(function(p){
            return {name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};
        }));
        document.getElementById('total_passengers_count').value = yatraPassengers.length;
        updateYatraTotal();
    }
    function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||'\x27));return d.innerHTML;}
    function fillYatraDefaults(sel) {
        var opt = sel.options[sel.selectedIndex];
        if(opt.value) {
            document.getElementById('per_person_amount').value = opt.dataset.ppa||0;
            updateYatraTotal();
        }
    }
    function updateYatraTotal() {
        var ppa = parseFloat(document.getElementById('per_person_amount').value)||0;
        var cnt = yatraPassengers.length||1;
        document.getElementById('total_amount_field').value = (ppa*cnt).toFixed(2);
    }
    <?php if($pref_yid): ?>
    document.addEventListener('DOMContentLoaded',function(){
        var sel=document.getElementById('yatra_sel');if(sel)fillYatraDefaults(sel);
    });
    <?php endif; ?>
    </script>
<?php }

function includeViewYatraBooking() {
    global $db;
    $id=intval($_GET['id']??0);
    $bk=getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Booking not found.</div>';return;}
    $cur=getSetting('currency_symbol','₹');
    $bal=floatval($bk['total_amount'])-floatval($bk['amount_paid']);
    $upi=generateUPILink($bal,$bk['booking_ref'],'Yatra: '.$bk['booking_ref']);
    $qr_url=$bal>0?generateQRCode($upi,160):'';
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    // QR verify token
    $vtok=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='yatra' AND doc_id=$id AND is_active=1 LIMIT 1");
    if(!$vtok) $vtok=generateVerifyToken('yatra',$id,$bk['pnr']??$bk['booking_ref'],'Yatra Booking '.$bk['booking_ref']);
    $vurl=getVerifyUrl($vtok);
    ?>
    <div class="action-buttons no-print" style="margin-bottom:16px">
        <a href="?page=yatra_bookings" class="btn-secondary btn">← Back</a>
        <a href="?page=edit_yatra_booking&id=<?php echo $id; ?>" class="btn" style="background:var(--warning);color:#fff">✏️ Edit</a>
        <button onclick="window.print()" class="btn" style="background:var(--success);color:#fff">🖨️ Print Ticket</button>
        <button onclick="shareYatraBk(<?php echo $id; ?>)" class="btn" style="background:#25D366;color:#fff">📤 Share</button>
        <button onclick="copyLink('<?php echo htmlspecialchars($vurl); ?>')" class="btn btn-secondary" style="font-size:12px">🔍 Verify QR Link</button>
    </div>

    <div class="invoice-preview" id="yatra_print_area">
        <div class="invoice-header">
            <div class="company-info">
                <?php $lp=getSetting('logo_path'); if($lp&&file_exists($lp)): ?>
                <img src="<?php echo htmlspecialchars($lp); ?>" style="max-height:70px;max-width:150px;margin-bottom:6px"><br>
                <?php endif; ?>
                <h2 style="font-size:18px;font-weight:800;color:#1e293b"><?php echo htmlspecialchars(getSetting('company_name','D K ASSOCIATES')); ?></h2>
                <div style="font-size:12px;color:#555;line-height:1.7;margin-top:4px">
                    <?php if($ph=getSetting('office_phone')) echo '📞 '.htmlspecialchars($ph).'<br>'; ?>
                    <?php if($em=getSetting('company_email')) echo '✉️ '.htmlspecialchars($em).'<br>'; ?>
                </div>
            </div>
            <div class="invoice-meta">
                <div style="font-size:20px;font-weight:800;margin-bottom:8px">🚌 YATRA TICKET</div>
                <?php if(!empty($bk['pnr'])): ?>
                <div style="background:#1e1b4b;color:#fff;padding:10px 16px;border-radius:10px;display:inline-block;margin:8px 0;text-align:center">
                    <div style="font-size:10px;letter-spacing:1px;opacity:.8;margin-bottom:2px">PNR</div>
                    <div style="font-family:monospace;font-size:24px;font-weight:800;letter-spacing:5px"><?php echo htmlspecialchars($bk['pnr']); ?></div>
                </div><br>
                <?php endif; ?>
                <div><strong>Ref #:</strong> <?php echo htmlspecialchars($bk['booking_ref']); ?></div>
                <div><strong>Booking Date:</strong> <?php echo $bk['booking_date']?date('d-m-Y',strtotime($bk['booking_date'])):''; ?></div>
                <?php if(!empty($bk['departure_date'])): ?>
                <div><strong>Departure:</strong> <?php echo date('d-m-Y',strtotime($bk['departure_date'])); ?></div>
                <?php endif; ?>
                <div style="margin-top:8px;text-align:center">
                    <img src="<?php echo htmlspecialchars(generateQRCode($vurl,80)); ?>" width="80" alt="Verify QR">
                    <div style="font-size:9px;color:#888;margin-top:2px">Scan to verify</div>
                </div>
            </div>
        </div>

        <div class="customer-info">
            <div style="display:flex;gap:24px;flex-wrap:wrap">
                <div>
                    <strong style="display:block;margin-bottom:4px">Lead Passenger:</strong>
                    <strong><?php echo htmlspecialchars($bk['lead_passenger_name']); ?></strong><br>
                    <?php if($bk['phone']) echo '📞 '.htmlspecialchars($bk['phone']).' <br>'; ?>
                    <?php if($bk['email']) echo '✉️ '.htmlspecialchars($bk['email']).' <br>'; ?>
                    <?php if($bk['address']) echo '📍 '.htmlspecialchars($bk['address']); ?>
                </div>
                <div>
                    <strong style="display:block;margin-bottom:4px">Yatra Details:</strong>
                    <strong><?php echo htmlspecialchars($bk['yatra_name']??''); ?></strong><br>
                    <?php if($bk['destination']) echo '📍 '.$bk['destination'].'<br>'; ?>
                    <?php if(!empty($bk['bus_details'])) echo '🚌 '.htmlspecialchars($bk['bus_details']).' <br>'; ?>
                    <?php if($bk['return_date']) echo 'Return: '.date('d-m-Y',strtotime($bk['return_date'])); ?>
                </div>
                <?php if(!empty($bk['emergency_contact'])): ?>
                <div>
                    <strong>Emergency Contact:</strong><br>
                    <?php echo htmlspecialchars($bk['emergency_contact_name']??''); ?><br>
                    <?php echo htmlspecialchars($bk['emergency_contact']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if(!empty($bk['passengers'])): ?>
        <h3 style="font-size:14px;font-weight:700;margin:16px 0 8px">👥 Passengers (<?php echo count($bk['passengers']); ?>)</h3>
        <table style="margin-bottom:16px">
            <thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th></tr></thead>
            <tbody>
            <?php foreach($bk['passengers'] as $i=>$p): ?>
            <tr>
                <td><?php echo $i+1; ?></td>
                <td><?php echo htmlspecialchars($p['name']); ?></td>
                <td><?php echo $p['age']?$p['age']:'—'; ?></td>
                <td><?php echo htmlspecialchars($p['gender']??''); ?></td>
                <td><?php echo htmlspecialchars($p['id_proof_type']??''); ?></td>
                <td><?php echo htmlspecialchars($p['id_proof_number']??''); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <table style="width:260px;font-size:13px">
            <tr><td style="padding:3px 8px;color:var(--text2)">Total Amount</td><td style="padding:3px 8px;font-weight:700;text-align:right"><?php echo $cur.' '.number_format($bk['total_amount'],2); ?></td></tr>
            <tr><td style="padding:3px 8px;color:var(--success)">Amount Paid</td><td style="padding:3px 8px;font-weight:700;text-align:right;color:var(--success)"><?php echo $cur.' '.number_format($bk['amount_paid'],2); ?></td></tr>
            <?php if($bal>0): ?>
            <tr style="border-top:2px solid var(--border)"><td style="padding:5px 8px;font-size:15px;font-weight:800;color:var(--danger)">Balance Due</td><td style="padding:5px 8px;font-size:15px;font-weight:800;text-align:right;color:var(--danger)"><?php echo $cur.' '.number_format($bal,2); ?></td></tr>
            <?php endif; ?>
        </table>
        </div>

        <?php if($bal>0&&$qr_url): ?>
        <div style="text-align:center;margin:16px 0" class="no-print">
            <p style="font-size:12px;font-weight:600;margin-bottom:6px">Scan to Pay <?php echo $cur.' '.number_format($bal,2); ?></p>
            <img src="<?php echo htmlspecialchars($qr_url); ?>" width="150" alt="Payment QR">
        </div>
        <?php endif; ?>

        <?php $pn=getSetting('payment_note'); if($pn): ?>
        <div style="background:#fffbeb;border-left:4px solid var(--warning);padding:10px 14px;border-radius:6px;font-size:12px;margin:12px 0"><?php echo htmlspecialchars($pn); ?></div>
        <?php endif; ?>
    </div>

    <?php if(!empty($bk['payments'])): ?>
    <div class="card no-print" style="margin-top:16px">
        <div class="card-header"><div class="card-title">Payment History</div></div>
        <div class="card-body" style="padding:0"><table>
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Txn ID</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($bk['payments'] as $p): ?>
        <tr>
            <td><?php echo date('d-m-Y',strtotime($p['payment_date'])); ?></td>
            <td><?php echo $cur.' '.number_format($p['amount'],2); ?></td>
            <td><?php echo htmlspecialchars($p['payment_method']??''); ?></td>
            <td><?php echo htmlspecialchars($p['transaction_id']??''); ?></td>
            <td>
                <?php if(isAdmin()||isManager()): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete_yatra_payment">
                    <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                    <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
                    <button type="submit" class="action-btn delete-btn">Del</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="card no-print" style="margin-top:16px">
        <div class="card-header"><div class="card-title">Add Payment</div></div>
        <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="add_yatra_payment">
            <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
            <div class="row">
                <div class="form-group"><label>Date</label><input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>"></div>
                <div class="form-group"><label>Amount (<?php echo $cur; ?>)</label><input type="number" step="0.01" name="amount" value="<?php echo max(0,$bal); ?>"></div>
                <div class="form-group"><label>Method</label><select name="payment_method"><?php foreach($methods as $m) echo '<option>'.htmlspecialchars($m).'</option>'; ?></select></div>
                <div class="form-group"><label>Transaction ID</label><input type="text" name="transaction_id"></div>
                <div class="form-group"><label>Notes</label><input type="text" name="notes"></div>
                <div class="form-group" style="display:flex;align-items:flex-end"><button type="submit" class="btn" style="background:var(--success);color:#fff">Add Payment</button></div>
            </div>
        </form>
        </div>
    </div>
    <script>
    function shareYatraBk(id) {
        fetch('?ajax=get_yatra_share&id='+id)
        .then(function(r){return r.json();})
        .then(function(d){if(d.url){navigator.clipboard.writeText(d.url).then(function(){alert('Link copied!\n'+d.url);}).catch(function(){prompt('Copy:',d.url);});}});
    }
    function copyLink(url){navigator.clipboard.writeText(url).then(function(){alert('Link copied!\n'+url);}).catch(function(){prompt('Copy:',url);});}
    </script>
<?php }

function includeEditYatraBooking() {
    global $db;
    $id=intval($_GET['id']??0);
    $bk=getYatraBookingById($id);
    if(!$bk){echo '<div class="message error">Not found.</div>';return;}
    $methods=explode(',',getSetting('payment_methods','Cash,UPI,Bank Transfer,Card,Cheque'));
    $passJson=json_encode(array_map(function($p){return['n'=>$p['name'],'a'=>$p['age']??'','g'=>$p['gender']??'Male','ipt'=>$p['id_proof_type']??'Aadhaar','ipn'=>$p['id_proof_number']??''];},$bk['passengers']));
    ?>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:20px">✏️ Edit Yatra Booking: <?php echo htmlspecialchars($bk['booking_ref']); ?></h2>
    <form method="POST">
    <input type="hidden" name="action" value="update_yatra_booking">
    <input type="hidden" name="booking_id" value="<?php echo $id; ?>">
    <input type="hidden" name="passengers_json" id="passengers_json" value="<?php echo htmlspecialchars($passJson); ?>">
    <input type="hidden" name="total_passengers_count" id="total_passengers_count" value="<?php echo count($bk['passengers']); ?>">

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><div class="card-title">Booking Details</div></div>
        <div class="card-body">
        <div class="row">
            <div class="form-group"><label>Lead Passenger</label><input type="text" name="lead_passenger_name" value="<?php echo htmlspecialchars($bk['lead_passenger_name']); ?>"></div>
            <div class="form-group"><label>Phone</label><input type="tel" name="phone" value="<?php echo htmlspecialchars($bk['phone']??''); ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($bk['email']??''); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Address</label><input type="text" name="address" value="<?php echo htmlspecialchars($bk['address']??''); ?>"></div>
            <div class="form-group"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($bk['emergency_contact_name']??''); ?>"></div>
            <div class="form-group"><label>Emergency Contact Phone</label><input type="tel" name="emergency_contact" value="<?php echo htmlspecialchars($bk['emergency_contact']??''); ?>"></div>
        </div>
        <div class="row">
            <div class="form-group"><label>Total Amount</label><input type="number" step="0.01" name="total_amount" value="<?php echo $bk['total_amount']; ?>"></div>
            <div class="form-group"><label>Booking Amount</label><input type="number" step="0.01" name="booking_amount" value="<?php echo $bk['booking_amount']??0; ?>"></div>
        </div>
        <div class="form-group"><label>Notes</label><textarea name="notes" rows="2"><?php echo htmlspecialchars($bk['notes']??''); ?></textarea></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <div class="card-title">Passengers</div>
            <button type="button" onclick="addPassenger()" class="btn" style="padding:6px 14px;font-size:12px;background:var(--success);color:#fff">+ Add</button>
        </div>
        <div class="card-body" style="padding:0">
        <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Proof Type</th><th>ID Proof No.</th><th></th></tr></thead>
        <tbody id="passengers_tbody"></tbody>
        </table></div>
        </div>
    </div>

    <div class="action-buttons">
        <button type="submit" class="btn" style="background:var(--success);color:#fff">💾 Save Changes</button>
        <a href="?page=view_yatra_booking&id=<?php echo $id; ?>" class="btn-secondary btn">Cancel</a>
    </div>
    </form>
    <script>
    var rawPass = <?php echo $passJson; ?>;
    var yatraPassengers = rawPass.map(function(p){return{n:p.n,a:p.a||'',g:p.g||'Male',ipt:p.ipt||'Aadhaar',ipn:p.ipn||''};});
    if(!yatraPassengers.length) yatraPassengers=[{n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''}];
    renderYatraPassengers();

    function addPassenger(){yatraPassengers.push({n:'',a:'',g:'Male',ipt:'Aadhaar',ipn:''});renderYatraPassengers();}
    function removePassenger(i){if(yatraPassengers.length>1){yatraPassengers.splice(i,1);renderYatraPassengers();}}
    function renderYatraPassengers(){
        var tb=document.getElementById('passengers_tbody');if(!tb)return;
        tb.innerHTML='';
        yatraPassengers.forEach(function(p,i){
            var tr=document.createElement('tr');
            tr.innerHTML='<td>'+(i+1)+'</td>'
                +'<td><input style="width:130px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.n)+'" oninput="yatraPassengers['+i+'].n=this.value"></td>'
                +'<td><input type="number" style="width:55px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.a||'')+'\x22 oninput="yatraPassengers['+i+'].a=this.value"></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].g=this.value">'
                    +['Male','Female','Other'].map(function(g){return '<option'+(p.g===g?' selected':'')+'>'+g+'</option>';}).join('')
                +'</select></td>'
                +'<td><select style="padding:6px;border:1px solid #ddd;border-radius:4px" onchange="yatraPassengers['+i+'].ipt=this.value">'
                    +['Aadhaar','PAN','Voter ID','Passport','Other'].map(function(t){return '<option'+(p.ipt===t?' selected':'')+'>'+t+'</option>';}).join('')
                +'</select></td>'
                +'<td><input style="width:120px;padding:6px;border:1px solid #ddd;border-radius:4px" value="'+escH(p.ipn)+'" oninput="yatraPassengers['+i+'].ipn=this.value"></td>'
                +'<td><button type="button" onclick="removePassenger('+i+')" style="padding:4px 8px;background:#fee2e2;color:#dc2626;border:none;border-radius:4px;cursor:pointer">✕</button></td>';
            tb.appendChild(tr);
        });
        document.getElementById('passengers_json').value=JSON.stringify(yatraPassengers.map(function(p){return{name:p.n,age:p.a,gender:p.g,id_proof_type:p.ipt,id_proof_number:p.ipn};}));
        document.getElementById('total_passengers_count').value=yatraPassengers.length;
    }
    function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||'')); return d.innerHTML;}
    </script>
<?php }
?>

<?php ob_end_flush(); ?>
