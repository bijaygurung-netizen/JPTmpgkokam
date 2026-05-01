<?php
/**
 * WhatsApp Webhook Proxy for MPG Solution
 * 
 * This PHP script handles Meta WhatsApp webhook requests and forwards them
 * to the Supabase Edge Function for processing.
 * 
 * URL: https://mpgsolution.com/api/whatsapp-webhook
 */

// Set headers for JSON responses
header('Content-Type: application/json');

// Supabase configuration
$SUPABASE_URL = 'https://afovmyjppfdcwgslnzik.supabase.co';
$SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImFmb3ZteWpwcGZkY3dnc2xuemlrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjAzNjYyNDEsImV4cCI6MjA3NTk0MjI0MX0.aMordGXPqNjAWsis4su0NrD6V07TDMIuG4xNQW6WRBM';
$EDGE_FUNCTION_URL = $SUPABASE_URL . '/functions/v1/whatsapp-webhook';

// Verify token for Meta webhook verification (from wa_settings table)
$VERIFY_TOKEN = '6b5d7418-6051-4fba-b920-603ebe0cac3a';

/**
 * Handle GET request - Meta Webhook Verification
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    
    // Log verification attempt
    error_log("WhatsApp Webhook Verification - Mode: $mode, Token: $token");
    
    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        // Verification successful - return the challenge
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    } else {
        // Verification failed
        http_response_code(403);
        echo json_encode(['error' => 'Verification failed', 'message' => 'Invalid verify token']);
        exit;
    }
}

/**
 * Handle POST request - Forward to Supabase Edge Function
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST body
    $body = file_get_contents('php://input');
    
    // Log incoming webhook (for debugging)
    error_log("WhatsApp Webhook POST received: " . substr($body, 0, 500));
    
    // Initialize cURL
    $ch = curl_init($EDGE_FUNCTION_URL);
    
    // Set cURL options
    // NOTE: Some shared hosting environments have outdated CA bundles.
    // To prevent webhook delivery from failing, we disable SSL peer verification
    // for this server-to-server call (Meta -> this proxy is still HTTPS).
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $SUPABASE_ANON_KEY,
            'apikey: ' . $SUPABASE_ANON_KEY,
            'X-Webhook-Proxy: mpgsolution-cpanel',
        ],
        // Keep it fast - Meta expects a quick 200
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        // Shared hosting CA bundles often break outbound SSL verification
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    
    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    // Handle cURL errors
    // IMPORTANT: Always return 200 to Meta so it doesn't stop sending webhooks.
    if ($curlError) {
        error_log("WhatsApp Webhook cURL Error: $curlError");
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'proxied' => false,
            'proxy_error' => $curlError,
        ]);
        exit;
    }
    
    // Log response (for debugging)
    error_log("WhatsApp Webhook Response (HTTP $httpCode): " . substr($response, 0, 200));
    
    // Return the response from Supabase (200/4xx doesn't matter much to Meta as long as it's fast)
    http_response_code(200);
    echo $response ?: json_encode(['success' => true, 'proxied' => true]);
    exit;
}

/**
 * Handle OPTIONS request - CORS preflight
 */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
