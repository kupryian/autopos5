<?php
/**
 * Простой OpenAI Proxy для Vercel
 */

// Включаем отладку
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS заголовки
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Логируем все входящие запросы
file_put_contents('/tmp/proxy_debug.log', date('Y-m-d H:i:s') . " - Method: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit('{"status":"OK"}');
}

// Обработка GET для проверки
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'OpenAI Proxy Active',
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    exit();
}

// Только POST запросы для API
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed: ' . $_SERVER['REQUEST_METHOD']]);
    exit();
}

try {
    // Получаем данные
    $input = file_get_contents('php://input');
    file_put_contents('/tmp/proxy_debug.log', "Input: " . substr($input, 0, 200) . "\n", FILE_APPEND);
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON decode error: ' . json_last_error_msg());
    }
    
    if (!$data || !isset($data['api_key']) || !isset($data['payload'])) {
        throw new Exception('Invalid request format - missing api_key or payload');
    }
    
    $api_key = $data['api_key'];
    $payload = $data['payload'];
    $endpoint = $data['endpoint'] ?? 'chat/completions';
    
    // URL OpenAI API
    $openai_urls = [
        'chat/completions' => 'https://api.openai.com/v1/chat/completions',
        'images/generations' => 'https://api.openai.com/v1/images/generations'
    ];
    
    $openai_url = $openai_urls[$endpoint] ?? $openai_urls['chat/completions'];
    
    // Запрос к OpenAI
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $openai_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'User-Agent: AutoPOS-Proxy/2.0'
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false // Упрощаем для отладки
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception('cURL error: ' . $curl_error);
    }
    
    // Логируем ответ OpenAI
    file_put_contents('/tmp/proxy_debug.log', "OpenAI response code: $http_code\n", FILE_APPEND);
    
    // Возвращаем ответ
    http_response_code($http_code);
    echo $response;
    
} catch (Exception $e) {
    file_put_contents('/tmp/proxy_debug.log', "Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>