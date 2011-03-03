<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Qamini User Model
 *
 * @package   qamini
 * @uses      Extends ORM
 * @since     0.1.0
 * @author    Serdar Yildirim
 */
class Model_User extends Model_Auth_User {

	protected $_has_many = array
	(
		'user_tokens'	=> array('model' => 'user_token'),
		'roles'       	=> array('model' => 'role', 'through' => 'roles_users'),
		'posts' 		=> array(),
	);

	/**
	 * User Model Constructor
	 */
	public function __construct()
	{
		parent::__construct();
	}

	public function rules()
	{
		return array(
			'username' => array(
				array('not_empty'),
				array('min_length', array(':value', 1)),
				array('max_length', array(':value', 32)),
				array('regex', array(':value', '/^[-\pL\pN_.]++$/uD')),
				array(array($this, 'username_available'), array(':validation', ':field')),
			),
			'password' => array(
				array('not_empty'),
			),
			'email' => array(
				array('not_empty'),
				array('min_length', array(':value', 4)),
				array('max_length', array(':value', 127)),
				array('email'),
				array(array($this, 'email_available'), array(':validation', ':field')),
			),
		);
	}
	
	public function filters()
	{
	    return array(
	        'username' => array(
	            array('trim'),
	        ),
	        'email' => array(
	            array('trim'),
	        ),
			'password' => array(
				array(array(Auth::instance(), 'hash'))
			)
	    );
	}

	/**
	 * Returns user by user id
	 *
	 * @param int user id
	 */
	public function get_user_by_id($id)
	{
		// Try to load the user
		$user = $this->where('id', '=', $id)->find();

		return ($user->loaded() === TRUE) ? $user : NULL;
	}

	/**
	 * Log user in if the data is validated
	 *
	 * @param  array   data
	 * @param  boolean remember me functionality
	 * @return boolean
	 */
	public function login(array &$data, $remember = FALSE)
	{
		$data = Validation::factory($data);

		// If the information is not valid, return false
		if (!$data->check())
			return FALSE;

		// Try to load the user
		$this->where('username', '=', $data['username'])->find();

		// Try to log user in
		if ($this->loaded() AND Auth::instance()->login($this, $data['password'], $remember))
			return TRUE;

		return FALSE;
	}

	/**
	 * Updates user's columns after login
	 */
	public function complete_login()
	{
		if (!$this->_loaded)
			return;

		// Update the number of logins
		$this->logins = new Database_Expression('logins + 1');

		// Set the last login and latest activity time
		$this->last_login = $this->latest_activity = time();

		// Set the user ip as his/her last ip
		$this->last_ip = Request::$client_ip;

		// Save the user
		$this->save();
	}

	/**
	 * Signs user up
	 * After validating user submitted data, new user is created with "login" role
	 *
	 * @param  array   data
	 * @param  string  directory of the current theme
	 * @throws ORM_Validation_Exception
	 * @return boolean
	 */
	public function signup(array &$data, $theme_dir)
	{
		$this->values($data);

		// Add the new user
		$this->save();

		// Give "login" role to the user
		$this->add('roles', ORM::factory('role', array('name' => 'login')));

		$link = URL::site(Route::get('user_ops')->uri(array('action' => 'confirm_signup'))
												. '?id=' . $this->id . '&auth_token='
												. Auth::instance()->hash($this->email)
											, 'http');

		$body = View::factory($theme_dir . 'email/confirm_signup', $this->as_array())
			->set('url', $link);

		// Get the email configuration into array
		$email_config = Kohana::config('email');

		// Load Swift Mailer required files
		require_once Kohana::find_file('vendor', 'swiftmailer/lib/swift_required');

		// Create an email message to reset user's password
		$message = Swift_Message::newInstance()
			->setSubject(Kohana::config('config.website_name') . __(' - Signup'))
			->setFrom(array(Kohana::config('config.email') => Kohana::config('config.website_name') . __(' Website')))
			->setTo(array($this->email => $this->username))
			->setBody($body);

		// Connect to the server
		$transport = Swift_SmtpTransport::newInstance($email_config->server, $email_config->port, $email_config->security)
			->setUsername($email_config->username)
			->setPassword($email_config->password);

		// Try to send the email
		try {
			Swift_Mailer::newInstance($transport)->send($message);
		}
		catch (Exception $ex) {
			Kohana_Log::instance()->add(Kohana_Log::ERROR, 'User signup email send error, msg: '. $ex->getMessage());
		}

		return TRUE;
	}

	/**
	 * Confirms user's registration by adding 'user' role to the user
	 *
	 * @param   integer  user id
	 * @param   string   confirmation token
	 * @return  boolean  true on success
	 */
	public function confirm_signup($id, $token)
	{
		if ($id < 0 || empty($token) || ($this->loaded() && $this->id != $id))
			return FALSE;

		// Load user by id
		if (!$this->loaded())
			$this->where('id', '=', $id)->find();

		// Invalid user id
		if (!$this->loaded())
			return FALSE;

		// Invalid confirmation token
		if ($token !== Auth::instance()->hash($this->email))
			return FALSE;

		// If user is not already confirmed, add user role
		if (!$this->has('roles', ORM::factory('role', array('name' => 'user'))))
		{
			$this->add('roles', ORM::factory('role', array('name' => 'user')));
		}

		return TRUE;
	}

	/**
	 * Sends an email contains a link to new password form.
	 * A timestamp and validation code is added to the link to validate
	 *
	 * @param  array    posted data
	 * @param  string   directory of the current theme
	 * @throws ORM_Validation_Exception
	 * @return boolean
	 */
	public function reset_password(array &$data, $theme_dir)
	{
		$email_rules = Validation::factory($data)
			->rule('email', 'email')
			->rule('email', array($this, 'is_email_registered'));

		if (!$email_rules->check())
		{
			$exception = new ORM_Validation_Exception('user', $email_rules);
			throw $exception;
		}

		// Load user data
		$this->where('email', '=', $data['email'])->find();

		$time = time();
		$link = URL::site(Route::get('user_ops')->uri(array('action' => 'confirm_forgot_password'))
				. '?id=' . $this->id . '&auth_token='
				. Auth::instance()->hash(sprintf('%s_%s_%d', $this->email, $this->password, $time))
				. '&time=' . $time
			, 'http');
			
		$body = View::factory($theme_dir . 'email/confirm_reset_password', $this->as_array())
			->set('time', $time)
			->set('url', $link);


		// Get the email configuration into array
		$email_config = Kohana::config('email');

		// Load Swift Mailer required files
		require_once Kohana::find_file('vendor', 'swiftmailer/lib/swift_required');

		// Create an email message to reset user's password
		$message = Swift_Message::newInstance()
			->setSubject(Kohana::config('config.website_name') . __(' - Reset Password'))
			->setFrom(array(Kohana::config('config.email') => Kohana::config('config.website_name') . __(' Website')))
			->setTo(array($this->email => $this->username))
			->setBody($body);
			
		// Connect to the server
		$transport = Swift_SmtpTransport::newInstance($email_config->server, $email_config->port, $email_config->security)
			->setUsername($email_config->username)
			->setPassword($email_config->password);

		// Try to send the email
		try {
			Swift_Mailer::newInstance($transport)->send($message);
		}
		catch (Exception $ex) {
			Kohana_Log::instance()->add(Kohana_Log::ERROR, 'User reset password email send error, msg: '. $ex->getMessage());
		}

		return TRUE;
	}

	/**
	 * Validates the confirmation link for a password reset.
	 *
	 * @param  integer  user id
	 * @param  string   confirmation token
	 * @param  integer  timestamp
	 * @return boolean
	 */
	public function confirm_reset_password_link($id, $auth_token, $time)
	{
		if ($id === 0 || $auth_token === '' || $time === 0)
			return FALSE;

		// Is the confirmation link expired
		if ($time + Kohana::config('config.reset_password_expiration_time') < time())
			return FALSE;

		// Load user by id
		if (!$this->loaded())
			$this->where('id', '=', $id)->find();

		// User does not exist
		if (!$this->loaded())
			return FALSE;

		// Invalid confirmation token
		if ($auth_token !== Auth::instance()->hash(sprintf('%s_%s_%d', $this->email, $this->password, $time)))
			return FALSE;

		return TRUE;
	}

	/**
	 * Confirms data sent by the user to reset his / her password
	 * If the data has been validated, it is saved
	 *
	 * @param  array values
	 * @param  Validation extra validations for user password
	 * @throws ORM_Validation_Exception
	 * @return boolean
	 */
	public function confirm_reset_password_form(array $data, $extra_rules)
	{
		$data = Validation::factory($data);

		$this->password = $data['password'];

		$this->save($extra_rules);

		if (!$this->has('roles', ORM::factory('role', array('name' => 'user'))))
		{
			$this->add('roles', ORM::factory('role', array('name' => 'user')));
		}

		return TRUE;
	}

	/**
	 * Changes a user's password if data is valid.
	 *
	 * @param  array values
	 * @param  Validation extra validations for user password
	 * @throws ORM_Validation_Exception
	 * @return boolean
	 */
	public function change_password(array $data, $extra_rules)
	{
		$extra_rules->rule('old_password', array($this, 'check_password'));
			
		// Save the new password
		$this->password = $data['password'];

		return $this->save($extra_rules);
	}

	/**
	 * Validates user's old password
	 *
	 * @param  string  field name
	 * @return void
	 */
	public function check_password($old_password)
	{
		if ($user = Auth::instance()->get_user())
		{
			if (Auth::instance()->password($user->username) === Auth::instance()->hash($old_password))
				return;
		}

		return FALSE;
	}

	/**
	 * Checks DB if the email is registered or not
	 *
	 * @param  string  email
	 * @return void
	 */
	public function is_email_registered($email)
	{
		return $this->unique_key_exists($email, 'email');
	}

	/**
	 * Returns user's posts to display in user profile page
	 *
	 * @param  int    page size
	 * @param  int    offset
	 * @param  string post type
	 * @param  post   status status of the post
	 * @return array  Model_Post objects
	 */
	public function get_user_posts($page_size, $offset, $post_type = Helper_PostType::QUESTION, $post_status = Helper_PostStatus::ALL)
	{
		return $this->posts->where('post_moderation', '!=', Helper_PostModeration::IN_REVIEW)
			->and_where('post_type', '=', $post_type)
			->order_by('latest_activity', 'desc')
			->limit($page_size)
			->offset($offset)
			->find_all();
	}

	/**
	 * Returns user's total count of the 'valid' post
	 *
	 * @param  string post type
	 * @param  string status of the posts that will be count
	 * @return int
	 */
	public function count_user_posts($post_type, $status = Helper_PostStatus::ALL)
	{
		switch ($status)
		{
			case Helper_PostStatus::ANSWERED:
				$count = $this->posts->where('post_moderation', '!=', Helper_PostModeration::DELETED)
					->and_where('post_moderation', '!=', Helper_PostModeration::IN_REVIEW)
					->and_where('post_status', '=', Helper_PostStatus::ANSWERED)
					->and_where('post_type', '=', $post_type)
					->count_all();
				break;
			default:
				$count = $this->posts->where('post_moderation', '!=', Helper_PostModeration::DELETED)
					->and_where('post_moderation', '!=', Helper_PostModeration::IN_REVIEW)
					->and_where('post_type', '=', $post_type)
					->count_all();
				break;
		}

		return $count;
	}

	/**
	 * Returns user's post by id
	 *
	 * @param  int               post id
	 * @param  string            post type, default is 'question'
	 * @throws Kohana_Exception
	 * @return object            instance of Model_Post
	 */
	public function get_post_by_id($id, $post_type = Helper_PostType::QUESTION)
	{
		$post = $this->posts->where('id', '=', $id)
			->and_where('post_moderation', '!=', Helper_PostModeration::DELETED)
			->and_where('post_type','=' , $post_type)->find();
			
		if (!$post->loaded())
			throw new Kohana_Exception(sprintf('Get_User_Post::Could not fetch the post by ID: %d for user ID: %d'
				, $id, $this->id));

		return $post;
	}

	/**
	 * Updates user's reputation point according to reputation type and subtract
	 *
	 * @param  string reputation type
	 * @param  bool   reputation will be added or subtracted
	 */
	public function update_reputation($reputation_type, $subtract)
	{
		// Calculate user's last reputation value
		$reputation_value = (int) Model_Setting::instance()->get($reputation_type);

		if ($subtract)
			$reputation_value *= -1;

		$this->reputation += $reputation_value;
			
		$this->update_user_info(array('latest_activity'));
	}

	/**
	 * Updates and saves a users field
	 *
	 * @param array columns
	 */
	public function update_user_info($columns)
	{
		foreach($columns as $col)
		{
			switch($col)
			{
				case 'latest_activity':
					$this->latest_activity = time();
					break;
			}
		}

		if (!$this->save())
		{
			Kohana_Log::instance()->add(Kohana_Log::ERROR
				, 'Model_User::update_user_info(): Could not update current user. ID: ' . $this->id);
		}
	}

	/***** STATIC METHODS *****/

	/**
	 * Returns user by username
	 *
	 * @param $username The username of the user
	 */
	public static function get_user_by_username($username)
	{
		$user = ORM::factory('user')->where('username', '=', $username)->find();

		return ($user->loaded() === TRUE) ? $user : NULL;
	}
}