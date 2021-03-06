<?hh // strict

class IndexAjaxController extends AjaxController {
  <<__Override>>
  protected function getFilters(): array<string, mixed> {
    return array(
      'POST' => array(
        'team_id' => FILTER_VALIDATE_INT,
        'teamname' => FILTER_UNSAFE_RAW,
        'password' => FILTER_UNSAFE_RAW,
        'logo' => array(
          'filter' => FILTER_VALIDATE_REGEXP,
          'options' => array('regexp' => '/^[\w+-\/]+={0,2}$/'),
        ),
        'isCustomLogo' => FILTER_VALIDATE_BOOLEAN,
        'logoType' => FILTER_UNSAFE_RAW,
        'token' => array(
          'filter' => FILTER_VALIDATE_REGEXP,
          'options' => array('regexp' => '/^[\w]+$/'),
        ),
        'names' => FILTER_UNSAFE_RAW,
        'emails' => FILTER_UNSAFE_RAW,
        'action' => array(
          'filter' => FILTER_VALIDATE_REGEXP,
          'options' => array('regexp' => '/^[\w-]+$/'),
        ),
      ),
    );
  }

  <<__Override>>
  protected function getActions(): array<string> {
    return array('register_team', 'register_names', 'login_team');
  }

  <<__Override>>
  protected async function genHandleAction(
    string $action,
    array<string, mixed> $params,
  ): Awaitable<string> {
    switch ($action) {
      case 'none':
        return Utils::error_response('Invalid action', 'index');
      case 'register_team':
        return await $this->genRegisterTeam(
          must_have_string($params, 'teamname'),
          must_have_string($params, 'password'),
          strval(must_have_idx($params, 'token')),
          must_have_string($params, 'logo'),
          must_have_bool($params, 'isCustomLogo'),
          strval(must_have_idx($params, 'logoType')),
          false,
          array(),
          array(),
        );
      case 'register_names':
        $names = json_decode(must_have_string($params, 'names'));
        $emails = json_decode(must_have_string($params, 'emails'));
        invariant(
          is_array($names) && is_array($emails),
          'names and emails should be arrays',
        );

        return await $this->genRegisterTeam(
          must_have_string($params, 'teamname'),
          must_have_string($params, 'password'),
          strval(must_have_idx($params, 'token')),
          must_have_string($params, 'logo'),
          must_have_bool($params, 'isCustomLogo'),
          strval(must_have_idx($params, 'logoType')),
          true,
          $names,
          $emails,
        );
      case 'login_team':
        $team_id = null;
        $login_select = await Configuration::gen('login_select');
        if ($login_select->getValue() === '1') {
          $team_id = must_have_int($params, 'team_id');
        } else {
          $team_name = must_have_string($params, 'teamname');
          $team_exists = await Team::genTeamExist($team_name);
          if ($team_exists) {
            $team = await Team::genTeamByName($team_name);
            $team_id = $team->getId();
          } else {
            return Utils::error_response('Login failed', 'login');
          }
        }
        invariant(is_int($team_id), 'team_id should be an int');

        $password = must_have_string($params, 'password');

        // If we are here, login!
        return await $this->genLoginTeam($team_id, $password);
      default:
        return Utils::error_response('Invalid action', 'index');
    }
  }

  private async function genRegisterTeam(
    string $teamname,
    string $password,
    ?string $token,
    string $logo,
    bool $is_custom_logo,
    ?string $logo_type,
    bool $register_names,
    array<string> $names,
    array<string> $emails,
  ): Awaitable<string> {
    $ldap_password = $password;

    // Check if registration is enabled
    $registration = await Configuration::gen('registration');
    if ($registration->getValue() === '0') {
      return Utils::error_response('Registration failed', 'registration');
    }

    // Check if strongs passwords are enforced
    $login_strongpasswords = await Configuration::gen('login_strongpasswords');
    if ($login_strongpasswords->getValue() !== '0') {
      $password_type = await Configuration::genCurrentPasswordType();
      if (!preg_match(strval($password_type->getValue()), $password)) {
        return Utils::error_response('Password too simple', 'registration');
      }
    }

    // Check if ldap is enabled and verify credentials if successful
    $ldap = await Configuration::gen('ldap');
    $ldap_password = '';
    if ($ldap->getValue() === '1') {
      // Get server information from configuration
      $ldap_server = await Configuration::gen('ldap_server');
      $ldap_port = await Configuration::gen('ldap_port');
      $ldap_domain_suffix = await Configuration::gen('ldap_domain_suffix');
      // connect to ldap server
      $ldapconn = ldap_connect(
        $ldap_server->getValue(),
        intval($ldap_port->getValue()),
      );
      if (!$ldapconn)
        return Utils::error_response(
          'Could not connect to LDAP server',
          'registration',
        );
      $teamname = trim($teamname);
      $bind = ldap_bind(
        $ldapconn,
        $teamname.$ldap_domain_suffix->getValue(),
        $password,
      );
      if (!$bind)
        return
          Utils::error_response('LDAP Credentials Error', 'registration');
      // Use randomly generated password for local account for LDAP users
      // This will help avoid leaking users ldap passwords if the server's database
      // is compromised.
      $ldap_password = $password;
      $password = strval(gmp_strval(
        gmp_init(bin2hex(openssl_random_pseudo_bytes(16)), 16),
        62,
      ));
    }

    // Check if tokenized registration is enabled
    $registration_type = await Configuration::gen('registration_type');
    if ($registration_type->getValue() === '2') {
      $token_check = await Token::genCheck((string) $token);
      // Check provided token
      if ($token === null || !$token_check) {
        return Utils::error_response('Registration failed', 'registration');
      }
    }

    // Check logo
    $logo_name = $logo;

    if ($is_custom_logo) {
      $custom_logo = await Logo::genCreateCustom($logo);
      if ($custom_logo) {
        $logo_name = $custom_logo->getName();
      } else {
        return Utils::error_response('Registration failed', 'registration');
      }
    }

    $check_exists = await Logo::genCheckExists($logo_name);
    if (!$check_exists) {
      $logo_name = await Logo::genRandomLogo();
    }

    // Check if team name is not empty or just spaces
    if (trim($teamname) === '') {
      return Utils::error_response('Registration failed', 'registration');
    }

    // Trim team name to 20 chars, to avoid breaking UI
    $shortname = substr($teamname, 0, 20);

    // Verify that this team name is not created yet
    $team_exists = await Team::genTeamExist($shortname);
    if (!$team_exists) {
      invariant(is_string($password), "Expected password to be a string");
      $password_hash = Team::generateHash($password);
      $team_id =
        await Team::genCreate($shortname, $password_hash, $logo_name);
      if ($team_id) {
        // Store team players data, if enabled
        if ($register_names) {
          for ($i = 0; $i < count($names); $i++) {
            await Team::genAddTeamData($names[$i], $emails[$i], $team_id);
          }
        }
        // If registration is tokenized, use the token
        if ($registration_type->getValue() === '2') {
          invariant($token !== null, 'token should not be null');
          await Token::genUse($token, $team_id);
        }
        // Login the team
        if ($ldap->getValue() === '1') {
          return await $this->genLoginTeam($team_id, $ldap_password);
        } else {
          return await $this->genLoginTeam($team_id, $password);
        }
      } else {
        return Utils::error_response('Registration failed', 'registration');
      }
    } else {
      return Utils::error_response('Registration failed', 'registration');
    }
  }

  private async function genLoginTeam(
    int $team_id,
    string $password,
  ): Awaitable<string> {
    // Verify credentials first so we can allow admins to login regardless of the login setting
    $team = await Team::genVerifyCredentials($team_id, $password);

    // Check if login is disabled and this isn't an admin
    $login = await Configuration::gen('login');
    if (($login->getValue() === '0') &&
        ($team === null || $team->getAdmin() === false)) {
      return Utils::error_response('Login failed', 'login');
    }

    // Otherwise let's login any valid attempt
    if ($team) {
      SessionUtils::sessionRefresh();
      if (!SessionUtils::sessionActive()) {
        SessionUtils::sessionSet('team_id', strval($team->getId()));
        SessionUtils::sessionSet('name', $team->getName());
        SessionUtils::sessionSet(
          'csrf_token',
          (string) gmp_strval(
            gmp_init(bin2hex(openssl_random_pseudo_bytes(16)), 16),
            62,
          ),
        );
        SessionUtils::sessionSet(
          'IP',
          must_have_string(Utils::getSERVER(), 'REMOTE_ADDR'),
        );
        if ($team->getAdmin()) {
          SessionUtils::sessionSet('admin', strval($team->getAdmin()));
        }
      }
      if ($team->getAdmin()) {
        $redirect = 'admin';
      } else {
        $redirect = 'game';
      }
      return Utils::ok_response('Login succesful', $redirect);
    } else {
      return Utils::error_response('Login failed', 'login');
    }
  }
}
