<?php

namespace LWMail\Protocol;

/**
 * Abstract Protocol Class 
 * 
 * @package LWMail
 * @copyright Copyright (C) Shinya Matsushita
 * @author  Shinya Matsushita <simpraight@gmail.com>
 * @license MIT
 */
abstract class Protocol
{
    /**
     * stream connection resource
     * 
     * @var resource
     */
    protected $stream = null;
    /**
     * current command name
     * 
     * @var string
     */
    private $current = 'default';
    /**
     * has error 
     * 
     * @var bool
     */
    private $has_error = false;
    /**
     * stored command stack
     * 
     * @var array
     */
    private $command_stack = array();
    /**
     * raw response data  of executed commands
     * 
     * @var array
     */
    private $raw_data = array();
    /**
     * formated response data of executed commands 
     * 
     * @var array
     */
    private $data = array();

    /**
     * last error
     * 
     * @var string
     */
    protected $last_error = null;
    /**
     * stream connection status
     * 
     * @var bool
     */
    protected $connected = false;
    /**
     * option values
     * 
     * @var array
     */
    protected $_options = array();
    /**
     * default option values
     * 
     * @var array
     */
    protected $_default_options = array();

    /**
     * Set options and Create new stream connection.
     * 
     * @param array $options 
     * @return void
     */
    public function __construct($options = array())
    {
        $this->_options = array_merge($this->_default_options, $options);
        $this->stream = new \LWMail\Net\StreamSocket($this->_options);
        if ($this->connect())
        {
            $this->connected = true;
        }
        else
        {
            $this->close();
        }
    }

    /**
     * destruct (Connection close)
     * 
     * @return void
     */
    public function __destruct()
    {
        if ($this->isConnected())
        {
            $this->close();
        }
    }

    abstract protected function connect();
    abstract protected function close();

    /**
     * Set command
     * 
     * @return \LWMail\Protocol  (this)
     */
    final protected function command()
    {
        $args = func_get_args();
        if (!isset($this->command_stack[$this->current]))
        {
            $this->name($this->current);
        }
        if (0 < count($args))
        {
            $command = array_shift($args);
            $this->command_stack[$this->current]['commands'][] = (0 < count($args)) ? vsprintf($command, $args) : $command;
        }
        return $this;
    }

    /**
     * Set command name
     * 
     * @param string $name 
     * @param string $eof 
     * @return \LWMail\Protocol (this)
     */
    final protected function name($name, $eof = "\r\n")
    {
        $this->current = $name;
        if (!isset($this->command_stack[$name])) { $this->command_stack[$name] = array('eof' => $eof, 'commands' => array()); }
        return $this->eof($eof);
    }

    /**
     * Set command EOF
     * 
     * @param string $eof 
     * @return \LWMail\Protocol (this)
     */
    final protected function eof($eof = "\r\n")
    {
        if (!isset($this->command_stack[$this->current]))
        {
            return $this->name($this->current, $eof);
        } 
        $this->command_stack[$this->current]['eof'] = $eof;
        return $this;
    }

    /**
     * Execute command
     * 
     * @return \LWMail\Protocol (this)
     */
    final protected function exec()
    {
        $this->data = array();
        $this->raw_data = array();
        $this->has_error = false;

        foreach ($this->command_stack as $name => $data)
        {
            $result = "";
            $response = "";
            $commands = $data['commands'];
            $eof = $data['eof'];
            if (!is_array($commands) || empty($commands)) { continue; }

            $eol = $this->getOption('eol', "\r\n");
            if ($result = $this->stream->send(join($eol, $commands) . $eol))
            {
                $result = $this->stream->getline($response, $eof);
            }

            $this->has_error = !$result || $this->has_error;
            $this->raw_data[$name] = $response;

            $method = "parse" . join('', array_map('ucfirst', explode('_', strtolower($name))));
            if (method_exists($this, $method))
            {
                $result = $this->{$method}($response, $this->data[$name]);
                $this->has_error = !$result || $this->has_error;
            }
        }

        $this->command_stack = array();
        return $this; 
    }

    /**
     * Return command exec result
     * 
     * @return bool
     */
    final protected function isOK()
    {
        return !$this->has_error;
    }

    /**
     *  Return command raw response data
     * 
     * @param string $name command name
     * @return string
     */
    public function getRawData($name = null)
    {
        return ($name === null) ? $this->raw_data : (isset($this->raw_data[$name]) ? $this->raw_data[$name] : "");
    }

    /**
     * Return command result data
     * 
     * @param string $name command name
     * @return string
     */
    public function getData($name = null)
    {
        return ($name === null) ? $this->data : (isset($this->data[$name]) ? $this->data[$name] : array());
    }

    /**
     * Return option value
     * 
     * @param string $name 
     * @param string $default 
     * @return mixed option value
     */
    public function getOption($name, $default = "")
    {
        return isset($this->_options[$name]) ? $this->_options[$name] : $default;
    }

    /**
     * is Connected 
     * 
     * @return bool 
     */
    public function isConnected()
    {
        return $this->connected;
    }

    /**
     * __call magic method
     *
     *     $this->getList();
     *     //  $this->_list();
     *     //  return $this->getData('list');
     * 
     * @param string $name 
     * @param mixed $args 
     * @return mixed  command result data  or  false
     */
    public function __call($name, $args)
    {
        if ($name === 'get' || substr($name, 0, 3) !== 'get') { return false; }
        $method = '_' . strtolower(substr($name, 3));
        if (!method_exists($this, $method)) { return false; }

        if (call_user_func_array(array(&$this, $method), $args))
        {
            return $this->getData(substr($method, 1));
        }
        return false;
    }
}
