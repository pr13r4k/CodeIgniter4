<?php namespace CodeIgniter\Session\Handlers;

/**
 * CodeIgniter
 *
 * An open source application development framework for PHP
 *
 * This content is released under the MIT License (MIT)
 *
 * Copyright (c) 2014 - 2017, British Columbia Institute of Technology
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package	CodeIgniter
 * @author	CodeIgniter Dev Team
 * @copyright	Copyright (c) 2014 - 2017, British Columbia Institute of Technology (http://bcit.ca/)
 * @license	https://opensource.org/licenses/MIT	MIT License
 * @link	https://codeigniter.com
 * @since	Version 3.0.0
 * @filesource
 */

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Session handler using current Database for storage
 */
class DatabaseHandler extends BaseHandler implements \SessionHandlerInterface
{
    /**
     * The database group to use for storage.
     *
     * @var string
     */
	protected $DBGroup;

    /**
     * The name of the table to store session info.
     *
     * @var string
     */
	protected $table;

    /**
     * The DB Connection instance.
     *
     * @var BaseConnection
     */
	protected $db;

    /**
     * The database type, for locking purposes.
     *
     * @var string
     */
	protected $platform;

    /**
     * Row exists flag
     *
     * @var bool
     */
	protected $rowExists = false;

	//--------------------------------------------------------------------

	/**
	 * Constructor
	 * @param BaseConfig $config
	 */
	public function __construct(BaseConfig $config)
	{
		parent::__construct($config);

        // Determine Table
		$this->table = $config->sessionSavePath;

		if (empty($this->table))
        {
            throw new \BadMethodCallException('`sessionSavePath` must have the table name for the Database Session Handler to work.');
        }

        // Get DB Connection
        $this->DBGroup = ! empty($config->sessionDBGroup)
            ? $config->sessionDBGroup
            : 'default';

        $this->db = Database::connect($this->DBGroup);

        // Determine Database type
        $driver = strtolower(get_class($this->db));
        if (strpos($driver, 'mysql') !== false)
        {
            $this->platform = 'mysql';
        }
        elseif (strpos($driver, 'postgre') !== false)
        {
            $this->platform = 'postgre';
        }
	}

	//--------------------------------------------------------------------

    /**
     * Open
     *
     * Ensures we have an initialized database connection.
     *
     * @param    string $savePath Path to session files' directory
     * @param    string $name     Session cookie name
     *
     * @return bool
     * @throws \Exception
     */
	public function open($savePath, $name): bool
	{
        if (empty($this->db->connID))
        {
            $this->db->initialize();
        }

		return true;
	}

	//--------------------------------------------------------------------

	/**
	 * Read
	 *
	 * Reads session data and acquires a lock
	 *
	 * @param    string $sessionID Session ID
	 *
	 * @return    string    Serialized session data
	 */
	public function read($sessionID)
	{
        if ($this->lockSession($sessionID) == false)
        {
            $this->fingerprint = md5('');
            return '';
        }

        // Needed by write() to detect session_regenerate_id() calls
        $this->sessionID = $sessionID;

        $builder = $this->db->table($this->table)
                        ->select('data')
                        ->where('id', $sessionID);

        if ($this->matchIP)
        {
            $builder = $builder->where('ip_address', $_SERVER['REMOTE_ADDR']);
        }

        if ($result = $builder->get()->getRow() === null)
        {
            // PHP7 will reuse the same SessionHandler object after
            // ID regeneration, so we need to explicitly set this to
            // FALSE instead of relying on the default ...
            $this->rowExists = FALSE;
            $this->fingerprint = md5('');

            return '';
        }

        // PostgreSQL's variant of a BLOB datatype is Bytea, which is a
        // PITA to work with, so we use base64-encoded data in a TEXT
        // field instead.
        if (is_bool($result))
        {
            $result = '';
        }
        else
        {
            $result = ($this->platform === 'postgre')
                ? base64_decode(rtrim($result->data))
                : $result->data;
        }

        $this->fingerprint = md5($result);
        $this->rowExists = true;

        return $result;
	}

	//--------------------------------------------------------------------

	/**
	 * Write
	 *
	 * Writes (create / update) session data
	 *
	 * @param    string $sessionID   Session ID
	 * @param    string $sessionData Serialized session data
	 *
	 * @return    bool
	 */
	public function write($sessionID, $sessionData): bool
	{
        if ($this->lock === false)
        {
            return $this->fail();
        }

        // Was the ID regenerated?
        elseif ($sessionID !== $this->sessionID)
        {
            if (! $this->releaseLock() || ! $this->lockSession($sessionID))
            {
                return $this->fail();
            }

            $this->rowExists = false;
            $this->sessionID = $sessionID;
        }

        if ($this->rowExists === false)
        {
            $insertData = [
                'id' => $sessionID,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'timestamp' => time(),
                'data' => $this->platform === 'postgre'
                    ? base64_encode($sessionData)
                    : $sessionData
            ];

            if (! $this->db->table($this->table)->insert($insertData))
            {
                return $this->fail();
            }

            $this->fingerprint = md5($sessionData);
            $this->rowExists = true;

            return true;
        }

        $builder = $this->db->table($this->table);

        if ($this->matchIP)
        {
            $builder = $builder->where('ip_address', $_SERVER['REMOTE_ADDR']);
        }

        $updateData = [
            'timestamp' => time()
        ];

        if ($this->fingerprint !== md5($sessionData))
        {
            $updateData['data'] = ($this->platform === 'postgre')
                ? base64_encode($sessionData)
                : $sessionData;
        }

        if (! $builder->update($updateData))
        {
            return $this->fail();
        }

        $this->fingerprint = md5($sessionData);

        return true;
	}

	//--------------------------------------------------------------------

	/**
	 * Close
	 *
	 * Releases locks and closes file descriptor.
	 *
	 * @return    bool
	 */
	public function close(): bool
	{
		return ($this->lock && ! $this->releaseLock())
            ? $this->fail()
            : true;
	}

	//--------------------------------------------------------------------

    /**
     * Destroy
     *
     * Destroys the current session.
     *
     * @param string $sessionID
     *
     * @return bool
     */
	public function destroy($sessionID): bool
	{
        if ($this->lock)
        {
            $builder = $this->db->table($this->table)->where('id', $sessionID);

            if ($this->matchIP)
            {
                $builder = $builder->where('ip_address', $_SERVER['REMOTE_ADDR']);
            }

            if (! $builder->delete())
            {
                return $this->fail();
            }
        }

        if ($this->close())
        {
            $this->destroyCookie();

            return true;
        }

        return $this->fail();
	}

	//--------------------------------------------------------------------

	/**
	 * Garbage Collector
	 *
	 * Deletes expired sessions
	 *
	 * @param    int $maxlifetime Maximum lifetime of sessions
	 *
	 * @return    bool
	 */
	public function gc($maxlifetime): bool
	{
		return ($this->db->table($this->table)->delete('timestamp < '.(time() - $maxlifetime)))
            ? true
            : $this->fail();
	}

	//--------------------------------------------------------------------

    protected function lockSession(string $sessionID): bool
    {
        if ($this->platform === 'mysql')
        {
            $arg = md5($sessionID.($this->matchIP ? '_'.$_SERVER['REMOTE_ADDR'] : ''));
            if ($this->db->query("SELECT GET_LOCK('{$arg}', 300) AS ci_session_lock")->getRow()->ci_session_lock)
            {
                $this->lock = $arg;
                return true;
            }

            return $this->fail();
        }
        elseif ($this->platform === 'postgre')
        {
            $arg = "hashtext('{$sessionID}')".($this->matchIP ? ", hashtext('{$_SERVER['REMOTE_ADDR']}')" : '');
            if ($this->db->simpleQuery("SELECT pg_advisory_lock({$arg})"))
            {
                $this->lock = $arg;
                return true;
            }

            return $this->fail();
        }

        // Unsupported DB? Let the parent handle the simplified version.
        return parent::lockSession($sessionID);
    }

    //--------------------------------------------------------------------

    /**
     * Releases the lock, if any.
     *
     * @return bool
     */
    protected function releaseLock(): bool
    {
        if (! $this->lock)
        {
            return true;
        }

        if ($this->platform === 'mysql')
        {
            if ($this->db->query("SELECT RELEASE_LOCK('{$this->lock}') AS ci_session_lock")->getRow()->ci_session_lock)
            {
                $this->lock = false;
                return true;
            }

            return $this->fail();
        }
        elseif ($this->platform === 'postgre')
        {
            if ($this->db->simpleQuery("SELECT pg_advisory_unlock({$this->lock})"))
            {
                $this->lock = false;
                return true;
            }

            return $this->fail();
        }

        // Unsupported DB? Let the parent handle the simple version.
        return parent::releaseLock();
    }

    //--------------------------------------------------------------------
}