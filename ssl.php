<?php
/**
 * Test script to check Deriv agent balance
 * This script connects to Deriv WebSocket API and retrieves the agent balance
 */

// Include the WebSocket client library
require_once 'vendor/autoload.php'; // Assuming you're using Composer
use WebSocket\Client;

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Africa/Nairobi');

class DerivBalanceTest {
    
    private $appId = 76420;
    private $endpoint = 'ws.binaryws.com';
    private $token = 'DidPRclTKE0WYtT';
    
    public function __construct() {
        echo "=== Deriv Agent Balance Checker ===\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n";
        echo "App ID: " . $this->appId . "\n";
        echo "Endpoint: " . $this->endpoint . "\n\n";
    }
    
    public function checkBalance() {
        $url = "wss://{$this->endpoint}/websockets/v3?app_id={$this->appId}";
        
        try {
            echo "Connecting to WebSocket: $url\n";
            
            // Create WebSocket client with timeout
            $client = new Client($url, [
                'timeout' => 10,
                'headers' => []
            ]);
            
            echo "Connected successfully!\n\n";
            
            // Step 1: Authorize
            echo "Step 1: Authorizing...\n";
            $authRequest = json_encode([
                "authorize" => $this->token,
                "req_id" => 1
            ]);
            
            echo "Sending auth request: " . $authRequest . "\n";
            $client->send($authRequest);
            
            $authResponse = $client->receive();
            echo "Auth response: " . $authResponse . "\n";
            
            $authData = json_decode($authResponse, true);
            
            if (isset($authData['error'])) {
                throw new Exception("Authorization failed: " . $authData['error']['message']);
            }
            
            if (isset($authData['authorize'])) {
                echo "Authorization successful!\n";
                echo "Account ID: " . $authData['authorize']['loginid'] . "\n";
                echo "Currency: " . $authData['authorize']['currency'] . "\n";
                echo "Balance: " . $authData['authorize']['balance'] . "\n\n";
            }
            
            // Step 2: Get Balance
            echo "Step 2: Getting balance...\n";
            $balanceRequest = json_encode([
                "balance" => 1,
                "req_id" => 2
            ]);
            
            echo "Sending balance request: " . $balanceRequest . "\n";
            $client->send($balanceRequest);
            
            $balanceResponse = $client->receive();
            echo "Balance response: " . $balanceResponse . "\n";
            
            $balanceData = json_decode($balanceResponse, true);
            
            if (isset($balanceData['error'])) {
                throw new Exception("Balance check failed: " . $balanceData['error']['message']);
            }
            
            // Close connection
            $client->close();
            
            if (isset($balanceData['balance'])) {
                echo "\n=== BALANCE INFORMATION ===\n";
                echo "Balance: " . number_format($balanceData['balance']['balance'], 2) . "\n";
                echo "Currency: " . $balanceData['balance']['currency'] . "\n";
                echo "Login ID: " . $balanceData['balance']['loginid'] . "\n";
                echo "Formatted: " . number_format($balanceData['balance']['balance'], 2) . " " . $balanceData['balance']['currency'] . "\n";
                
                return [
                    'success' => true,
                    'balance' => $balanceData['balance']['balance'],
                    'currency' => $balanceData['balance']['currency'],
                    'loginid' => $balanceData['balance']['loginid']
                ];
            } else {
                throw new Exception("No balance data received");
            }
            
        } catch (Exception $e) {
            echo "\nERROR: " . $e->getMessage() . "\n";
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function testConnection() {
        echo "=== Testing Connection ===\n";
        
        $result = $this->checkBalance();
        
        if ($result['success']) {
            echo "\n✅ SUCCESS: Connection and balance check completed!\n";
            echo "Agent Balance: " . number_format($result['balance'], 2) . " " . $result['currency'] . "\n";
            
            // Check if balance is sufficient for transactions
            if ($result['balance'] >= 100) {
                echo "✅ Balance is sufficient for transactions\n";
            } else {
                echo "⚠️  WARNING: Low balance - may not be sufficient for large transactions\n";
            }
            
        } else {
            echo "\n❌ FAILED: " . $result['error'] . "\n";
        }
        
        return $result;
    }
    
    public function runDiagnostics() {
        echo "\n=== Running Diagnostics ===\n";
        
        // Check if WebSocket client is available
        if (!class_exists('WebSocket\Client')) {
            echo "❌ WebSocket Client class not found. Please install: composer require textalk/websocket-client\n";
            return false;
        } else {
            echo "✅ WebSocket Client class available\n";
        }
        
        // Check if curl is available
        if (!function_exists('curl_init')) {
            echo "❌ cURL not available\n";
        } else {
            echo "✅ cURL available\n";
        }
        
        // Check if json functions are available
        if (!function_exists('json_encode') || !function_exists('json_decode')) {
            echo "❌ JSON functions not available\n";
        } else {
            echo "✅ JSON functions available\n";
        }
        
        // Test internet connectivity
        echo "Testing internet connectivity...\n";
        $connected = @fsockopen("www.google.com", 80, $errno, $errstr, 5);
        if ($connected) {
            echo "✅ Internet connection available\n";
            fclose($connected);
        } else {
            echo "❌ No internet connection\n";
        }
        
        return true;
    }
}

// Run the test
try {
    $test = new DerivBalanceTest();
    
    // Run diagnostics first
    $test->runDiagnostics();
    
    // Test the connection and balance check
    $result = $test->testConnection();
    
    // Output JSON format for API usage
    echo "\n=== JSON OUTPUT ===\n";
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>