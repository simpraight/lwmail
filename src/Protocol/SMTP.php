<?php

namespace LWMail\Protocol;

/**
 * SMTP Protocol
 *   - supported RFC5321
 * 
 * @uses Protocol
 * @package LWMail
 * @copyright Copyright (C) Shinya Matsushita
 * @author  Shinya Matsushita <simpraight@gmail.com>
 * @license MIT
 */
class SMTP extends Protocol
{

    /**
     * List of supported SMTP commands
     * 
     * @var array
     */
    private $supported = array();
    /**
     * Allowed max message size 
     * 
     * @var int
     */
    private $max_size = 0;
    /**
     * Auth challenge text (if use CRAM-MD5 Auth mechanism) 
     * 
     * @var string
     */
    private $auth_challenge = null;
    /**
     * default options 
     * 
     * @var array
     */
    protected $_default_options = array(
        'protocol' => 'tcp',        // if use SSL or TLS connection, set "ssl" or "tls"
        'hostname' => null,         // default is $_SERVER['SERVER_NAME']
        'port' => 25,
        'smtp.auth' => false,       // if use SMTP-AUTH, set true
        'smtp.user' => null,
        'smtp.pass' => null,
        'smtp.starttls'  => false,  // if use STARTTLS (not a TLS)
        'smtp.pop_before' => false, // TODO: currently do not suppord. if use POP before SMTP, set true
        'timeout' => 20,
    );

    /**
     * Connect to smtp server
     * 
     * @return bool
     */
    public function connect()
    {
        if (!$this->stream->connect())
        {
            $this->last_error = join("\n", $this->stream->getErrors());
            return false;
        }
        $this->stream->setTimeout($this->getOption('timeout'));
        $this->stream->getline($message, "\r\n");
        if (strpos(trim($message), '2') !== 0)
        {
            $this->last_error = $message;
            return false; 
        }
        
        if (!$this->_ehlo())
        {
            if (!$this->_helo())
            {
                return false; 
            }
        }
        $isOK = true;
        if ($this->getOption('smtp.starttls'))
        {
            if (!$this->_starttls())
            {
                return false;
            }
        }
        if ($this->getOption('smtp.auth') && $this->getOption('smtp.user') && $this->getOption('smtp.pass'))
        {
            if (!$this->_auth($this->getOption('smtp.user'), $this->getOption('smtp.pass')))
            {
                return false;
            }
        }
        if ($this->getOption('smtp.pop_before') && $this->getOption('smtp.user') && $this->getOption('smtp.pass'))
        {
            // TODO: POP before SMTP
            return false;    
        }
        return true;
    }

    /**
     * close SMTP server connection
     * 
     * @return void
     */
    public function close()
    {
        if ($this->isConnected())
        {
            return $this->_quit();
        }
    }

    /**
     * Send Mail Message
     * 
     * @param \LWMail\MailMessage $message 
     * @return bool send result
     * @see \LWMail\MailMessage
     */
    public function send(\LWMail\MailMessage $message)
    {
        if (!$this->isConnected())
        {
            return false;
        }

        if (!$message->isValid())
        {
            return false;
        }

        $from = $message->from;
        if (!$this->_mail_from($from[0]['mail']))
        {
            return false;
        }

        $to = $message->to;
        if (!$this->_rcpt_to($to[0]['mail']))
        {
            return false;
        }

        if (!$this->_data($message->data_for_send))
        {
            return false;
        }

        return true;
    }

    // RFC2821
    /**
     * SMTP EHLO command
     * 
     * @return result
     */
    protected function _ehlo()
    {
        $host = $this->getOption('hostname') === "" ? php_uname('n') : $this->getOption('hostname');
        return $this->name('ehlo')->command('EHLO %s', $host)->exec()->isOK();
    }

    /**
     * SMTP HELO command
     * 
     * @return bool result
     */
    protected function _helo()
    {
        $host = $this->getOption('hostname') === "" ? php_uname('n') : $this->getOption('hostname');
        return $this->name('helo')->command('HELO %s', $host)->exec()->isOK();
    }

    /**
     * SMTP [MAIL FROM] command
     * 
     * @param string $mail 
     * @return bool
     */
    protected function _mail_from($mail)
    {
        return $this->name('mail_from')->command('MAIL FROM: <%s>', rtrim(ltrim($mail,'<'),'>'))->exec()->isOK();
    }

    /**
     * SMTP [RCPT TO] command
     * 
     * @param string $mail 
     * @return bool
     */
    protected function _rcpt_to($mail)
    {
        return $this->name('rcpt_to')->command('RCPT TO: <%s>', rtrim(ltrim($mail,'<'),'>'))->exec()->isOK();
    }

    /**
     * SMTP DATA command
     * 
     * @param string $formated_data 
     * @return bool
     */
    protected function _data($formated_data)
    {
        if (0 < $this->max_size)
        {
            $size = strlen($formated_data);
            if ($this->max_size < $size)
            {
                $this->last_error = 'Message size exceeds server limit';
                return false;
            }
        }
        if (empty($formated_data))
        {
            return false;
        }
        return $this->name('data')->command('DATA')
                    ->name('data_end')->command($formated_data)->exec()->isOK();
    }

    /**
     * SMTP QUIT command
     * 
     * @return bool
     */
    protected function _quit()
    {
        return $this->name('quit')->command('QUIT')->exec()->isOK();
    }

    /**
     * SMTP RSET command 
     * 
     * @return void
     */
    protected function _rset()
    {
        return $this->name('rset')->command('RSET')->exec()->isOK();
    }

    // RFC2554
    /**
     * SMTP AUTH command
     * 
     * @param string $user 
     * @param string  $pass 
     * @return bool
     */
    protected function _auth($user, $pass)
    {
        $supported = $this->supported;
        switch (true)
        {
            case in_array('PLAIN', $supported):
                return $this->name('auth_plain')->command('AUTH PLAIN %s', base64_encode(sprintf("%s\0%s\0%s", $user, $user, $pass)))->exec()->isOK();
            case in_array('LOGIN', $supported):
                return $this->name('auth_login')->command('AUTH LOGIN')
                            ->name('auth_login_user')->command('%s', base64_encode($user))
                            ->name('auth_login_pass')->command('%s', base64_encode($pass))->exec()->isOK();
            case in_array('CRAM-MD5', $supported):
                if (!$this->name('auth_cram')->command('AUTH CRAM-MD5')->exec()->isOK())
                {
                    return false;
                }
                $secret = base64_encode($user . ' ' . hash_hmac('md5', base64_decode($this->auth_challenge), $pass));
                return $this->name('auth_cram_pass')->command($secret)->exec()->isOK();
            case in_array('DIGEST-MD5', $supported):
                // TODO: Authenticate DIGEST_MD5
                return false;
            default:
                return false;
        }
    }

    // RFC2487
    /**
     * SMTP STARTTLS command
     * 
     * @return bool
     */
    protected function _starttls()
    {
        if ($this->name('starttls')->command('STARTTLS')->exec()->isOK())
        {
            return $this->stream->setCrypto(STREAM_CRYPTO_METHOD_TLS_CLIENT);
        }
    }


    // Note: Unsupported smtp commands
    //       SEND, SOML, SAML, BDAT, VRFY, EXPN, HELP, NOOP, TURN, ETRN, ATRN
    

    /**
     * Parse HELO command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseHelo($response, &$data)
    {
        if (strpos(trim($response), '2') !== 0)
        {
            $this->last_error = trim($response);
            return false;
        }
        return true;
    }

    /**
     * Parse EHLO command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseEhlo($response, &$data)
    {
        $no_problem = true;
        if (preg_match('/^(\d+)([\s-])+(.+)$/', trim($response), $m))
        {
            $is_last = (trim($m[2]) != '-');
            if (strpos($m[1], '2') !== 0)
            {
                $this->last_error = $response;
                $no_problem = false;
            }
            else
            {
                $keyword = trim(strtoupper($m[3]));
                if (in_array($keyword, array('STARTTLS','8BITMIME'))) { $this->supported[] = $keyword; }
                else if (strpos($keyword, 'AUTH') === 0) {
                    $auth_supported = explode(' ', substr($keyword, 5));
                    $this->supported = array_merge($this->supported, $auth_supported);
                } else if (strpos($keyword, 'SIZE=') === 0) {
                    $size = substr($keyword, 5);
                    if (is_numeric($size)) { $this->max_size = intval($size); }
                }
            }
            if (!$is_last &&  $this->stream->getline($new_response))
            {
                $this->parseEhlo($new_response, &$data);
            }
        }
        return $no_problem;
    }

    /**
     * Parse EHLO command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthPlain($response, &$data)
    {
        if (strpos(trim($response), '2') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [AUTH LOGIN] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthLogin($response, &$data)
    {
        if (strpos(trim($response), '334') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [AUTH LOIN] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthLoginUser($response, &$data)
    {
        if (strpos(trim($response), '334') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [AUTH LOGIN] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthLoginPass($response, &$data)
    {
        if (strpos(trim($response), '2') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [CRAM-MD5]command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthCram($response, &$data)
    {
        if (strpos(trim($response), '334') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        $response = explode(' ', $response);
        if (count($response) < 2)
        {
            $this->last_error = 'Could not get challenge words.';
            return false;
        }
        $this->auth_challenge = trim($response[1]);
        return true;
    }

    /**
     * Parse [AUTH CRAM-MD5] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseAuthCramPass($response, &$data)
    {
        if (strpos(trim($response), '2') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [MAIL FROM] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseMailFrom($response, &$data)
    {
        if (strpos(trim($response), '25') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [RCPT TO] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseRcptTo($response, &$data)
    {
        if (strpos(trim($response), '25') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [DATA] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseData($response, &$data)
    {
        if (strpos(trim($response), '354') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }

    /**
     * Parse [DATA] command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseDataEnd($response, &$data)
    {
        if (strpos(trim($response), '250') !== 0)
        {
            $this->last_error = $response;
            return false;
        }
        return true;
    }
    
    /**
     * Parse QUIT command response 
     * 
     * @param string $response 
     * @param mixed $data 
     * @return bool
     */
    protected function parseQuit($response, &$data)
    {
        return (strpos(trim($response), '2') === 0);
    }
}
