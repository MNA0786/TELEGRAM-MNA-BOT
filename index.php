<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -------------------- CONFIG --------------------
// SECURITY: Sab kuch environment se load karo
define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN') ?: '8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU');
define('API_ID', $_ENV['API_ID'] ?? getenv('API_ID') ?: '21944581');
define('API_HASH', $_ENV['API_HASH'] ?? getenv('API_HASH') ?: '7b1c174a5cd3466e25a976c39a791737');
define('ADMIN_ID', $_ENV['ADMIN_ID'] ?? getenv('ADMIN_ID') ?: '1080317415');
define('BOT_USERNAME', $_ENV['BOT_USERNAME'] ?? getenv('BOT_USERNAME') ?: '@EntertainmentTadkaBot');

// Public Channels Configuration
define('PUBLIC_CHANNELS', [
    ['id' => -1003181705395, 'name' => 'Main Channel', 'username' => '@EntertainmentTadka786', 'header' => 'on'],
    ['id' => -1003614546520, 'name' => 'Serial Channel', 'username' => '@Entertainment_Tadka_Serial_786', 'header' => 'on'],
    ['id' => -1002831605258, 'name' => 'Theater Prints', 'username' => '@threater_print_movies', 'header' => 'on'],
    ['id' => -1002964109368, 'name' => 'Backup Channel', 'username' => '@ETBackup', 'header' => 'on']
]);

// Private Channels (For internal use)
define('PRIVATE_CHANNELS', [
    ['id' => -1003251791991, 'name' => 'Private Channel 1', 'header' => 'off'],
    ['id' => -1002337293281, 'name' => 'Private Channel 2', 'header' => 'off']
]);

// Request Group
define('REQUEST_GROUP_USERNAME', $_ENV['REQUEST_GROUP_USERNAME'] ?? getenv('REQUEST_GROUP_USERNAME') ?: '@EntertainmentTadka7860');
define('REQUEST_GROUP_ID', $_ENV['REQUEST_GROUP_ID'] ?? getenv('REQUEST_GROUP_ID') ?: -1003083386043);

// CSV Format LOCKED - PERMANENT
define('CSV_FILE', $_ENV['CSV_FILE'] ?? getenv('CSV_FILE') ?: 'movies.csv');
define('CSV_FORMAT', ['movie_name', 'message_id', 'channel_id']); // FORMAT LOCKED - DO NOT CHANGE
define('USERS_FILE', $_ENV['USERS_FILE'] ?? getenv('USERS_FILE') ?: 'users.json');
define('STATS_FILE', $_ENV['STATS_FILE'] ?? getenv('STATS_FILE') ?: 'bot_stats.json');
define('REQUESTS_FILE', 'requests.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);
define('ITEMS_PER_PAGE', 5);
define('DAILY_REQUEST_LIMIT', 3);
// ------------------------------------------------

// File initialization
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
    @chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    validate_csv_format();
    @chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 
        'total_users' => 0, 
        'total_searches' => 0, 
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0666);
}

if (!file_exists(REQUESTS_FILE)) {
    file_put_contents(REQUESTS_FILE, json_encode(['pending' => [], 'approved' => []]));
    @chmod(REQUESTS_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0777, true);
}

// Validate CSV Format on every start
validate_csv_format();

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();
$broadcast_states = array();

// ==============================
// CSV FORMAT VALIDATION - LOCKED
// ==============================
function validate_csv_format() {
    $expected = CSV_FORMAT;
    
    if (!file_exists(CSV_FILE)) {
        $handle = fopen(CSV_FILE, 'w');
        fputcsv($handle, $expected);
        fclose($handle);
        return true;
    }
    
    $handle = fopen(CSV_FILE, 'r');
    $header = fgetcsv($handle);
    fclose($handle);
    
    if ($header !== $expected) {
        // Auto backup corrupted file
        $backup = CSV_FILE . '.backup.' . date('Y-m-d_H-i-s');
        copy(CSV_FILE, $backup);
        
        // Read all existing data
        $data = [];
        $handle = fopen(CSV_FILE, 'r');
        $old_header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $data[] = [
                    'movie_name' => $row[0] ?? 'Unknown',
                    'message_id' => $row[1] ?? '',
                    'channel_id' => $row[2] ?? PUBLIC_CHANNELS[0]['id']
                ];
            }
        }
        fclose($handle);
        
        $handle = fopen(CSV_FILE, 'w');
        fputcsv($handle, $expected);
        foreach ($data as $row) {
            fputcsv($handle, [$row['movie_name'], $row['message_id'], $row['channel_id']]);
        }
        fclose($handle);
        
        error_log("⚠️ CSV format fixed! Old file backed up to: $backup");
    }
}

// ==============================
// CHANNEL FUNCTIONS
// ==============================
function get_all_channels() {
    return [
        'public' => PUBLIC_CHANNELS,
        'private' => PRIVATE_CHANNELS
    ];
}

function get_channel_info($channel_id) {
    foreach (array_merge(PUBLIC_CHANNELS, PRIVATE_CHANNELS) as $channel) {
        if ($channel['id'] == $channel_id) {
            return $channel;
        }
    }
    return null;
}

function get_channel_display_name($channel_id) {
    $info = get_channel_info($channel_id);
    return $info ? $info['name'] : 'Unknown Channel';
}

function get_channel_link($channel_id) {
    $info = get_channel_info($channel_id);
    return isset($info['username']) ? 'https://t.me/' . substr($info['username'], 1) : null;
}

function channel_header_status($channel_id) {
    foreach (PUBLIC_CHANNELS as $ch) {
        if ($ch['id'] == $channel_id) return $ch['header'];
    }
    foreach (PRIVATE_CHANNELS as $ch) {
        if ($ch['id'] == $channel_id) return $ch['header'];
    }
    return 'off';
}

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    
    validate_csv_format();
    
    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $channel_id = isset($row[2]) ? trim($row[2]) : PUBLIC_CHANNELS[0]['id'];

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'channel_id' => $channel_id
                ];
                
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('sendMessage', $data);
    return json_decode($result, true);
}

function sendTypingAction($chat_id) {
    apiRequest('sendChatAction', [
        'chat_id' => $chat_id,
        'action' => 'typing'
    ]);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $result = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return $result;
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null, $parse_mode = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        if ($parse_mode) $data['parse_mode'] = $parse_mode;
        apiRequest('editMessageText', $data);
    }
}

// ==============================
// DELIVERY FUNCTIONS
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    $channel_id = $item['channel_id'] ?? PUBLIC_CHANNELS[0]['id'];
    
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        $result = json_decode(forwardMessage($chat_id, $channel_id, $item['message_id']), true);
        
        if ($result && $result['ok']) {
            return true;
        } else {
            copyMessage($chat_id, $channel_id, $item['message_id']);
            return true;
        }
    }
    return false;
}

function deliver_movie_with_attribution($chat_id, $item, $username = null) {
    $channel_id = $item['channel_id'] ?? PUBLIC_CHANNELS[0]['id'];
    
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        $result = forwardMessage($chat_id, $channel_id, $item['message_id']);
        $result_data = json_decode($result, true);
        
        if ($result_data && $result_data['ok'] && $username) {
            $caption = "━━━━━━━━━━━━━━━\nREQUESTED BY : @{$username}";
            apiRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $caption,
                'reply_to_message_id' => $result_data['result']['message_id'],
                'parse_mode' => 'HTML'
            ]);
        }
        
        return true;
    }
    return false;
}

function calculateETA($current, $total, $start_time) {
    if ($current == 0) return "Calculating...";
    $elapsed = time() - $start_time;
    $avg_time_per_item = $elapsed / $current;
    $remaining_items = $total - $current;
    $eta_seconds = round($avg_time_per_item * $remaining_items);
    
    if ($eta_seconds < 60) {
        return "~{$eta_seconds}s";
    } else {
        $mins = floor($eta_seconds / 60);
        $secs = $eta_seconds % 60;
        return "~{$mins}m {$secs}s";
    }
}

// ==============================
// COMPLETELY FIXED FUNCTION - NO + OPERATOR ANYWHERE
// ==============================
function forward_page_movies_with_eta($chat_id, array $page_movies, $username = null) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    sendTypingAction($chat_id);
    
    $start_time = time();
    $success_count = 0;
    $fail_count = 0;
    $last_update = 0;
    
    $progress_msg = sendMessage($chat_id, 
        "⏳ <b>Preparing to forward {$total} movies...</b>\n" .
        "├ ETA: Calculating...\n" .
        "└ Status: Initializing...", 
        null, 'HTML'
    );
    
    foreach ($page_movies as $index => $m) {
        $current = $index + 1;
        $percentage = round(($current / $total) * 100);
        
        if ($current % 3 == 0 || $current == 1) {
            sendTypingAction($chat_id);
        }
        
        $success = deliver_movie_with_attribution($chat_id, $m, $username);
        if ($success) {
            $success_count = $success_count + 1;
        } else {
            $fail_count = $fail_count + 1;
        }
        
        $now = time();
        if ($now - $last_update >= 2 || $current % 3 == 0 || $current == $total) {
            $eta = calculateETA($current, $total, $start_time);
            
            $bar_length = 20;
            $filled = round(($percentage / 100) * $bar_length);
            $empty = $bar_length - $filled;
            
            // FIXED: Using = . instead of .= everywhere
            $progress_text = "⏳ <b>Forwarding Movies...</b>\n";
            $progress_text = $progress_text . "├ " . str_repeat("█", $filled) . str_repeat("░", $empty) . "█ " . $percentage . "%\n";
            $progress_text = $progress_text . "├ 📊 " . $current . "/" . $total . " items\n";
            $progress_text = $progress_text . "├ ✅ Success: " . $success_count . "\n";
            $progress_text = $progress_text . "├ ❌ Failed: " . $fail_count . "\n";
            $progress_text = $progress_text . "├ ⏱️ ETA: " . $eta . "\n";
            $progress_text = $progress_text . "└ 🎬 Current: " . htmlspecialchars(substr($m['movie_name'], 0, 30)) . "...";
            
            editMessage($chat_id, $progress_msg, $progress_text, null, 'HTML');
            $last_update = $now;
        }
        
        usleep(300000);
    }
    
    $final_msg = "✅ <b>Forwarding Complete!</b>\n";
    $final_msg = $final_msg . "├ 📊 Total: {$total}\n";
    $final_msg = $final_msg . "├ ✅ Success: {$success_count}\n";
    $final_msg = $final_msg . "├ ❌ Failed: {$fail_count}\n";
    $final_msg = $final_msg . "├ ⏱️ Time Taken: " . (time() - $start_time) . "s\n";
    $final_msg = $final_msg . "└ 📢 Join: @EntertainmentTadka786";
    
    editMessage($chat_id, $progress_msg, $final_msg, null, 'HTML');
    sendTypingAction($chat_id);
}

function bulk_send_movies($chat_id, $movies, $username = null) {
    $total = count($movies);
    $success = 0;
    
    $progress = sendMessage($chat_id, "⏳ Sending {$total} movies...");
    
    foreach ($movies as $index => $movie) {
        if (deliver_movie_with_attribution($chat_id, $movie, $username)) {
            $success = $success + 1;
        }
        
        if (($index + 1) % 3 == 0) {
            $percent = round((($index + 1) / $total) * 100);
            editMessage($chat_id, $progress, "⏳ Progress: {$index + 1}/{$total} ({$percent}%)");
        }
        
        usleep(300000);
    }
    
    editMessage($chat_id, $progress, "✅ Sent {$success}/{$total} movies successfully!");
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) {
        return [
            'total' => 0,
            'total_pages' => 1, 
            'page' => 1,
            'slice' => []
        ];
    }
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function build_pagination_keyboard($page, $total_pages, $prefix = 'page') {
    $kb = ['inline_keyboard' => []];
    $row = [];
    
    if ($page > 1) {
        $row[] = ['text' => '⬅️ Prev', 'callback_data' => $prefix . '_' . ($page - 1)];
    }
    
    $row[] = ['text' => "📄 {$page}/{$total_pages}", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $row[] = ['text' => 'Next ➡️', 'callback_data' => $prefix . '_' . ($page + 1)];
    }
    
    $kb['inline_keyboard'][] = $row;
    
    $all = get_all_movies_list();
    $pg = paginate_movies($all, $page);
    $kb['inline_keyboard'][] = [
        ['text' => "📦 Send All (" . count($pg['slice']) . ")", 'callback_data' => 'send_all_' . $page]
    ];
    
    return $kb;
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    
    $nav_row = [];
    if ($page > 1) {
        $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'tu_prev_' . ($page - 1)];
    }
    
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'tu_next_' . ($page + 1)];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    $action_row = [];
    $action_row[] = ['text' => '🎬 Send This Page', 'callback_data' => 'tu_view_' . $page];
    $action_row[] = ['text' => '🛑 Stop', 'callback_data' => 'tu_stop'];
    
    $kb['inline_keyboard'][] = $action_row;
    
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) {
            $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'tu_prev_1'];
        }
        if ($page < $total_pages) {
            $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'tu_next_' . $total_pages];
        }
        if (!empty($jump_row)) {
            $kb['inline_keyboard'][] = $jump_row;
        }
    }
    
    return $kb;
}

// ==============================
// /totalupload controller
// ==============================
function totalupload_controller($chat_id, $page = 1, $username = null) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili! Pehle kuch movies add karo.");
        return;
    }
    
    $pg = paginate_movies($all, (int)$page);
    
    forward_page_movies_with_eta($chat_id, $pg['slice'], $username);
    
    sleep(1);
    sendTypingAction($chat_id);
    
    $title = "🎬 <b>Total Uploads</b>\n\n";
    $title = $title . "📊 <b>Statistics:</b>\n";
    $title = $title . "• Total Movies: <b>{$pg['total']}</b>\n";
    $title = $title . "• Current Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n";
    $title = $title . "• Showing: <b>" . count($pg['slice']) . " movies</b>\n\n";
    $title = $title . "📍 Use buttons below to navigate";
    
    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $channel_id, $date = null) {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    
    validate_csv_format();
    
    $entry = [$movie_name, $message_id_raw, $channel_id];
    
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);
    
    @chmod(CSV_FILE, 0666);
    
    global $movie_messages, $movie_cache;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'channel_id' => $channel_id,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];
    
    update_stats('total_movies', 1);
    error_log("✅ Movie added: $movie_name | MsgID: $message_id_raw | Channel: $channel_id");
}

// ==============================
// Search functions
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score, 'count'=>count($entries)];
    }
    
    uasort($results, function($a, $b) { return $b['score'] - $a['score']; });
    return array_slice($results, 0, 10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म', 'मूवी', 'डाउनलोड', 'हिंदी'];
    $english_keywords = ['movie', 'download', 'watch'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text, $k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text, $k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka7860\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka7860\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1, 'found_movie'=>5, 'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages, $waiting_users;
    
    sendTypingAction($chat_id);
    
    $q = strtolower(trim($query));
    
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    $invalid_keywords = ['vlc', 'audio', 'track', 'how', 'what', 'problem', 'help', 'hi', 'hello'];
    $query_words = explode(' ', $q);
    $invalid_count = 0;
    
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count = $invalid_count + 1;
        }
    }
    
    if ($invalid_count > 0 && ($invalid_count / count($query_words)) > 0.5) {
        $help_msg = "🎬 Please enter a movie name!\n\n";
        $help_msg = $help_msg . "📢 Join: @EntertainmentTadka786\n";
        $help_msg = $help_msg . "💬 Help: @EntertainmentTadka7860";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    $found = smart_search($q);
    
    if (!empty($found)) {
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1;
        foreach ($found as $movie=>$data) {
            $msg = $msg . "$i. $movie (" . $data['count'] . " entries)\n";
            $i = $i + 1; if ($i>15) break;
        }
        sendMessage($chat_id, $msg);
        
        $keyboard = ['inline_keyboard'=>[]];
        foreach (array_slice(array_keys($found), 0, 5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text'=>"🎬 ".ucwords($movie), 'callback_data'=>'get_'.$movie ]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
    }
    
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    
    $msg = "📊 Bot Statistics\n\n";
    $msg = $msg . "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg = $msg . "👥 Total Users: " . $total_users . "\n";
    $msg = $msg . "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg = $msg . "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg = $msg . "📈 Recent Uploads:\n";
    foreach ($recent as $r) {
        $channel_name = get_channel_display_name($r['channel_id'] ?? PUBLIC_CHANNELS[0]['id']);
        $msg = $msg . "• " . $r['movie_name'] . " (" . $channel_name . ")\n";
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Show CSV Data
// ==============================
function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 CSV Movie Database\n\n";
    $message = $message . "📁 Total Movies: " . count($movies) . "\n";
    if (!$show_all) {
        $message = $message . "🔍 Showing latest 10 entries\n";
        $message = $message . "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message = $message . "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $channel_id = $movie[2] ?? 'N/A';
        $channel_name = get_channel_display_name($channel_id);
        
        $message = $message . "$i. 🎬 " . htmlspecialchars($movie_name) . "\n";
        $message = $message . "   📝 ID: $message_id\n";
        $message = $message . "   📺 Channel: $channel_name\n\n";
        
        $i = $i + 1;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message = $message . "💾 File: " . CSV_FILE . "\n";
    $message = $message . "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// REQUEST FUNCTIONS
// ==============================
function can_user_request($user_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $today = date('Y-m-d');
    
    $count = 0;
    foreach ($requests['pending'] as $req) {
        if ($req['user_id'] == $user_id && substr($req['date'], 0, 10) == $today) {
            $count = $count + 1;
        }
    }
    
    return $count < DAILY_REQUEST_LIMIT;
}

function check_remaining_requests($user_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $today = date('Y-m-d');
    
    $count = 0;
    foreach ($requests['pending'] as $req) {
        if ($req['user_id'] == $user_id && substr($req['date'], 0, 10) == $today) {
            $count = $count + 1;
        }
    }
    
    return $count;
}

function add_movie_request($user_id, $username, $movie_name) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    
    $request_id = uniqid();
    $requests['pending'][] = [
        'id' => $request_id,
        'user_id' => $user_id,
        'username' => $username,
        'movie' => $movie_name,
        'date' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ];
    
    file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    return $request_id;
}

function get_pending_requests($limit = null) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $pending = $requests['pending'] ?? [];
    
    if ($limit) {
        return array_slice($pending, 0, $limit);
    }
    return $pending;
}

function approve_requests($request_ids) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    $approved = [];
    
    foreach ($requests['pending'] as $key => $req) {
        if (in_array($req['id'], $request_ids)) {
            $approved[] = $req;
            unset($requests['pending'][$key]);
            
            append_movie($req['movie'], 'REQUESTED', PUBLIC_CHANNELS[0]['id'], date('d-m-Y'));
        }
    }
    
    $requests['approved'] = array_merge($requests['approved'] ?? [], $approved);
    $requests['pending'] = array_values($requests['pending']);
    
    file_put_contents(REQUESTS_FILE, json_encode($requests, JSON_PRETTY_PRINT));
    return $approved;
}

function show_user_requests($chat_id, $user_id) {
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    
    $user_reqs = array_filter($requests['pending'], function($r) use ($user_id) {
        return $r['user_id'] == $user_id;
    });
    
    if (empty($user_reqs)) {
        sendMessage($chat_id, "📭 You haven't made any requests yet.");
        return;
    }
    
    $msg = "📋 <b>Your Pending Requests</b>\n\n";
    $count = 1;
    foreach (array_reverse($user_reqs) as $req) {
        $msg = $msg . "{$count}. 🎬 " . htmlspecialchars($req['movie']) . "\n";
        $msg = $msg . "   📅 " . date('d-m-Y H:i', strtotime($req['date'])) . "\n\n";
        $count = $count + 1;
    }
    
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// ADMIN FUNCTIONS
// ==============================
function get_basic_stats() {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    
    return [
        'movies' => $stats['total_movies'] ?? 0,
        'users' => count($users_data['users'] ?? []),
        'searches' => $stats['total_searches'] ?? 0,
        'pending_requests' => count($requests['pending'] ?? []),
        'approved_requests' => count($requests['approved'] ?? [])
    ];
}

function show_admin_panel($chat_id) {
    $stats = get_basic_stats();
    
    $panel = "👑 <b>ADMIN PANEL</b>\n\n";
    $panel = $panel . "📊 <b>Statistics:</b>\n";
    $panel = $panel . "├ 🎬 Movies: <b>{$stats['movies']}</b>\n";
    $panel = $panel . "├ 👥 Users: <b>{$stats['users']}</b>\n";
    $panel = $panel . "├ 🔍 Searches: <b>{$stats['searches']}</b>\n";
    $panel = $panel . "├ ⏳ Pending: <b>{$stats['pending_requests']}</b>\n";
    $panel = $panel . "└ ✅ Approved: <b>{$stats['approved_requests']}</b>\n\n";
    $panel = $panel . "🕒 Last Updated: " . date('d-m-Y H:i:s');
    
    $keyboard = [
        'inline_keyboard' => [
            [['text' => "📥 Pending Requests ({$stats['pending_requests']})", 'callback_data' => 'admin_pending']],
            [['text' => "✅ Bulk Approve (5)", 'callback_data' => 'bulk_approve_5'],
             ['text' => "✅ Bulk Approve (10)", 'callback_data' => 'bulk_approve_10']],
            [['text' => "✅ Approve All", 'callback_data' => 'approve_all'],
             ['text' => "📊 Full Stats", 'callback_data' => 'admin_stats']],
            [['text' => "📢 Broadcast", 'callback_data' => 'admin_broadcast'],
             ['text' => "🔄 System Info", 'callback_data' => 'system_info']],
            [['text' => "📁 CSV Format", 'callback_data' => 'admin_csv'],
             ['text' => "🔄 Refresh", 'callback_data' => 'refresh_admin']]
        ]
    ];
    
    sendMessage($chat_id, $panel, $keyboard, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE, REQUESTS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) mkdir($backup_dir, 0777, true);
    
    foreach ($backup_files as $f) {
        if (file_exists($f)) {
            copy($f, $backup_dir . '/' . basename($f) . '.bak');
        }
    }
    
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a, $b) { return filemtime($a) - filemtime($b); });
        foreach (array_slice($old, 0, count($old)-7) as $d) {
            $files = glob($d . '/*'); 
            foreach ($files as $ff) @unlink($ff); 
            @rmdir($d);
        }
    }
}

function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $y_movies = [];
    
    $h = fopen(CSV_FILE, "r");
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r)>=3 && $r[2] == $yesterday) $y_movies[] = $r[0];
        }
        fclose($h);
    }
    
    if (!empty($y_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $uid => $ud) {
            $msg = "📅 Daily Movie Digest\n\n";
            $msg = $msg . "📢 Join our channel: @EntertainmentTadka786\n\n";
            $msg = $msg . "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            foreach (array_slice($y_movies,0,10) as $m) $msg = $msg . "• " . $m . "\n";
            if (count($y_movies)>10) $msg = $msg . "• ... and " . (count($y_movies)-10) . " more\n";
            $msg = $msg . "\n🔥 Total: " . count($y_movies) . " movies";
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { 
        sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); 
        return; 
    }
    
    $date_counts = [];
    $h = fopen(CSV_FILE, 'r'); 
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r) >= 3) { 
                $d = $r[2]; 
                if (!isset($date_counts[$d])) $date_counts[$d] = 0; 
                $date_counts[$d] = $date_counts[$d] + 1; 
            }
        }
        fclose($h);
    }
    
    krsort($date_counts);
    $msg = "📅 Movies Upload Record\n\n";
    $total_days = 0; 
    $total_movies = 0;
    
    foreach ($date_counts as $date => $count) { 
        $msg = $msg . "➡️ $date: $count movies\n"; 
        $total_days = $total_days + 1; 
        $total_movies = $total_movies + $count; 
    }
    
    $msg = $msg . "\n📊 Summary:\n";
    $msg = $msg . "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id, $msg, null, 'HTML');
}

function total_uploads($chat_id, $page = 1, $username = null) {
    totalupload_controller($chat_id, $page, $username);
}

// ==============================
// Group Message Filter
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    if (strpos($text, '/') === 0) {
        return true;
    }
    
    if (strlen($text) < 3) {
        return false;
    }
    
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// Main update processing (webhook)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);

// Deploy notification
if (php_sapi_name() === 'cli' || isset($_GET['deploy'])) {
    $admin_id = ADMIN_ID;
    
    $msg = "🚀 Bot Successfully Deployed on Render.com!\n\n";
    $msg = $msg . "📅 Time: " . date('Y-m-d H:i:s') . "\n";
    $msg = $msg . "✅ Status: Running\n";
    $msg = $msg . "📊 PHP Version: " . phpversion() . "\n";
    $msg = $msg . "📁 CSV Movies: " . (file_exists(CSV_FILE) ? count(file(CSV_FILE)) - 1 : 0);
    
    file_get_contents("https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage?chat_id=" . $admin_id . "&text=" . urlencode($msg));
}

if ($update) {
    get_cached_movies();

    // Channel post handling
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];
        
        $is_authorized_channel = false;
        $channel_name = '';
        
        foreach (PUBLIC_CHANNELS as $channel) {
            if ($chat_id == $channel['id']) {
                $is_authorized_channel = true;
                $channel_name = $channel['name'];
                break;
            }
        }
        
        foreach (PRIVATE_CHANNELS as $pchannel) {
            if ($chat_id == $pchannel['id']) {
                $is_authorized_channel = true;
                $channel_name = $pchannel['name'] . ' (Private)';
                break;
            }
        }
        
        if ($is_authorized_channel) {
            $text = '';
            
            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            else {
                $text = 'Media - ' . date('d-m-Y H:i');
            }
            
            if (!empty(trim($text))) {
                $movie_name = preg_replace('/\s+/', ' ', trim($text));
                append_movie($movie_name, $message_id, $chat_id, date('d-m-Y'));
                error_log("📥 Movie added from $channel_name: $movie_name");
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';
        $username = $message['from']['username'] ?? $message['from']['first_name'];

        // Group message filtering
        if ($chat_type !== 'private') {
            if (strpos($text, '/') !== 0) {
                if (!is_valid_movie_query($text)) {
                    return;
                }
            }
        }

        // User tracking
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        // Command handling
        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = strtolower($parts[0]);
            $is_admin = ($user_id == ADMIN_ID);

            if ($command == '/start') {
                sendTypingAction($chat_id);
                
                $welcome = "```\n";
                $welcome = $welcome . "┌─────────────────────────────────────────────────────┐\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "│  🎬 Welcome to Entertainment Tadka!                │\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "│  📢 How to use this bot:                            │\n";
                $welcome = $welcome . "│  • Simply type any movie name                       │\n";
                $welcome = $welcome . "│  • Use English or Hindi                             │\n";
                $welcome = $welcome . "│  • Partial names also work                          │\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "│  🔍 Examples:                                       │\n";
                $welcome = $welcome . "│  • MANDALA MURDER 2025                              │\n";
                $welcome = $welcome . "│  • SQUID GMAES ALL SEASON                           │\n";
                $welcome = $welcome . "│  • ZEBRA 2024                                       │\n";
                $welcome = $welcome . "│  • SHOW TIME 2025                                   │\n";
                $welcome = $welcome . "│  • ANDHADHUN 2018                                   │\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "│  📢 Join Our Channels:                              │\n";
                $welcome = $welcome . "│  🍿 Main: @EntertainmentTadka786                    │\n";
                $welcome = $welcome . "│  📥 Requests: @EntertainmentTadka7860               │\n";
                $welcome = $welcome . "│  🎭 Theater: @threater_print_movies                 │\n";
                $welcome = $welcome . "│  🔒 Backup: @ETBackup                               │\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "│  💬 Need help? Use /help                            │\n";
                $welcome = $welcome . "│                                                     │\n";
                $welcome = $welcome . "└─────────────────────────────────────────────────────┘\n";
                $welcome = $welcome . "```";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔍 Search Movies', 'callback_data' => 'search_help'],
                            ['text' => '🍿 Main Channel', 'url' => 'https://t.me/EntertainmentTadka786']
                        ],
                        [
                            ['text' => '📥 Requests', 'url' => 'https://t.me/EntertainmentTadka7860'],
                            ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies']
                        ],
                        [
                            ['text' => '🔒 Backup', 'url' => 'https://t.me/ETBackup'],
                            ['text' => '❓ Help', 'callback_data' => 'show_help']
                        ]
                    ]
                ];
                
                sendMessage($chat_id, $welcome, $keyboard, 'MarkdownV2');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/help') {
                sendTypingAction($chat_id);
                
                $help_menu = "```\n";
                $help_menu = $help_menu . "┌─────────────────────────────────────────────────────┐\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  🤖 Entertainment Tadka Bot - Help                  │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  📢 Available Commands:                             │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  🔍 SEARCH:                                         │\n";
                $help_menu = $help_menu . "│  • /search movie - Search movies                    │\n";
                $help_menu = $help_menu . "│  • Just type name - Quick search                    │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  📁 BROWSE:                                         │\n";
                $help_menu = $help_menu . "│  • /totaluploads - All movies                       │\n";
                $help_menu = $help_menu . "│  • /checkcsv - Database view                        │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  📝 REQUEST:                                        │\n";
                $help_menu = $help_menu . "│  • /request movie - Request movie                   │\n";
                $help_menu = $help_menu . "│  • /myrequests - Your requests                      │\n";
                $help_menu = $help_menu . "│  • /requestlimit - Daily limit                      │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  📢 CHANNELS:                                       │\n";
                $help_menu = $help_menu . "│  • /all_channels - All channels info                │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "│  ❓ OTHER:                                           │\n";
                $help_menu = $help_menu . "│  • /start - Welcome                                 │\n";
                $help_menu = $help_menu . "│  • /help - This menu                                │\n";
                $help_menu = $help_menu . "│                                                     │\n";
                $help_menu = $help_menu . "└─────────────────────────────────────────────────────┘\n";
                $help_menu = $help_menu . "```";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🍿 Main', 'url' => 'https://t.me/EntertainmentTadka786'],
                            ['text' => '📥 Requests', 'url' => 'https://t.me/EntertainmentTadka7860'],
                            ['text' => '🎭 Theater', 'url' => 'https://t.me/threater_print_movies']
                        ],
                        [
                            ['text' => '🔒 Backup', 'url' => 'https://t.me/ETBackup'],
                            ['text' => '🔍 Quick Search', 'switch_inline_query' => '']
                        ]
                    ]
                ];
                
                sendMessage($chat_id, $help_menu, $keyboard, 'MarkdownV2');
            }
            elseif ($command == '/all_channels') {
                $all = get_all_channels();
                
                $msg = "📢 <b>All Channels</b>\n\n";
                $msg = $msg . "🍿 <b>Public Channels:</b>\n";
                foreach ($all['public'] as $ch) {
                    $status = $ch['header'] == 'on' ? '✅ Active' : '⭕ Inactive';
                    $msg = $msg . "├ {$ch['name']}: {$ch['username']} ({$status})\n";
                }
                
                if ($is_admin) {
                    $msg = $msg . "\n🔒 <b>Private Channels (Admin):</b>\n";
                    foreach ($all['private'] as $ch) {
                        $msg = $msg . "├ {$ch['name']}: <code>{$ch['id']}</code>\n";
                    }
                }
                
                $keyboard = ['inline_keyboard' => []];
                foreach ($all['public'] as $ch) {
                    $keyboard['inline_keyboard'][] = [
                        ['text' => $ch['name'], 'url' => get_channel_link($ch['id'])]
                    ];
                }
                
                sendMessage($chat_id, $msg, $keyboard, 'HTML');
            }
            elseif ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload' || $command == '/totaluploads') total_uploads($chat_id, 1, $username);
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/stats' && $is_admin) admin_stats($chat_id);
            
            // Request commands
            elseif (strpos($command, '/request ') === 0) {
                $movie_name = substr($text, 9);
                
                if (empty(trim($movie_name))) {
                    sendMessage($chat_id, "❌ Usage: /request Movie Name");
                    return;
                }
                
                if (!can_user_request($user_id)) {
                    $used = check_remaining_requests($user_id);
                    $limit_msg = "❌ Daily request limit reached!\n";
                    $limit_msg = $limit_msg . "Used: {$used}/" . DAILY_REQUEST_LIMIT . "\n";
                    $limit_msg = $limit_msg . "Use /requestlimit to check status";
                    sendMessage($chat_id, $limit_msg);
                    return;
                }
                
                $request_id = add_movie_request($user_id, $username, $movie_name);
                $used = check_remaining_requests($user_id) + 1;
                
                $msg = "✅ <b>Request Submitted!</b>\n\n";
                $msg = $msg . "🎬 Movie: " . htmlspecialchars($movie_name) . "\n";
                $msg = $msg . "🆔 Request ID: <code>{$request_id}</code>\n";
                $msg = $msg . "📊 Daily Limit: {$used}/" . DAILY_REQUEST_LIMIT . "\n\n";
                $msg = $msg . "📢 Join: @EntertainmentTadka7860";
                
                sendMessage($chat_id, $msg, null, 'HTML');
                
                $admin_msg = "📥 <b>New Request</b>\n";
                $admin_msg = $admin_msg . "👤 @{$username}\n";
                $admin_msg = $admin_msg . "🎬 {$movie_name}\n";
                $admin_msg = $admin_msg . "🆔 {$request_id}";
                sendMessage(ADMIN_ID, $admin_msg, null, 'HTML');
            }
            elseif ($command == '/myrequests') {
                show_user_requests($chat_id, $user_id);
            }
            elseif ($command == '/requestlimit') {
                $used = check_remaining_requests($user_id);
                $remaining = DAILY_REQUEST_LIMIT - $used;
                
                $msg = "📊 <b>Request Limit Status</b>\n\n";
                $msg = $msg . "✅ Used: {$used}/" . DAILY_REQUEST_LIMIT . "\n";
                $msg = $msg . "⏳ Remaining: {$remaining}\n";
                $msg = $msg . "📅 Reset: Tomorrow 12:00 AM";
                
                sendMessage($chat_id, $msg, null, 'HTML');
            }
            
            // Admin commands
            elseif (($command == '/pending' || $command == '/pending_requests') && $is_admin) {
                $pending = get_pending_requests();
                
                if (empty($pending)) {
                    sendMessage($chat_id, "📭 No pending requests.");
                    return;
                }
                
                $msg = "⏳ <b>Pending Requests: " . count($pending) . "</b>\n\n";
                foreach (array_slice($pending, 0, 10) as $req) {
                    $msg = $msg . "🆔 <code>{$req['id']}</code>\n";
                    $msg = $msg . "👤 @{$req['username']}\n";
                    $msg = $msg . "🎬 " . htmlspecialchars($req['movie']) . "\n";
                    $msg = $msg . "📅 " . date('d-m H:i', strtotime($req['date'])) . "\n━━━━━━━━━\n";
                }
                
                if (count($pending) > 10) {
                    $msg = $msg . "\n... and " . (count($pending) - 10) . " more";
                }
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => "✅ Bulk Approve (5)", 'callback_data' => 'bulk_approve_5'],
                         ['text' => "✅ Bulk Approve (10)", 'callback_data' => 'bulk_approve_10']],
                        [['text' => "✅ Approve All", 'callback_data' => 'approve_all'],
                         ['text' => "🔄 Refresh", 'callback_data' => 'refresh_pending']]
                    ]
                ];
                
                sendMessage($chat_id, $msg, $keyboard, 'HTML');
            }
            elseif ($command == '/admin' && $is_admin) {
                show_admin_panel($chat_id);
            }
            elseif (strpos($command, '/bulk_approve ') === 0 && $is_admin) {
                $count = (int)substr($command, 14);
                if ($count <= 0) $count = 5;
                
                $pending = get_pending_requests($count);
                $request_ids = array_column($pending, 'id');
                
                if (empty($request_ids)) {
                    sendMessage($chat_id, "📭 No pending requests to approve.");
                    return;
                }
                
                $approved = approve_requests($request_ids);
                
                foreach ($approved as $req) {
                    $user_msg = "✅ Your request '{$req['movie']}' has been approved!";
                    sendMessage($req['user_id'], $user_msg);
                }
                
                sendMessage($chat_id, "✅ Approved " . count($approved) . " requests!");
            }
            elseif (strpos($command, '/search ') === 0) {
                $search_query = substr($text, 8);
                if (!empty(trim($search_query))) {
                    advanced_search($chat_id, $search_query, $user_id);
                } else {
                    sendMessage($chat_id, "❌ Please enter movie name. Example: /search KGF");
                }
            }
        } 
        else if (!empty(trim($text)) && strpos($text, '/') !== 0) {
            advanced_search($chat_id, $text, $user_id);
        }
    }

    // Callback query handling
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        $user_id = $query['from']['id'];
        $username = $query['from']['username'] ?? $query['from']['first_name'];
        $is_admin = ($user_id == ADMIN_ID);

        global $movie_messages;

        if (strpos($data, 'get_') === 0) {
            $movie_name = substr($data, 4);
            $movie_lower = strtolower($movie_name);
            
            if (isset($movie_messages[$movie_lower])) {
                $entries = $movie_messages[$movie_lower];
                $cnt = 0;
                foreach ($entries as $entry) {
                    deliver_movie_with_attribution($chat_id, $entry, $username);
                    usleep(200000);
                    $cnt = $cnt + 1;
                }
                sendMessage($chat_id, "✅ '$movie_name' ke $cnt messages forward ho gaye!");
                answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
            } else {
                answerCallbackQuery($query['id'], "❌ Movie not available");
            }
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_', '', $data);
            totalupload_controller($chat_id, $page, $username);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_', '', $data);
            totalupload_controller($chat_id, $page, $username);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_', '', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies_with_eta($chat_id, $pg['slice'], $username);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif (strpos($data, 'send_all_') === 0) {
            $page = (int)str_replace('send_all_', '', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            
            sendMessage($chat_id, "⏳ Sending all " . count($pg['slice']) . " movies...");
            bulk_send_movies($chat_id, $pg['slice'], $username);
            
            answerCallbackQuery($query['id'], "✅ All movies sent!");
        }
        elseif ($data == 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif ($data == 'search_help') {
            $help_text = "🔍 <b>Search Tips:</b>\n\n";
            $help_text = $help_text . "• Type full movie name: <code>Mandala Murder 2025</code>\n";
            $help_text = $help_text . "• Type partial: <code>Squid Game</code>\n";
            $help_text = $help_text . "• Add year: <code>Zebra 2024</code>\n";
            $help_text = $help_text . "• Hindi/English dono use karo\n\n";
            $help_text = $help_text . "Example: <i>Andhadhun 2018</i>";
            
            answerCallbackQuery($query['id'], "🔍 Search Tips!");
            sendMessage($chat_id, $help_text, null, 'HTML');
        }
        elseif ($data == 'show_help') {
            $help_text = "❓ <b>Available Commands:</b>\n\n";
            $help_text = $help_text . "/start - Welcome message\n";
            $help_text = $help_text . "/checkdate - Date-wise stats\n";
            $help_text = $help_text . "/totalupload - Upload statistics\n";
            $help_text = $help_text . "/checkcsv - Check movie list\n";
            $help_text = $help_text . "/request - Request movie\n";
            $help_text = $help_text . "/myrequests - Your requests\n";
            $help_text = $help_text . "/requestlimit - Daily limit\n";
            $help_text = $help_text . "/all_channels - All channels\n";
            $help_text = $help_text . "/help - This message";
            
            answerCallbackQuery($query['id'], "📋 Commands List");
            sendMessage($chat_id, $help_text, null, 'HTML');
        }
        elseif ($data == 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        
        // Admin callback handlers
        elseif ($data == 'admin_pending' && $is_admin) {
            $pending = get_pending_requests();
            
            if (empty($pending)) {
                sendMessage($chat_id, "📭 No pending requests.");
                answerCallbackQuery($query['id'], "No pending requests");
                return;
            }
            
            $msg = "⏳ <b>Pending Requests: " . count($pending) . "</b>\n\n";
            foreach (array_slice($pending, 0, 10) as $req) {
                $msg = $msg . "🆔 <code>{$req['id']}</code>\n";
                $msg = $msg . "👤 @{$req['username']}\n";
                $msg = $msg . "🎬 " . htmlspecialchars($req['movie']) . "\n";
                $msg = $msg . "📅 " . date('d-m H:i', strtotime($req['date'])) . "\n━━━━━━━━━\n";
            }
            
            sendMessage($chat_id, $msg, null, 'HTML');
            answerCallbackQuery($query['id'], "Pending requests");
        }
        elseif ($data == 'bulk_approve_5' && $is_admin) {
            $pending = get_pending_requests(5);
            $request_ids = array_column($pending, 'id');
            
            if (empty($request_ids)) {
                answerCallbackQuery($query['id'], "No pending requests!");
                return;
            }
            
            $approved = approve_requests($request_ids);
            
            foreach ($approved as $req) {
                $user_msg = "✅ Your request '{$req['movie']}' has been approved!";
                sendMessage($req['user_id'], $user_msg);
            }
            
            sendMessage($chat_id, "✅ Approved " . count($approved) . " requests!");
            answerCallbackQuery($query['id'], "✅ Bulk approve completed");
        }
        elseif ($data == 'bulk_approve_10' && $is_admin) {
            $pending = get_pending_requests(10);
            $request_ids = array_column($pending, 'id');
            
            if (empty($request_ids)) {
                answerCallbackQuery($query['id'], "No pending requests!");
                return;
            }
            
            $approved = approve_requests($request_ids);
            
            foreach ($approved as $req) {
                $user_msg = "✅ Your request '{$req['movie']}' has been approved!";
                sendMessage($req['user_id'], $user_msg);
            }
            
            sendMessage($chat_id, "✅ Approved " . count($approved) . " requests!");
            answerCallbackQuery($query['id'], "✅ Bulk approve completed");
        }
        elseif ($data == 'approve_all' && $is_admin) {
            $pending = get_pending_requests();
            $request_ids = array_column($pending, 'id');
            
            if (empty($request_ids)) {
                answerCallbackQuery($query['id'], "No pending requests!");
                return;
            }
            
            $approved = approve_requests($request_ids);
            
            foreach ($approved as $req) {
                $user_msg = "✅ Your request '{$req['movie']}' has been approved!";
                sendMessage($req['user_id'], $user_msg);
            }
            
            sendMessage($chat_id, "✅ Approved all " . count($approved) . " requests!");
            answerCallbackQuery($query['id'], "✅ All requests approved");
        }
        elseif ($data == 'admin_stats' && $is_admin) {
            admin_stats($chat_id);
            answerCallbackQuery($query['id'], "Statistics");
        }
        elseif ($data == 'admin_broadcast' && $is_admin) {
            global $broadcast_states;
            $broadcast_states[$user_id] = ['mode' => 'broadcast'];
            sendMessage($chat_id, "📢 Broadcast mode activated.\nType your message to broadcast to all users:");
            answerCallbackQuery($query['id'], "Broadcast mode");
        }
        elseif ($data == 'system_info' && $is_admin) {
            $info = "🔄 <b>System Information</b>\n\n";
            $info = $info . "├ PHP Version: " . phpversion() . "\n";
            $info = $info . "├ Memory Limit: " . ini_get('memory_limit') . "\n";
            $info = $info . "├ Max Execution: " . ini_get('max_execution_time') . "s\n";
            $info = $info . "├ Upload Max: " . ini_get('upload_max_filesize') . "\n";
            $info = $info . "├ Post Max: " . ini_get('post_max_size') . "\n";
            $info = $info . "├ CSV Size: " . round(filesize(CSV_FILE)/1024, 2) . " KB\n";
            $info = $info . "├ Total Lines: " . (file_exists(CSV_FILE) ? count(file(CSV_FILE)) - 1 : 0) . "\n";
            $info = $info . "├ Pending Requests: " . count(get_pending_requests()) . "\n";
            $info = $info . "└ Last Backup: " . (file_exists(BACKUP_DIR) ? count(glob(BACKUP_DIR . '*', GLOB_ONLYDIR)) : 0) . " backups";
            
            sendMessage($chat_id, $info, null, 'HTML');
            answerCallbackQuery($query['id'], "System Info");
        }
        elseif ($data == 'admin_csv' && $is_admin) {
            $format = implode(', ', CSV_FORMAT);
            $msg = "📁 <b>CSV Format Status</b>\n\n";
            $msg = $msg . "🔒 <b>LOCKED FORMAT:</b> $format\n";
            $msg = $msg . "📊 <b>Total Columns:</b> 3\n";
            $msg = $msg . "✅ <b>Status:</b> Permanently Locked\n\n";
            
            if (file_exists(CSV_FILE)) {
                $handle = fopen(CSV_FILE, 'r');
                $header = fgetcsv($handle);
                fclose($handle);
                
                if ($header === CSV_FORMAT) {
                    $msg = $msg . "✅ Current file format is correct!";
                } else {
                    $msg = $msg . "⚠️ File format mismatch! Auto-fixed on next write.";
                }
            }
            sendMessage($chat_id, $msg, null, 'HTML');
            answerCallbackQuery($query['id'], "CSV Format");
        }
        elseif ($data == 'refresh_pending' && $is_admin) {
            $pending = get_pending_requests();
            
            if (empty($pending)) {
                sendMessage($chat_id, "📭 No pending requests.");
                answerCallbackQuery($query['id'], "Refreshed");
                return;
            }
            
            $msg = "⏳ <b>Pending Requests: " . count($pending) . "</b>\n\n";
            foreach (array_slice($pending, 0, 10) as $req) {
                $msg = $msg . "🆔 <code>{$req['id']}</code>\n";
                $msg = $msg . "👤 @{$req['username']}\n";
                $msg = $msg . "🎬 " . htmlspecialchars($req['movie']) . "\n";
                $msg = $msg . "📅 " . date('d-m H:i', strtotime($req['date'])) . "\n━━━━━━━━━\n";
            }
            
            sendMessage($chat_id, $msg, null, 'HTML');
            answerCallbackQuery($query['id'], "Refreshed");
        }
        elseif ($data == 'refresh_admin' && $is_admin) {
            show_admin_panel($chat_id);
            answerCallbackQuery($query['id'], "Refreshed");
        }
        else {
            answerCallbackQuery($query['id'], "Unknown command");
        }
    }

    // Auto tasks
    if (date('H:i') == '00:00') auto_backup();
    if (date('H:i') == '08:00') send_daily_digest();
}

// Webhook setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
    }
    exit;
}

// Status page
if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $requests = json_decode(file_get_contents(REQUESTS_FILE), true);
    
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<p><strong>Pending Requests:</strong> " . count($requests['pending'] ?? []) . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Available Commands</h3>";
    echo "<ul>";
    echo "<li><code>/start</code> - Welcome message</li>";
    echo "<li><code>/help</code> - Help menu</li>";
    echo "<li><code>/search [movie]</code> - Search movies</li>";
    echo "<li><code>/request [movie]</code> - Request movie</li>";
    echo "<li><code>/myrequests</code> - Your requests</li>";
    echo "<li><code>/requestlimit</code> - Daily limit</li>";
    echo "<li><code>/totaluploads</code> - All movies</li>";
    echo "<li><code>/checkcsv</code> - Database view</li>";
    echo "<li><code>/all_channels</code> - All channels info</li>";
    echo "<li><code>/checkdate</code> - Date-wise stats</li>";
    echo "<li><code>/pending</code> - Pending requests (admin)</li>";
    echo "<li><code>/bulk_approve [n]</code> - Bulk approve (admin)</li>";
    echo "<li><code>/admin</code> - Admin panel (admin)</li>";
    echo "<li><code>/stats</code> - Full stats (admin)</li>";
    echo "</ul>";
}
?>
