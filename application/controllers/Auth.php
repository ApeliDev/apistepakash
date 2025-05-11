<?php



class Auth extends CI_Controller

{

    

    private $currentDateTime;

    

    private $date;

    

	public function __construct()

    {

        

        parent::__construct();

        $this->load->model('Operations');

         header('Content-Type: application/json');

         

                

        $this->currentDateTime = new DateTime('now', new DateTimeZone('Africa/Nairobi'));

        

         $this->date  = $this->currentDateTime->format('Y-m-d H:i:s');



    }





	

	public function index()

	{

		$this->load->view('login');

	}

	

    public function Login()

    {

        $response = array();

    

        $this->form_validation->set_rules('phone', 'phone', 'required');

        $this->form_validation->set_rules('password', 'password', 'required');

        $this->form_validation->set_rules('ip_address', 'ip_address', 'required');


    

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            if ($this->form_validation->run() == FALSE) {

                // Handle validation errors

                $response['status'] = 'fail';

                $response['message'] = 'Phone,ip address or password required';

                $response['data'] = '';

            } else {

                // Process the validated data

                $table = "customers";

    

                $phoned = $this->input->post('phone');

                $phoned = str_replace(' ', '', $phoned); // Remove spaces from

                $phone = preg_replace('/^(?:\+?254|0)?/', '+254', $phoned);

                $password = $this->input->post('password');


                $password = str_replace(' ', '', $password); // Remove spaces from

                $ip_address = $this->input->post('ip_address');


    

                if (empty($phone) || empty($password) || empty($ip_address)) {

                    // Handle specific error cases

                    $response['status'] = 'fail';

                    $response['message'] = 'Phone and password are required'; 

                    $response['data'] = '';

                } else {

                    if ($this->Operations->resolve_user_login($phone, $password, $table)) {

                        $user_id = $this->Operations->get_user_id_from_phone($phone, $table);

                        $user = $this->Operations->get_user($user_id, $table);

                        $id = $user->id;

                        $wallet_id = $user->wallet_id;

                        $account_number = $user->account_number;

                        $phone = $user->phone;

                        $agent = $user->agent;


                        // $userIP = $this->getUserIP();

                        

                        $session_id = $this->Operations->SaveLoginSession($wallet_id,$phone,$ip_address,$this->date);

                        

    

                        $data = array(

                            'id' => $id,

                            'wallet_id' => $wallet_id,

                            'account_number' => $account_number,

                            'phone' => $phone,

                            'agent' => $agent,

                            'session_id'=>$session_id,

                            'created_on'=>$this->date,

                        );



    

                        $response['status'] = 'success';

                        $response['message'] = 'Login successful';

                        $response['data'] = $data;

                    } else {

                        $response['status'] = 'fail';

                        $response['message'] = 'Phone number or password not correct';

                        $response['data'] = '';

                    }

                }

            }

        } else {

            http_response_code(400); // Bad Request

            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';

        }

    

        echo json_encode($response);

    }

    public function getUserIP()
    {
         $ip = null;
 
         // Check if the IP is from a shared internet connection
         if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
             $ip = $_SERVER['HTTP_CLIENT_IP'];
         } 
         // Check if the IP is from a proxy
         elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
             $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
         } 
         // Use the remote address if available
         elseif (!empty($_SERVER['REMOTE_ADDR'])) {
             $ip = $_SERVER['REMOTE_ADDR'];
         }
 
         // Additional checks for IP addresses
         if (strpos($ip, ',') !== false) {
             // If multiple IP addresses are provided (common in proxy configurations), get the first one
             $ipList = explode(',', $ip);
             $ip = trim($ipList[0]);
         } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
             // If the IP is an IPv6 address, convert it to IPv4
             $ip = '::ffff:' . $ip;
         }
 
         return $ip;
    }



	public function CreateAccount()

    {

        

        $this->form_validation->set_rules('phone', 'phone', 'required');

	    $this->form_validation->set_rules('password', 'password', 'required');

	    $this->form_validation->set_rules('confirmpassword', 'confirmpassword', 'required');

	    //$this->form_validation->set_rules('account_number', 'Account Number', 'required');



	    

	     if ($_SERVER['REQUEST_METHOD'] == 'POST') {



        if ($this->form_validation->run() == FALSE) {

            // Handle validation errors

  

			$response['status'] = 'fail';

            $response['message'] = validation_errors();

            $response['data'] = '';



        } else {

             $table = 'customers';

            

    		$phone = $this->input->post('phone');

            $phoned = str_replace(' ', '', $phone); // Remove spaces from

            $mobile = preg_replace('/^(?:\+?254|0)?/', '+254', $phoned);

			$password = $this->input->post('password');

			// Remove spaces from password

            $password = str_replace(' ', '', $password);

			$confirmpassword = $this->input->post('confirmpassword');

			// Remove spaces from confirmpassword

            $confirmpassword = str_replace(' ', '', $confirmpassword);

			$account_number = strtoupper($this->input->post('account_number'));

            $account_number = str_replace(' ', '', $account_number);

			

			$p_id = $this->Operations->get_user_id_from_phone($mobile,$table);

            $ph   = $this->Operations->get_user($p_id,$table);

            $last_id = $this->Operations->getLastWalletId();

            $wallet_id = $this->getNextWallet($last_id);

            
           $wallet_condition  = array('wallet_id'=>$wallet_id);

           $get_wallet = $this->Operations->SearchByCondition('customers',$wallet_condition);



            if (empty($phone)) {

			$response['status'] = 'fail';

            $response['message'] = 'phone required';

            $response['data'] = '';

            }

            //elseif (!ctype_digit($mobile)) {

                // Invalid phone number format

          

			//$response['status'] = 'fail';

            //$response['message'] = 'invalid phone number';

            //$response['data'] = '';

            //}

             elseif (strlen($mobile) !== 13 || substr($mobile, 0, 4) !== '+254') {

                // Phone number should be of length 12 and start with +254

                $response['status'] = 'fail';

                $response['message'] = 'Invalid phone number format. Please use the format +2547xxxx';

                $response['data'] = '';

            }

            //elseif(empty($account_number)){

              

			//	$response['status'] = 'fail';

            //$response['message'] = 'CR number required';

            //$response['data'] = '';

            //}

          // elseif(!(strlen($account_number) === 8 || strlen($account_number) === 9) || substr($account_number, 0, 2) !== 'CR') {

                // Account number should be of length 8 or 9 (including 'CR') and start with 'CR'

             //   $response['status'] = 'fail';

              //  $response['message'] = 'Invalid CR number format. Please use CR followed by digits.';

              //  $response['data'] = '';

           // }

           elseif($get_wallet){

			$response['status'] = 'fail';

            $response['message'] = 'account already exists';

            $response['data'] = '';

           }

            elseif(empty($confirmpassword)){

         

			$response['status'] = 'fail';

            $response['message'] = 'password confirm required';

            $response['data'] = '';

            }

            elseif(empty($password)){

        

				

			$response['status'] = 'fail';

            $response['message'] = 'password  required';

            $response['data'] = '';

            }

			elseif($password != $confirmpassword){

         

			$response['status'] = 'fail';

            $response['message'] = 'password  must match';

            $response['data'] = '';

            }

			elseif($ph)

			{



			$response['status'] = 'fail';

            $response['message'] = 'phone number already exists';

            $response['data'] = '';

			}

			else{

                   $data = array(

                'phone' => $mobile,

                'password' => $this->Operations->hash_password($password),

                'wallet_id' => $wallet_id,

                'account_number' =>$account_number,

                'created_on' => $this->date,

                );

				if ($this->Operations->Create($table,$data)) {

					$subject = 'Account created';

					$message = 'Success account created, wallet ID '.$wallet_id.', use the following to login: phone : '.$mobile.' and same password you created account with';

					

					$sms = $this->Operations->sendSMS($mobile, $message);



					  

					$response['status'] = 'success';

                    $response['message'] = 'Succesfull account created login to start';

                    $response['data'] = '';

                } else {

    		

    				$response['status'] = 'fail';

                    $response['message'] = 'Unable to add now,try again';

                    $response['data'] = '';

                }

            }

        }

        

	     }else

        {

            http_response_code(400);



            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';



        }

        

        echo json_encode($response);

        

    }







    public function sendotp()

    {

        $response = array();

    

        $phoned = $this->input->post('phone');

        $phoned = str_replace(' ', '', $phoned); // Remove spaces from

        $mobile = preg_replace('/^(?:\+?254|0)?/', '+254', $phoned);

    

        $this->form_validation->set_rules('phone', 'phone', 'required');

    

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            if ($this->form_validation->run() == FALSE) {

                // Handle validation errors

                $response['status'] = 'fail';

                $response['message'] = 'Phone is required';

                $response['data'] = '';

            } else {

                if (empty($mobile)) {

                    $response['status'] = 'fail';

                    $response['message'] = 'Phone is required';

                    $response['data'] = '';

                } else {

                    $table = 'customers';

                    $condition = array('phone' => $mobile);

                    $search = $this->Operations->SearchByCondition($table, $condition);

    

                    if ($search) {

                        $wallet_id = $search[0]['wallet_id'];

                        $mobile = $search[0]['phone'];

                        $otp = $this->Operations->OTP(6);

                        

                        $sessdata = array

                        (

                            'wallet_id'=>$wallet_id,

                            'phone'=>$mobile,

                            'otp'=>$otp,

                            'created_on'=>$this->date,

                        );

                        

                        $save = $this->Operations->Create('forgot_password',$sessdata);

                        if($save === TRUE)

                        {

                            $message = 'Password reset OTP verification code '.$otp.' input it on time';

                            $sms = $this->Operations->sendSMS($mobile,$message);

                            $response['status'] = 'success';

                            $response['message'] = 'OTP code has been sent to '.$mobile.' please input on time';

                            $response['data'] = $sessdata; 

                        }

                        else

                        {

                            $response['status'] = 'error';

                            $response['message'] = 'Something went wrong resetting password';

                        }

    

              

    

                        

                    } else {

                        $response['status'] = 'fail';

                        $response['message'] = 'Phone number not registered';

                        $response['data'] = '';

                    }

                }

            }

        } else {

            http_response_code(400); // Bad Request

            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';

        }

    

        echo json_encode($response);

    }

    

    

     public function verifyOtp()

    {

        $response = array();

    

        $otp = $this->input->post('otp');

        $otp = str_replace(' ', '', $otp);

    

        $this->form_validation->set_rules('otp', 'otp', 'required');

    

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            if ($this->form_validation->run() == FALSE) {

                // Handle validation errors

                $response['status'] = 'fail';

                $response['message'] = 'otp is required';

                $response['data'] = '';

            } else {

                if (empty($otp)) {

                    $response['status'] = 'fail';

                    $response['message'] = 'otp is required';

                    $response['data'] = '';

                } else {

                    $table = 'forgot_password';

                    $condition = array('otp' => $otp);

                    $search = $this->Operations->SearchByCondition($table, $condition);

    

                    if (!empty($search) && $search[0]['otp'] == $otp) {

                        // Check if OTP is still valid (within 5 minutes)

                        $timestamp = strtotime($search[0]['created_on']);

                        $currentTimestamp = strtotime($this->date);

    

                        $timeDifference = $currentTimestamp - $timestamp;

                        $expirationTime = 5 * 60; // 5 minutes in seconds

    

                        if ($timeDifference <= $expirationTime) {

                            $wallet_id = $search[0]['wallet_id'];

                            $mobile = $search[0]['phone'];

    

                            $sessdata = array(

                                'wallet_id' => $wallet_id,

                                'phone' => $mobile,

                            );

    

                            $response['status'] = 'success';

                            $response['message'] = 'phone verified, reset your password now';

                            $response['data'] = $sessdata;

                        } else {

                            $response['status'] = 'fail';

                            $response['message'] = 'Verification code has expired';

                            $response['data'] = '';

                        }

                    } else {

                        $response['status'] = 'fail';

                        $response['message'] = 'Invalid Verification code ';

                        $response['data'] = '';

                    }

                }

            }

        } else {

            http_response_code(400); // Bad Request

            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';

        }

    

        echo json_encode($response);

    }



    public function updatepassword()

    {

        $response = array();

    

        $table = 'customers';

        $pass1 = $this->input->post('password');

        $pass2 = $this->input->post('confirmpassword');

        $mobile = $this->input->post('phone');

        $wallet_id = $this->input->post('wallet_id');

    

        $this->form_validation->set_rules('password', 'password', 'required');

        $this->form_validation->set_rules('confirmpassword', 'confirmpassword', 'required');

        $this->form_validation->set_rules('phone', 'phone', 'required');

        $this->form_validation->set_rules('wallet_id', 'wallet_id', 'required');

    

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {

            if ($this->form_validation->run() == FALSE) {

                // Handle validation errors

                $response['status'] = 'fail';

                $response['message'] = 'Phone, password, confirmpassword, and wallet_id are required';

                $response['data'] = '';

            } else {

                if ($pass1 != $pass2) {

                    $response['status'] = 'fail';

                    $response['message'] = 'Passwords must match';

                    $response['data'] = '';

                } else {

                    $data = array(

                        'password' => $this->Operations->hash_password($pass2),

                    );

    

                    $condition = array(

                        'wallet_id' => $wallet_id,

                    );

    

                    if ($this->Operations->UpdateData($table, $condition, $data)) {

                        $action = 'Updated account details';

                        $this->Operations->RecordAction($action);

                        $message = "Password updated. New Password: " . $pass2;

                        $sms = $this->Operations->sendSMS($mobile, $message);

    

                        $response['status'] = 'success';

                        $response['message'] = 'Password updated successfully';

                        $response['data'] = '';

                    } else {

                        $response['status'] = 'fail';

                        $response['message'] = 'Unable to update password, try again';

                        $response['data'] = '';

                    }

                }

            }

        } else {

            http_response_code(400); // Bad Request

            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';

            $response['data'] = '';

        }

    

        echo json_encode($response);

    }









	private function validateemail($email)

    {

        return filter_var($email, FILTER_VALIDATE_EMAIL);

    }

      private  function clean_input($data) 

    {

           $data = trim($data);

           $data = stripslashes($data);

           $data = htmlspecialchars($data);

           return $data;

    }



    private function hash_password($password) 

	{

        

        return password_hash($password, PASSWORD_BCRYPT);

        

    }





    private function verify_password_hash($password, $hash)

	{

        

        return password_verify($password, $hash);

        

    }

    //Jwt SECTION



   function generate_jwt($payload, $secret = 'secret')

   {

        $headers = array('alg'=>'HS256','typ'=>'JWT');

         $headers_encoded = $this->base64url_encode(json_encode($headers));

         

         $payload_encoded = $this->base64url_encode(json_encode($payload));

         

         $signature = hash_hmac('SHA256', "$headers_encoded.$payload_encoded", $secret, true);

         $signature_encoded = $this->base64url_encode($signature);

         

         $jwt = "$headers_encoded.$payload_encoded.$signature_encoded";

         

         return $jwt;

   }



   function base64url_encode($str) 

   {

    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');

   }



   public function ValidateToken()

   {

      $header = getallheaders();



      if ($header == "") {

             echo json_encode(array('message' => 'Access Denied'));

        }



        $authcode = trim($header['Authorization']);

        if ($authcode == "") {

             echo json_encode(array('message' => 'Authorization Token not Set'));

             die();

        }



        if($authcode !=""){

            $token = substr($authcode,7);

        $response = $this->ValidateJwt($token);



        print_r($response);

        }



        

   }



   public function ValidateJwt($jwt, $secret = 'secret') 

	{

          // split the jwt

          $tokenParts = explode('.', $jwt);

          $header = base64_decode($tokenParts[0]);

          $payload = base64_decode($tokenParts[1]);

          $signature_provided = $tokenParts[2];



          // check the expiration time - note this will cause an error if there is no 'exp' claim in the jwt

          $expiration = json_decode($payload)->exp;

          $is_token_expired = ($expiration - time()) < 0;



          // build a signature based on the header and payload using the secret

          $base64_url_header = $this->base64url_encode($header);

          $base64_url_payload = $this->base64url_encode($payload);

          $signature = hash_hmac('SHA256', $base64_url_header . "." . $base64_url_payload, $secret, true);

          $base64_url_signature = $this->base64url_encode($signature);



          // verify it matches the signature provided in the jwt

             $is_signature_valid = ($base64_url_signature === $signature_provided);

          

       if ($is_token_expired || !$is_signature_valid) {

          return json_encode(array('message'=> "Timeout"));

        } else {



        $decoded = json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $jwt)[1]))));

          $arr = ['message'=>'Access Allowed','status'=>200,'data'=>$decoded];



          return json_encode($arr);

          }

    }







    public function logout()

	{



        

        if (isset($_SESSION['wallet_id']) && $_SESSION['phone'] === true ) {

            

            // remove session datas

            foreach ($_SESSION as $key => $value) {

                unset($_SESSION[$key]);

            }

            redirect(base_url());

            

        } else {

					foreach ($_SESSION as $key => $value) {

						unset($_SESSION[$key]);

				}

            redirect(base_url());

            

        }

        



    }

    

    public function adminLogin()

    {

        $response = array();

    

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

            http_response_code(400); // Bad Request

            $response['status'] = 'fail';

            $response['message'] = 'Only POST request allowed';

            $response['data'] = '';

        } else {

            $table = "users";

    

            $email = $this->input->post('email');

            $password = $this->input->post('password');

    

            if (empty($email)) {

                $response['status'] = 'fail';

                $response['message'] = 'Email required';

                $response['data'] = '';

            } elseif (empty($password)) {

                $response['status'] = 'fail';

                $response['message'] = 'Password required';

                $response['data'] = '';

            } else {

                if ($this->Operations->resolve_super_admin_login($email, $password, $table)) {

                    $user_id = $this->Operations->get_admin_id_from_username($email, $table);

                    $user = $this->Operations->get_user($user_id, $table);

                    $id = $user->id;

                    $names = $user->names;

                    $phone = $user->phone;

                    $email1 = $user->email;

    

                    $data['id'] = $id;

                    $data['names'] = $names;

                    $data['phone'] = $phone;

                    $data['email'] = $email1;

                    $tim = $this->date;

    

                    $action = '' . $phone . ' logged in the system at ' . $tim . '';

                    $this->Operations->RecordAction($action);

    

                    $response['status'] = 'success';

                    $response['message'] = 'Successfully logged in. Welcome';

                    $response['data'] = $data;
          

                } else {

                    $response['status'] = 'fail';

                    $response['message'] = 'Unauthorised credentials!';

                    $response['data'] = '';

                }

            }

        }

    

        echo json_encode($response);

    }


    public function getNextWallet($currentReceipt) {
        // Separate the letters, digits, and extra letter from the receipt number
        preg_match('/([A-Z]+)(\d+)([A-Z]*)/', $currentReceipt, $matches);
        $letters = $matches[1];
        $digits = intval($matches[2]);
        $extraLetter = isset($matches[3]) ? $matches[3] : '';
    
        // Define the maximum number of digits and letters
        $maxDigits = 4; // Adjust as needed
        $maxLetters = 2; // Adjust as needed
    
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
    
        // If the digits part rolls over to the maximum, adjust letters and reset digits to 1
        if ($nextDigits > str_repeat('9', $maxDigits)) {
            // Increment the last letter
            $lettersArray = str_split($letters);
            $lastIndex = count($lettersArray) - 1;
            $lettersArray[$lastIndex] = chr(ord($lettersArray[$lastIndex]) + 1);
    
            // Convert the array back to a string
            $nextLetters = implode('', $lettersArray);
    
            // If all letters are exhausted, reset letters to 'A' and increment digits by 1
            if (strlen($nextLetters) > $maxLetters) {
                $nextLetters = 'A';
                $nextDigits = 1;
            }
        } else {
            $nextLetters = $letters;
        }
    
        // Ensure that the digits part is formatted with leading zeros if necessary
        $nextDigitsStr = str_pad($nextDigits, $maxDigits, '0', STR_PAD_LEFT);
    
        // Construct the next receipt number
        $nextReceipt = $nextLetters . $nextDigitsStr . $nextExtraLetter;
    
        return $nextReceipt;
    }
    





}

