<?php
// AgroBusiness Malawi - Complete API Endpoints
// Place this file at: /home/p601229/public_html/agrobusinessmw/api.php

// --- CRITICAL FIX 1: ERROR CATCHING & JSON HEADERS ---
// This ensures even a crash returns readable JSON
http_response_code(200); // Force OK so frontend can read the error message
register_shutdown_function(function() {
    $error = error_get_last();
    // Catch hard crashes (Fatal Errors)
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        if (ob_get_length()) ob_clean(); // Clear any partial HTML output
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'Fatal PHP Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit;
    }
});

// Disable HTML error printing (breaks JSON)
ini_set('display_errors', 0); 
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Set JSON header & CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// --- SMART ENVIRONMENT DETECTION ---
// Method 1: Check request header (from JavaScript)
$requestedEnvironment = $_GET['env'] ?? $_SERVER['HTTP_X_ENVIRONMENT'] ?? 'production';

// Method 2: Automatic detection by domain
$currentDomain = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = ($currentDomain === 'localhost' || 
           $currentDomain === '127.0.0.1' || 
           strpos($currentDomain, '.local') !== false);

if ($isLocal || $requestedEnvironment === 'local') {
    // LOCAL DEVELOPMENT - Connect to Remote Server
    $host = '185.43.232.18'; // Your server IP for remote MySQL
    error_log('🌐 Environment: Local -> Remote Server');
} else {
    // PRODUCTION/STAGING - Connect to Localhost
    $host = 'localhost';
    error_log('🌐 Environment: Production');
}


// --- CRITICAL FIX 2: SMART HOST CONNECTION ---
// Automatically detects if you are on Laptop (Local) or Server (CPanel)
$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1');

$username = 'p601229';
$password = '2:p2WpmX[0YTs7';
$database = 'p601229_AgroBusiness_MW';

if ($is_local) {
    // 🛑 RUNNING LOCALLY: Connect to Remote Server using IP
    $host = '185.43.232.18';  // Replace with your actual server IP
} else {
    // 🟢 RUNNING ON SERVER: Connect to Localhost
    $host = 'localhost';
}

// Connect to database
try {
    if (!class_exists('mysqli')) {
        throw new Exception("Critical Error: MySQLi extension is not loaded in php.ini.");
    }

    $mysqli = mysqli_init();
    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10); // 10s Timeout
    
    // Suppress warnings to handle errors manually
    if (!@$mysqli->real_connect($host, $username, $password, $database)) {
        throw new Exception("Connect Failed: " . mysqli_connect_error());
    }

    $mysqli->set_charset('utf8mb4');
    
    // Log successful connection
    error_log('✅ Database connected successfully');
    
} catch (Exception $e) {
    ob_clean();
    // Return 200 OK with error details so the App can display it
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'hint' => $is_local ? "Is your IP whitelisted in CPanel > Remote MySQL?" : "Check database credentials.",
        'environment' => $is_local ? 'Local -> Remote' : 'Production',
        'timestamp' => date('c')
    ]);
    exit;
}

// Get action parameter
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'test':
            // Test database connection
            $result = $mysqli->query("SELECT COUNT(*) as count FROM districts");
            if ($result) {
                $row = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'message' => 'Database connection successful',
                    'districts_count' => $row['count'],
                    'environment' => $is_local ? 'Local -> Remote' : 'Production',
                    'timestamp' => date('c')
                ]);
            } else {
                throw new Exception('Test query failed');
            }
            break;
            
        case 'districts':
            // Get all districts
            $query = "SELECT id, name FROM districts ORDER BY name ASC";
            $result = $mysqli->query($query);
            
            if (!$result) {
                throw new Exception('Districts query failed: ' . $mysqli->error);
            }
            
            $districts = [];
            while ($row = $result->fetch_assoc()) {
                $districts[] = $row;
            }
            
            error_log('📍 Districts loaded: ' . count($districts));
            
            echo json_encode([
                'success' => true,
                'data' => $districts,
                'count' => count($districts),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'crops':
            // Get all crops
            $query = "SELECT id, name FROM crops ORDER BY name ASC";
            $result = $mysqli->query($query);
            
            if (!$result) {
                throw new Exception('Crops query failed: ' . $mysqli->error);
            }
            
            $crops = [];
            while ($row = $result->fetch_assoc()) {
                $crops[] = $row;
            }
            
            error_log('🌾 Crops loaded: ' . count($crops));
            
            echo json_encode([
                'success' => true,
                'data' => $crops,
                'count' => count($crops),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'crop_prices':
            // Get crop prices
            $query = "
                SELECT 
                    c.id,
                    c.name,
                    COALESCE(cp.min_price, '') AS min_price,
                    COALESCE(cp.market_price, '') AS market_price,
                    COALESCE(cp.unit, 'kg') AS unit
                FROM crops c
                LEFT JOIN crop_prices cp ON c.id = cp.crop_id
                ORDER BY c.name ASC
            ";
            
            $result = $mysqli->query($query);
            
            if (!$result) {
                throw new Exception('Crop prices query failed: ' . $mysqli->error);
            }
            
            $crops = [];
            while ($row = $result->fetch_assoc()) {
                $crops[] = $row;
            }
            
            error_log('💰 Crop prices loaded: ' . count($crops));
            
            echo json_encode([
                'success' => true,
                'data' => $crops,
                'count' => count($crops),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'market_insights':
            // Get market insights for a district
            $district_id = (int)($_GET['district_id'] ?? 0);
            
            if (!$district_id) {
                throw new Exception('District ID is required');
            }
            
            $query = "
                SELECT 
                    mi.id,
                    mi.district_id,
                    d.name as district_name,
                    mi.insight_en,
                    mi.insight_ci
                FROM market_insights mi
                JOIN districts d ON mi.district_id = d.id
                WHERE mi.district_id = ?
                ORDER BY mi.id DESC
            ";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $district_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $insights = [];
            while ($row = $result->fetch_assoc()) {
                $insights[] = $row;
            }
            
            error_log('📊 Market insights loaded: ' . count($insights) . ' for district ' . $district_id);
            
            echo json_encode([
                'success' => true,
                'data' => $insights,
                'count' => count($insights),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'sellers':
            // Get sellers for a district
            $district_id = (int)($_GET['district_id'] ?? 0);
            
            if (!$district_id) {
                throw new Exception('District ID is required');
            }
            
            $query = "
                SELECT 
                    s.id,
                    s.name,
                    s.district_id,
                    d.name as district_name,
                    scd.phone_number,
                    scd.email,
                    scd.address,
                    GROUP_CONCAT(c.name SEPARATOR ', ') as crops_display,
                    ROUND(AVG(r.rating_value), 1) as rating
                FROM sellers s
                JOIN districts d ON s.district_id = d.id
                JOIN seller_contact_details scd ON s.contact_id = scd.id
                LEFT JOIN seller_crops sc ON s.id = sc.seller_id
                LEFT JOIN crops c ON sc.crop_id = c.id
                LEFT JOIN ratings r ON s.id = r.seller_id
                WHERE s.district_id = ?
                GROUP BY s.id
                ORDER BY s.name ASC
            ";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $district_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $sellers = [];
            while ($row = $result->fetch_assoc()) {
                $sellers[] = $row;
            }
            
            error_log('👨‍🌾 Sellers loaded: ' . count($sellers) . ' for district ' . $district_id);
            
            echo json_encode([
                'success' => true,
                'data' => $sellers,
                'count' => count($sellers),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'buyers':
            // Get buyers for a district
            $district_id = (int)($_GET['district_id'] ?? 0);
            
            if (!$district_id) {
                throw new Exception('District ID is required');
            }
            
            $query = "
                SELECT 
                    b.id,
                    b.name,
                    b.district_id,
                    d.name as district_name,
                    bcd.phone_number,
                    bcd.email,
                    bcd.address,
                    GROUP_CONCAT(c.name SEPARATOR ', ') as crops_display
                FROM buyers b
                JOIN districts d ON b.district_id = d.id
                JOIN buyer_contact_details bcd ON b.contact_id = bcd.id
                LEFT JOIN buyer_crops bc ON b.id = bc.buyer_id
                LEFT JOIN crops c ON bc.crop_id = c.id
                WHERE b.district_id = ?
                GROUP BY b.id
                ORDER BY b.name ASC
            ";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $district_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $buyers = [];
            while ($row = $result->fetch_assoc()) {
                $buyers[] = $row;
            }
            
            error_log('🏢 Buyers loaded: ' . count($buyers) . ' for district ' . $district_id);
            
            echo json_encode([
                'success' => true,
                'data' => $buyers,
                'count' => count($buyers),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'pest_control':
            // Get pest control tips
            $crop_id = (int)($_GET['crop_id'] ?? 0);
            $district_id = (int)($_GET['district_id'] ?? 0);
            
            if (!$crop_id || !$district_id) {
                throw new Exception('Crop ID and District ID are required');
            }
            
            $query = "
                SELECT 
                    pct.id,
                    pct.crop_id,
                    pct.district_id,
                    c.name as crop_name,
                    d.name as district_name,
                    pct.tip_en,
                    pct.tip_ci
                FROM pest_control_tips pct
                JOIN crops c ON pct.crop_id = c.id
                JOIN districts d ON pct.district_id = d.id
                WHERE pct.crop_id = ? AND pct.district_id = ?
                ORDER BY pct.id ASC
            ";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('ii', $crop_id, $district_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $tips = [];
            while ($row = $result->fetch_assoc()) {
                $tips[] = $row;
            }
            
            error_log('🐛 Pest control tips loaded: ' . count($tips) . ' for crop ' . $crop_id . ', district ' . $district_id);
            
            echo json_encode([
                'success' => true,
                'data' => $tips,
                'count' => count($tips),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'farming_tips':
            // Get farming tips for a crop
            $crop_id = (int)($_GET['crop_id'] ?? 0);
            
            if (!$crop_id) {
                throw new Exception('Crop ID is required');
            }
            
            $query = "
                SELECT 
                    fbp.id,
                    fbp.crop_id,
                    c.name as crop_name,
                    fbp.practice_type,
                    fbp.practice_en,
                    fbp.practice_ci
                FROM farming_best_practices fbp
                JOIN crops c ON fbp.crop_id = c.id
                WHERE fbp.crop_id = ?
                ORDER BY fbp.practice_type ASC
            ";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param('i', $crop_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $practices = [];
            while ($row = $result->fetch_assoc()) {
                $practices[] = $row;
            }
            
            error_log('🌾 Farming tips loaded: ' . count($practices) . ' for crop ' . $crop_id);
            
            echo json_encode([
                'success' => true,
                'data' => $practices,
                'count' => count($practices),
                'timestamp' => date('c')
            ]);
            break;
            
        case 'basic_info':
            // Get basic farming information
            $query = "
                SELECT 
                    id,
                    topic,
                    info_en,
                    info_ci
                FROM basic_farming_info
                ORDER BY id ASC
            ";
            
            $result = $mysqli->query($query);
            
            if (!$result) {
                throw new Exception('Basic info query failed: ' . $mysqli->error);
            }
            
            $info = [];
            while ($row = $result->fetch_assoc()) {
                $info[] = $row;
            }
            
            error_log('📚 Basic info loaded: ' . count($info));
            
            echo json_encode([
                'success' => true,
                'data' => $info,
                'count' => count($info),
                'timestamp' => date('c')
            ]);
            break;
            
        default:
            throw new Exception('Invalid action specified. Available actions: test, districts, crops, crop_prices, market_insights, sellers, buyers, pest_control, farming_tips, basic_info');
    }
    
} catch (Exception $e) {
    error_log('❌ API Error: ' . $e->getMessage());
    ob_clean();
    
    // --- CRITICAL FIX 3: RETURN 200 ON ERROR ---
    // Return 200 instead of 500 so the frontend can display the message
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'action' => $action,
        'timestamp' => date('c')
    ]);
}

// Close database connection
if (isset($mysqli)) {
    $mysqli->close();
}



?>