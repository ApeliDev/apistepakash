<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Api extends CI_Controller
{

    private $validKey = 'your-secret-key';

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Operations');
        $this->load->library('session');

        // Set headers for all responses
        header('Content-Type: application/json');
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-KEY');

        // Handle OPTIONS requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit();
        }

        // Verify API key for all endpoints except OPTIONS
        $apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        if ($apiKey !== $this->validKey) {
            $this->jsonResponse(['success' => false, 'message' => 'Invalid API key'], 401);
        }
    }

    /**
     * Helper method to send JSON response
     */
    private function jsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }

    /**
     * Validate session
     */
    public function validate_session()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['valid' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $session_id = $this->input->post('session_id');
        $validation = $this->validateSession($session_id);

        $this->jsonResponse($validation);
    }

    /**
     * Get user data including balance and rates
     */
    public function user_data()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $wallet_id = $this->input->post('wallet_id');
        $session_id = $this->input->post('session_id');

        // Validate session first
        $sessionValidation = $this->validateSession($session_id);
        if (!$sessionValidation['valid']) {
            $this->jsonResponse(['success' => false, 'message' => $sessionValidation['message']], 401);
        }

        // Get user summary
        $summary = $this->Operations->customer_transection_summary($wallet_id);

        // Get buy rate
        $buyratecondition = array('exchange_type' => 1, 'service_type' => 1);
        $buyrate = $this->Operations->SearchByConditionBuy('exchange', $buyratecondition);

        $this->jsonResponse([
            'success' => true,
            'message' => 'User data retrieved',
            'data' => [
                'summary' => [
                    'total_credit' => $summary[0][0]['total_credit'],
                    'total_debit' => $summary[1][0]['total_debit']
                ],
                'buy_rate' => $buyrate[0] ?? null,
                'user_info' => $this->getUserInfo($wallet_id)
            ]
        ]);
    }

    /**
     * Get sell rate
     */
    public function sell_rate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $sellratecondition = array('exchange_type' => 2, 'service_type' => 1);
        $sellrate = $this->Operations->SearchByConditionBuy('exchange', $sellratecondition);

        $this->jsonResponse([
            'success' => true,
            'message' => 'Sell rate retrieved',
            'data' => $sellrate[0] ?? null
        ]);
    }

    /**
     * Check for duplicate transactions
     */
    public function check_transaction()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $transaction_id = $this->input->post('transaction_id');
        $table = 'deriv_deposit_request';
        $condition = array('transaction_id' => $transaction_id, 'status' => 1);
        $duplicate_check = $this->Operations->SearchByCondition($table, $condition);

        $this->jsonResponse([
            'success' => true,
            'is_duplicate' => !empty($duplicate_check),
            'message' => !empty($duplicate_check) ? 'Transaction already processed' : 'No duplicate found'
        ]);
    }

    /**
     * Check for pending withdrawals
     */
    public function pending_withdrawals()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $wallet_id = $this->input->post('wallet_id');
        $condition = array('wallet_id' => $wallet_id, 'status' => 0);
        $pending_withdrawals = $this->Operations->SearchByCondition('deriv_withdraw_request', $condition);

        $this->jsonResponse([
            'success' => true,
            'pending_withdrawals' => $pending_withdrawals,
            'message' => count($pending_withdrawals) . ' pending withdrawals found'
        ]);
    }

    /**
     * Create deposit request
     */
    public function create_deposit_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $data = [
            'transaction_id' => $this->input->post('transaction_id'),
            'transaction_number' => $this->input->post('transaction_number'),
            'wallet_id' => $this->input->post('wallet_id'),
            'cr_number' => $this->input->post('cr_number'),
            'amount' => $this->input->post('amount'),
            'rate' => $this->input->post('rate'),
            'status' => $this->input->post('status') ?? 0,
            'request_date' => $this->input->post('request_date') ?? date('Y-m-d H:i:s')
        ];

        $save = $this->Operations->Create('deriv_deposit_request', $data);

        if ($save) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Deposit request created successfully',
                'request_id' => $data['transaction_id']
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create deposit request'
            ], 500);
        }
    }

    /**
     * Update deposit request
     */
    public function update_deposit_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $transaction_id = $this->input->post('transaction_id');
        $updateData = $this->input->post('data');

        $condition = array('transaction_id' => $transaction_id);
        $update = $this->Operations->UpdateData('deriv_deposit_request', $condition, $updateData);

        if ($update) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Deposit request updated successfully'
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update deposit request'
            ], 500);
        }
    }

    /**
     * Get deposit request
     */
    public function get_deposit_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $request_id = $this->input->post('request_id');
        $condition = array('transaction_id' => $request_id);
        $request = $this->Operations->SearchByCondition('deriv_deposit_request', $condition);

        $this->jsonResponse([
            'success' => true,
            'data' => $request[0] ?? null,
            'message' => empty($request) ? 'Request not found' : 'Request retrieved'
        ]);
    }

    /**
     * Create withdrawal request
     */
    public function create_withdrawal_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $data = [
            'wallet_id' => $this->input->post('wallet_id'),
            'cr_number' => $this->input->post('cr_number'),
            'amount' => $this->input->post('amount'),
            'rate' => $this->input->post('rate'),
            'status' => $this->input->post('status') ?? 0,
            'request_date' => $this->input->post('request_date') ?? date('Y-m-d H:i:s'),
            'transaction_id' => $this->Operations->OTP(9),
            'transaction_number' => $this->GenerateNextTransaction()
        ];

        $save = $this->Operations->Create('deriv_withdraw_request', $data);

        if ($save) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Withdrawal request created successfully',
                'request_id' => $this->db->insert_id(),
                'transaction_id' => $data['transaction_id']
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create withdrawal request'
            ], 500);
        }
    }

    /**
     * Update withdrawal request
     */
    public function update_withdrawal_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $request_id = $this->input->post('request_id');
        $updateData = $this->input->post('data');

        $condition = array('id' => $request_id);
        $update = $this->Operations->UpdateData('deriv_withdraw_request', $condition, $updateData);

        if ($update) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Withdrawal request updated successfully'
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to update withdrawal request'
            ], 500);
        }
    }

    /**
     * Get withdrawal request
     */
    public function get_withdrawal_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $request_id = $this->input->post('request_id');
        $condition = array('id' => $request_id);
        $request = $this->Operations->SearchByCondition('deriv_withdraw_request', $condition);

        $this->jsonResponse([
            'success' => true,
            'data' => $request[0] ?? null,
            'message' => empty($request) ? 'Request not found' : 'Request retrieved'
        ]);
    }

    /**
     * Create ledger entries
     */
    public function create_ledger_entries()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $data = $this->input->post();

        // Customer ledger data
        $customer_ledger_data = [
            'transaction_id' => $data['transaction_id'],
            'transaction_number' => $data['transaction_number'],
            'receipt_no' => $this->Operations->Generator(15),
            'description' => $data['description'] ?? 'Deriv Transaction',
            'pay_method' => $data['pay_method'] ?? 'STEPAKASH',
            'wallet_id' => $data['wallet_id'],
            'paid_amount' => $data['amount_kes'] ?? $data['amount'],
            'cr_dr' => $data['cr_dr'],
            'currency' => $data['currency'] ?? 'USD',
            'amount' => $data['amount'],
            'deriv' => 1,
            'rate' => $data['rate'],
            'chargePercent' => $data['chargePercent'] ?? 0,
            'charge' => $data['charge'] ?? 0,
            'total_amount' => $data['total_amount'] ?? $data['amount'],
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // System ledger data
        $system_ledger_data = $customer_ledger_data;
        $system_ledger_data['receipt_no'] = $this->Operations->Generator(15);

        // Start transaction
        $this->db->trans_start();

        $save_customer = $this->Operations->Create('customer_ledger', $customer_ledger_data);
        $save_system = $this->Operations->Create('system_ledger', $system_ledger_data);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to create ledger entries'
            ], 500);
        } else {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Ledger entries created successfully'
            ]);
        }
    }

    /**
     * Get user info
     */
    public function get_user_info()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $wallet_id = $this->input->post('wallet_id');
        $condition = array('wallet_id' => $wallet_id);
        $user = $this->Operations->SearchByCondition('customers', $condition);

        if (empty($user)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user = $user[0];
        $this->jsonResponse([
            'success' => true,
            'data' => [
                'wallet_id' => $user['wallet_id'],
                'name' => $user['name'],
                'phone' => $user['phone'],
                'email' => $user['email'],
                'crnumber' => $user['crnumber'] ?? ''
            ],
            'message' => 'User info retrieved'
        ]);
    }

    /**
     * Send SMS
     */
    public function send_sms()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $phone = $this->input->post('phone');
        $message = $this->input->post('message');

        // Implement your SMS sending logic here
        $sms_result = $this->Operations->sendSMS($phone, $message);

        $this->jsonResponse([
            'success' => $sms_result,
            'message' => $sms_result ? 'SMS sent successfully' : 'Failed to send SMS'
        ]);
    }

    /**
     * Get transactions for a wallet
     */
    public function get_transactions()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['success' => false, 'message' => 'Only POST request allowed'], 405);
        }

        $wallet_id = $this->input->post('wallet_id');
        $condition = array('wallet_id' => $wallet_id);
        $transactions = $this->Operations->SearchByConditionDeriv('customer_ledger', $condition);

        $trans_data = [];
        foreach ($transactions as $key) {
            $trans_detail = $this->mapTransactionDetails($key);
            $user_trans = [
                'transaction_type' => $trans_detail['transaction_type'],
                'status_text' => $trans_detail['status_text'],
                'status_color' => $trans_detail['status_color'],
                'text_arrow' => $trans_detail['text_arrow'],
                'transaction_number' => $key['transaction_number'],
                'receipt_no' => $key['receipt_no'],
                'pay_method' => $key['pay_method'],
                'wallet_id' => $key['wallet_id'],
                'trans_id' => $key['trans_id'],
                'paid_amount' => $key['paid_amount'],
                'amount' => $key['amount'],
                'trans_date' => $key['trans_date'],
                'currency' => $key['currency'],
                'status' => $key['status'],
                'created_at' => $key['created_at']
            ];
            $trans_data[] = $user_trans;
        }

        $this->jsonResponse([
            'success' => true,
            'data' => $trans_data,
            'message' => count($trans_data) . ' transactions found'
        ]);
    }

    /**
     * Helper method to validate session
     */
    private function validateSession($session_id)
    {
        $session_table = 'login_session';
        $session_condition = array('session_id' => $session_id);
        $checksession = $this->Operations->SearchByCondition($session_table, $session_condition);

        if (empty($checksession) || $checksession[0]['session_id'] !== $session_id) {
            return [
                'valid' => false,
                'message' => 'Invalid session_id or user not logged in',
                'data' => null
            ];
        }

        $loggedtime = $checksession[0]['created_on'];
        $currentTime = date('Y-m-d H:i:s');
        $loggedTimestamp = strtotime($loggedtime);
        $currentTimestamp = strtotime($currentTime);
        $timediff = $currentTimestamp - $loggedTimestamp;
        $timeframe = 1800; // 30 minutes

        if ($timediff > $timeframe) {
            return [
                'valid' => false,
                'message' => 'Session expired. Please login again.',
                'data' => null
            ];
        }

        // Update session timestamp to extend session
        $update_session_data = array('created_on' => $currentTime);
        $this->Operations->UpdateData($session_table, $session_condition, $update_session_data);

        return [
            'valid' => true,
            'message' => 'Session valid',
            'data' => $checksession[0]
        ];
    }

    /**
     * Helper method to get user info
     */
    private function getUserInfo($wallet_id)
    {
        $condition = array('wallet_id' => $wallet_id);
        $user = $this->Operations->SearchByCondition('customers', $condition);
        return $user[0] ?? null;
    }

    /**
     * Helper method to map transaction details
     */
    private function mapTransactionDetails($transaction)
    {
        $transaction_type = '';
        $status_text = '';
        $status_color = '';
        $text_arrow = '';

        if ($transaction['cr_dr'] == 'cr') {
            $transaction_type = 'Deposit';
            $text_arrow = 'text-success';
        } else {
            $transaction_type = 'Withdrawal';
            $text_arrow = 'text-danger';
        }

        if ($transaction['status'] == 1) {
            $status_text = 'Completed';
            $status_color = 'badge-success';
        } elseif ($transaction['status'] == 0) {
            $status_text = 'Pending';
            $status_color = 'badge-warning';
        } else {
            $status_text = 'Failed';
            $status_color = 'badge-danger';
        }

        return [
            'transaction_type' => $transaction_type,
            'status_text' => $status_text,
            'status_color' => $status_color,
            'text_arrow' => $text_arrow
        ];
    }

    /**
     * Helper method to generate next transaction number
     */
    private function GenerateNextTransaction()
    {
        // Implement your transaction number generation logic
        return 'TXN' . date('YmdHis') . rand(100, 999);
    }
}
