<?php

class auth extends Controller
{

  /*
  ** This function will authenticate a user , with use of githubs oauth
  ** i do the oauth flow myself, therefore there will be many lines of code,
  ** prepare yourself LOLOLOL
  */
  public function reset($params = [])
  {

    /*
    ** Check if the user is trying make this request while logged on
    ** if so redirect, change password is the functionality he should 
    ** rather use
    */

    if ($this->valid())
    {
      $this->flash_message(
        'Oops, you cannot reset a logged on account!',
        'warning',
        SITE_URL
      );
    }

    /*
    ** If there are two params we expect them to be uid and token
    ** if so verify and update the user token so that a new view is rendered
    ** and the token has expired
    */

    if (sizeof($params) == 2)
    {
      $uid = isset($params[0]) ? explode('=', trim($params[0]))[1] : NULL;
      $token = isset($params[1]) ? explode('=', trim($params[1]))[1] : NULL;

      if (isset($uid) && isset($token))
      {
        /*
        ** Verify that this is a valid token and uid, render view for new password
        */

        if ($this->model('user_signin')->reset_token(
          base64_decode($uid), 
          $token, 
          false) === true)
        {
          //for some reason model always returns true
          $this->view('auth/reset', ['resp' => 'ok']);
        }
      }
      else
      {
        $this->flash_message(
          'Oops, You have no business here!',
          'warning',
          SITE_URL . '/auth/reset'
        );
      }
    }

    /*
    ** If there is a post with the new passwords, validate the length
    ** validate that this user is allowed to make this request and update
    ** the database
    */

    if (filter_has_var(INPUT_POST, 'password1') && 
        filter_has_var(INPUT_POST, 'password2'))
    {

      $password1 = filter_input(INPUT_POST, 'password1', FILTER_SANITIZE_STRING);
      $password2 = filter_input(INPUT_POST, 'password2', FILTER_SANITIZE_STRING);
      
      /*
      ** Check that passwords match, and are longer than 8 characters
      */

      if ($password1 === $password2)
      {
        $uid = isset($params[0]) ? explode('=', trim($params[0]))[1] : NULL;

        if (strlen($password1) > 7)
        {

          /*
          ** Update password in db
          */

          if ($this->model('user_signin')->reset_password(base64_decode($uid), $password1) === true)
          {
            echo json_encode(['success' => 'Password successfully reset!']);
          }
          else
          {
            echo json_encode(['error' => 'Error updating password']);
          }
        }
        else
        {
          echo json_encode(['error' => 'Password length must be atleast 8 ']);
        }
      }
      else
      {
        echo json_encode(['error' => 'Passwords do not match!']);
      }
      exit();
    }

    /*
    ** If there is a post request from ajax, check if its a valid eail
    ** and is not an oauth account, send an email with a reset token
    ** if there is no post just render the view for reset
    */

    if (filter_has_var(INPUT_POST, 'email'))
    {
      $verify = $this->model('user_signin')->validate_account(
        filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL)
      );

      if ($verify)
      {

        $token = hash('whirlpool', rand(0, 100));
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $uid = base64_encode($email);

        /*
        ** Send email with uid and reset token
        */

        if ($this->model('user_signin')->reset_token(base64_decode($uid), $token))
        {

          /*
          ** reset token
          */

          $url = SITE_URL . '/auth/reset/uid=' .  $uid . '/token=' . $token;

          /*
          ** Mail vars
          */

          $params = [
            'email' => $email,
            'link' => $url
          ];

          /*
          ** Send mailer
          */

          if ($this->helper('Mailer')->reset_mail($params)) {
            echo json_encode([
              'success' => 'Success, please check your email!'
            ]);
          }
          else {
            echo json_encode([
              'error' => 'Sorry, our mailer failed to send email'
            ]);
          }
        }
        else
        {
          echo json_encode([
            'error' => 'Sorry, an error has occurred!'
          ]);
        }
      }
      else
      {
        echo json_encode([
          'error' => 'This is not a valid account'
        ]);
      }
    }
    else
    {
      $this->view('auth/reset', $params);
    }
  }

  /*
  ** login is done asynchronously, so after authentication a json response
  ** is returned for the user
  */

  public function login($params = [])
  {
    /*
    ** Users only allowed here if they are not logged on
    */

    if ($this->valid())
    {
      $this->flash_message(
        'Oops, you are already signed in!',
        'warning',
        SITE_URL
      );
    }

    if (filter_has_var(INPUT_POST, 'email')
     && filter_has_var(INPUT_POST, 'password'))
    {
      $auth = $this->model('user_signin')->authenticate(
        trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL)),
        trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING))
      );

      if ($auth === false)
      {

        /*
        ** The user information is invalid
        */

        echo json_encode(['error' => 'Invalid username']);
      }
      else
      {

        /*
        ** the user information is valid, we create the users session var
        ** and redirect home
        */

        $_SESSION['user'] = $auth;
        if (isset($_SESSION['user']))
        {
          echo json_encode([
            'success' => 'Authentification Success!',
            'username' => $_SESSION['user']['username']
          ]);
        }
      }
    }
    else
      $this->view('auth/signin', $params);
  }

  /*
  ** This function will log a user out.
  */

  public function logout($params = [])
  {
  
    if ($this->valid()) {
      
      /*
      ** Remove the user session by replacing data
      */

      $_SESSION = [];

      $this->flash_message(
        'Yayy, successfully logged out!',
        'success',
        SITE_URL . '/home'
      );
    }
    $this->redirect();
  }

  /*
  ** The verification email sent will be dealt with here
  ** This funcion will be given an array of params containing
  ** the uid and verification
  */

  public function verify($params = [])
  {
    if ($this->valid())
    {
      $this->flash_message(
        'This account is already verified',
        'warning',
        SITE_URL
      );
    }

    /*
    ** clean up the params so uid and ver have hash values only, this is done by
    ** exploding the strs by delim = because the format of the params is uid=???
    ** verification=??? (thats why after the explode i assign the 2nd element to
    ** uid and ver respectively).
    */

    $uid = isset($params[0]) ? explode('=', trim($params[0]))[1] : NULL;
    $ver = isset($params[1]) ? explode('=', trim($params[1]))[1] : NULL;

    if (isset($uid) && isset($ver))
    {
      if (($result = $this->model('user_signup')
      ->check_verify(base64_decode($uid), $ver)) !== false)
      {
        if (($this->model('user_signup')
        ->create_perm_account(
          $result['email'],
          $result['username'],
          $result['password'])) == false)
        {

          /*
          ** For some reason the account wasnt able to be created, redierect the
          ** user home. If any arg is null it will fail, if an pdo excepion is
          ** thrown etc.
          */

          $this->redirect();
        }
        else
        {

          /*
          ** By this point the user has successdfully completed registration.
          ** render the home page wih info about how registration went. Then unset
          ** it.
          */

          $this->flash_message(
            'Yayyy, account verification successful! log in.',
            'success',
            SITE_URL . '/home'
          );
        }
      }
      else
      {
        $this->flash_message(
          'Oops, invalid or expired token! Create an account
            <a href="' . SITE_URL . '/auth/signup' . '">here</a>',
          'warning',
          SITE_URL . '/home'
        );
      }
    }
    else
    {
      $this->flash_message(
        'Oops, you have no business being here!',
        'danger',
        SITE_URL . '/home'
      );
    }
  }

  /*
  ** This function will either render a view or it will register a user
  ** from the ajax request
  */

  public function signup($params = [])
  {

    /*
    ** If a user is logged in he shouldnt be allowed to create a account
    ** Redirect home if logged in
    */

    if ($this->valid())
    {
      $this->flash_message(
        "ops, you're already signed in!",
        'warning',
        SITE_URL
      );
    }

    if (filter_has_var(INPUT_POST, 'email')
     && filter_has_var(INPUT_POST, 'username')
     && filter_has_var(INPUT_POST, 'password'))
    {
      /*
      ** Validate email, username, password
      */

      $validator = $this->helper('Validate');

      $result = $validator->holy_trinity([
        'email' => trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL)),
        'username' => trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING)),
        'password' => trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING))
      ]);

      if ($result) {
        $this->register_user(
          trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL)),
          trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING)),
          trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING))
        );
      }
      else {
        echo json_encode([
          'error' => 'Failed to create account'
        ]);
      }
    }
    else
      $this->view('auth/signup', $params);
  }

  /*
  ** This index function is for when users try to access urls that dont
  ** exists. this is temporar until i create my own 404 page
  */


  public function index($params = [])
  {
    $this->flash_message(
      '404 page not found',
      'danger',
      SITE_URL . '/auth/signup');
  }

  /*
  ** This function will generate a random hash to be used as scope for oauth
  ** to avoid forgery attacks by passing in a value that's unique to the user
  ** currently authenticating and checking it when oauth completes
  */

  private function get_state($randomm_int)
  {
    $arr = str_split(base64_encode($randomm_int));

    foreach ($arr as $key) {
      $final .= md5($key);
    }
    return hash('whirlpool', $final);
  }

  /*
  ** This function will check the db to see if the email or username is taken
  ** if they are available, the users will be temporarily added as a user until
  ** email validation.
  */

  private function register_user($email, $username, $password)
  {

    /*
    ** function validate_details will return an array which will dictate below
    ** whether to create a new account or not. regardless the response will
    ** be returned so the clien can be updated with how the registration
    ** went
    */

    $response = $this->model('user_signup')->validate_details($email, $username);

    if ($response['username'] === 'OK' && $response['email'] === 'OK')
    {
      /*
      ** By this point its confirmed that the user can create an account
      ** since the username and email are available, we will tempoaraily add them
      ** and once they verify via email their account will be active.
      ** create_temp_account will return false if the a pdo exception is thrown
      */

      $verification = hash('whirlpool', mt_rand(50, 100));
      $link = SITE_URL . '/auth/verify/uid=' . base64_encode($username) . '/code=' . $verification;

      /*
      ** Add to unverified tbl and send mailer if successfully inserted
      */

      $result = $this->model('user_signup')
      ->create_temp_account($email, $username, $password, $verification);

      if ($result)
      {

        /*
        ** Variables needed for the mailer
        */

        $params  = [
          'username' => $username,
          'link' => $link,
          'to' => $email
        ];

        if ($this->helper('Mailer')->verify_mail($params)) {
          $response['status'] = 200;
        }
        else {
          $response['error'] = 'Failed to create account';
          //$response['link'] = $link; //for debugging
        }
      }
      else
      {
        $response['error'] = 'Failed to create account';
      }
    }
    echo json_encode ($response);
  }
}