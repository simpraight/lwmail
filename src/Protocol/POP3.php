<?php

namespace LWMail\Protocol;


/**
 * POP3 
 * 
 *   - supported RFC1734,RFC1939
 *
 * @uses Protocol
 * @package LWMail
 * @copyright Copyright (C) Shinya Matsushita
 * @author  Shinya Matsushita <simpraight@gmail.com>
 * @license MIT
 */
class POP3 extends Protocol
{
    protected $_default_options = array(
        'protocol' => 'tcp',   // if use ssl or tls connection "ssl" or "tls" 
        'port' => 110,
        'eol'  => "\r\n",
        'pop3.user' => null,
        'pop3.pass' => null,
        'pop3.apop' => false,  // todo: currentlly do not suppord.
        'timeout' => 20,
    );

    /**
     * connect pop3 server
     * 
     * @return bool connection result
     */
    protected function connect()
    {
        if (!$this->stream->connect())
        {
            $this->last_error = join("\n", $this->stream->getErrors());
            return false;
        }

        $this->stream->setTimeout($this->getOption('timeout'));
        $this->stream->getline($message, "\r\n");
        if (strpos(trim($message), '+OK') !== 0)
        {
            $this->last_error = $message;
            return false; 
        }

        if (!$this->_user($this->getOption('pop3.user')))
        {
            return false;
        }

        if (!$this->_pass($this->getOption('pop3.pass')))
        {
            return false;
        }

        return true;
    }

    /**
     * close connection
     * 
     * @return void
     */
    protected function close()
    {
        $this->_quit();
    }

    /**
     * POP3 STAT command 
     * 
     * @return bool result
     */
    protected function _stat()
    {
        return $this->name('stat')->command('STAT')->exec()->isOK();
    }

    /**
     * POP3 LIST command 
     * 
     * @param int $msg id
     * @return bool result
     */
    protected function _list($msg = null)
    {
        if ($msg === null)
        {
            return $this->name('list', '.')->command('LIST')->exec()->isOK();
        }
        else
        {
            return $this->name('list', '.')->command('LIST %d', $msg)->exec()->isOK();
        }
    }

    /**
     * POP3 RETR command
     * 
     * @param int $msg id
     * @return bool result
     */
    protected function _retr($msg)
    {
        return $this->name('retr', ".")->command('RETR %d', $msg)->exec()->isOK();
    }

    /**
     * POP3 DELE command
     * 
     * @param int $msg id
     * @return bool result
     */
    protected function _dele($msg)
    {
        return $this->name('dele', '.')->command('DELE %d', $msg)->exec()->isOK();
    }

    /**
     * POP3 NOOP command
     * 
     * @return bool result
     */
    protected function _noop()
    {
        return $this->name('noop')->command('NOOP')->exec()->isOK();
    }

    /**
     * POP3 RSET command
     * 
     * @return bool result
     */
    protected function _rset()
    {
        return $this->name('rset')->command('RSET')->exec()->isOK();
    }

    /**
     * POP3 TOP command
     * 
     * @return bool result
     */
    protected function _top($msg, $num = 1)
    {
        return $this->name('top')->command('TOP %d %d', $msg, $num)->exec()->isOK();
    }

    /**
     * POP3 UIDL command
     * 
     * @param int $msg id or null
     * @return bool result
     */
    protected function _uidl($msg = null)
    {
        if ($msg === null)
        {
            return $this->name('uidl', '.')->command('UIDL')->exec()->isOK();
        }
        else
        {
            return $this->name('uidl', '.')->command('UIDL %d', $msg)->exec()->isOK();
        }
    }

    /**
     * POP3 USER command
     * 
     * @param string $name auth user name
     * @return bool
     */
    protected function _user($name)
    {
        return $this->name('user')->command('USER %s', $name)->exec()->isOK();
    }

    /**
     * POP3 PASS command 
     * 
     * @param string $pass auth user password
     * @return bool
     */
    protected function _pass($pass)
    {
        return $this->name('pass')->command('PASS %s', $pass)->exec()->isOK();
    }

    /**
     * POP3 APOP command
     *  - TODO: currently do not support 
     * 
     * @param string $name 
     * @param string $digest 
     * @return bool
     */
    protected function _apop($name, $digest)
    {
        // TODO:
        // $digest: MD5(<server-timestamp-string>+password);
        return $this->name('apop')->command('APOP %s %s', $name, $digest)->exec()->isOK();
    }

    /**
     * POP3 QUIT command 
     * 
     * @return bool
     */
    protected function _quit()
    {
        return $this->name('quit')->command('QUIT')->exec()->isOK();
    }

    /**
     * POP3 AUTH command 
     * 
     * @param string $type auth type 
     * @return bool
     */
    protected function _auth($type)
    {
        return $this->name('AUTH')->command('AUTH %s', $type)->exec()->isOK();
    }

    /**
     * Parse UIDL command response
     * 
     * @param string $response 
     * @param mixed $data parsed data
     * @return bool
     */
    protected function parseUidl($response, &$data)
    {
        if (strpos(trim($response), '+OK') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        
        if (!$this->stream->getline($response, "\r\n."))
        {
            $this->last_error = join("\n", $this->stream->getErrors());
            return false;
        }

        $lines = explode("\n", $response);
        array_shift($lines);

        $data = array();
        foreach ($lines as $line)
        {
            if (trim($line) == "" || trim($line) == '.') { continue; }
            list($msg, $uidl) = explode(' ', $line);
            $data[] = (object)array(
                'id' => $msg,
                'uidl' => $uidl,
            );
        }
        return true;
    }

    /**
     * Parse USER command response
     * 
     * @param string $response 
     * @param mixed $data parsed data
     * @return bool
     */
    protected function parseUser($response, &$data)
    {
        if (strpos(trim($response), '+OK') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse PASS command response
     * 
     * @param string $response 
     * @param mixed $data parsed data
     * @return bool
     */
    protected function parsePass($response, &$data)
    {
        if (strpos(trim($response), '+OK') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse RETR command response
     * 
     * @param mixed $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseRetr($response, &$data)
    {
        if (strpos(trim($response), '+OK') !== 0)
        {
            return false;
        }
        if (!$this->stream->getline($response, "\r\n."))
        {
            return false;
        }

        $headers = array();
        $body = "";

        $data = explode("\r\n", $response);
        array_shift($data);
        array_pop($data);
        array_pop($data);

        return true;
    }

    /**
     * Parse LIST command response
     * 
     * @param mixed $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseList($response, &$data)
    {
        $lines = explode("\n", $response);
        if (count($lines) === 0)
        {
            return false;
        }
        $summary = array_shift($lines);

        if (!strpos(trim($summary), '+OK') === 0)
        {
            return false;
        }
        $data = array();
        
        foreach ($lines as $line)
        {
            if (trim($line) === '.') break;
            list($msg, $size) = explode(" ", $line);
            $data[] = (object)array('id' => $msg, 'bytes' => $size);
        }
        return true;
    }

    /**
     * Parse STAT command response
     * 
     * @param mixed $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseStat($response, &$data)
    {
        if (strpos(trim($response), '+OK') !== 0)
        {
            return false;
        }
        list($ok, $total, $size) = explode(' ', $response);
        $data = (object)array(
            'count' => $total,
            'bytes' => $size,
        );
        return true;
    }

    /**
     * Parse QUIT command response
     * 
     * @param mixed $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseQuit($response, &$data)
    {
        return (strpos(trim($response), '+OK') === 0);
    }
}
