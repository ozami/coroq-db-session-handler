<?php
namespace Coroq;
use Coroq\DbSessionHandler\Error;
use Coroq\Db;
use Coroq\Db\Error as DbError;

/**
 * Session handler that saves data into RDBMS via Coroq\Db
 *
 * By design, this session handler does not lock the session storage during read() and write().
 * Therefore concurrent access to the same session ID can cause race condition (the last writer wins).
 * Note that this class throws an exception on error, and session_start() will throw an exception.
 */
class DbSessionHandler implements \SessionHandlerInterface {
  /** @var \Coroq\Db */
  private $db;
  /** @var string */
  private $table_name;
  /** @var callable */
  private $getCurrentTime;
  /** @var float */
  private $per_session_cleanup_rate;
  /** @var ?string */
  private $last_session_data;

  /**
   * Constructor
   *
   * @param \Coroq\Db $db
   * @param string $table_name
   * @param array $options getCurrentTime => callable, per_session_cleanup_rate => float
   */
  public function __construct($db, $table_name, array $options = []) {
    if (preg_match("#[^a-zA-Z0-9_]#", $table_name)) {
      throw new \DomainException("Invalid \$table_name.");
    }
    $options += [
      "getCurrentTime" => "time",
      "per_session_cleanup_rate" => 0.2,
    ];
    $this->db = $db;
    $this->table_name = $table_name;
    $this->getCurrentTime = $options["getCurrentTime"];
    $this->per_session_cleanup_rate = $options["per_session_cleanup_rate"];
    $this->last_session_data = null;
  }

  /**
   * Initialize session
   *
   * Do nothing in this implementation.
   * @param string $save_path not used.
   * @param string $session_name not used.
   * @return true
   */
  public function open($save_path, $session_name) {
    return true;
  }

  /**
   * Read session data
   *
   * @param string $session_id The session ID
   * @return string An encoded string of the read data. If nothing was read, it must return an empty string.
   * @throws Error
   */
  public function read($session_id) {
    try {
      $encoded_session_data = $this->db->select([
        "table" => $this->table_name,
        "where" => [
          "session_id" => $session_id,
        ],
        "order" => "-id",
        "limit" => 1,
        "column" => ["session_data"],
        "fetch" => Db::FETCH_ONE,
      ]);
      if ($encoded_session_data === null) {
        return "";
      }
      $session_data = base64_decode($encoded_session_data, true);
      if ($session_data === false) {
        throw new Error("Session data of $session_id has been corrupted.");
      }
      $this->last_session_data = $session_data;
      return $session_data;
    }
    catch (DbError $error) {
      throw new Error("", 0, $error);
    }
  }

  /**
   * Write session data
   *
   * @param string $session_id
   * @param string $session_data 
   * @return true
   * @throws Error
   */
  public function write($session_id, $session_data) {
    try {
      $now = call_user_func($this->getCurrentTime);
      // lazy write
      if ($session_data === $this->last_session_data) {
        return true;
      }
      $encoded_session_data = base64_encode($session_data);
      // TODO: consider whether rollback is needed within a transaction
      $this->db->insert([
        "table" => $this->table_name,
        "data" => [
          "time_created" => $now,
          "session_id" => $session_id,
          "session_data" => $encoded_session_data,
        ],
      ]);
      $this->last_session_data = $session_data;
      $this->deletePreviousData($now, $session_id);
      return true;
    }
    catch (DbError $error) {
      throw new Error("", 0, $error);
    }
  }

  /**
   * Delete previous data of the specified session
   * @param int $time_created
   * @param string $session_id
   */
  private function deletePreviousData($time_created, $session_id) {
    if ($this->per_session_cleanup_rate < mt_rand(1, 100) / 100) {
      return;
    }
    // ignore errors because cleaning up is not a fatal operation.
    try {
      $this->db->delete([
        "table" => $this->table_name,
        "where" => [
          "time_created:lt" => $time_created,
          "session_id" => $session_id,
        ],
      ]);
    }
    catch (DbError $error) {
      // do nothing
    }
  }

  /**
   * Close the session
   *
   * Do nothing in this implementation.
   * @return true
   */
  public function close() {
    return true;
  }

  /**
   * Destroy a session
   *
   * @param string $session_id
   * @return true
   * @throws Error
   */
  public function destroy($session_id) {
    try {
      $this->db->delete([
        "table" => $this->table_name,
        "where" => [
          "session_id" => $session_id,
        ],
      ]);
      $this->last_session_data = null;
      return true;
    }
    catch (DbError $error) {
      throw new Error("", 0, $error);
    }
  }

  /**
   * Cleanup old sessions
   *
   * @param int $maxlifetime
   * @return true
   * @throws Error
   */
  public function gc($maxlifetime) {
    try {
      $expiration_time = call_user_func($this->getCurrentTime) - $maxlifetime;
      $this->db->delete([
        "table" => $this->table_name,
        "where" => [
          "time_created:le" => $expiration_time,
        ],
      ]);
      return true;
    }
    catch (DbError $error) {
      throw new Error("", 0, $error);
    }
  }
}
