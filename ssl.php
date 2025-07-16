<?php
declare(strict_types=1);

/**
 * Deriv Agent Balance Checker
 * 
 * Connects to Deriv WebSocket API to retrieve and verify agent account balance
 */

require_once 'vendor/autoload.php';
use WebSocket\Client;
use WebSocket\TimeoutException;
use JsonException;

class DerivBalanceChecker
{
    private const APP_ID = 76420;
    private const ENDPOINT = 'ws.binaryws.com';
    private const TIMEOUT = 10;
    private const LOW_BALANCE_THRESHOLD = 100;

    private string $token;
    private Client $client;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->initialize();
    }

    private function initialize(): void
    {
        date_default_timezone_set('Africa/Nairobi');
        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        $this->printHeader();
    }

    private function printHeader(): void
    {
        echo "=== Deriv Agent Balance Checker ===" . PHP_EOL;
        echo "Time: " . date('Y-m-d H:i:s') . PHP_EOL;
        echo "App ID: " . self::APP_ID . PHP_EOL;
        echo "Endpoint: " . self::ENDPOINT . PHP_EOL . PHP_EOL;
    }

    public function run(): array
    {
        try {
            $this->runDiagnostics();
            $balanceData = $this->checkBalance();
            $this->printResults($balanceData);
            
            return $this->formatResults($balanceData);
            
        } catch (Exception $e) {
            $this->handleError($e);
            return $this->formatError($e);
        }
    }

    private function connect(): void
    {
        $url = sprintf('wss://%s/websockets/v3?app_id=%d', self::ENDPOINT, self::APP_ID);
        echo "Connecting to WebSocket: $url" . PHP_EOL;
        
        $this->client = new Client($url, [
            'timeout' => self::TIMEOUT
        ]);
        
        echo "Connected successfully!" . PHP_EOL . PHP_EOL;
    }

    private function authorize(): array
    {
        echo "Step 1: Authorizing..." . PHP_EOL;
        
        $request = [
            "authorize" => $this->token,
            "req_id" => 1
        ];
        
        $this->sendRequest($request, 'Auth request');
        $response = $this->receiveResponse();
        
        if (!isset($response['authorize'])) {
            throw new RuntimeException("Authorization failed: " . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        echo "Authorization successful!" . PHP_EOL;
        echo "Account ID: " . $response['authorize']['loginid'] . PHP_EOL;
        echo "Currency: " . $response['authorize']['currency'] . PHP_EOL;
        echo "Balance: " . $response['authorize']['balance'] . PHP_EOL . PHP_EOL;
        
        return $response;
    }

    private function getBalance(): array
    {
        echo "Step 2: Getting balance..." . PHP_EOL;
        
        $request = [
            "balance" => 1,
            "req_id" => 2
        ];
        
        $this->sendRequest($request, 'Balance request');
        $response = $this->receiveResponse();
        
        if (!isset($response['balance'])) {
            throw new RuntimeException("Balance check failed: " . ($response['error']['message'] ?? 'Unknown error'));
        }
        
        return $response['balance'];
    }

    private function checkBalance(): array
    {
        $this->connect();
        $this->authorize();
        $balanceData = $this->getBalance();
        $this->client->close();
        
        return $balanceData;
    }

    private function runDiagnostics(): void
    {
        echo "=== Running Diagnostics ===" . PHP_EOL;
        
        $checks = [
            'WebSocket Client' => class_exists(Client::class),
            'cURL' => function_exists('curl_init'),
            'JSON' => function_exists('json_encode') && function_exists('json_decode'),
            'Internet Connection' => $this->checkInternetConnection(),
        ];
        
        foreach ($checks as $name => $status) {
            echo ($status ? "✅" : "❌") . " $name" . PHP_EOL;
        }
        
        echo PHP_EOL;
    }

    private function checkInternetConnection(): bool
    {
        $connected = @fsockopen("www.google.com", 80, $errno, $errstr, 5);
        if ($connected) {
            fclose($connected);
            return true;
        }
        return false;
    }

    private function printResults(array $balanceData): void
    {
        echo "=== BALANCE INFORMATION ===" . PHP_EOL;
        echo "Balance: " . number_format($balanceData['balance'], 2) . PHP_EOL;
        echo "Currency: " . $balanceData['currency'] . PHP_EOL;
        echo "Login ID: " . $balanceData['loginid'] . PHP_EOL;
        echo "Formatted: " . number_format($balanceData['balance'], 2) . " " . $balanceData['currency'] . PHP_EOL;
        
        if ($balanceData['balance'] >= self::LOW_BALANCE_THRESHOLD) {
            echo "✅ Balance is sufficient for transactions" . PHP_EOL;
        } else {
            echo "⚠️  WARNING: Low balance" . PHP_EOL;
        }
    }

    private function sendRequest(array $data, string $description): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        echo "$description: $json" . PHP_EOL;
        $this->client->send($json);
    }

    private function receiveResponse(): array
    {
        $response = $this->client->receive();
        echo "Response: $response" . PHP_EOL;
        
        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON response: " . $e->getMessage());
        }
    }

    private function formatResults(array $balanceData): array
    {
        return [
            'success' => true,
            'balance' => $balanceData['balance'],
            'currency' => $balanceData['currency'],
            'loginid' => $balanceData['loginid'],
            'formatted' => number_format($balanceData['balance'], 2) . ' ' . $balanceData['currency'],
            'sufficient' => $balanceData['balance'] >= self::LOW_BALANCE_THRESHOLD
        ];
    }

    private function handleError(Exception $e): void
    {
        echo PHP_EOL . "ERROR: " . $e->getMessage() . PHP_EOL;
    }

    private function formatError(Exception $e): array
    {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ];
    }
}

// Execution
try {
    $checker = new DerivBalanceChecker('DidPRclTKE0WYtT');
    $result = $checker->run();
    
    echo PHP_EOL . "=== JSON OUTPUT ===" . PHP_EOL;
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . "=== Script Complete ===" . PHP_EOL;