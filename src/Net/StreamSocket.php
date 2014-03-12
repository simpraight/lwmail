<?php

namespace LWMail\Net;

/**
 * Simple stream socket communication
 * 
 * @package Net
 * @copyright Copyright (C) Shinya Matsushita
 * @author  Shinya Matsushita <simpraight@gmail.com>
 * @license MIT
 */
class StreamSocket
{
    /**
     * socket connection resource 
     * 
     * @var resource
     */
    private $_resource = null;
    /**
     * Default options
     * 
     * @var array
     */
    private $_default_options = array(
        'protocol' => 'tcp',
        'host' => null,
        'port'   => 0,
        'timeout' => 30,
        'async' => false,
        'persistent' => false,
        'blocking' => true,
        'crypto' => false,
        'auto_connect' => false,
        'debug' => false,
    );
    /**
     * Socket options (inherit default options)
     * 
     * @var array
     */
    private $_options = array();
    /**
     * List of communication errors
     * 
     * @var array
     */
    private $_errors = array();
    /**
     * List of communication logs 
     * 
     * @var array
     */
    private $_logs = array();

    /**
     * Set options
     * 
     * @param array $options 
     * @return void
     */
    public function __construct($options = array())
    {
        $this->setOptions($options);
        if ($this->getOption('auto_connect') === true)
        {
            $this->debug('auto connect');
            $this->connect();
        }
    }

    /**
     * Close current connection
     * 
     * @return void
     */
    public function __destruct()
    {
        if ($this->isAlive())
        {
            $this->debug('close');
            $this->close();
        }
    }

    /**
     * Connecting socket
     * 
     * @return bool result
     */
    public function connect()
    {
        if (!$this->isAlive())
        {
            $this->logging('info', 'connect to %s:%d.', $this->getOption('host'), $this->getOption('port'));

            $flag = $this->getOption('async') ? STREAM_CLIENT_ASYNC_CONNECT : STREAM_CLIENT_CONNECT;
            $flag = $this->getOption('persistent') ? ($flag | STREAM_CLIENT_PERSISTENT) : $flag;
            $address = sprintf('%s://%s:%d', $this->getOption('protocol'), $this->getOption('host'), $this->getOption('port'));
            $this->debug(sprintf('open stream %s', $address));
            $this->_resource = @stream_socket_client($address, $err, $errno, $this->getOption('timeout'), $flag);
            if ($this->_resource === false)
            {
                $this->debug(sprintf('error %s', $err));
                $this->addError($errno, $err);
                return false;
            }
            if ($this->getOption('blocking') == false)
            {
                $this->setBlocking(false);
            }
            if ($this->getOption('crypto') !== false)
            {
                $this->setCrypto($this->getOption('crypto'));
            }
        } 
        return true;
    }

    /**
     * Return the connection state.
     * 
     * @return bool  true=connection is active
     */
    public function isAlive()
    {
        if (!is_resource($this->_resource))
        {
            return false;
        }
        return true;
    }

    /**
     * Set stream timeout 
     * 
     * @param int $timeout 
     * @return bool
     */
    public function setTimeout($timeout)
    {
        if (!$this->isAlive())
        {
            return false;
        }
        $this->debug(sprintf('set timeout to %s', $timeout));
        return @stream_set_timeout($this->_resource, $timeout);
    }

    /**
     * Set stream blocking mode
     * 
     * @param bool $enable 
     * @return bool result
     */
    public function setBlocking($enable)
    {
        if (!$this->isAlive())
        {
            return false;
        }
        $this->logging('info', 'Change blocking mode to %d', $enable ? 1 : 0);
        if (@stream_set_blocking($this->_resource, ($enable) ? 1 : 0) === false)
        {
            $this->addError(null, 'Could not change blocking mode');
            return false;
        }
        return true;
    }

    /**
     * Set strem crypto mode
     * 
     * @param bool||constant $crypto_type STREAM_CRYPTO_METHOD_*
     * @return bool result
     */
    public function setCrypto($crypto_type = false)
    {
        if (!$this->isAlive())
        {
            return false;
        }
        if ($cypro_type == false)
        {
            $this->logging('info', 'disabled crypto');
            if (!@stream_set_enable_crypto($this->_resource, false))
            {
                $this->addError('Could not change crypto mode.');
                return false;
            }
            return true;
        }

        if (!in_array($crypto_type, array(STREAM_CRYPTO_METHOD_SSLv2_CLIENT
            ,STREAM_CRYPTO_METHOD_SSLv3_CLIENT
            ,STREAM_CRYPTO_METHOD_SSLv23_CLIENT
            ,STREAM_CRYPTO_METHOD_TLS_CLIENT
            ,STREAM_CRYPTO_METHOD_SSLv2_SERVER
            ,STREAM_CRYPTO_METHOD_SSLv3_SERVER
            ,STREAM_CRYPTO_METHOD_SSLv23_SERVER
            ,STREAM_CRYPTO_METHOD_TLS_SERVER)))
        {
            $this->addError(sprintf('Unsupported crypto type %s', $crypto_type));
            return false;
        }
        
        $this->logging('info', 'Change crypto mode to %d', $crypto_type);
        if (@stream_set_enable_crypto($this->_resource, true, $crypto_type))
        {
            $this->addError('Could not change crypto mode.');
            return false;
        }
        return true;
    }

    /**
     * Close current connection
     * 
     * @return bool result
     */
    public function close()
    {
        if ($this->isAlive())
        {
            $this->logging('info', 'Closing socket');
            return @socket_close($this->_resource);
        }
        return true;
    }

    /**
     * Communicate
     * 
     * @param string $send_message sending data
     * @param string by reference $receive_message receiving data
     * @return bool result
     */
    public function communicate($send_message, &$receive_message, $eof = "\r\n")
    {
        $this->debug('call send()');
        if ($this->send($send_message) === false)
        {
            return false;
        }
        $this->debug('call getline()');
        if ($this->getline($receive_message, $eof) === false)
        {
            return false;
        }

        return true;
    }

    /**
     * Send data to stream
     * 
     * @param string $message 
     * @return bool result
     */
    public function send($message)
    {
        if (!$this->isAlive())
        {
            return false;
        }

        $this->debug('send message > ' . $message);
        $len = strlen($message);
        $this->logging('info', 'Send %d bytes data started', $len);
        $writed = 0;
        for ($written = 0; $written < $len; $written += $writed)
        {
            $writed = fwrite($this->_resource, substr($message, $written));
            if ($writed === false)
            {
                $this->addError(null, sprintf('Aborted sending data at %d bytes written', $written));
                return false;
            }
        }
        $this->logging('info', 'Sent %d bytes data succeed', $len);
        return true;
    }

    /**
     * Receive data from stream
     * 
     * @param string by reference $message receiving data
     * @return bool result
     */
    public function getline(&$message, $eof = "\r\n")
    {
        if (!$this->isAlive())
        {
            return false;
        }

        $this->logging('info', 'Receive data started');
        $data = "";
        if (empty($message)) { $message = ""; }
        $_message = "";
        while (strpos($_message, $eof) === false)
        {
            $data = fgets($this->_resource, 512);
            $this->debug('receive message > ' . $data);
            if ($data === false)
            {
                break;
            }
            $_message .= $data;
        }
        $this->logging('info', 'Received %d bytes data', strlen($_message));
        $message .= $_message;
        return true;
    }

    /**
     * Set options 
     * 
     * @param array $options 
     * @return void
     */
    public function setOptions($options = array())
    {
        if (!is_array($options)) { $options = array(); }
        if (empty($this->_options))
        {
            $this->_options = array_merge($this->_default_options, $options);
        }
        else
        {
            $this->_options = array_merge($this->_options, $options);
        }
    }

    /**
     * Set option value
     * 
     * @param string $key 
     * @param mixed $value 
     * @return void
     */
    public function setOption($key, $value)
    {
        $this->_options[$key] = $value;
    }

    /**
     * Get option value
     * 
     * @param string $name 
     * @return mixed value
     */
    private function getOption($name)
    {
        if (!isset($this->_options[$name]))
        {
            return null;
        }
        return $this->_options[$name];
    }

    /**
     * Logging
     * 
     * @param mixed [$type],[$message],[$arg1],...
     * @return void
     */
    private function logging()
    {
        $type = 'info';
        $message = '';
        $args = func_get_args();
        if (count($args) === 0) { return; }
        $type = array_shift($args);
        if (count($args) === 0) { $message = $type; $type = 'info'; }
        else { $message = array_shift($args); }

        $this->debug(vsprintf($message, $args));
        $this->_logs[] = sprintf('[%s] %s', strtoupper($type), vsprintf($message, $args));
    }

    /**
     * Add error message
     * 
     * @param number $code 
     * @param string $message 
     * @return void
     */
    private function addError($code = null, $message)
    {
        $message = ($code != null) ? sprintf('%s: %s', $code , $message) : $message;
        $this->logging('error', $message);
        $this->_errors[] = $message;
    }

    /**
     * Return all logs
     * 
     * @return array
     */
    public function getLogs()
    {
        return $this->_logs;
    }

    /**
     * Return all errors
     * 
     * @return array
     */
    public function getErrors()
    {
        return $this->_errors;
    }


    /**
     * Debug print
     * 
     * @param string $message 
     * @return void
     */
    private function debug($message)
    {
        if ($this->getOption('debug'))
        {
            $trace = debug_backtrace();
            echo sprintf('DEBUG: %s() %s', $trace[1]['function'], trim($message) . PHP_EOL);
        }
    }
    
}
