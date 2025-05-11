
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Money extends CI_Controller {
    
    private $currentDateTime;
    
    private $date;

    private $transaction_number;

    private $partner_transaction_number;

    
    public function __construct()
    {
        
        parent::__construct();
        $this->load->model('Operations');
        
         $this->currentDateTime = new DateTime('now', new DateTimeZone('Africa/Nairobi'));
        
         $this->date  = $this->currentDateTime->format('Y-m-d H:i:s');

         $transaction_number =  $this->GenerateNextTransaction();

         $this->transaction_number = $transaction_number;

         $partner_transaction_number =  $this->GeneratePartnerNextTransaction();

         $this->partner_transaction_number = $partner_transaction_number;

 

         

    }
    
    
    public function stkresults()
    {
        //$stkCallbackResponse = file_get_contents('php://input');
        $response = $this->input->post('response');
         file_put_contents("mpesac2b/".$this->date.".txt", $response);

        $decode = json_decode($response,true);
        $MerchantRequestID = $decode['Body']['stkCallback']['MerchantRequestID'];
        $CheckoutRequestID = $decode['Body']['stkCallback']['CheckoutRequestID'];
        $Amount = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'];
        $MpesaReceiptNumber = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'];
        $TransactionDate = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][3]['Value'];
        $phone = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][4]['Value'];
        $ResultCode = $decode['Body']['stkCallback']['ResultCode'];

        $CustomerMessage = $decode['Body']['stkCallback']['ResultDesc'];
        
        if(!empty($MpesaReceiptNumber) && ($Amount > 0))
        {
               $this->SaveRequest($MerchantRequestID,$CheckoutRequestID,$MpesaReceiptNumber,$Amount,$TransactionDate,$phone);
        }
     



    }

    public function payment_response()
    {
        $response = $this->input->post('response');
        // $stkCallbackResponse = file_get_contents('php://input');
         file_put_contents("mpesac2b/test".$this->date.".txt", $response);
    }


    public function SaveRequest($MerchantRequestID,$CheckoutRequestID,$MpesaReceiptNumber,$Amount,$TransactionDate,$phone)
    {
    
        $moby = preg_replace('/^(?:\+?254|0)?/','+254', $phone);
 
	    
        

        if(!empty($MpesaReceiptNumber) || !empty($Amount) || $Amount !=NULL || $MpesaReceiptNumber !=NULL)
        {

            $condition = array('MerchantRequestID'=>$MerchantRequestID,'CheckoutRequestID'=>$CheckoutRequestID);

            $data = array(
                'amount'=>$Amount,
                'paid'=>1,
                'txn'=>$MpesaReceiptNumber,
                'TransactionDate'=>$TransactionDate,
                'created_on'=>$this->date,
                );

            $update = $this->Operations->UpdateData('mpesa_deposit',$condition,$data);
            
            //GET WALLET ID

            $searchUser = $this->Operations->SearchByCondition('mpesa_deposit',$condition);
    
            $wallet_id = $searchUser[0]['wallet_id'];
            
                     //get our buy rate 
    	    $buyratecondition = array('exchange_type'=>1,'service_type'=>1);
    	    $buyrate 	= $this->Operations->SearchByConditionBuy('exchange',$buyratecondition);
    	    
    	       //get our sell rate 
    	    $sellratecondition = array('exchange_type'=>2,'service_type'=>1);
    	    $sellrate 	= $this->Operations->SearchByConditionBuy('exchange',$sellratecondition);


            //SAVE TO LEDGERS AND SYSTEM ACCOUNTS


            $cr_dr = 'cr';
            
            $conversionRate = $buyrate[0]['kes'];

            $transaction_id =  $this->Operations->OTP(9);

            $transaction_number = $this->transaction_number;

      
                $customer_ledger_data = array(
                    'transaction_id'	=>	$transaction_id,
                    'transaction_number' => $transaction_number,
                    'receipt_no'		=>	$this->Operations->Generator(15),
                    'description'		=>	'ITP',
                    'pay_method' => 'MPESA',
                    'wallet_id' => $wallet_id,
                    'trans_id' => $MpesaReceiptNumber,
                    'paid_amount' => $Amount,
                    'cr_dr'=>$cr_dr,
                    'trans_date' => $this->date,
                    'currency' => 'KES',
                    'amount' => $Amount,
                    'rate' => 0,
                    'deriv' => 0,
                    'chargePercent' =>0,
                    'charge' =>0,
                    'total_amount' =>$Amount,
                    'status' => 1,
                    'created_at' => $this->date,
                );
                $save_customer_ledger = $this->Operations->Create('customer_ledger',$customer_ledger_data);
        
                $system_ledger_data = array
                (
                    'transaction_id'	=>	$transaction_id,
                    'transaction_number' => $transaction_number,
                    'receipt_no'		=>	$this->Operations->Generator(15),
                    'description'		=>	'ITP',
                    'pay_method' => 'MPESA',
                    'wallet_id' => $wallet_id,
                    'trans_id' => $MpesaReceiptNumber,
                    'paid_amount' => $Amount,
                    'cr_dr'=>$cr_dr,
                    'trans_date' => $this->date , 
                    'currency' => 'KES',
                    'deriv' => 0,
                    'amount' => $Amount,
                    'rate' => 0,
                    'chargePercent' =>0,
                    'charge' =>0,
                    'total_amount' =>$Amount,
                    'status' => 1,
                    'created_at' => $this->date,
                );
        
                $save_system_ledger = $this->Operations->Create('system_ledger',$system_ledger_data);



                //SEND APP USER NOTIFICATION
                
                
                 $condition1 = array('wallet_id'=>$wallet_id);
	    
        	    $searchuser1 = $this->Operations->SearchByCondition('customers',$condition1);
        	    
        	    $mobile = $searchuser1[0]['phone'];

                $transaction_number = $this->transaction_number;
        
               
                
                $message = ''.$transaction_number.' Successfully KES '.$Amount.' deposited to your STEPAKASH Account';
		    	
        


            if($save_customer_ledger === TRUE && $save_system_ledger === TRUE)
            {
                //SEND USER APP NOTIFICATION 
                $sms = $this->Operations->sendSMS($mobile, $message);
                $response['status'] = 'success';
                $response['message'] = $message;
        
            }else
            {
                $response['status'] = 'fail';
                $response['message'] = 'something went wrong,try again';
            }

        }

        echo json_encode($response);

  
    

    }
    
    
    public function b2c_result()
    {

        $response = $this->input->post('response');
        
       // $stkCallbackResponse = file_get_contents('php://input');
        file_put_contents("mpesab2c/".$this->date.".txt", $response);

        $data = json_decode($response,true);
        
        $resultType = $data['Result']['ResultType'];
        $resultCode = $data['Result']['ResultCode'];
        $resultDesc = $data['Result']['ResultDesc'];
        $originatorConversationID = $data['Result']['OriginatorConversationID'];
        $conversationID = $data['Result']['ConversationID'];
        $MpesaReceiptNumber = $data['Result']['TransactionID'];
        $Amount = $data['Result']['ResultParameters']['ResultParameter'][0]['Value'];
        $transactionReceipt = $data['Result']['ResultParameters']['ResultParameter'][1]['Value'];
        $receiverPartyPublicName = $data['Result']['ResultParameters']['ResultParameter'][2]['Value'];
        $transactionCompletedDateTime = $data['Result']['ResultParameters']['ResultParameter'][3]['Value'];
        $b2cUtilityAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][4]['Value'];
        $b2cWorkingAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][5]['Value'];
        $b2cRecipientIsRegisteredCustomer = $data['Result']['ResultParameters']['ResultParameter'][6]['Value'];
        $b2cChargesPaidAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][7]['Value'];
        
        
         if(!empty($MpesaReceiptNumber) || !empty($Amount) || $Amount !=NULL || $MpesaReceiptNumber !=NULL && ($Amount > 0))
        {
        $this->SaveB2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,$MpesaReceiptNumber,
        $Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds);
        }

        
    }
    
    public function SaveB2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,
    $MpesaReceiptNumber,$Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds)
    {

        


            $condition = array('conversationID'=>$conversationID,'OriginatorConversationID'=>$originatorConversationID);

            $data = array('amount'=>$Amount,'paid'=>1,'receiverPartyPublicName'=>$receiverPartyPublicName,
            'b2cUtilityAccountAvailableFunds'=>$b2cUtilityAccountAvailableFunds,
            'MpesaReceiptNumber'=>$MpesaReceiptNumber,'currency'=>'KES');

            $update = $this->Operations->UpdateData('mpesa_withdrawals',$condition,$data);
            
            //GET WALLET ID

            $searchUser = $this->Operations->SearchByCondition('mpesa_withdrawals',$condition);
    
            $wallet_id = $searchUser[0]['wallet_id'];
            $transaction_number = $searchUser[0]['transaction_number'];

            

            //SAVE TO LEDGERS AND SYSTEM ACCOUNTS


            $cr_dr = 'dr';
            
            $conversionRate = 0;

            $condition1 = array('wallet_id'=>$wallet_id);
    
            $searchuser1 = $this->Operations->SearchByCondition('customers',$condition1);
            
            $mobile = $searchuser1[0]['phone'];
        
            
            $message = ''.$transaction_number.' Successfully KES '.$Amount.' Withdrawn from your STEPAKASH Wallet';
		    	

            if($update === TRUE)
            {
                //SEND USER APP NOTIFICATION 
                $sms = $this->Operations->sendSMS($mobile, $message);
                $response['status'] = 'success';
                $response['message'] = $message;
                
        
            }else
            {
                $response['status'] = 'fail';
                $response['message'] = 'something went wrong,try again';
            }

        

        echo json_encode($response);
        
    }
    
    
    public function register_url()
    {
        //kindly change the values below
        $mpesa_consumer_key ="WGEE5jgXQowJ56mld9g3GKG15AtqUMPj"; //replace this  key from Daraja Portal
        $mpesa_consumer_secret = "0gtwpYJd57dFdh9b"; //replace this also
        $paybill_number = "4124755"; //replace this with your paybill number/till number
        $confirmation_url ="https://stk.stepakash.com/c2b_callback.php";
        $validation_url = "https://stk.stepakash.com/c2b_callback.php";
        $plaintext = '386ab37a45b861a7813ce7d412c4db1ce2c552dd8872505cfedfd64a94036a55'; /**replace this with the password of the mpesa user you created in the mpesa portal. It is important if the user is a business operator who has all the privileges apart from those of auditor to avoid conflicts. This user will be used in other sections of the module later**/ 
        
        $token_url = 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
  
           $timestamp = date("Ymdhis");
        //set pass
        $password = base64_encode($paybill_number.$plaintext.$timestamp);
        
        $curl = curl_init();
        $url = 'https://api.safaricom.co.ke/oauth/v2/generate?grant_type=client_credentials';
        curl_setopt($curl, CURLOPT_URL, $url);
        $credentials = base64_encode($mpesa_consumer_key.':'.$mpesa_consumer_secret);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Authorization: Basic '.$credentials)); //setting a custom header
        curl_setopt($curl, CURLOPT_HEADER, false); //set false to allow json decode
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); //very important
        $curl_response = curl_exec($curl);
        $cred_password_raw = json_decode($curl_response, true); 
        $cred_password = $cred_password_raw['access_token']; 
          
          $url = 'https://api.safaricom.co.ke/mpesa/c2b/v2/registerurl';
          
          $curl = curl_init();
          curl_setopt($curl, CURLOPT_URL, $url);
          curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$cred_password)); //setting custom header
          
          
          $curl_post_data = array(
            //Fill in the request parameters with valid values
            'ShortCode' => 4124755,
            'ResponseType' => 'Completed',
            'ConfirmationURL' => $validation_url,
            'ValidationURL' => $validation_url,
          );
          
          $data_string = json_encode($curl_post_data);
          
          curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($curl, CURLOPT_POST, true);
          curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
          
          $curl_response = curl_exec($curl);
          print_r($curl_response);
    }
    
    public function mpesa_c2b_results()
    {
        $stkCallbackResponse = file_get_contents('php://input');
        file_put_contents("mpesac2b/".$this->date.".txt", $stkCallbackResponse);
        
    }

    public function post_c2b_results()
    {

        $response = $this->input->post('response');
        file_put_contents("mpesac2b/today".$this->date.".txt", $response);

        $data = json_decode($response,true);
        
    }
    
    public function validation_url()
    {
         $stkCallbackResponse = file_get_contents('php://input');
        file_put_contents("mpesac2b/".$this->date.".txt", $stkCallbackResponse);
        
    }

    public function GenerateNextTransaction()
    {
        $last_id = $this->Operations->getLastTransactionId();
        // echo $last_id; 

        $nextReceipt = $this->getNextReceipt($last_id);
        return $nextReceipt;
    }


    public function GeneratePartnerNextTransaction()
    {
        $last_id = $this->Operations->getLastPartnerTransactionId();
        // echo $last_id; 

        $nextReceipt = $this->getNextReceipt($last_id);
        return $nextReceipt;
    }


    public function getNextReceipt($currentReceipt) {
        // Separate the letters, digits, and extra letter from the receipt number
        preg_match('/([A-Z]+)(\d+)([A-Z]*)/', $currentReceipt, $matches);
        $letters = $matches[1];
        $digits = intval($matches[2]);
        $extraLetter = isset($matches[3]) ? $matches[3] : '';
    
        // Increment the extra letter if it exists, otherwise increment the digits part
        if (!empty($extraLetter)) {
            // Increment the extra letter
            $nextExtraLetter = chr(ord($extraLetter) + 1);
    
            // If the extra letter rolls over to 'Z', reset it to 'A' and increment the digits part
            if ($nextExtraLetter > 'Z') {
                $nextExtraLetter = 'A';
                $nextDigits = $digits + 1;
            } else {
                $nextDigits = $digits;
            }
        } else {
            // If there is no extra letter, increment the digits part
            $nextExtraLetter = 'A';
            $nextDigits = $digits + 1;
        }
    
        // If the digits part rolls over to 100, adjust letters and reset digits to 1
        if ($nextDigits == 100) {
            // Increment the last letter
            $lettersArray = str_split($letters);
            $lastIndex = count($lettersArray) - 1;
            $lettersArray[$lastIndex] = chr(ord($lettersArray[$lastIndex]) + 1);
    
            // Convert the array back to a string
            $nextLetters = implode('', $lettersArray);
    
            // Reset digits to 1
            $nextDigits = 1;
        } else {
            $nextLetters = $letters;
        }
    
        // Ensure that the digits part is formatted with leading zeros if necessary
        $nextDigitsStr = str_pad($nextDigits, 2, '0', STR_PAD_LEFT);
    
        // Construct the next receipt number
        $nextReceipt = $nextLetters . $nextDigitsStr . $nextExtraLetter;
    
        return $nextReceipt;
    }


    public function next_receipt()
    {
        $transaction_id =  $this->GenerateNextTransaction();
        $response['status'] = 'success';
        $response['message'] = 'next receipt';
        $response['data'] = $transaction_id;

        echo json_encode($response);

    }

    public function toa()
    {
        $response['status'] = 'success';
        $response['message'] = 'next receipt';
        $response['data'] = $this->transaction_number;

        echo json_encode($response);
    }


    public function partners_stkresults()
    {
        //$stkCallbackResponse = file_get_contents('php://input');
        $response = $this->input->post('response');
         file_put_contents("mpesapartnerc2b/".$this->date.".txt", $response);

        $decode = json_decode($response,true);
        $MerchantRequestID = $decode['Body']['stkCallback']['MerchantRequestID'];
        $CheckoutRequestID = $decode['Body']['stkCallback']['CheckoutRequestID'];
        $Amount = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value'];
        $MpesaReceiptNumber = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value'];
        $TransactionDate = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][3]['Value'];
        $phone = $decode['Body']['stkCallback']['CallbackMetadata']['Item'][4]['Value'];
        $ResultCode = $decode['Body']['stkCallback']['ResultCode'];

        $CustomerMessage = $decode['Body']['stkCallback']['ResultDesc'];
        
        if(!empty($MpesaReceiptNumber) && ($Amount > 0))
        {
               $this->SavePartnersMpesaRequest($MerchantRequestID,$CheckoutRequestID,$MpesaReceiptNumber,$Amount,$TransactionDate,$phone);
        }
     



    }


    public function SavePartnersMpesaRequest($MerchantRequestID,$CheckoutRequestID,$MpesaReceiptNumber,$Amount,$TransactionDate,$phone)
    {

        

        if(!empty($MpesaReceiptNumber) || !empty($Amount) || $Amount !=NULL || $MpesaReceiptNumber !=NULL)
        {

            $condition = array('MerchantRequestID'=>$MerchantRequestID,'CheckoutRequestID'=>$CheckoutRequestID);

            $data = array(
                'amount'=>$Amount,
                'status'=>1,
                'receipt_no'=>$MpesaReceiptNumber,
                'TransactionDate'=>$TransactionDate,
                'created_on'=>$this->date,
                );

            $update = $this->Operations->UpdateData('partner_mpesa_deposit',$condition,$data);
            
            //GET WALLET ID

            $searchUser = $this->Operations->SearchByCondition('partner_mpesa_deposit',$condition);
    
            $partner_id = $searchUser[0]['partner_id'];
            $partner_phone_paid = $searchUser[0]['phone'];

            

            //SAVE TO LEDGERS AND SYSTEM ACCOUNTS


            $cr_dr = 'cr';
            
            $transaction_id =  $this->Operations->OTP(9);

            $partner_transaction_number = $this->partner_transaction_number;

            $description = 'funds top up';

      
                $partner_ledger_data = array(
                    'transaction_id'	=>	$transaction_id,
                    'transaction_number' => $partner_transaction_number,
                    'receipt_no'		=>	$this->Operations->Generator(15),
                    'description'		=>	$description,
                    'pay_method' => 'MPESA',
                    'partner_id' => $partner_id,
                    'trans_id' => $MpesaReceiptNumber,
                    'trans_amount' => $Amount,
                    'cr_dr'=>$cr_dr,
                    'charge' =>0,
                    'charge_percent' =>0,
                    'currency' => 'KES',
                    'amount' => $Amount,
                    'total_amount' =>$Amount,
                    'ledger_account'=>1,
                    'status' => 1,
                    'trans_date' => $this->date,
                );
                $save_partner_ledger = $this->Operations->Create('partner_ledger',$partner_ledger_data);
        
                $partner_system_ledger_data = array
                (
                    'transaction_id'	=>	$transaction_id,
                    'transaction_number' => $partner_transaction_number,
                    'receipt_no'		=>	$this->Operations->Generator(15),
                    'description'		=>	$description,
                    'pay_method' => 'MPESA',
                    'partner_id' => $partner_id,
                    'trans_id' => $MpesaReceiptNumber,
                    'trans_amount' => $Amount,
                    'cr_dr'=>$cr_dr,
                    'charge' =>0,
                    'charge_percent' =>0,
                    'currency' => 'KES',
                    'amount' => $Amount,
                    'total_amount' =>$Amount,
                    'ledger_account'=>1,
                    'status' => 1,
                    'trans_date' => $this->date,
                );
        
                $save_partner_system_ledger = $this->Operations->Create('partner_system_ledger',$partner_system_ledger_data);



                //SEND SMS NOTIFICATION
               
                $message = ''.$partner_transaction_number.' Successfully KES '.$Amount.' deposited to account for partner: '.$partner_id.'';
		    	

                if($update === TRUE && $save_partner_ledger === TRUE && $save_partner_system_ledger === TRUE)
                {
                    //SEND USER APP NOTIFICATION 
                    $sms = $this->Operations->sendSMS($partner_phone_paid, $message);
                    $response['status'] = 'success';
                    $response['message'] = $message;
            
                }else
                {
                    $response['status'] = 'fail';
                    $response['message'] = 'something went wrong,try again';
                }

        }

        echo json_encode($response);

  
    

    }



    public function partner_b2c_result()
    {
        $response = $this->input->post('response'); 
       // $stkCallbackResponse = file_get_contents('php://input');
        file_put_contents("partnerb2c/".$this->date.".txt", $response);

        $data = json_decode($response,true);
        
        $resultType = $data['Result']['ResultType'];
        $resultCode = $data['Result']['ResultCode'];
        $resultDesc = $data['Result']['ResultDesc'];
        $originatorConversationID = $data['Result']['OriginatorConversationID'];
        $conversationID = $data['Result']['ConversationID'];
        $MpesaReceiptNumber = $data['Result']['TransactionID'];
        $Amount = $data['Result']['ResultParameters']['ResultParameter'][0]['Value'];
        $transactionReceipt = $data['Result']['ResultParameters']['ResultParameter'][1]['Value'];
        $receiverPartyPublicName = $data['Result']['ResultParameters']['ResultParameter'][2]['Value'];
        $transactionCompletedDateTime = $data['Result']['ResultParameters']['ResultParameter'][3]['Value'];
        $b2cUtilityAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][4]['Value'];
        $b2cWorkingAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][5]['Value'];
        $b2cRecipientIsRegisteredCustomer = $data['Result']['ResultParameters']['ResultParameter'][6]['Value'];
        $b2cChargesPaidAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][7]['Value'];
        
        
         if(!empty($MpesaReceiptNumber) || !empty($Amount) || $Amount !=NULL || $MpesaReceiptNumber !=NULL && ($Amount > 0))
        {
        $this->Save_partner_B2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,$MpesaReceiptNumber,
        $Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds);
        }

        
    }
    
    public function Save_partner_B2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,
    $MpesaReceiptNumber,$Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds)
    {

        


            $condition = array('conversationID'=>$conversationID,'OriginatorConversationID'=>$originatorConversationID);

            $data = array(
                'receipt_no'=>$MpesaReceiptNumber,
                'amount'=>$Amount,
                'withdraw'=>$Amount,
                'status'=>1,
                'currency'=>'KES'
            );

            $update = $this->Operations->UpdateData('partner_transfer_funds',$condition,$data);
            
            //GET WALLET ID

            $searchUser = $this->Operations->SearchByCondition('partner_transfer_funds',$condition);
    
            $account_number = $searchUser[0]['account_number'];
            $transaction_number = $searchUser[0]['transaction_number'];
            $receipt_no = $searchUser[0]['receipt_no'];


        
            //SAVE TO LEDGERS AND SYSTEM ACCOUNTS


            $message = ''.$transaction_number.', Successfully KES '.$Amount.' transfered to '.$account_number.' account';
		    	

            if($update === TRUE)
            {
                //SEND USER APP NOTIFICATION 
                $sms = $this->Operations->sendSMS($account_number, $message);
                $response['status'] = 'success';
                $response['message'] = $message;
                
        
            }else
            {
                $response['status'] = 'fail';
                $response['message'] = 'something went wrong,try again';
            }

        

        echo json_encode($response);
        
    }


    public function gifting_b2c_result()
    {
        $response = $this->input->post('response'); 
       // $stkCallbackResponse = file_get_contents('php://input');
        file_put_contents("partnerb2c/".$this->date.".txt", $response);

        $data = json_decode($response,true);
        
        $resultType = $data['Result']['ResultType'];
        $resultCode = $data['Result']['ResultCode'];
        $resultDesc = $data['Result']['ResultDesc'];
        $originatorConversationID = $data['Result']['OriginatorConversationID'];
        $conversationID = $data['Result']['ConversationID'];
        $MpesaReceiptNumber = $data['Result']['TransactionID'];
        $Amount = $data['Result']['ResultParameters']['ResultParameter'][0]['Value'];
        $transactionReceipt = $data['Result']['ResultParameters']['ResultParameter'][1]['Value'];
        $receiverPartyPublicName = $data['Result']['ResultParameters']['ResultParameter'][2]['Value'];
        $transactionCompletedDateTime = $data['Result']['ResultParameters']['ResultParameter'][3]['Value'];
        $b2cUtilityAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][4]['Value'];
        $b2cWorkingAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][5]['Value'];
        $b2cRecipientIsRegisteredCustomer = $data['Result']['ResultParameters']['ResultParameter'][6]['Value'];
        $b2cChargesPaidAccountAvailableFunds = $data['Result']['ResultParameters']['ResultParameter'][7]['Value'];
        
        
         if(!empty($MpesaReceiptNumber) || !empty($Amount) || $Amount !=NULL || $MpesaReceiptNumber !=NULL && ($Amount > 0))
        {
        $this->Save_gifting_B2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,$MpesaReceiptNumber,
        $Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds);
        }

        
    }
    
    public function Save_gifting_B2cResult($resultCode,$resultDesc,$originatorConversationID,$conversationID,
    $MpesaReceiptNumber,$Amount,$receiverPartyPublicName,$transactionCompletedDateTime,$b2cUtilityAccountAvailableFunds,$b2cWorkingAccountAvailableFunds)
    {

        


            $condition = array('conversationID'=>$conversationID,'OriginatorConversationID'=>$originatorConversationID);

            $data = array(
                'receipt_no'=>$MpesaReceiptNumber,
                'sent'=>$Amount,
                'status'=>1,
                'currency'=>'KES'
            );

            $update = $this->Operations->UpdateData('gifting',$condition,$data);
            
            //GET WALLET ID

            $searchUser = $this->Operations->SearchByCondition('gifting',$condition);
    
            $phone = $searchUser[0]['phone'];
            $transaction_number = $searchUser[0]['transaction_number'];
        
            //SAVE TO LEDGERS AND SYSTEM ACCOUNTS

            $message = ''.$transaction_number.', Successfully KES '.$Amount.' gifted to '.$phone.' account';
		    	
            if($update === TRUE)
            {
                //SEND USER APP NOTIFICATION 
                // $sms = $this->Operations->sendSMS($phone, $message);
                $response['status'] = 'success';
                $response['message'] = $message;
                
        
            }else
            {
                $response['status'] = 'fail';
                $response['message'] = 'something went wrong,try again';
            }

        

        echo json_encode($response);
        
    }





	



}
