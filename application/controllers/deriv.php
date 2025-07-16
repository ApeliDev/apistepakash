<?php
defined('BASEPATH') or exit('No direct script access allowed');

use WebSocket\Client;
use WebSocket\ConnectionException;

class Deriv extends CI_Controller
{
    private $currentDateTime;
    private $date;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Operations');
        $this->currentDateTime = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
        $this->date = $this->currentDateTime->format('Y-m-d H:i:s');

        header('Content-Type: application/json');
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Handle Deriv deposits without session checks
     */
    public function deposit()
    {
        $response = array();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(400);
            $response['status'] = 'fail';
            $response['message'] = 'Only POST request allowed';
            echo json_encode($response);
            exit();
        }

        // Fetch and sanitize inputs
        $crNumber = trim(str_replace(' ', '', $this->input->post('crNumber')));
        $amount = (float)$this->input->post('amount');
        $wallet_id = $this->input->post('wallet_id');
        $transaction_id = $this->input->post('transaction_id');
        $transaction_number = $this->input->post('transaction_number');

        // Form validation
        $this->form_validation->set_rules('crNumber', 'CR Number', 'required|min_length[8]|max_length[12]');
        $this->form_validation->set_rules('amount', 'Amount', 'required|numeric|greater_than[0]');
        $this->form_validation->set_rules('wallet_id', 'Wallet ID', 'required');
        $this->form_validation->set_rules('transaction_id', 'Transaction ID', 'required');
        $this->form_validation->set_rules('transaction_number', 'Transaction Number', 'required');

        if ($this->form_validation->run() == FALSE) {
            $response['status'] = 'fail';
            $response['message'] = validation_errors();
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }

        // Get exchange rate
        $buyratecondition = array('exchange_type' => 1, 'service_type' => 1);
        $buyrate = $this->Operations->SearchByConditionBuy('exchange', $buyratecondition);

        if (empty($buyrate)) {
            $response['status'] = 'error';
            $response['message'] = 'Exchange rate not available. Please try again later.';
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }

        $conversionRate = $buyrate[0]['kes'];
        $boughtbuy = $buyrate[0]['bought_at'];
        $amountUSD = round($amount / $conversionRate, 2);

        // Validate minimum amount
        if ($amountUSD < 2.5) {
            $response['status'] = 'error';
            $response['message'] = 'The minimum deposit amount is $2.50 USD.';
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }

        // Check for duplicate transactions
        $duplicate_check = $this->Operations->SearchByCondition(
            'deriv_deposit_request',
            array('transaction_id' => $transaction_id, 'status' => 1)
        );

        if (!empty($duplicate_check)) {
            $response['status'] = 'error';
            $response['message'] = 'Transaction already processed.';
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }

        // ATTEMPT DERIV TRANSFER
        $transferResult = $this->transferToDerivAccount($crNumber, $amountUSD);

        if (!$transferResult['success']) {
            // If transfer failed due to insufficient agent balance, notify admin
            if (strpos($transferResult['message'], 'Insufficient payment agent balance') !== false) {
                $this->Operations->sendSMS('0703416091', "CRITICAL: Deriv agent balance low. Needed: $" . $amountUSD);
                
                // Still save the request as pending
                $this->savePendingDeposit($wallet_id, $transaction_id, $transaction_number, $crNumber, $amountUSD, $conversionRate, $boughtbuy);
                
                $response['status'] = 'processing';
                $response['message'] = 'Transaction is being processed. You will receive a confirmation once completed.';
                $response['data'] = null;
            } else {
                $response['status'] = 'error';
                $response['message'] = $transferResult['message'];
                $response['data'] = null;
            }
            echo json_encode($response);
            exit();
        }

        // If transfer successful, complete the transaction
        $this->completeDeposit($wallet_id, $transaction_id, $transaction_number, $crNumber, $amount, $amountUSD, $conversionRate, $boughtbuy);

        $user = $this->Operations->SearchByCondition('customers', array('wallet_id' => $wallet_id));
        $phone = $user[0]['phone'];

        $message = 'Txn ID: ' . $transaction_number . ', deposit of $' . $amountUSD . ' USD successfully completed to Deriv account ' . $crNumber;
        $this->Operations->sendSMS($phone, $message);
        $this->Operations->sendSMS('0703416091', "Deposit completed: $" . $amountUSD . " USD to " . $crNumber);

        $response['status'] = 'success';
        $response['message'] = $message;
        $response['data'] = $transferResult['data'];

        echo json_encode($response);
    }

    /**
     * Save pending deposit when agent balance is insufficient
     */
    private function savePendingDeposit($wallet_id, $transaction_id, $transaction_number, $crNumber, $amountUSD, $conversionRate, $boughtbuy)
    {
        $deposit_data = array(
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction_number,
            'wallet_id' => $wallet_id,
            'cr_number' => $crNumber,
            'amount' => $amountUSD,
            'rate' => $conversionRate,
            'status' => 0, // 0 = pending
            'deposited' => 0,
            'bought_at' => $boughtbuy,
            'request_date' => $this->date
        );
        $this->Operations->Create('deriv_deposit_request', $deposit_data);
    }

    /**
     * Complete deposit transaction
     */
    private function completeDeposit($wallet_id, $transaction_id, $transaction_number, $crNumber, $amount, $amountUSD, $conversionRate, $boughtbuy)
    {
        $this->db->trans_start();

        // Save to deriv_deposit_request
        $deposit_data = array(
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction_number,
            'wallet_id' => $wallet_id,
            'cr_number' => $crNumber,
            'amount' => $amountUSD,
            'rate' => $conversionRate,
            'status' => 1, // 1 = completed
            'deposited' => $amountUSD,
            'bought_at' => $boughtbuy,
            'request_date' => $this->date,
            'processed_at' => $this->date
        );
        $this->Operations->Create('deriv_deposit_request', $deposit_data);

        $mycharge = ($conversionRate - $boughtbuy);
        $newcharge = (float)$mycharge * $amountUSD;

        // Create customer ledger entry
        $customer_ledger_data = array(
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction_number,
            'description' => 'Deposit to Deriv',
            'pay_method' => 'STEPAKASH',
            'wallet_id' => $wallet_id,
            'paid_amount' => $amount,
            'cr_dr' => 'dr',
            'deriv' => 1,
            'trans_date' => $this->date,
            'currency' => 'USD',
            'amount' => $amountUSD,
            'rate' => $conversionRate,
            'chargePercent' => 0,
            'charge' => $newcharge,
            'total_amount' => $amount,
            'status' => 1,
            'created_at' => $this->date,
        );
        $this->Operations->Create('customer_ledger', $customer_ledger_data);

        // Create system ledger entry
        $system_ledger_data = array(
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction_number,
            'description' => 'Deposit to Deriv',
            'pay_method' => 'STEPAKASH',
            'wallet_id' => $wallet_id,
            'paid_amount' => $amount,
            'cr_dr' => 'dr',
            'deriv' => 1,
            'trans_date' => $this->date,
            'currency' => 'USD',
            'amount' => $amountUSD,
            'rate' => $conversionRate,
            'chargePercent' => 0,
            'charge' => $newcharge,
            'total_amount' => $amount,
            'status' => 1,
            'created_at' => $this->date,
        );
        $this->Operations->Create('system_ledger', $system_ledger_data);

        $this->db->trans_complete();
    }

    /**
     * Function to transfer funds to Deriv account
     */
    private function transferToDerivAccount($loginid, $amount)
    {
        try {
            $appId = 76420;
            $url = "wss://ws.derivws.com/websockets/v3?app_id={$appId}";
            $token = 'DidPRclTKE0WYtT';

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $client = new Client($url, [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json'
                ]
            ], ['context' => $context]);

            // 1. Authorize with Payment Agent token
            $client->send(json_encode(["authorize" => $token]));
            $authResponse = $client->receive();
            $authData = json_decode($authResponse, true);

            if (isset($authData['error'])) {
                $this->logError('Auth failed: ' . $authData['error']['message']);
                return [
                    'success' => false,
                    'message' => 'Authorization failed: ' . $authData['error']['message'],
                    'data' => null
                ];
            }

            // 2. Validate payment agent balance
            if (isset($authData['authorize']['balance']) && $authData['authorize']['balance'] < $amount) {
                $this->logError('Insufficient payment agent balance');
                return [
                    'success' => false,
                    'message' => 'Insufficient payment agent balance',
                    'data' => null
                ];
            }

            // 3. Make the transfer
            $transferRequest = [
                "paymentagent_transfer" => 1,
                "transfer_to" => $loginid,
                "amount" => $amount,
                "currency" => "USD",
                "description" => "Deposit via Stepakash"
            ];

            $client->send(json_encode($transferRequest));
            $transferResponse = $client->receive();
            $transferData = json_decode($transferResponse, true);

            $client->close();

            if (isset($transferData['error'])) {
                $this->logError('Transfer failed: ' . $transferData['error']['message']);
                return [
                    'success' => false,
                    'message' => $transferData['error']['message'],
                    'data' => $transferData
                ];
            }

            if (isset($transferData['paymentagent_transfer']) && $transferData['paymentagent_transfer'] == 1) {
                $this->logSuccess("Transfer successful to $loginid: $amount USD");
                return [
                    'success' => true,
                    'message' => 'Transfer successful',
                    'data' => $transferData
                ];
            }

            return [
                'success' => false,
                'message' => 'Unexpected response from Deriv API',
                'data' => $transferData
            ];
        } catch (Exception $e) {
            $this->logError('Connection error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Log error messages
     */
    private function logError($message)
    {
        file_put_contents('deriv_errors.log', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }

    /**
     * Log success messages
     */
    private function logSuccess($message)
    {
        file_put_contents('deriv_success.log', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }

    /**
     * Process callback from Deriv when deposit is complete
     */
    public function callback()
    {
        $json_data = file_get_contents('php://input');
        $data = json_decode($json_data, true);

        if (isset($data['paymentagent_transfer']) && $data['paymentagent_transfer'] == 1) {
            $transaction_id = $data['req_id'] ?? '';
            $amount = $data['amount'] ?? 0;
            $loginid = $data['transfer_to'] ?? '';

            if (!empty($transaction_id)) {
                $table = 'deriv_deposit_request';
                $condition = array('transaction_id' => $transaction_id);
                $request = $this->Operations->SearchByCondition($table, $condition);

                if (!empty($request)) {
                    $updateData = array(
                        'status' => 1,
                        'deposited' => $amount,
                        'processed_at' => $this->date
                    );
                    $this->Operations->UpdateData($table, $condition, $updateData);

                    // Notify user
                    $wallet_id = $request[0]['wallet_id'];
                    $user = $this->Operations->SearchByCondition('customers', array('wallet_id' => $wallet_id));
                    $phone = $user[0]['phone'];
                    $message = "Your deposit of $amount USD to Deriv account $loginid is complete";
                    $this->Operations->sendSMS($phone, $message);
                }
            }
        }

        // Always return success to Deriv
        echo json_encode(array('status' => 'success'));
    }
}