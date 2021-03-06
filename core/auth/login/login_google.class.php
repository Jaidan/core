<?php
/*	Project:	EQdkp-Plus
 *	Package:	EQdkp-plus
 *	Link:		http://eqdkp-plus.eu
 *
 *	Copyright (C) 2006-2016 EQdkp-Plus Developer Team
 *
 *	This program is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU Affero General Public License as published
 *	by the Free Software Foundation, either version 3 of the License, or
 *	(at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU Affero General Public License for more details.
 *
 *	You should have received a copy of the GNU Affero General Public License
 *	along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( !defined('EQDKP_INC') ){
	header('HTTP/1.0 404 Not Found');exit;
}

class login_google extends gen_class {
	private $oauth_loaded = false;
	private $redirURL = "";

	public static $functions = array(
			'login_button'		=> 'login_button',
			'account_button'	=> 'account_button',
			'get_account'		=> 'get_account',
			'register_button' 	=> 'register_button',
			'pre_register'		=> 'pre_register',
			'redirect'			=> 'redirect',
	);

	public static $options = array(
			'connect_accounts'	=> true,
	);

	public function __construct(){
		$this->redirURL = $this->env->buildLink().'index.php/auth-endpoint/?lmethod=google';
	}

	public function settings(){
		$settings = array(
				'login_google_publickey'	=> array(
						'type'	=> 'text',
				),
				'login_google_privatekey' => array(
						'type'	=> 'password',
						'set_value' => true,
				),
		);
		return $settings;
	}

	private $appid, $appsecret = false;

	private $AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/auth';
	private $TOKEN_ENDPOINT         = 'https://accounts.google.com/o/oauth2/token';


	public function init_oauth(){
		if (!$this->oauth_loaded && !class_exists('OAuth2\\Client')){
			require($this->root_path.'libraries/oauth/Client.php');
			require($this->root_path.'libraries/oauth/GrantType/IGrantType.php');
			require($this->root_path.'libraries/oauth/GrantType/AuthorizationCode.php');
			$this->oauth_loaded = true;
		}

		$this->appid = $this->config->get('login_google_publickey');
		$this->appsecret = $this->config->get('login_google_privatekey');
	}

	public function redirect($arrOptions=array()){
		$this->init_oauth();
		$redir_url = $this->env->buildLink().'index.php/auth-endpoint/?lmethod=google';

		if(!strlen($this->appid) || !strlen($this->appsecret)){
			message_die('Google Client-ID or Client-Secret is missing. Please insert it into the fields at the EQdkp Plus settings, tab "User".');
		}

		$state = random_string(32);
		$this->user->setSessionVar('_google_state', $state);

		$client = new OAuth2\Client($this->appid, $this->appsecret);
		$auth_url = $client->getAuthenticationUrl($this->AUTHORIZATION_ENDPOINT, $redir_url, array('scope' => 'profile openid email', 'state' => $state));

		return $auth_url;
	}

	public function login_button(){
		$auth_url = $this->redirURL.'&status=login&link_hash='.$this->user->csrfGetToken('authendpoint_pageobjectlmethod');

		//Button color: #7289DA
		return '<button type="button" class="mainoption thirdpartylogin google loginbtn" onclick="window.location=\''.$auth_url.'\'"><i class="fa fa-google fa-lg"></i> Google</button>';
	}


	public function account_button(){
		$auth_url = $this->redirURL.'&status=account&link_hash='.$this->user->csrfGetToken('authendpoint_pageobjectlmethod');

		return '<button type="button" class="mainoption thirdpartylogin google accountbtn" onclick="window.location=\''.$auth_url.'\'"><i class="fa fa-google fa-lg"></i> Google</button>';
	}

	public function register_button(){
		$auth_url = $this->redirURL.'&status=register&link_hash='.$this->user->csrfGetToken('authendpoint_pageobjectlmethod');

		return '<button type="button" class="mainoption thirdpartylogin google accountbtn" onclick="window.location=\''.$auth_url.'\'"><i class="fa fa-google fa-lg"></i> Google</button>';
	}

	public function pre_register(){
		$this->init_oauth();

		$blnLoginResult = false;

		if($this->in->exists('code')){
			//Check state
			$strSavedState = $this->user->data['session_vars']['_google_state'];
			if(!$strSavedState || $strSavedState == '' || $strSavedState !== $this->in->get('state')){
				return false;
			}

			$client = new OAuth2\Client($this->appid, $this->appsecret);
			$params = array('code' => $this->in->get('code'), 'redirect_uri' => $this->redirURL, 'scope' => 'profile openid email');
			$response = $client->getAccessToken($this->TOKEN_ENDPOINT, 'authorization_code', $params);

			if ($response && $response['result']){
				$arrAccountResult = $this->fetchUserData($response['result']['access_token']);

				if($arrAccountResult){
					$auth_account = $arrAccountResult['sub'];

					$bla = array(
							'username'			=> isset($arrAccountResult['name']) ? utf8_ucfirst($arrAccountResult['name']) : '',
							'user_email'		=> isset($arrAccountResult['email']) ? $arrAccountResult['email'] : '',
							'user_email2'		=> isset($arrAccountResult['email']) ? $arrAccountResult['email'] : '',
							'auth_account'		=> $arrAccountResult['sub'],
							'user_timezone'		=> $this->config->get('timezone'),
							'user_lang'			=> $this->user->lang_name,
							'avatar'			=> isset($arrAccountResult['picture']) ? $arrAccountResult['picture'] : '',
					);

					$arrUserData = $this->user->registerUserFromAuthProvider($bla, 'google');
					if(isset($arrUserData['user_id'])){
						//Log the user in
						$auth_url = $this->controller_path_plain.'auth-endpoint/?lmethod=google&status=login&link_hash='.$this->user->csrfGetToken('authendpoint_pageobjectlmethod');
						redirect($auth_url);
					}

					return $arrUserData;
				}
			}

		}

		return false;
	}


	public function get_account(){
		$this->init_oauth();

		$code = $this->in->get('code');

		if ($code){
			//Check state
			$strSavedState = $this->user->data['session_vars']['_google_state'];
			if(!$strSavedState || $strSavedState == '' || $strSavedState !== $this->in->get('state')){
				return false;
			}

			$client = new OAuth2\Client($this->appid, $this->appsecret);

			$params = array('code' => $code, 'redirect_uri' => $this->redirURL, 'scope' => 'profile openid email');
			$response = $client->getAccessToken($this->TOKEN_ENDPOINT, 'authorization_code', $params);

			if ($response && $response['result'] && $response['result']['access_token']){
				$arrAccountResult = $this->fetchUserData($response['result']['access_token']);
				if($arrAccountResult){
					if(isset($arrAccountResult['sub'])){
						return $arrAccountResult['sub'];
					}
				}
			}
		}

		return false;
	}



	/**
	 * User-Login for google
	 *
	 * @param $strUsername
	 * @param $strPassword
	 * @return bool/array
	 */
	public function login($strUsername, $strPassword){
		$this->init_oauth();

		$code = $_GET['code'];

		if ($code){
			$client = new OAuth2\Client($this->appid, $this->appsecret);

			$params = array('code' => $code, 'redirect_uri' => $this->redirURL, 'scope' => 'profile openid email');
			$response = $client->getAccessToken($this->TOKEN_ENDPOINT, 'authorization_code', $params);

			if ($response && $response['result']){
				$arrAccountResult = $this->fetchUserData($response['result']['access_token']);
				if($arrAccountResult){
					if(isset($arrAccountResult['sub'])){
						$userid = $this->pdh->get('user', 'userid_for_authaccount', array($arrAccountResult['sub'], 'google'));
						if ($userid){
							$userdata = $this->pdh->get('user', 'data', array($userid));
							if ($userdata){
								return array(
										'status'		=> 1,
										'user_id'		=> $userdata['user_id'],
										'password_hash'	=> $userdata['user_password'],
										'autologin'		=> true,
										'user_login_key' => $userdata['user_login_key'],
								);
							}
						} elseif((int)$this->config->get('cmsbridge_active') != 1 && (int)$this->config->get('login_fastregister')){
							$auth_url = $this->controller_path_plain.'auth-endpoint/?lmethod=google&status=register&link_hash='.$this->user->csrfGetToken('authendpoint_pageobjectlmethod');
							redirect($auth_url);
						}

					}
				}

			}
		}

		return false;
	}

	/**
	 * User-Logout
	 *
	 * @return bool
	 */
	public function logout(){
		return true;
	}

	/**
	 * Autologin
	 *
	 * @param $arrCookieData The Data ot the Session-Cookies
	 * @return bool
	 */
	public function autologin($arrCookieData){
		return false;
	}

	private function fetchUserData($strAccessToken){
		$result = register('urlfetcher')->fetch('https://openidconnect.googleapis.com/v1/userinfo', array('Authorization: Bearer '.$strAccessToken));
		if($result){
			$arrJSON = json_decode($result, true);
			if(!isset($arrJSON['error'])){
				return $arrJSON;
			} else {
				$this->core->message($arrJSON['errors'][0], 'Google Error', 'red');
			}
		}
		return false;
	}

}
