<?php
require_once 'Flux/BaseServer.php';
require_once 'Flux/RegisterError.php';

/**
 * Represents an eAthena Login Server.
 */
class Flux_LoginServer extends Flux_BaseServer {
	/**
	 * Connection to the MySQL server.
	 *
	 * @access public
	 * @var Flux_Connection
	 */
	public $connection;
	
	/**
	 * Login server database.
	 *
	 * @access public
	 * @var string
	 */
	public $loginDatabase;
	
	/**
	 * Overridden to add custom properties.
	 *
	 * @access public
	 */
	public function __construct(Flux_Config $config)
	{
		parent::__construct($config);
		$this->loginDatabase = $config->getDatabase();
	}
	
	/**
	 * Set the connection object to be used for this LoginServer instance.
	 *
	 * @param Flux_Connection $connection
	 * @return Flux_Connection
	 * @access public
	 */
	public function setConnection(Flux_Connection $connection)
	{
		$this->connection = $connection;
		return $connection;
	}
	
	/**
	 * Validate credentials against the login server's database information.
	 *
	 * @param string $username Ragnarok account username.
	 * @param string $password Ragnarok account password.
	 * @return bool True/false if valid or invalid.
	 * @access public
	 */
	public function isAuth($username, $password)
	{
		if ($this->config->get('UseMD5')) {
			$password = md5($password);
		}
		
		if (trim($username) == '' || trim($password) == '') {
			return false;
		}
		
		$sql = "SELECT userid FROM {$this->loginDatabase}.login WHERE sex != 'S' AND level >= 0 AND userid = ? AND user_pass = ? LIMIT 1";
		$sth = $this->connection->getStatement($sql);
		$sth->execute(array($username, $password));
		
		$res = $sth->fetch();
		if ($res) {
			return true;
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function register($username, $password, $confirmPassword, $email, $gender, $securityCode)
	{
		if (strlen($username) < Flux::config('MinUsernameLength')) {
			throw new Flux_RegisterError('Username is too short', Flux_RegisterError::USERNAME_TOO_SHORT);
		}
		elseif (strlen($username) > Flux::config('MaxUsernameLength')) {
			throw new Flux_RegisterError('Username is too long', Flux_RegisterError::USERNAME_TOO_LONG);
		}
		elseif (strlen($password) < Flux::config('MinPasswordLength')) {
			throw new Flux_RegisterError('Password is too short', Flux_RegisterError::PASSWORD_TOO_SHORT);
		}
		elseif (strlen($password) > Flux::config('MaxPasswordLength')) {
			throw new Flux_RegisterError('Password is too long', Flux_RegisterError::PASSWORD_TOO_LONG);
		}
		elseif ($password !== $confirmPassword) {
			throw new Flux_RegisterError('Passwords do not match', Flux_RegisterError::PASSWORD_MISMATCH);
		}
		elseif (!preg_match('/(.+?)@(.+?)/', $email)) {
			throw new Flux_RegisterError('Invalid e-mail address', Flux_RegisterError::INVALID_EMAIL_ADDRESS);
		}
		elseif (!in_array(strtoupper($gender), array('M', 'F'))) {
			throw new Flux_RegisterError('Invalid gender', Flux_RegisterError::INVALID_GENDER);
		}
		elseif (Flux::config('UseCaptcha') && $securityCode !== Flux::$sessionData->securityCode) {
			throw new Flux_RegisterError('Invalid security code', Flux_RegisterError::INVALID_SECURITY_CODE);
		}
		
		$sql = "SELECT userid FROM {$this->loginDatabase}.login WHERE userid = ? LIMIT 1";
		$sth = $this->connection->getStatement($sql);
		$sth->execute(array($username));
		
		$res = $sth->fetch();
		if ($res) {
			throw new Flux_RegisterError('Username is already taken', Flux_RegisterError::USERNAME_ALREADY_TAKEN);
		}
		
		if (!Flux::config('AllowDuplicateEmails')) {
			$sql = "SELECT email FROM {$this->loginDatabase}.login WHERE email = ? LIMIT 1";
			$sth = $this->connection->getStatement($sql);
			$sth->execute(array($email));

			$res = $sth->fetch();
			if ($res) {
				throw new Flux_RegisterError('E-mail address is already in use', Flux_RegisterError::EMAIL_ADDRESS_IN_USE);
			}
		}
		
		if ($this->config->getUseMD5()) {
			$password = md5($password);
		}
		
		$sql = "INSERT INTO {$this->loginDatabase}.login (userid, user_pass, email, sex) VALUES (?, ?, ?, ?)";
		$sth = $this->connection->getStatement($sql);
		$res = $sth->execute(array($username, $password, $email, $gender));
		
		if ($res) {
			$idsth = $this->connection->getStatement("SELECT LAST_INSERT_ID() AS account_id");
			$idsth->execute();
			
			$idres = $idsth->fetch();
			$createTable = Flux::config('FluxTables.AccountCreateTable');
			
			$sql  = "INSERT INTO {$this->loginDatabase}.{$createTable} (account_id, userid, user_pass, sex, email, reg_date, reg_ip) ";
			$sql .= "VALUES (?, ?, ?, ?, ?, NOW(), ?)";
			$sth  = $this->connection->getStatement($sql);
			
			return (bool)$sth->execute(array($idres->account_id, $username, $password, $gender, $email, $_SERVER['REMOTE_ADDR']));
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function temporarilyBan($bannedBy, $banReason, $accountID, $until)
	{
		$info  = $this->getBanInfo($accountID);
		$table = Flux::config('FluxTables.AccountBanTable');
		
		if (!$info || $info->ban_type !== '1') {
			$sql  = "INSERT INTO {$this->loginDatabase}.$table (account_id, banned_by, ban_type, ban_until, ban_date, ban_reason) ";
			$sql .= "VALUES (?, ?, 1, ?, NOW(), ?)";
			$sth  = $this->connection->getStatement($sql);
			$res  = $sth->execute(array($accountID, $bannedBy, $until, $banReason));
			
			$ts   = strtotime($until);
			$sql  = "UPDATE {$this->loginDatabase}.login SET state = 0, unban_time = '$ts' WHERE account_id = ?";
			$sth  = $this->connection->getStatement($sql);
			return $sth->execute(array($accountID));
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function permanentlyBan($bannedBy, $banReason, $accountID)
	{
		$info  = $this->getBanInfo($accountID);
		$table = Flux::config('FluxTables.AccountBanTable');
		
		if (!$info || $info->ban_type !== '2') {
			$sql  = "INSERT INTO {$this->loginDatabase}.$table (account_id, banned_by, ban_type, ban_until, ban_date, ban_reason) ";
			$sql .= "VALUES (?, ?, 2, '0000-00-00 00:00:00', NOW(), ?)";
			$sth  = $this->connection->getStatement($sql);
			$res  = $sth->execute(array($accountID, $bannedBy, $banReason));
			
			if ($res) {
				$sql  = "UPDATE {$this->loginDatabase}.login SET state = 5, unban_time = 0 WHERE account_id = ?";
				$sth  = $this->connection->getStatement($sql);
				return $sth->execute(array($accountID));
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function unban($unbannedBy, $unbanReason, $accountID)
	{
		$info = $this->getBanInfo($accountID);
		$table = Flux::config('FluxTables.AccountBanTable');
		
		if (!$info || !$info->ban_type) {
			$sql  = "INSERT INTO {$this->loginDatabase}.$table (account_id, banned_by, ban_type, ban_until, ban_date, ban_reason) ";
			$sql .= "VALUES (?, ?, 0, '0000-00-00 00:00:00', NOW(), ?)";
			$sth  = $this->connection->getStatement($sql);
			$res  = $sth->execute(array($accountID, $unbannedBy, $unbanReason));
			
			if ($res) {
				$sql  = "UPDATE {$this->loginDatabase}.login SET state = 0, unban_time = 0 WHERE account_id = ?";
				$sth  = $this->connection->getStatement($sql);
				return $sth->execute(array($accountID));
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function getBanInfo($accountID)
	{
		$table = Flux::config('FluxTables.AccountBanTable');
		$col   = "$table.id, $table.account_id, $table.banned_by, $table.ban_type, ";
		$col  .= "$table.ban_until, $table.ban_date, $table.ban_reason, login.userid";
		$sql   = "SELECT $col FROM {$this->loginDatabase}.$table ";
		$sql  .= "LEFT OUTER JOIN {$this->loginDatabase}.login ON login.account_id = $table.banned_by ";
		$sql  .= "WHERE $table.account_id = ? ORDER BY $table.ban_date DESC ";
		$sth   = $this->connection->getStatement($sql);
		$res   = $sth->execute(array($accountID));
		
		if ($res) {
			$ban = $sth->fetchAll();
			return $ban;
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function hasCreditsRecord($accountID)
	{
		$creditsTable = Flux::config('FluxTables.CreditsTable');
		
		$sql = "SELECT COUNT(account_id) AS hasRecord FROM {$this->loginDatabase}.$creditsTable WHERE account_id = ?";
		$sth = $this->connection->getStatement($sql);
		
		$sth->execute(array($accountID));
		
		if ($sth->fetch()->hasRecord) {
			return true;
		}
		else {
			return false;
		}
	}
	
	/**
	 *
	 */
	public function depositCredits($targetAccountID, $credits, $donationAmount = null)
	{
		$sql = "SELECT COUNT(account_id) AS accountExists FROM {$this->loginDatabase}.login WHERE account_id = ?";
		$sth = $this->connection->getStatement($sql);
		
		if (!$sth->execute(array($targetAccountID)) || !$sth->fetch()->accountExists) {
			return false; // Account doesn't exist.
		}
		
		$creditsTable = Flux::config('FluxTables.CreditsTable');
		
		if (!$this->hasCreditsRecord($targetAccountID)) {
			$fields = 'account_id, balance';
			$values = '?, ?';
			
			if (!is_null($donationAmount)) {
				$fields .= ', last_donation_date, last_donation_amount';
				$values .= ', NOW(), ?';
			}
			
			$sql  = "INSERT INTO {$this->loginDatabase}.$creditsTable ($fields) VALUES ($values)";
			$sth  = $this->connection->getStatement($sql);
			$vals = array($targetAccountID, $credits);
			
			if (!is_null($donationAmount)) {
				$vals[] = $donationAmount;
			}
			
			return $sth->execute($vals);
		}
		else {
			$vals = array();
			$sql  = "UPDATE {$this->loginDatabase}.$creditsTable SET balance = balance + ? ";

			if (!is_null($donationAmount)) {
				$sql .= ", last_donation_date = NOW(), last_donation_amount = ? ";
			}
			
			$vals[] = $credits;
			if (!is_null($donationAmount)) {
				$vals[] = $donationAmount;
			}
			$vals[] = $targetAccountID;
			
			$sql .= "WHERE account_id = ?";
			$sth  = $this->connection->getStatement($sql);
			
			return $sth->execute($vals);
		}
	}
	
	/**
	 *
	 */
	public function transferCredits($fromAccountID, $targetAccountID, $credits)
	{
		//
		// Return values:
		// -1 = From or to account, one or the other does not exist. (likely the latter.)
		// -2 = Sender has an insufficient balance.
		// true = Successful transfer
		// false = Error
		//
		
		$sql  = "SELECT COUNT(account_id) AS accounts FROM {$this->loginDatabase}.login WHERE ";
		$sql .= "account_id = ? OR account_id = ? LIMIT 2";
		$sth  = $this->connection->getStatement($sql);
		
		if (!$sth->execute(array($fromAccountID, $targetAccountID)) || $sth->fetch()->accounts != 2) {
			// One or the other, from or to, are non-existent accounts.
			return -1;
		}
		
		if (!$this->hasCreditsRecord($fromAccountID)) {
			// Sender has a zero balance.
			return -2;
		}
		
		$creditsTable = Flux::config('FluxTables.CreditsTable');
		$xferTable    = Flux::config('FluxTables.CreditTransferTable');
		
		// Get balance of sender.
		$sql = "SELECT balance FROM {$this->loginDatabase}.$creditsTable WHERE account_id = ? LIMIT 1";
		$sth = $this->connection->getStatement($sql);
		
		if (!$sth->execute(array($fromAccountID))) {
			// Error.
			return false;
		}
		
		if ($sth->fetch()->balance < $credits) {
			// Insufficient balance.
			return -2;
		}
		
		// Take credits from fromAccount first.
		if ($this->depositCredits($fromAccountID, -$credits)) {
			// Then deposit to targetAccount next.
			if (!$this->depositCredits($targetAccountID, $credits)) {
				// Attempt to restore credits if deposit to toAccount failed.
				$this->depositCredits($fromAccountID, $credits);
				return false;
			}
			else {
				$sql  = "INSERT INTO {$this->loginDatabase}.$xferTable ";
				$sql .= "(from_account_id, target_account_id, amount, transfer_date) ";
				$sql .= "VALUES (?, ?, ?, NOW())";
				$sth  = $this->connection->getStatement($sql);
				
				// Log transfer.
				$sth->execute(array($fromAccountID, $targetAccountID, $credits));
				
				return true;
			}
		}
		else {
			return false;
		}
	}
}
?>