<?php
// ============================================
// إعدادات الخادم والـ SSE
// ============================================
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');
set_time_limit(0);

if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}

@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', true);
ob_implicit_flush(true);

// ============================================
// دالة إرسال الإيميل (بدون تغيير)
// ============================================
function sendEmailSMTP($to, $subject, $body, $smtpConfig, $attachments = [], $isHtml = true, $rtl = true) {
    $host = $smtpConfig['host'];
    $port = $smtpConfig['port'];
    $username = $smtpConfig['username'];
    $password = $smtpConfig['password'];
    $fromName = $smtpConfig['from_name'];
    
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    
    $socket = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    
    if (!$socket) {
        return ['success' => false, 'error' => "فشل الاتصال: $errstr ($errno)"];
    }
    
    stream_set_timeout($socket, 30);
    
    $response = getSMTPResponse($socket);
    if (substr($response, 0, 3) != '220') {
        fclose($socket);
        return ['success' => false, 'error' => 'استجابة غير متوقعة: ' . $response];
    }
    
    sendSMTPCommand($socket, "EHLO " . gethostname());
    $ehloResponse = getSMTPResponse($socket);
    
    if ($port == 587) {
        sendSMTPCommand($socket, "STARTTLS");
        $tlsResponse = getSMTPResponse($socket);
        if (substr($tlsResponse, 0, 3) != '220') {
            fclose($socket);
            return ['success' => false, 'error' => 'فشل STARTTLS: ' . $tlsResponse];
        }
        
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => 'فشل تفعيل TLS'];
        }
        
        sendSMTPCommand($socket, "EHLO " . gethostname());
        getSMTPResponse($socket);
    }
    
    sendSMTPCommand($socket, "AUTH LOGIN");
    $authResponse = getSMTPResponse($socket);
    if (substr($authResponse, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => 'الخادم لا يدعم AUTH LOGIN'];
    }
    
    sendSMTPCommand($socket, base64_encode($username));
    getSMTPResponse($socket);
    sendSMTPCommand($socket, base64_encode($password));
    $passResponse = getSMTPResponse($socket);
    
    if (substr($passResponse, 0, 3) != '235') {
        fclose($socket);
        return ['success' => false, 'error' => 'خطأ في تسجيل الدخول. تأكد من كلمة مرور التطبيق'];
    }
    
    sendSMTPCommand($socket, "MAIL FROM:<{$username}>");
    getSMTPResponse($socket);
    sendSMTPCommand($socket, "RCPT TO:<{$to}>");
    getSMTPResponse($socket);
    sendSMTPCommand($socket, "DATA");
    getSMTPResponse($socket);
    
    $boundary = '----=_Part_' . md5(uniqid());
    $messageId = '<' . md5(uniqid()) . '@' . gethostname() . '>';
    $date = date('r');
    
    $direction = $rtl ? 'rtl' : 'ltr';
    $align = $rtl ? 'right' : 'left';
    
    if ($isHtml) {
        $styledBody = '<!DOCTYPE html>
<html dir="' . $direction . '" lang="ar">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body {
    font-family: Arial, Tahoma, sans-serif;
    direction: ' . $direction . ';
    text-align: ' . $align . ';
    line-height: 1.6;
    color: #333;
}
.container { max-width: 600px; margin: 0 auto; padding: 20px; }
h1, h2, h3 { color: #2563eb; }
</style>
</head>
<body>
<div class="container">
' . $body . '
</div>
</body>
</html>';
    } else {
        $styledBody = $body;
    }
    
    $headers = "Date: {$date}\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$username}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "Message-ID: {$messageId}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    if (!empty($attachments)) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($styledBody)) . "\r\n";
        
        foreach ($attachments as $attachment) {
            if (!file_exists($attachment['path'])) continue;
            
            $fileContent = file_get_contents($attachment['path']);
            $fileName = basename($attachment['name']);
            $fileType = $attachment['type'] ?? 'application/octet-stream';
            
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: {$fileType}; name=\"{$fileName}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n\r\n";
            $message .= chunk_split(base64_encode($fileContent)) . "\r\n";
        }
        
        $message .= "--{$boundary}--\r\n";
    } else {
        $headers .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        $message = chunk_split(base64_encode($styledBody));
    }
    
    fputs($socket, $headers . "\r\n" . $message . "\r\n.\r\n");
    $sendResponse = getSMTPResponse($socket);
    
    if (substr($sendResponse, 0, 3) != '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'فشل إرسال الرسالة: ' . $sendResponse];
    }
    
    sendSMTPCommand($socket, "QUIT");
    fclose($socket);
    
    return ['success' => true, 'error' => ''];
}

function sendSMTPCommand($socket, $command) {
    fputs($socket, $command . "\r\n");
}

function getSMTPResponse($socket) {
    $response = '';
    while (substr($response, 3, 1) != ' ') {
        $line = fgets($socket, 515);
        if ($line === false) break;
        $response .= $line;
    }
    return $response;
}

// ============================================
// معالجة رفع الملفات
// ============================================
$uploadedFiles = [];
if (!empty($_FILES['attachments'])) {
    $uploadDir = sys_get_temp_dir() . '/email_attachments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    foreach ($_FILES['attachments']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
            $fileName = $_FILES['attachments']['name'][$key];
            $fileType = $_FILES['attachments']['type'][$key];
            $targetPath = $uploadDir . uniqid() . '_' . $fileName;
            
            if (move_uploaded_file($tmpName, $targetPath)) {
                $uploadedFiles[] = [
                    'path' => $targetPath,
                    'name' => $fileName,
                    'type' => $fileType
                ];
            }
        }
    }
}

// ============================================
// معالجة إرسال الإيميلات
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_emails') {
    header('Content-Type: application/json');
    
    $smtpConfig = [
        'host' => $_POST['smtp_host'] ?? 'smtp-mail.outlook.com',
        'port' => intval($_POST['smtp_port'] ?? 587),
        'username' => $_POST['smtp_username'] ?? '',
        'password' => $_POST['smtp_password'] ?? '',
        'from_name' => $_POST['from_name'] ?? 'مرسل الوظائف'
    ];
    
    $subject = $_POST['email_subject'] ?? '';
    $body = $_POST['email_body'] ?? '';
    $emails = json_decode($_POST['emails_list'] ?? '[]', true);
    $delay = intval($_POST['send_delay'] ?? 3);
    $isHtml = isset($_POST['is_html']) && $_POST['is_html'] == '1';
    $rtl = isset($_POST['text_direction']) && $_POST['text_direction'] == 'rtl';
    
    if (empty($smtpConfig['username']) || empty($smtpConfig['password'])) {
        echo json_encode(['success' => false, 'message' => 'يرجى إدخال بيانات SMTP']);
        exit;
    }
    
    if (empty($subject) || empty($body)) {
        echo json_encode(['success' => false, 'message' => 'يرجى إدخال عنوان ومحتوى الرسالة']);
        exit;
    }
    
    if (empty($emails)) {
        echo json_encode(['success' => false, 'message' => 'لا توجد إيميلات للإرسال']);
        exit;
    }
    
    $results = [];
    $successCount = 0;
    $failCount = 0;
    
    foreach ($emails as $index => $email) {
        $result = sendEmailSMTP($email, $subject, $body, $smtpConfig, $uploadedFiles, $isHtml, $rtl);
        
        if ($result['success']) {
            $successCount++;
            $results[] = ['email' => $email, 'status' => 'success'];
        } else {
            $failCount++;
            $results[] = ['email' => $email, 'status' => 'failed', 'error' => $result['error']];
        }
        
        if ($delay > 0 && $index < count($emails) - 1) {
            sleep($delay);
        }
    }
    
    foreach ($uploadedFiles as $file) {
        if (file_exists($file['path'])) {
            unlink($file['path']);
        }
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($emails),
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'results' => $results
    ]);
    exit;
}

// ============================================
// الكلاس الأساسي للسكريبر (مبسط بدون Logs)
// ============================================
class EwdifhScraperPHP {
    private $baseUrl = "https://www.ewdifh.com";
    private $categoryUrl = "https://www.ewdifh.com/category/corporate-jobs";
    private $results = [];
    private $emails = [];
    private $settings = [];
    private $sseMode = false;
    
    public function __construct($settings = []) {
        $this->settings = array_merge([
            'max_pages' => 5,
            'concurrent' => 30,
            'delay' => 0,
            'timeout' => 15,
            'connect_timeout' => 5,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'follow_redirects' => true,
            'keyword_filter' => '',
            'search_in_title' => true,
            'search_in_content' => false
        ], $settings);
        
        $this->sseMode = (isset($_GET['sse']) && $_GET['sse'] == '1');
    }
    
    private function sendSSE($data) {
        if ($this->sseMode) {
            echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            usleep(10000);
        }
    }
    
    public function discoverPagination() {
        $pages = [];
        for ($i = 1; $i <= $this->settings['max_pages']; $i++) {
            $pages[] = ($i == 1) ? $this->categoryUrl : $this->categoryUrl . "?page=" . $i;
        }
        
        $this->sendSSE([
            'type' => 'stat_update',
            'stat' => 'pages',
            'value' => count($pages)
        ]);
        
        return $pages;
    }
    
    private function extractEmails($html) {
        $emails = [];
        $cleanHtml = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $cleanHtml = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $cleanHtml);
        
        if (preg_match_all('/mailto:([^?\s"\'<>]+@[^?\s"\'<>]+\.[^?\s"\'<>]+)/i', $cleanHtml, $matches)) {
            foreach ($matches[1] as $email) {
                $email = strtolower(trim(urldecode($email)));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = true;
                }
            }
        }
        
        if (preg_match_all('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $cleanHtml, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));
                if (strlen($email) < 100 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = true;
                }
            }
        }
        
        return array_keys($emails);
    }
    
    private function extractTextContent($html) {
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', ' ', $html);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', ' ', $text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return strtolower(trim($text));
    }
    
    private function matchesKeywordFilter($title, $content) {
        $keyword = strtolower(trim($this->settings['keyword_filter']));
        if (empty($keyword)) return true;
        
        $matches = false;
        if ($this->settings['search_in_title']) {
            if (stripos($title, $keyword) !== false) $matches = true;
        }
        if ($this->settings['search_in_content'] && !$matches) {
            if (stripos($content, $keyword) !== false) $matches = true;
        }
        return $matches;
    }
    
    private function fetchMultiUrls($urls) {
        if (empty($urls)) return [];
        
        $mh = curl_multi_init();
        $handles = [];
        $results = [];
        
        foreach ($urls as $key => $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $this->settings['timeout'],
                CURLOPT_CONNECTTIMEOUT => $this->settings['connect_timeout'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => $this->settings['user_agent'],
                CURLOPT_ENCODING => 'gzip, deflate',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: ar,en;q=0.9'
                ]
            ]);
            
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running > 0);
        
        foreach ($handles as $key => $ch) {
            $results[$key] = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($mh);
        return $results;
    }
    
    public function extractJobLinks($pages) {
        $allLinks = [];
        $chunks = array_chunk($pages, $this->settings['concurrent']);
        
        foreach ($chunks as $chunk) {
            $responses = $this->fetchMultiUrls($chunk);
            foreach ($responses as $response) {
                if ($response && preg_match_all('/href=["\']([^"\']*(?:\/jobs\/|\/job\/)[^"\']*)["\']/i', $response, $matches)) {
                    foreach ($matches[1] as $link) {
                        $fullUrl = (strpos($link, 'http') === 0) ? $link : $this->baseUrl . $link;
                        $allLinks[$fullUrl] = true;
                    }
                }
            }
        }
        
        $uniqueLinks = array_keys($allLinks);
        
        $this->sendSSE([
            'type' => 'stat_update',
            'stat' => 'jobs',
            'value' => count($uniqueLinks)
        ]);
        
        return $uniqueLinks;
    }
    
    public function processJobs($jobLinks) {
        $total = count($jobLinks);
        $chunks = array_chunk($jobLinks, $this->settings['concurrent']);
        $processed = 0;
        $skipped = 0;
        
        foreach ($chunks as $chunk) {
            $responses = $this->fetchMultiUrls($chunk);
            
            foreach ($responses as $index => $response) {
                if (!$response) {
                    $processed++;
                    continue;
                }
                
                $url = $chunk[$index];
                
                preg_match('/<title[^>]*>([^<]*)<\/title>/i', $response, $titleMatch);
                $title = isset($titleMatch[1]) ? preg_replace('/\s*-\s*اي وظيفة\s*$/i', '', trim($titleMatch[1])) : 'بدون عنوان';
                
                $contentText = $this->extractTextContent($response);
                
                if (!$this->matchesKeywordFilter($title, $contentText)) {
                    $skipped++;
                    $processed++;
                    continue;
                }
                
                $emails = $this->extractEmails($response);
                
                if (!empty($this->settings['email_filter'])) {
                    $emails = array_filter($emails, function($email) {
                        return stripos($email, $this->settings['email_filter']) !== false;
                    });
                }
                
                if (!empty($emails)) {
                    $this->results[] = [
                        'url' => $url,
                        'title' => $title,
                        'emails' => array_values($emails),
                        'count' => count($emails),
                        'matched_keyword' => !empty($this->settings['keyword_filter'])
                    ];
                    
                    foreach ($emails as $email) {
                        $this->emails[$email] = true;
                    }
                    
                    $this->sendSSE([
                        'type' => 'email_found',
                        'count' => count($this->emails)
                    ]);
                }
                
                $processed++;
            }
            
            $progress = min(100, round(($processed / $total) * 100));
            
            $this->sendSSE([
                'type' => 'progress',
                'progress' => $progress,
                'processed' => $processed,
                'total' => $total,
                'emails_found' => count($this->emails),
                'skipped' => $skipped
            ]);
        }
        
        return $this->results;
    }
    
    public function getResults() { return $this->results; }
    public function getUniqueEmails() { return array_keys($this->emails); }
    
    public function exportCSV() {
        $filename = "emails_" . date('Ymd_His') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['العنوان', 'الرابط', 'الايميلات', 'مطابق للبحث']);
        
        foreach ($this->results as $row) {
            fputcsv($output, [
                $row['title'],
                $row['url'],
                implode(' | ', $row['emails']),
                $row['matched_keyword'] ? 'نعم' : 'لا'
            ]);
        }
        
        fclose($output);
        exit;
    }
}

// ============================================
// معالجة طلبات SSE (مبسط)
// ============================================
if (isset($_GET['sse']) && $_GET['sse'] == '1') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_implicit_flush(true);
    
    echo "data: " . json_encode([
        'type' => 'connected',
        'message' => 'جاري البدء...'
    ], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    
    $settings = [
        'max_pages' => intval($_GET['max_pages'] ?? 3),
        'concurrent' => intval($_GET['concurrent'] ?? 30),
        'delay' => intval($_GET['delay'] ?? 0),
        'email_filter' => $_GET['email_filter'] ?? '',
        'timeout' => intval($_GET['timeout'] ?? 15),
        'keyword_filter' => $_GET['keyword_filter'] ?? '',
        'search_in_title' => isset($_GET['search_in_title']) && $_GET['search_in_title'] == '1',
        'search_in_content' => isset($_GET['search_in_content']) && $_GET['search_in_content'] == '1'
    ];
    
    $startTime = microtime(true);
    $scraper = new EwdifhScraperPHP($settings);
    
    try {
        $pages = $scraper->discoverPagination();
        $jobLinks = $scraper->extractJobLinks($pages);
        $results = $scraper->processJobs($jobLinks);
        
        $uniqueEmails = $scraper->getUniqueEmails();
        $stats = [
            'pages' => count($pages),
            'jobs' => count($jobLinks),
            'emails' => count($uniqueEmails),
            'time' => round(microtime(true) - $startTime, 2)
        ];
        
        $_SESSION['scraper_results'] = $results;
        $_SESSION['scraper_emails'] = $uniqueEmails;
        $_SESSION['scraper_stats'] = $stats;
        
        echo "data: " . json_encode([
            'type' => 'complete',
            'stats' => $stats,
            'redirect' => '?show_results=1'
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        
    } catch (Exception $e) {
        echo "data: " . json_encode([
            'type' => 'error',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
    
    exit;
}

// ============================================
// معالجة النموذج العادي
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scrape') {
    $startTime = microtime(true);
    $settings = [
        'max_pages' => intval($_POST['max_pages'] ?? 3),
        'concurrent' => intval($_POST['concurrent'] ?? 30),
        'delay' => intval($_POST['delay'] ?? 0),
        'email_filter' => $_POST['email_filter'] ?? '',
        'timeout' => intval($_POST['timeout'] ?? 15),
        'keyword_filter' => $_POST['keyword_filter'] ?? '',
        'search_in_title' => isset($_POST['search_in_title']) && $_POST['search_in_title'] == '1',
        'search_in_content' => isset($_POST['search_in_content']) && $_POST['search_in_content'] == '1'
    ];
    
    $scraper = new EwdifhScraperPHP($settings);
    
    $pages = $scraper->discoverPagination();
    $jobLinks = $scraper->extractJobLinks($pages);
    $results = $scraper->processJobs($jobLinks);
    
    $uniqueEmails = $scraper->getUniqueEmails();
    $stats = [
        'pages' => count($pages),
        'jobs' => count($jobLinks),
        'emails' => count($uniqueEmails),
        'time' => round(microtime(true) - $startTime, 2)
    ];
    
    $_SESSION['scraper_results'] = $results;
    $_SESSION['scraper_emails'] = $uniqueEmails;
    $_SESSION['scraper_stats'] = $stats;
    
    header("Location: ?show_results=1");
    exit;
}

// ============================================
// تحميل البيانات من الـ Session
// ============================================
$logs = [];
$results = [];
$uniqueEmails = [];
$stats = ['pages' => 0, 'jobs' => 0, 'emails' => 0, 'time' => 0];

if (isset($_GET['show_results']) && $_GET['show_results'] == '1') {
    if (isset($_SESSION['scraper_results'])) {
        $results = $_SESSION['scraper_results'];
        $uniqueEmails = $_SESSION['scraper_emails'];
        $stats = $_SESSION['scraper_stats'];
    }
}

// ============================================
// تصدير البيانات
// ============================================
if (isset($_GET['export'])) {
    if (isset($_SESSION['scraper_results'])) {
        $exportScraper = new EwdifhScraperPHP();
        $exportScraper->results = $_SESSION['scraper_results'];
        
        if ($_GET['export'] === 'csv') {
            $exportScraper->exportCSV();
        }
    }
}

?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مستخرج الإيميلات - نسخة محسنة</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { font-family: 'Tajawal', sans-serif; }
        
        /* تصميم عصري متدرج */
        .gradient-bg { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
        }
        .gradient-bg:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        
        /* تأثيرات الزر */
        .btn-interactive {
            transition: all 0.2s ease;
            transform: scale(1);
        }
        .btn-interactive:active {
            transform: scale(0.96);
        }
        .btn-interactive:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: scale(1);
        }
        
        /* شريط التقدم المتحرك */
        .progress-container {
            background: #e5e7eb;
            border-radius: 9999px;
            height: 16px;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 50%, #667eea 100%);
            background-size: 200% 100%;
            border-radius: 9999px;
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            animation: gradient-shift 2s ease infinite;
        }
        
        @keyframes gradient-shift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background: linear-gradient(
                90deg,
                rgba(255,255,255,0) 0%,
                rgba(255,255,255,0.4) 50%,
                rgba(255,255,255,0) 100%
            );
            animation: shimmer 1.5s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        /* بطاقات الإحصائيات */
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
        }
        
        /* حالات التحميل */
        .loading-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* تنسيقات النماذج */
        .form-input {
            width: 100%;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }
        
        /* وسوم الفلتر */
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #f3f4f6;
            border: 2px solid #e5e7eb;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        
        .filter-chip.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        /* شريط التمرير */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1; 
        }
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1; 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1; 
        }
        
        /* وسوم الإيميل */
        .email-tag {
            background: #e0e7ff;
            color: #3730a3;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid #c7d2fe;
            transition: all 0.2s;
        }
        
        .email-tag:hover { 
            background: #3730a3; 
            color: white; 
            transform: scale(1.05);
        }
        
        /* حاوية النتائج */
        .results-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Header -->
    <header class="gradient-bg text-white p-6 shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-envelope-open-text text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-black">مستخرج الإيميلات</h1>
                    <p class="text-xs opacity-80">استخراج ذكي من إعلانات الوظائف</p>
                </div>
            </div>
            <?php if (!empty($results)): ?>
                <div class="flex items-center gap-2 bg-white/20 px-4 py-2 rounded-full backdrop-blur-sm">
                    <i class="fas fa-check-circle text-green-300"></i>
                    <span class="text-sm font-bold"><?php echo count($uniqueEmails); ?> إيميل</span>
                </div>
            <?php endif; ?>
        </div>
    </header>
    
    <main class="max-w-7xl mx-auto p-4 lg:p-6 grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Sidebar - الإعدادات -->
        <div class="lg:col-span-4">
            <div class="bg-white rounded-2xl shadow-lg p-6 sticky top-24 border border-gray-200">
                <h2 class="font-bold text-lg mb-6 flex items-center gap-2 text-gray-800">
                    <i class="fas fa-sliders-h text-purple-600"></i> 
                    إعدادات الاستخراج
                </h2>
                
                <form method="POST" id="scraperForm" class="space-y-5">
                    <input type="hidden" name="action" value="scrape">
                    
                    <!-- فلتر البحث -->
                    <div class="bg-blue-50 p-4 rounded-xl border border-blue-100">
                        <label class="form-label text-blue-900">
                            <i class="fas fa-search ml-1"></i> فلتر البحث (اختياري)
                        </label>
                        <input type="text" name="keyword_filter" id="keywordFilter"
                            placeholder="مثال: امن معلومات، برمجة، محاسبة..."
                            class="form-input border-blue-200 focus:border-blue-500">
                        <div class="flex flex-wrap gap-2 mt-3">
                            <div class="filter-chip active" id="tagTitle" onclick="toggleFilter('title')">
                                <input type="checkbox" name="search_in_title" id="searchInTitle" value="1" checked class="hidden">
                                <i class="fas fa-heading"></i> العنوان
                            </div>
                            <div class="filter-chip" id="tagContent" onclick="toggleFilter('content')">
                                <input type="checkbox" name="search_in_content" id="searchInContent" value="1" class="hidden">
                                <i class="fas fa-align-left"></i> المحتوى
                            </div>
                        </div>
                    </div>
                    
                    <!-- عدد الصفحات -->
                    <div>
                        <label class="form-label">عدد الصفحات للبحث</label>
                        <input type="number" name="max_pages" id="maxPages" value="5" min="1"
                            class="form-input">
                        <p class="text-xs text-gray-500 mt-1">يمكنك إدخال أي عدد (مثال: 50, 100)</p>
                    </div>
                    
                    <!-- المعالجة المتزامنة -->
                    <div>
                        <label class="form-label">سرعة المعالجة</label>
                        <select name="concurrent" class="form-input">
                            <option value="10">عادية (10)</option>
                            <option value="30" selected>سريعة (30)</option>
                            <option value="50">صاروخية (50)</option>
                            <option value="100">هايبر (100)</option>
                        </select>
                    </div>
                    
                    <!-- خيار العرض المباشر -->
                    <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                        <input type="checkbox" id="enableLiveLog" checked 
                            class="w-5 h-5 text-purple-600 rounded focus:ring-purple-500 border-gray-300">
                        <label for="enableLiveLog" class="text-sm font-bold text-gray-700 cursor-pointer">
                            عرض التقدم اللحظي
                        </label>
                    </div>
                    
                    <!-- زر البدء -->
                    <button type="submit" id="submitBtn"
                        class="w-full gradient-bg text-white py-4 rounded-xl font-bold text-lg hover:shadow-xl transition flex items-center justify-center gap-2 btn-interactive">
                        <i class="fas fa-play" id="btnIcon"></i>
                        <span id="btnText">بدء الاستخراج</span>
                    </button>
                </form>
                
                <!-- منطقة التقدم (تظهر فقط أثناء التشغيل) -->
                <div id="progressArea" class="mt-6 hidden">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-sm font-bold text-gray-700">جاري المعالجة...</span>
                        <span id="progressPercent" class="text-lg font-black text-purple-600">0%</span>
                    </div>
                    
                    <div class="progress-container mb-4">
                        <div class="progress-fill" id="progressBar" style="width: 0%"></div>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-3">
                        <div class="stat-card p-3 text-center">
                            <div class="text-2xl font-black text-purple-600" id="statPages">0</div>
                            <div class="text-xs text-gray-500 font-bold">صفحات</div>
                        </div>
                        <div class="stat-card p-3 text-center">
                            <div class="text-2xl font-black text-blue-600" id="statJobs">0</div>
                            <div class="text-xs text-gray-500 font-bold">إعلان</div>
                        </div>
                        <div class="stat-card p-3 text-center">
                            <div class="text-2xl font-black text-emerald-600" id="statEmails">0</div>
                            <div class="text-xs text-gray-500 font-bold">إيميل</div>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <div class="inline-flex items-center gap-2 text-sm text-gray-500">
                            <i class="fas fa-circle-notch fa-spin text-purple-600"></i>
                            <span id="statusText">جاري الاتصال...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="lg:col-span-8">
            <?php if (!empty($results)): ?>
                <!-- نتائج النجاح -->
                <div class="bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-2xl p-6 mb-6 shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold mb-1">✨ اكتمل الاستخراج بنجاح!</h2>
                            <p class="text-emerald-100">تم العثور على <?php echo $stats['emails']; ?> إيميل فريد في <?php echo $stats['time']; ?> ثانية</p>
                        </div>
                        <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-trophy text-3xl"></i>
                        </div>
                    </div>
                </div>
                
                <!-- قسم إرسال الإيميلات -->
                <div class="results-container p-6 mb-6">
                    <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                        <i class="fas fa-paper-plane text-blue-600"></i>
                        إرسال الإيميلات
                    </h3>
                    
                    <!-- تبويبات -->
                    <div class="flex gap-2 mb-4 bg-gray-100 p-1 rounded-xl">
                        <button class="flex-1 py-2 px-4 rounded-lg bg-white shadow-sm text-sm font-bold text-gray-800" id="tab-single" onclick="switchTab('single')">
                            <i class="fas fa-user ml-1"></i> إيميل واحد
                        </button>
                        <button class="flex-1 py-2 px-4 rounded-lg text-sm font-bold text-gray-500 hover:text-gray-700" id="tab-bulk" onclick="switchTab('bulk')">
                            <i class="fas fa-users ml-1"></i> إرسال جماعي
                        </button>
                    </div>
                    
                    <!-- إعدادات SMTP -->
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="form-label text-xs">SMTP Host</label>
                            <select id="smtpHost" class="form-input py-2" onchange="updateSMTPSettings()">
                                <option value="smtp-mail.outlook.com">Outlook/Hotmail</option>
                                <option value="smtp.gmail.com">Gmail</option>
                                <option value="smtp.office365.com">Office 365</option>
                                <option value="smtp.mail.yahoo.com">Yahoo</option>
                            </select>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="form-label text-xs">Port</label>
                            <select id="smtpPort" class="form-input py-2">
                                <option value="587" selected>587 (STARTTLS)</option>
                                <option value="465">465 (SSL)</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="form-label text-xs">البريد الإلكتروني</label>
                            <input type="email" id="smtpUsername" placeholder="example@outlook.com" class="form-input py-2">
                        </div>
                        <div class="col-span-2">
                            <label class="form-label text-xs">كلمة مرور التطبيق</label>
                            <input type="password" id="smtpPassword" placeholder="ليس كلمة المرور العادية" class="form-input py-2">
                        </div>
                    </div>
                    
                    <!-- محتوى الرسالة -->
                    <div class="mb-4">
                        <label class="form-label text-xs">عنوان الرسالة</label>
                        <input type="text" id="emailSubject" placeholder="فرصة عمل..." class="form-input py-2 mb-3">
                        
                        <label class="form-label text-xs">محتوى الرسالة</label>
                        <textarea id="emailBody" rows="4" placeholder="اكتب رسالتك هنا..." class="form-input"></textarea>
                    </div>
                    
                    <!-- لوحة إيميل واحد -->
                    <div id="panel-single">
                        <label class="form-label text-xs">المرسل إليه</label>
                        <input type="email" id="singleEmail" placeholder="email@example.com" class="form-input py-2 mb-3">
                    </div>
                    
                    <!-- لوحة الإرسال الجماعي -->
                    <div id="panel-bulk" class="hidden">
                        <label class="form-label text-xs">اختيار الإيميلات (<?php echo count($uniqueEmails); ?> متاح)</label>
                        <div class="bg-gray-50 p-3 rounded-xl max-h-40 overflow-y-auto mb-3 border border-gray-200">
                            <?php foreach ($uniqueEmails as $email): ?>
                                <label class="flex items-center gap-2 mb-2 cursor-pointer hover:bg-white p-2 rounded transition">
                                    <input type="checkbox" class="email-checkbox w-4 h-4 text-purple-600 rounded" value="<?php echo htmlspecialchars($email); ?>">
                                    <span class="text-sm text-gray-700"><?php echo htmlspecialchars($email); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="selectAllEmails()" class="text-xs bg-purple-100 text-purple-700 px-3 py-1.5 rounded-lg font-bold hover:bg-purple-200 transition">تحديد الكل</button>
                            <button onclick="deselectAllEmails()" class="text-xs bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg font-bold hover:bg-gray-200 transition">إلغاء التحديد</button>
                        </div>
                    </div>
                    
                    <!-- زر الإرسال -->
                    <button onclick="sendEmails()" id="sendBtn"
                        class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold hover:bg-blue-700 transition flex items-center justify-center gap-2 btn-interactive mt-4">
                        <i class="fas fa-paper-plane"></i>
                        <span id="sendBtnText">إرسال</span>
                    </button>
                    
                    <!-- نتائج الإرسال -->
                    <div id="sendResults" class="mt-4 hidden">
                        <div class="bg-gray-50 rounded-xl p-4 max-h-60 overflow-y-auto" id="sendResultsList"></div>
                    </div>
                </div>
                
                <!-- الإحصائيات -->
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="stat-card text-center">
                        <div class="stat-icon bg-purple-100 text-purple-600 mx-auto">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="text-3xl font-black text-gray-800 mb-1"><?php echo $stats['pages']; ?></div>
                        <div class="text-sm text-gray-500 font-bold">صفحة</div>
                    </div>
                    <div class="stat-card text-center">
                        <div class="stat-icon bg-blue-100 text-blue-600 mx-auto">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <div class="text-3xl font-black text-gray-800 mb-1"><?php echo $stats['jobs']; ?></div>
                        <div class="text-sm text-gray-500 font-bold">إعلان</div>
                    </div>
                    <div class="stat-card text-center">
                        <div class="stat-icon bg-emerald-100 text-emerald-600 mx-auto">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="text-3xl font-black text-gray-800 mb-1"><?php echo $stats['emails']; ?></div>
                        <div class="text-sm text-gray-500 font-bold">إيميل فريد</div>
                    </div>
                </div>
                
                <!-- زر التصدير -->
                <div class="mb-6">
                    <a href="?export=csv" class="block w-full bg-emerald-500 text-white py-4 rounded-xl text-center font-bold hover:bg-emerald-600 transition shadow-lg flex items-center justify-center gap-2 btn-interactive">
                        <i class="fas fa-file-csv text-xl"></i>
                        تحميل النتائج (CSV)
                    </a>
                </div>
                
                <!-- جدول النتائج -->
                <div class="results-container overflow-hidden">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="font-bold text-gray-800">تفاصيل الإعلانات</h3>
                        <span class="text-sm text-gray-500"><?php echo count($results); ?> نتيجة</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-right font-bold text-gray-600 w-12">#</th>
                                    <th class="px-4 py-3 text-right font-bold text-gray-600">الوظيفة</th>
                                    <th class="px-4 py-3 text-center font-bold text-gray-600 w-20">الإيميلات</th>
                                    <th class="px-4 py-3 w-16"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($results as $index => $row): ?>
                                    <tr class="hover:bg-purple-50/50 transition-colors">
                                        <td class="px-4 py-3 text-gray-400 font-bold"><?php echo $index + 1; ?></td>
                                        <td class="px-4 py-3">
                                            <div class="font-bold text-gray-800 mb-2">
                                                <?php echo htmlspecialchars($row['title']); ?>
                                                <?php if (!empty($row['matched_keyword'])): ?>
                                                    <span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full mr-2">مطابق</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($row['emails'] as $email): ?>
                                                    <span class="email-tag text-xs" onclick="copy('<?php echo htmlspecialchars($email); ?>')">
                                                        <?php echo htmlspecialchars($email); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="bg-purple-100 text-purple-700 px-3 py-1 rounded-full font-bold"><?php echo $row['count']; ?></span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="<?php echo htmlspecialchars($row['url']); ?>" target="_blank"
                                                class="w-10 h-10 rounded-full bg-gray-100 text-gray-600 hover:bg-purple-600 hover:text-white flex items-center justify-center transition-all">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- جميع الإيميلات -->
                <?php if (!empty($uniqueEmails)): ?>
                    <div class="mt-6 results-container p-6">
                        <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                            <i class="fas fa-envelope-open text-purple-600"></i>
                            جميع الإيميلات الفريدة (<?php echo count($uniqueEmails); ?>)
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($uniqueEmails as $email): ?>
                                <span class="email-tag" onclick="copy('<?php echo htmlspecialchars($email); ?>')">
                                    <i class="fas fa-copy text-xs"></i>
                                    <?php echo htmlspecialchars($email); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- حالة البداية -->
                <div class="h-full flex items-center justify-center min-h-[500px]">
                    <div class="text-center">
                        <div class="w-32 h-32 bg-gradient-to-br from-purple-100 to-blue-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                            <i class="fas fa-search text-5xl text-purple-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-800 mb-2">ابدأ الاستخراج الآن</h3>
                        <p class="text-gray-500 max-w-md mx-auto leading-relaxed">
                            اضبط إعدادات البحث من القائمة على اليسار، ثم اضغط على "بدء الاستخراج" للحصول على قائمة الإيميلات من إعلانات الوظائف.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // ============================================
        // دوال مساعدة
        // ============================================
        function copy(text) {
            navigator.clipboard.writeText(text).then(() => {
                const toast = document.createElement('div');
                toast.className = 'fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-gray-800 text-white px-6 py-3 rounded-full shadow-2xl z-50 flex items-center gap-2 animate-bounce';
                toast.innerHTML = '<i class="fas fa-check-circle text-green-400"></i> تم النسخ';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 2000);
            });
        }
        
        function toggleFilter(type) {
            const checkbox = document.getElementById(type === 'title' ? 'searchInTitle' : 'searchInContent');
            const tag = document.getElementById(type === 'title' ? 'tagTitle' : 'tagContent');
            checkbox.checked = !checkbox.checked;
            tag.classList.toggle('active', checkbox.checked);
        }
        
        function updateSMTPSettings() {
            const host = document.getElementById('smtpHost').value;
            const portSelect = document.getElementById('smtpPort');
            if (host === 'smtp.gmail.com') portSelect.value = '587';
            else if (host === 'smtp.office365.com') portSelect.value = '587';
        }
        
        function switchTab(tab) {
            document.getElementById('tab-single').className = tab === 'single' ? 
                'flex-1 py-2 px-4 rounded-lg bg-white shadow-sm text-sm font-bold text-gray-800' : 
                'flex-1 py-2 px-4 rounded-lg text-sm font-bold text-gray-500 hover:text-gray-700';
            document.getElementById('tab-bulk').className = tab === 'bulk' ? 
                'flex-1 py-2 px-4 rounded-lg bg-white shadow-sm text-sm font-bold text-gray-800' : 
                'flex-1 py-2 px-4 rounded-lg text-sm font-bold text-gray-500 hover:text-gray-700';
            
            document.getElementById('panel-single').classList.toggle('hidden', tab !== 'single');
            document.getElementById('panel-bulk').classList.toggle('hidden', tab !== 'bulk');
        }
        
        function selectAllEmails() {
            document.querySelectorAll('.email-checkbox').forEach(cb => cb.checked = true);
        }
        
        function deselectAllEmails() {
            document.querySelectorAll('.email-checkbox').forEach(cb => cb.checked = false);
        }
        
        // ============================================
        // إرسال الإيميلات
        // ============================================
        function sendEmails() {
            const smtpConfig = {
                host: document.getElementById('smtpHost').value,
                port: document.getElementById('smtpPort').value,
                username: document.getElementById('smtpUsername').value,
                password: document.getElementById('smtpPassword').value
            };
            
            const subject = document.getElementById('emailSubject').value;
            const body = document.getElementById('emailBody').value;
            
            let emails = [];
            if (!document.getElementById('panel-single').classList.contains('hidden')) {
                const singleEmail = document.getElementById('singleEmail').value;
                if (singleEmail) emails.push(singleEmail);
            } else {
                document.querySelectorAll('.email-checkbox:checked').forEach(cb => emails.push(cb.value));
            }
            
            if (!smtpConfig.username || !smtpConfig.password) {
                alert('يرجى إدخال بيانات SMTP');
                return;
            }
            if (!subject || !body) {
                alert('يرجى إدخال عنوان ومحتوى الرسالة');
                return;
            }
            if (emails.length === 0) {
                alert('يرجى اختيار إيميل واحد على الأقل');
                return;
            }
            
            const btn = document.getElementById('sendBtn');
            const btnText = document.getElementById('sendBtnText');
            btn.disabled = true;
            btnText.textContent = 'جاري الإرسال...';
            
            const formData = new FormData();
            formData.append('action', 'send_emails');
            formData.append('smtp_host', smtpConfig.host);
            formData.append('smtp_port', smtpConfig.port);
            formData.append('smtp_username', smtpConfig.username);
            formData.append('smtp_password', smtpConfig.password);
            formData.append('email_subject', subject);
            formData.append('email_body', body);
            formData.append('emails_list', JSON.stringify(emails));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                btn.disabled = false;
                btnText.textContent = 'إرسال';
                
                const resultsDiv = document.getElementById('sendResults');
                const resultsList = document.getElementById('sendResultsList');
                resultsDiv.classList.remove('hidden');
                resultsList.innerHTML = '';
                
                if (data.success) {
                    data.results.forEach(result => {
                        const div = document.createElement('div');
                        div.className = 'flex items-center justify-between p-3 mb-2 rounded-lg ' + 
                            (result.status === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700');
                        div.innerHTML = '<span class="text-sm font-bold">' + result.email + '</span>' +
                            (result.status === 'success' ? 
                                '<i class="fas fa-check-circle text-emerald-500"></i>' : 
                                '<i class="fas fa-times-circle text-red-500"></i>');
                        resultsList.appendChild(div);
                    });
                }
            })
            .catch(error => {
                btn.disabled = false;
                btnText.textContent = 'إرسال';
                alert('حدث خطأ: ' + error);
            });
        }
        
        // ============================================
        // معالجة SSE والتقدم (مبسطة بدون Logs)
        // ============================================
        document.getElementById('scraperForm').addEventListener('submit', function(e) {
            if (document.getElementById('enableLiveLog').checked) {
                e.preventDefault();
                
                const btn = document.getElementById('submitBtn');
                const btnText = document.getElementById('btnText');
                const btnIcon = document.getElementById('btnIcon');
                const progressArea = document.getElementById('progressArea');
                
                // إظهار منطقة التقدم
                progressArea.classList.remove('hidden');
                btn.disabled = true;
                btnIcon.className = 'fas fa-circle-notch fa-spin';
                btnText.textContent = 'جاري العمل...';
                
                // جمع البيانات
                const maxPages = document.getElementById('maxPages').value;
                const concurrent = document.querySelector('select[name="concurrent"]').value;
                const keywordFilter = encodeURIComponent(document.getElementById('keywordFilter').value);
                const searchInTitle = document.getElementById('searchInTitle').checked ? '1' : '0';
                const searchInContent = document.getElementById('searchInContent').checked ? '1' : '0';
                
                const url = '?sse=1&max_pages=' + maxPages + 
                           '&concurrent=' + concurrent + 
                           '&keyword_filter=' + keywordFilter + 
                           '&search_in_title=' + searchInTitle + 
                           '&search_in_content=' + searchInContent;
                
                const eventSource = new EventSource(url);
                
                eventSource.onmessage = function(e) {
                    try {
                        const data = JSON.parse(e.data);
                        
                        if (data.type === 'stat_update') {
                            const element = document.getElementById(
                                data.stat === 'pages' ? 'statPages' : 
                                data.stat === 'jobs' ? 'statJobs' : 'statEmails'
                            );
                            if (element) element.textContent = data.value;
                        }
                        
                        if (data.type === 'progress') {
                            document.getElementById('progressBar').style.width = data.progress + '%';
                            document.getElementById('progressPercent').textContent = data.progress + '%';
                            document.getElementById('statEmails').textContent = data.emails_found;
                            document.getElementById('statusText').textContent = 
                                'تم معالجة ' + data.processed + ' من ' + data.total;
                        }
                        
                        if (data.type === 'complete') {
                            eventSource.close();
                            btnIcon.className = 'fas fa-check';
                            btnText.textContent = 'اكتمل!';
                            btn.classList.remove('gradient-bg');
                            btn.classList.add('bg-emerald-500');
                            
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 800);
                        }
                        
                        if (data.type === 'error') {
                            eventSource.close();
                            btn.disabled = false;
                            btnIcon.className = 'fas fa-exclamation-triangle';
                            btnText.textContent = 'خطأ - إعادة المحاولة';
                            alert('حدث خطأ: ' + data.message);
                        }
                        
                    } catch (err) {
                        console.error('Error:', err);
                    }
                };
                
                eventSource.onerror = function() {
                    eventSource.close();
                    btn.disabled = false;
                    btnIcon.className = 'fas fa-play';
                    btnText.textContent = 'إعادة المحاولة';
                    document.getElementById('scraperForm').submit();
                };
            }
        });
    </script>
</body>
</html>
