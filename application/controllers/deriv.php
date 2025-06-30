<?php

/**
 * Enhanced Deriv deposit processing with robust error handling
 */
class DerivDepositProcessor
{
    private $appId;
    private $paymentAgentToken;
    private $logFile;
    
    public function __construct()
    {
        $this->appId = 76420; // Your app ID
        $this->paymentAgentToken = 'DidPRclTKE0WYtT'; // Replace with actual token
        $this->logFile = 'logs/deriv_transactions_' . date('Y-m-d') . '.log';
    }
    
    /**
     * Process deposit request with comprehensive logging
     */
    public function process_request($request_id)
    {
        $this->log("=== Starting deposit process for request_id: $request_id ===");
        
        if (empty($request_id)) {
            $this->log("ERROR: Request ID is empty");
            return $this->errorResponse('Request ID is empty.');
        }

        // Get request details from database
        $table = 'deriv_deposit_request';
        $condition = array('transaction_id' => $request_id);
        $search = $this->Operations->SearchByCondition($table, $condition);
        
        if (empty($search)) {
            $this->log("ERROR: Request not found in database for ID: $request_id");
            return $this->errorResponse('Request not found.');
        }
        
        $amount = $search[0]['amount'];
        $cr_number = $search[0]['cr_number'];
        $wallet_id = $search[0]['wallet_id'];
        $transaction_number = $search[0]['transaction_number'];
        
        $this->log("Request details - Amount: $amount, CR Number: $cr_number, Wallet ID: $wallet_id");
        
        // Validate Payment Agent account first
        $validationResult = $this->validatePaymentAgent();
        if (!$validationResult['success']) {
            $this->log("ERROR: Payment Agent validation failed: " . $validationResult['message']);
            return $this->errorResponse('Payment Agent validation failed: ' . $validationResult['message']);
        }
        
        // Attempt the transfer with multiple retry attempts
        $maxRetries = 3;
        $transferResult = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $this->log("Transfer attempt $attempt of $maxRetries");
            $transferResult = $this->transferToDerivAccount($cr_number, $amount, $transaction_number);
            
            if ($transferResult['success']) {
                $this->log("Transfer successful on attempt $attempt");
                break;
            } else {
                $this->log("Transfer failed on attempt $attempt: " . $transferResult['message']);
                if ($attempt < $maxRetries) {
                    sleep(2); // Wait 2 seconds before retry
                }
            }
        }
        
        if ($transferResult['success']) {
            // Update database only after successful transfer
            $data = array('status' => 1, 'deposited' => $amount);
            $update = $this->Operations->UpdateData($table, $condition, $data);
            
            if ($update === TRUE) {
                $this->sendSuccessNotifications($wallet_id, $transaction_number, $amount, $cr_number);
                $this->log("SUCCESS: Deposit completed successfully");
                return $this->successResponse("$transaction_number processed, {$amount}USD has been successfully deposited to deriv account $cr_number", $transferResult['data']);
            } else {
                $this->log("ERROR: Database update failed after successful transfer");
                return $this->errorResponse('Database update failed after transfer');
            }
        } else {
            $this->log("ERROR: All transfer attempts failed");
            return $this->errorResponse('Transfer failed after multiple attempts: ' . $transferResult['message']);
        }
    }
    
    /**
     * Enhanced transfer function with better error handling
     */
    private function transferToDerivAccount($loginid, $amount, $transaction_number)
    {
        $this->log("Initiating transfer to loginid: $loginid, amount: $amount");
        
        try {
            // Create WebSocket connection with enhanced settings
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ]
            ]);
            
            $endpoint = 'ws.binaryws.com';
            $url = "wss://{$endpoint}/websockets/v3?app_id={$this->appId}";
            
            $this->log("Connecting to WebSocket: $url");
            
            // Use ReactPHP or Ratchet WebSocket client for better reliability
            $conn = null;
            \Ratchet\Client\connect($url)->then(
                function($connection) use (&$conn) {
                    $conn = $connection;
                },
                function($e) {
                    throw new Exception("Could not connect: {$e->getMessage()}");
                }
            );
            
            // Wait for the connection to be established
            $waitStart = time();
            while ($conn === null && (time() - $waitStart) < 10) {
                usleep(100000); // 0.1 second
            }
            if (!$conn) {
                throw new Exception("Failed to establish WebSocket connection");
            }
            
            $this->log("WebSocket connection established");
            
            // Step 1: Authorize with Payment Agent token
            $authMessage = json_encode(["authorize" => $this->paymentAgentToken]);
            $this->log("Sending authorization: " . $authMessage);
            
            $conn->send($authMessage);
            $authResponse = $this->waitForResponse($conn, 10); // 10 second timeout
            
            if (!$authResponse) {
                throw new Exception("No authorization response received");
            }
            
            $authData = json_decode($authResponse, true);
            $this->log("Authorization response: " . $authResponse);
            
            if (isset($authData['error'])) {
                throw new Exception('Authorization failed: ' . $authData['error']['message']);
            }
            
            if (!isset($authData['authorize']) || !$authData['authorize']['loginid']) {
                throw new Exception('Authorization response invalid');
            }
            
            // Step 2: Check Payment Agent balance
            $balanceCheck = $this->checkPaymentAgentBalance($conn, $amount);
            if (!$balanceCheck['success']) {
                $conn->close();
                return $balanceCheck;
            }
            
            // Step 3: Perform the transfer
            $transferRequest = [
                "paymentagent_transfer" => 1,
                "transfer_to" => $loginid,
                "amount" => floatval($amount),
                "currency" => "USD",
                "description" => "Deposit via Payment Agent - Ref: $transaction_number"
            ];
            
            $transferMessage = json_encode($transferRequest);
            $this->log("Sending transfer request: " . $transferMessage);
            
            $conn->send($transferMessage);
            $transferResponse = $this->waitForResponse($conn, 15); // 15 second timeout
            
            $conn->close();
            
            if (!$transferResponse) {
                throw new Exception("No transfer response received");
            }
            
            $transferData = json_decode($transferResponse, true);
            $this->log("Transfer response: " . $transferResponse);
            
            if (isset($transferData['error'])) {
                throw new Exception($transferData['error']['message']);
            }
            
            if (isset($transferData['paymentagent_transfer']) && $transferData['paymentagent_transfer'] == 1) {
                $this->log("Transfer successful - Client balance updated");
                return [
                    'success' => true,
                    'message' => 'Transfer successful',
                    'data' => $transferData
                ];
            } else {
                throw new Exception('Unexpected response from Deriv API');
            }
            
        } catch (Exception $e) {
            $this->log("Transfer exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    private function validatePaymentAgent()
    {
        try {
            $endpoint = 'ws.binaryws.com';
            $url = "wss://{$endpoint}/websockets/v3?app_id={$this->appId}";
            
            $client = new \Ratchet\Client\WebSocket($url);
            $conn = $client->connect();
            
            // Authorize
            $conn->send(json_encode(["authorize" => $this->paymentAgentToken]));
            $response = $this->waitForResponse($conn, 10);
            
            if (!$response) {
                $conn->close();
                return ['success' => false, 'message' => 'No authorization response'];
            }
            
            $data = json_decode($response, true);
            
            if (isset($data['error'])) {
                $conn->close();
                return ['success' => false, 'message' => $data['error']['message']];
            }
            
            // Check if account has Payment Agent permissions
            if (!isset($data['authorize']['account_list'])) {
                $conn->close();
                return ['success' => false, 'message' => 'No account list in response'];
            }
            
            $hasPaymentAgentAccess = false;
            foreach ($data['authorize']['account_list'] as $account) {
                if (isset($account['is_payment_agent']) && $account['is_payment_agent'] == 1) {
                    $hasPaymentAgentAccess = true;
                    break;
                }
            }
            
            $conn->close();
            
            if (!$hasPaymentAgentAccess) {
                return ['success' => false, 'message' => 'Account is not approved as Payment Agent'];
            }
            
            return ['success' => true, 'message' => 'Payment Agent validation successful'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Validation error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Check Payment Agent balance before transfer
     */
    private function checkPaymentAgentBalance($conn, $requiredAmount)
    {
        try {
            $conn->send(json_encode(["balance" => 1, "subscribe" => 1]));
            $response = $this->waitForResponse($conn, 10);
            
            if (!$response) {
                return ['success' => false, 'message' => 'No balance response received'];
            }
            
            $balanceData = json_decode($response, true);
            
            if (isset($balanceData['error'])) {
                return ['success' => false, 'message' => 'Balance check failed: ' . $balanceData['error']['message']];
            }
            
            if (isset($balanceData['balance'])) {
                $currentBalance = floatval($balanceData['balance']['balance']);
                $this->log("Payment Agent balance: $currentBalance USD");
                
                if ($currentBalance < $requiredAmount) {
                    return ['success' => false, 'message' => "Insufficient Payment Agent balance. Required: $requiredAmount, Available: $currentBalance"];
                }
                
                return ['success' => true, 'message' => 'Sufficient balance available'];
            }
            
            return ['success' => false, 'message' => 'Invalid balance response'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Balance check error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Wait for WebSocket response with timeout
     */
    private function waitForResponse($conn, $timeoutSeconds = 10)
    {
        $startTime = time();
        
        while ((time() - $startTime) < $timeoutSeconds) {
            try {
                $response = $conn->receive();
                if ($response) {
                    return $response;
                }
            } catch (Exception $e) {
                $this->log("Error waiting for response: " . $e->getMessage());
                break;
            }
            usleep(100000); // Wait 0.1 seconds
        }
        
        return null;
    }
    
    /**
     * Send success notifications
     */
    private function sendSuccessNotifications($wallet_id, $transaction_number, $amount, $cr_number)
    {
        $condition1 = array('wallet_id' => $wallet_id);
        $searchuser = $this->Operations->SearchByCondition('customers', $condition1);
        $mobile = $searchuser[0]['phone'];
        $phone = preg_replace('/^(?:\+?254|0)?/', '254', $mobile);
        
        $message = "$transaction_number processed, {$amount}USD has been successfully deposited to your deriv account $cr_number";
        
        $sms = $this->Operations->sendSMS($phone, $message);
        $stevephone = '0703416091';
        $sendadminsms0 = $this->Operations->sendSMS($stevephone, $message);
        
        $this->log("Success notifications sent to user: $phone and admin: $stevephone");
    }
    
    /**
     * Enhanced logging function
     */
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Standardized error response
     */
    private function errorResponse($message)
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => null
        ];
    }
    
    /**
     * Standardized success response
     */
    private function successResponse($message, $data = null)
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ];
    }
}

?>