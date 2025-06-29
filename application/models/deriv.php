<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Deriv extends CI_Controller {
    
    private $transaction_id;
    private $transaction_number;
    private $currentDateTime;
    private $date;
    private $timeframe = 600;
    
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Operations');
        $this->load->library('session');
        
        $this->currentDateTime = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
        $this->date = $this->currentDateTime->format('Y-m-d H:i:s');
            
        // Check if transaction_id and time_frame are already set in the session
        $transaction_id = $this->session->userdata('transaction_id');
        $time_frame = $this->session->userdata('time_frame');
    
        // Check if the stored time_frame is still valid (within the allowed time frame)
        $valid_time_frame = $time_frame && (time() - $time_frame <= 30);
    
        // If transaction_id is not set or the time_frame is not valid, generate new values
        if (!$transaction_id || !$valid_time_frame) {
            $transaction_id = $this->Operations->OTP(6);
            $transaction_number = $this->GenerateNextTransaction();
            $this->transaction_number = $transaction_number;
            $time_frame = time();
    
            // Set transaction_id and time_frame in the session
            $this->session->set_userdata('transaction_id', $transaction_id);
            $this->session->set_userdata('time_frame', $time_frame);
        }
        
        // Set transaction_id in $this->transaction_id
        $this->transaction_id = $transaction_id;
        $this->transaction_number = $this->GenerateNextTransaction();

        header('Content-Type: application/json');
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Handle Deriv deposits
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
    
        // Fetch inputs
        $crNumber = $this->input->post('crNumber');
        $crNumber = str_replace(' ', '', $crNumber);
        $amount = $this->input->post('amount');
        $session_id = $this->input->post('session_id');
        $transaction_id = $this->input->post('transaction_id');
        
        // Form validation
        $this->form_validation->set_rules('crNumber', 'crNumber', 'required');
        $this->form_validation->set_rules('amount', 'amount', 'required|numeric|greater_than[0]');
        $this->form_validation->set_rules('session_id', 'session_id', 'required');
        $this->form_validation->set_rules('transaction_id', 'transaction_id', 'required');
        
        if ($this->form_validation->run() == FALSE) {
            $response['status'] = 'fail';
            $response['message'] = 'crNumber, amount, transaction_id and session_id required';
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }
    
        // Validate session
        $session_table = 'login_session';
        $session_condition = array('session_id' => $session_id);
        $checksession = $this->Operations->SearchByCondition($session_table, $session_condition);
        
        if (empty($checksession) || $checksession[0]['session_id'] !== $session_id) {
            $response['status'] = 'error';
            $response['message'] = 'Invalid session_id or user not logged in';
            $response['data'] = null;
            echo json_encode($response);
            exit();
        }

        $wallet_id = $checksession[0]['wallet_id'];
        $summary = $this->Operations->customer_transection_summary($wallet_id);
        
        // Get our buy rate 
        $buyratecondition = array('exchange_type'=>1,'service_type'=>1);
        $buyrate = $this->Operations->SearchByConditionBuy('exchange',$buyratecondition);
    
        // Remove commas and convert to float
        $total_credit = (float) str_replace(',', '', $summary[0][0]['total_credit']);
        $total_debit = (float) str_replace(',', '', $summary[1][0]['total_debit']);
    
        // Calculate the balance in KES
        $total_balance_kes = $total_credit - $total_debit;
    
        // Convert the balance to USD using the conversion rate
        $conversionRate = $buyrate[0]['kes'];
        $boughtbuy = $buyrate[0]['bought_at'];
        $amountUSD = round($amount / $conversionRate,2);
    
        $chargePercent = 0;
        $chargeAmount = $amountUSD * $chargePercent;
        $amountUSDAfterCharge = $amountUSD - $chargeAmount;
        $amountKESAfterCharge = ((float)$chargeAmount * (float)$conversionRate);
    
        // Check if the amount is greater than $1
        if ($amountUSD < 1) {
            $response['status'] = 'error';
            $response['message'] = 'The amount must be greater than $1.';
            $response['data'] = null;
        } elseif ($total_balance_kes < $amount) {
            $response['status'] = 'error';
            $response['message'] = 'You dont have sufficient funds in your wallet';
            $response['data'] = null;
        } else {
            $table = 'deriv_deposit_request';
            $condition1 = array('wallet_id'=>$wallet_id);
            $searchUser = $this->Operations->SearchByCondition('customers',$condition1);
            $phone = $searchUser[0]['phone'];
            
            $mycharge = ($buyrate[0]['kes'] - $boughtbuy);
            $newcharge = (float)$mycharge * $amountUSD;

            $data = array(
                'transaction_id'=>$transaction_id,
                'transaction_number'=>$this->transaction_number,
                'wallet_id'=>$wallet_id,
                'cr_number'=>$crNumber,
                'amount'=>$amountUSD,
                'rate'=>$conversionRate,
                'status'=>0,
                'deposited'=>0,
                'bought_at'=>$boughtbuy,
                'request_date'=>$this->date,
            );
            $save = $this->Operations->Create($table, $data);

            $paymethod = 'STEPAKASH';
            $description = 'Deposit to deriv';
            $currency = 'USD';
            $dateTime = $this->date;

            $totalAmountKES = $amountKESAfterCharge + $amount;

            $cr_dr = 'dr';
            $customer_ledger_data = array(
                'transaction_id'    =>    $transaction_id,
                'transaction_number' => $this->transaction_number,
                'description'        =>    $description,
                'pay_method' => $paymethod,
                'wallet_id' => $wallet_id,
                'paid_amount' => $amount,
                'cr_dr'=>$cr_dr,
                'deriv'=>1,
                'trans_date' => $this->date,
                'currency' => $currency,
                'amount' => $amountUSD,
                'rate' => $conversionRate,
                'chargePercent' =>$chargePercent,
                'charge' =>$newcharge,
                'total_amount' =>$totalAmountKES,
                'status' => 1,
                'created_at' => $this->date,
            );
            $save_customer_ledger = $this->Operations->Create('customer_ledger',$customer_ledger_data);

            $system_ledger_data = array(
                'transaction_id'    =>    $transaction_id,
                'transaction_number' => $this->transaction_number,
                'description'        =>    $description,
                'pay_method' => $paymethod,
                'wallet_id' => $wallet_id,
                'paid_amount' => $amount,
                'cr_dr'=>$cr_dr,
                'deriv'=>1,
                'trans_date' => $this->date,
                'currency' => $currency,
                'amount' => $amountUSD,
                'rate' => $conversionRate,
                'chargePercent' =>$chargePercent,
                'charge' =>$newcharge,
                'total_amount' =>$totalAmountKES,
                'status' => 1,
                'created_at' => $this->date,
            );
            $save_system_ledger = $this->Operations->Create('system_ledger',$system_ledger_data);
            
            if($save === TRUE && $save_customer_ledger === TRUE && $save_system_ledger === TRUE) {
                $message = 'Txn ID: ' . $this->transaction_number . ', a deposit of ' . $amountUSD . ' USD is currently being processed.';
                
                $stevephone = '0703416091';
                $sendadminsms0 = $this->Operations->sendSMS($samphone,$message);
                
                //SEND USER APP NOTIFICATION 
                $sms = $this->Operations->sendSMS($phone, $message);
              
                $response['status'] = 'success';
                $response['message'] = $message;
                $response['data'] = null;
           } else {
                $response['status'] = 'fail';
                $response['message'] = 'Unable to process your request now try again';
                $response['data'] = null;
            }
        }
    
        echo json_encode($response);
    }

    /**
     * Handle Deriv withdrawals
     */
    public function withdraw()
    {
        $response = array();
    
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(400);
            $response['status'] = 'fail';
            $response['message'] = 'Only POST request allowed';
            echo json_encode($response);
            exit();
        }
    
        // Retrieve data from POST request
        $session_id = $this->input->post('session_id');
        $crNumber = $this->input->post('crNumber');
        $amount = $this->input->post('amount');
    
        // Validate form data
        $this->form_validation->set_rules('session_id', 'session_id', 'required');
        $this->form_validation->set_rules('crNumber', 'crNumber', 'required');
        $this->form_validation->set_rules('amount', 'amount', 'required');
    
        if ($this->form_validation->run() == FALSE) {
            $response['status'] = 'fail';
            $response['message'] = 'session_id, CR number, and amount are required';
            $response['data'] = null;
        } else {
            // Check session
            $session_condition = array('session_id' => $session_id);
            $checksession = $this->Operations->SearchByCondition('login_session', $session_condition);
            
            if (!empty($checksession) && $checksession[0]['session_id'] == $session_id) {
                // Valid session, retrieve wallet_id
                $wallet_id = $checksession[0]['wallet_id'];
    
                //get our sell rate
                $sellratecondition = array('exchange_type' => 2,'service_type'=>1);
                $sellrate = $this->Operations->SearchByConditionBuy('exchange', $sellratecondition);
                
                $boughtsell = $sellrate[0]['bought_at'];
                $conversionRate = $sellrate[0]['kes'];
                $table = 'deriv_withdraw_request';
    
                $condition1 = array('wallet_id' => $wallet_id);
                $searchUser = $this->Operations->SearchByCondition('customers', $condition1);
                $phone = $searchUser[0]['phone'];
                $transaction_number = $this->transaction_number;

                $data = array(
                    'wallet_id' => $wallet_id,
                    'cr_number' => $crNumber,
                    'amount' => $amount,
                    'rate' => $conversionRate,
                    'status' => 0,
                    'withdraw' => 0,
                    'bought_at'=>$boughtsell,
                    'request_date' => $this->date,
                );
        
                $save = $this->Operations->Create($table, $data);
        
                $paymethod = 'STEPAKASH';
                $description = 'Withdrawal from deriv';
                $currency = 'USD';
                $dateTime = $this->date;
        
                if ($save === TRUE) {
                    $message = 'Withdrawal of ' . $amount . ' USD is currently being processed.';
                    $sms = $this->Operations->sendSMS($phone, $message);

                    $stevephone = '0757259996';
                    $albertphone = '0727010129';
                    $samphone = '0793601418';

                    $sendadminsms0 = $this->Operations->sendSMS($samphone,$message);
                    $sendadminsms1 = $this->Operations->sendSMS($stevephone,$message);
                    $sendadminsms2 = $this->Operations->sendSMS($albertphone,$message);
        
                    $response['status'] = 'success';
                    $response['message'] = $message;
                    $response['data'] = $data;
                } else {
                    $response['status'] = 'error';
                    $response['message'] = 'Unable to process your request now, try again';
                    $response['data'] = null;
                }    
            } else {
                // User not logged in
                $response['status'] = 'fail';
                $response['message'] = 'User not logged in';
                $response['data'] = null;
            }
        }
    
        echo json_encode($response);
    }

    /**
     * Process Deriv deposit requests (admin function)
     */
    public function process_deposit_request()
    {
        $response = array();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['status'] = 'error';
            $response['message'] = 'Invalid request method. Only POST requests are allowed.';
            $response['data'] = null;
        } else {
            $request_id = $this->input->post('request_id');
            
            if (empty($request_id)) {
                $response['status'] = 'error';
                $response['message'] = 'Request ID is empty.';
                $response['data'] = null;
            } else {
                $table = 'deriv_deposit_request';
                $condition = array('transaction_id'=>$request_id);
                $search = $this->Operations->SearchByCondition($table,$condition);
                
                $amount = $search[0]['amount'];
                $cr_number = $search[0]['cr_number'];
                $wallet_id= $search[0]['wallet_id'];
                $transaction_number= $search[0]['transaction_number'];

                $data = array('status'=>1,'deposited'=>$amount);
                $update = $this->Operations->UpdateData($table,$condition,$data);
                
                $condition1 = array('wallet_id'=>$wallet_id);
                $searchuser = $this->Operations->SearchByCondition('customers',$condition1);
                $mobile = $searchuser[0]['phone'];
                $phone = preg_replace('/^(?:\+?254|0)?/','254', $mobile);
                
                if($update === TRUE) {
                    $message = ''.$transaction_number.' processed, '.$amount.'USD has been successfully deposited to your deriv account '.$cr_number.'';
                    $sms = $this->Operations->sendSMS($phone, $message);

                    $stevephone = '0703416091';
                    $sendadminsms0 = $this->Operations->sendSMS($stevephone,$message);

                    $response['status'] = 'success';
                    $response['message'] = $message;
                    $response['data'] = null;
                } else {
                    $response['status'] = 'error';
                    $response['message'] = 'Unable to process request now try again';
                    $response['data'] = null;
                }
            }
        }
        
        echo json_encode($response);
    }

    /**
     * Process Deriv withdrawal requests (admin function)
     */
    public function process_withdrawal_request()
    {
        $response = array();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $response['status'] = 'error';
            $response['message'] = 'Invalid request method. Only POST requests are allowed.';
            $response['data'] = null;
        } else {
            $request_id = $this->input->post('request_id');
            $checkcondition = array('status' => 1,'id' => $request_id);
            $confirmcondition = $this->Operations->SearchByCondition('deriv_withdraw_request', $checkcondition);
            
            $checkcondition2 = array('status' => 0,'id' => $request_id);
            $confirmcondition2 = $this->Operations->SearchByCondition('deriv_withdraw_request', $checkcondition2);
                
            if (!$request_id || empty($request_id)) {
                $response['status'] = 'fail';
                $response['message'] = 'Request ID required';
                $response['data'] = null;
            } else if($confirmcondition) {
                $response['status'] = 'success';
                $response['message'] = 'Similar request already approved';
                $response['data'] = null;
            } else if($confirmcondition2) {
                //get our sell rate
                $sellratecondition = array('exchange_type' => 2,'service_type'=>1);
                $sellrate = $this->Operations->SearchByConditionBuy('exchange', $sellratecondition);
                
                $boughtsell = $sellrate[0]['bought_at'];
                
                $table = 'deriv_withdraw_request';
                $condition = array('id' => $request_id);
                $search = $this->Operations->SearchByCondition($table, $condition);
            
                $amount = $search[0]['amount'];
                $cr_number = $search[0]['cr_number'];
                $wallet_id = $search[0]['wallet_id'];

                $data = array('status' => 1, 'withdraw' => $amount);
                $update = $this->Operations->UpdateData($table, $condition, $data);
            
                $condition1 = array('wallet_id' => $wallet_id);
                $searchuser = $this->Operations->SearchByCondition('customers', $condition1);
                $mobile = $searchuser[0]['phone'];
                $phone = preg_replace('/^(?:\+?254|0)?/', '254', $mobile);
            
                $paymethod = 'STEPAKASH';
                $description = 'Withdraw from deriv';
                $currency = 'USD';
                $dateTime = $this->date;
                $cr_dr = 'cr';
                $conversionRate = $sellrate[0]['kes'];
                $chargePercent = 0;
                $chargeAmount = (float)($amount * $chargePercent);
                $amountKESAfterCharge = ((float) $amount * (float) $conversionRate);
                $finalCharge = ((float) $chargeAmount * (float) $conversionRate);
                $totalAmt = ((float) $finalCharge + (float) $amountKESAfterCharge);
                
                $mycharge = ($boughtsell - $sellrate[0]['kes']);
                $newcharge = (float)$mycharge * $amount;

                $transaction_number =  $this->transaction_number;
                $transaction_id = $this->Operations->OTP(9);
                
                $customer_ledger_data = array(
                    'transaction_id' => $transaction_id,
                    'transaction_number'=>$transaction_number,
                    'receipt_no' => $this->Operations->Generator(15),
                    'description' => 'ITP',
                    'pay_method' => $paymethod,
                    'wallet_id' => $wallet_id,
                    'paid_amount' => $amountKESAfterCharge,
                    'cr_dr' => $cr_dr,
                    'currency' => $currency,
                    'amount' => $amount,
                    'deriv' => 1,
                    'rate' => $conversionRate,
                    'chargePercent' => $chargePercent,
                    'charge' => $newcharge,
                    'total_amount' => $totalAmt,
                    'status' => 1,
                    'created_at' => $this->date,
                );
            
                $save_customer_ledger = $this->Operations->Create('customer_ledger', $customer_ledger_data);
            
                $system_ledger_data = array(
                    'transaction_id' => $transaction_id,
                    'transaction_number' => $transaction_number,
                    'receipt_no' => $this->Operations->Generator(15),
                    'description' => 'ITP',
                    'pay_method' => $paymethod,
                    'wallet_id' => $wallet_id,
                    'paid_amount' => $amountKESAfterCharge,
                    'cr_dr' => $cr_dr,
                    'currency' => $currency,
                    'amount' => $amount,
                    'deriv' => 1,
                    'rate' => $conversionRate,
                    'chargePercent' => $chargePercent,
                    'charge' => $newcharge,
                    'total_amount' => $totalAmt,
                    'status' => 1,
                    'created_at' => $this->date,
                );
            
                $save_system_ledger = $this->Operations->Create('system_ledger', $system_ledger_data);
            
                if ($update === TRUE && $save_system_ledger === TRUE && $save_customer_ledger === TRUE) {
                    $message = ''.$transaction_number.', ' . $amount .'USD has been successfully withdraw from your deriv account ' . $cr_number . '';
            
                    //SEND USER APP NOTIFICATION 
                    $sms = $this->Operations->sendSMS($phone, $message);
     
                    $response['status'] = 'success';
                    $response['message'] = $message;
                    $response['data'] = null;
                } else {
                    $response['status'] = 'error';
                    $response['message'] = 'Unable to process request now, try again';
                    $response['data'] = null;
                }
            } else {
                $response['status'] = 'error';
                $response['message'] = 'Something went wrong, try again';
                $response['data'] = null;
            }
        }
        
        echo json_encode($response);
    }

    /**
     * Reject Deriv withdrawal request (admin function)
     */
    public function reject_withdrawal_request()
    {
        $response = array();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(400);
            $response['status'] = 'fail';
            $response['message'] = 'Only POST request allowed';
            $response['data'] = '';
        } else {
            $request_id = $this->input->post('request_id');
            $response = array();
            if (!$request_id || empty($request_id)) {
                $response['status'] = 'fail';
                $response['message'] = 'Request ID required';
                $response['data'] = null;
            } else {
                $table = 'deriv_withdraw_request';
                $condition = array('id' => $request_id);
                $data = array('status' => 2);
                $update = $this->Operations->UpdateData($table, $condition, $data);
                
                if ($update === TRUE) {
                    $message = 'Withdrawal Request Rejected';
                    $response['status'] = 'success';
                    $response['message'] = $message;
                    $response['data'] = null;
                } else {
                    $response['status'] = 'error';
                    $response['message'] = 'Unable to process request now, try again later';
                    $response['data'] = null;
                }
            }
        }
        
        echo json_encode($response);
    }

    /**
     * Generate the next transaction number
     */
    private function GenerateNextTransaction()
    {
        $last_id = $this->Operations->getLastTransactionId();
        return $this->getNextReceipt($last_id);
    }

    /**
     * Helper function to generate the next receipt number
     */
    private function getNextReceipt($currentReceipt) {
        preg_match('/([A-Z]+)(\d+)([A-Z]*)/', $currentReceipt, $matches);
        $letters = $matches[1];
        $digits = intval($matches[2]);
        $extraLetter = isset($matches[3]) ? $matches[3] : '';
    
        $maxDigits = 6;
        $maxLetters = 2;
    
        if (!empty($extraLetter)) {
            $nextExtraLetter = chr(ord($extraLetter) + 1);
            if ($nextExtraLetter > 'Z') {
                $nextExtraLetter = 'A';
                $nextDigits = $digits + 1;
            } else {
                $nextDigits = $digits;
            }
        } else {
            $nextExtraLetter = 'A';
            $nextDigits = $digits + 1;
        }
    
        if ($nextDigits > str_repeat('9', $maxDigits)) {
            $lettersArray = str_split($letters);
            $lastIndex = count($lettersArray) - 1;
            $lettersArray[$lastIndex] = chr(ord($lettersArray[$lastIndex]) + 1);
            $nextLetters = implode('', $lettersArray);
    
            if (strlen($nextLetters) > $maxLetters) {
                $nextLetters = 'A';
                $nextDigits = 1;
            }
        } else {
            $nextLetters = $letters;
        }
    
        $nextDigitsStr = str_pad($nextDigits, $maxDigits, '0', STR_PAD_LEFT);
        return $nextLetters . $nextDigitsStr . $nextExtraLetter;
    }
}