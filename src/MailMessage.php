<?php

namespace LWMail;

/**
 * MailMessage Format
 *  - supported RFC5322, RFC2045, RFC2046, RFC2047
 * 
 * @package LWMails
 * @copyright Copyright (C) Shiny Matsushita
 * @author  Shinya Matsushita <simpraight@gmail.com>
 * @license MIT
 */
class MailMessage
{
    const REGEXP_EMAIL = '/^(?:[\w\!#\$%\&\'\*\+\/\=\?\^_\{\}\\\|~\.-]+?)@(?:[\w][\w-]*\.)+[\w][\w-]*$/';
    /**
     * Headers
     * 
     * @var array
     */
    private $_headers = array();
    /**
     * Bodies
     * 
     * @var array
     */
    private $_bodies = array();
    /**
     * Attachments
     * 
     * @var array
     */
    private $_attachments = array();

    /**
     * Body (raw data)
     * 
     * @var string
     */
    private $raw_body = null;
    
    /**
     * already parsed body data
     * 
     * @var bool
     */
    private $_body_parsed = false;

    /**
     * Load from raw message
     * 
     * @param string||array $raw_message 
     * @return void
     */
    private function __construct()
    {
        $this->_headers = array();
        $this->_bodies = array();
        $this->_attachments = array();
    }

    /**
     * Create new message
     * 
     * @return MailMessage instance
     */
    public static function create()
    {
        $instance = new self();
        $instance->_body_parsed = true;
        return $instance;
    }

    /**
     * Parse headers and raw body-data from raw message.
     * 
     * @param string||array $lines 
     * @return instance of MailMessage
     */
    public static function &parse($lines)
    {
        $instance = new self();

        if ($lines == null) return;
        if (is_string($lines)) $lines = explode("\r\n", $lines);
        if (!is_array($lines)) return;

        //
        // Parse Header Part
        //
        $headers = $instance->parseHeader($lines);
        foreach ($headers as $k => $v)
        {
            $instance->{$k} = $v;
        }

        //
        //  Body
        //
        $instance->raw_body = trim(join("\r\n", $lines));

        return $instance;
    }

    /**
     * message data is valid for send.
     * 
     * @return bool
     */
    public function isValid()
    {
        $no_problem = true;
        if (!isset($this->_headers['from']) || empty($this->_headers['from']))
        {
            $no_problem = false;
        }
        else
        {
            foreach ($this->_headers['from'] as $from)
            {
                if (!isset($from['name'], $from['mail'])) { $no_problem = false; continue; }
                if (!preg_match(self::REGEXP_EMAIL, $from['mail'])) { $no_problem = false; continue; }
            }
        }
        if (!isset($this->_headers['to']) || empty($this->_headers['to']))
        {
            $no_problem = false;
        }
        else
        {
            foreach ($this->_headers['to'] as $to)
            {
                if (!isset($to['name'], $to['mail'])) { $no_problem = false; continue; }
                if (!preg_match(self::REGEXP_EMAIL, $to['mail'])) { $no_problem = false; continue; }
            }
        }
        if (!isset($this->_headers['subject']) || empty($this->_headers['subject']))
        {
            $no_problem = false;
        }
        if (empty($this->_bodies))
        {
            $no_problem = false;
        }

        return $no_problem;
    }
    
    /**
     * Parse header part
     * 
     * @param mixed $lines 
     * @return void
     */
    private function parseHeader(&$lines)
    {
        if (is_string($lines)) { $lines = explode("\r\n", $lines); }
        if (!is_array($lines)) { return; }

        $key = "";
        $headers = array();
        $l = count($lines);

        for ($i = 0; $i < $l; $i++)
        {
            if (trim($lines[$i]) === "")
            {
                array_shift($lines);
                break;
            }

            if ($key !== "" && ((strpos($lines[$i], ' ') === 0) || (strpos($lines[$i], "\t") === 0)))
            {
                if (is_array($headers[$key]))
                {
                    $last = array_pop($headers[$key]);
                    $last .= ('' . trim($lines[$i]));
                    $headers[$key][] = $last;
                }
                else
                {
                    $headers[$key] .= ('' . trim($lines[$i]));
                }
            }
            else if (($pos = strpos($lines[$i], ':')) !== false)
            {
                $key = str_replace('-', '_', strtolower(substr($lines[$i], 0, $pos)));
                $val = trim(substr($lines[$i], $pos + 1));
                if (isset($headers[$key]))
                {
                    if (!is_array($headers[$key])) $headers[$key] = array($headers[$key]);
                    $headers[$key][] = $val;
                }
                else
                {
                    $headers[$key] = $val;
                }
            }
            array_shift($lines);
            $i--; $l--;
        }
        return $headers;
    }

    /**
     * Parse body part
     * 
     * @param mixed $body 
     * @param mixed $headers 
     * @return void
     */
    private function parseBody($body = null, $headers = null)
    {
        if ($this->_body_parsed || empty($this->raw_body) || empty($this->_headers))
        {
            return;
        }

        $first_call = ($body === null && $headers == null);
        $body = ($body === null) ? $this->raw_body : $body;
        $headers = ($headers == null) ? $this->_headers : $headers;

        if (isset($headers['content_type'], $headers['content_type']['boundary']))
        {
            $boundary = '--' . $headers['content_type']['boundary'];  
            if (($pos = strpos($body, $boundary)) !== false)
            {
                $body = substr($body, $pos);
            }

            $body = explode($boundary, $body);
            foreach ($body as $b)
            {
                if (trim($b) == "") continue;
                if (trim($b) == "--") continue;

                $lines = explode("\r\n", $b);
                $_headers = $this->parseHeader($lines);
                if (isset($_headers['content_type']))
                {
                    $_headers['content_type'] = $this->_normalizeContentType($_headers['content_type']);
                }
                if (isset($_headers['content_disposition']))
                {
                    $_headers['content_disposition'] = $this->_normalizeContentDisposition($_headers['content_disposition']);
                }

                $this->parseBody(join("\r\n", $lines), $_headers);
            }
        }
        else
        {
            $encoding = isset($headers['content_transfer_encoding']) ? $headers['content_transfer_encoding'] : "";
            $disposition = isset($headers['content_disposition']) ? $headers['content_disposition'] : array();
            $type = isset($headers['content_type']) ? $headers['content_type'] : array();
            $mime = isset($type['mime']) ? $type['mime'] : 'application/octet-stream';
            if (strtolower($encoding) == 'base64')
            {
                $body = join('', array_map('trim', explode("\r\n", $body)));
                $body = base64_decode($body);
                if (strpos($mime, 'text') !== false  &&  isset($type['charset']))
                {
                    $body = mb_convert_encoding($body, 'UTF-8', $type['charset']);
                }
            }
            else
            {
                 $body = isset($type['charset']) ? mb_convert_encoding($body, 'UTF-8', $type['charset']) : $body;
            }
            if (isset($disposition['filename']) || isset($type['name']))
            {
                $this->_attachments[] = array(
                    'name' => isset($disposition['filename']) ? $disposition['filename'] : $type['name'],
                    'mime' => $mime,
                    'size' => strlen($body),
                    'data' => $body
                );
            }
            else
            {
                $this->_bodies[] = array(
                    'mime' => $mime,
                    'data' => $body,
                );
            }
            unset($body);
        }

        if ($first_call)  { $this->_body_parsed = true; }
    }


    /**
     * Return Message text data for send.
     * 
     * @return string message text
     */
    protected function _getDataForSend()
    {
        if (!$this->isValid()) return '';
        
        if ($this->message_id == "") { $this->message_id = sprintf('<%s@lwmail>', sha1(uniqid(rand().time(), true))); }
        if ($this->sender == "") { $this->sender = $this->from[0]; }
        if (count($this->reply_to) == 0) { $this->reply_to = $this->from; }
        
        $charset = 'iso-2022-jp';
        $format_mimeheader = function($text) use ($charset) {
            $enc = mb_internal_encoding(); mb_internal_encoding($charset);
            $ret = mb_encode_mimeheader(mb_convert_encoding($text, $charset, 'UTF-8'), $charset);
            mb_internal_encoding($enc);
            return $ret;
        };
        $format_addresses = function($addresses) use ($format_mimeheader){
            $ret = array();
            if (array_key_exists('mail', $addresses)) { $addresses = array($addresses); }
            foreach ($addresses as $a) {
                $ret[] = (!empty($a['name'])) ? sprintf('%s <%s>', $format_mimeheader($a['name']), $a['mail']) : sprintf('<%s>', $a['mail']);
            }
            return join(', ', $ret);
        };
        $headers = array();
        $bodies = array();
        
        // Build Header Part
        //$headers[] = sprintf('Date: %s', date('r', $this->date));
        $headers[] = sprintf('From: %s', $format_addresses($this->from));
        $headers[] = sprintf('Sender: %s', $format_addresses($this->sender));
        $headers[] = sprintf('Reply-To: %s', $format_addresses($this->reply_to));
        $headers[] = sprintf('To: %s', $format_addresses($this->to));
        if (($cc = $this->cc) && !empty($cc)) { $headers[] = sprintf('Cc: %s', $format_addresses($cc)); }
        if (($bcc = $this->bcc) && !empty($bcc)) { $headers[] = sprintf('Bcc: %s', $format_addresses($bcc)); }
        $headers[] = sprintf('Message-ID: %s', $this->message_id);
        if (($in_reply_to = $this->in_reply_to) && !empty($in_reply_to)) { $headers[] = sprintf('In-Reply-To: %s', join("\r\n    ", $in_reply_to)); }
        if (($refs = $this->references) && !empty($refs)) { $headers[] = sprintf('References: %s', join("\r\n    ", $refs)); }
        $headers[] = sprintf('Subject: %s', $format_mimeheader($this->subject));
        if (($comms = $this->comments) && !empty($comms)) { $headers[] = sprintf('Comments: %s', $format_mimeheader($comments)); }
        if (($keywords = $this->keywords) && !empty($keywords)) { $headers[] = sprintf('Keywords: %s', $format_mimeheader(join(', ', $keywords))); }

        $headers[] = 'MIME-Verion: 1.0';
        // Build Body Part
        if (1 == count($this->_bodies) && empty($this->_attachments))
        {
            if (strpos($this->_bodies[0]['mime'], 'text/plain') === 0)
            {
                $headers[] = sprintf('Content-Type: %s; charset=%s', $this->_bodies[0]['mime'], 'ISO-2022-JP');
                $headers[] = sprintf('Content-Transfer-Encoding: %s', '7bit');
                $bodies[] = mb_convert_encoding($this->_bodies[0]['data'], 'ISO-2022-JP-MS', 'UTF-8');
            }
            else
            {
                $headers[] = sprintf('Content-Type: %s', $this->_bodies[0]['mime']);
                $headers[] = sprintf("Content-Transfer-Encoding: %s\r\n", 'base64');
                $bodies[] = chunk_split(base64_encode(mb_convert_encoding($this->_bodies[0]['data'], 'ISO-2022-JP-MS', 'UTF-8')));
            }
        }
        else
        {
            $boundary1 = sprintf('----NextPart=%d_%s', time(), uniqid(rand(), true));
            $boundary2 = sprintf('----NextPart=%d_%s', time(), uniqid(rand(), true));
            $headers[] = sprintf("Content-Type: multipart/mixed;\r\n    boundary=\"%s\"", $boundary1);
            
            $bodies[] = sprintf("--%s\r\nContent-Type: multipart/alternative;\r\n    boundary=\"%s\"\r\n", $boundary1, $boundary2);

            foreach ($this->_bodies as $body)
            {
                $bodies[] = sprintf("--%s", $boundary2);
                if (strpos($body['mime'], 'text/plain') === 0)
                {
                    $bodies[] = sprintf('Content-Type: %s; charset=%s', $body['mime'], 'ISO-2022-JP');
                    $bodies[] = sprintf("Content-Transfer-Encoding: %s\r\n", '7bit');
                    $bodies[] = mb_convert_encoding($body['data'], 'ISO-2022-JP-MS', 'UTF-8');
                }
                else
                {
                    $bodies[] = sprintf('Content-Type: %s; charset=%s', $body['mime'], 'ISO-2022-JP');
                    $bodies[] = sprintf("Content-Transfer-Encoding: %s\r\n", 'base64');
                    $bodies[] = chunk_split(base64_encode(mb_convert_encoding($body['data'], 'ISO-2022-JP-MS', 'UTF-8')));
                }
            }

            $bodies[] = sprintf("--%s--\r\n", $boundary2);

            foreach ($this->_attachments as $attach)
            {
                if (!isset($attach['filename'], $attach['mime'])) continue;
                if (!isset($attach['data']) && !isset($attach['path']) && !is_file($attach['path'])) continue;

                $bodies[] = sprintf("--%s", $boundary1);
                $bodies[] = sprintf("Content-Type: %s;\r\n name=\"%s\"", $attach['mime'], $format_mimeheader($attach['filename']));
                $bodies[] = sprintf("Content-Transfer-Encoding: %s", 'base64');
                $bodies[] = sprintf("Content-Disposition: attachment;\r\n filename=\"%s\"\r\n", $format_mimeheader($attach['filename']));
                
                if (!isset($attach['data']))
                {
                    $bodies[] = chunk_split(base64_encode(file_get_contents($attach['path'])));
                }
                else
                {
                    $bodies[] = chunk_split(base64_encode($attach['data']));
                }
            }

            $bodies[] = sprintf("--%s--\r\n", $boundary1);
        }
        
        return join("\r\n", $headers) . "\r\n\r\n" . join("\r\n", $bodies) . "\r\n.";
    }

    /**
     * Return all bodies
     * 
     * @return array
     */
    protected function _getBodies()
    {
        $this->parseBody();
        return $this->_bodies;
    }

    /**
     * Return body (text/plain on a priority bases)
     * 
     * @return array
     */
    protected function _getBody()
    {   
        $this->parseBody();

        if (empty($this->_bodies))
        {
            return "";
        }
        foreach ($this->_bodies as $body)
        {
            if ($body['mime'] == 'text/plain')
            {
                return $body;
            }
        }

        reset($this->_bodies);
        return current($this->_bodies);
    }

    /**
     * Set body data (as plain text)
     * 
     * @param string $body 
     * @return void
     */
    protected function _setBody($body)
    {
        $index = count($this->_bodies);
        for ($i=0; $i < count($this->_bodies); $i++)
        {
            if ($this->_bodies[$i]['mime'] == 'text/plain') { $index = $i; }
        }
        $this->_bodies[$index] = array('mime' => 'text/plain', 'data' => $body);
    }

    /**
     * Set body data (as a html text) 
     * 
     * @param string $body 
     * @return void
     */
    protected function _setHtmlBody($body)
    {
        $index = count($this->_bodies);
        for ($i=0; $i < count($this->_bodies); $i++)
        {
            if ($this->_bodies[$i]['mime'] == 'text/html') { $index = $i; }
        }
        $this->_bodies[$index] = array('mime' => 'text/html', 'data' => $body);
    }

    /**
     * Set attachement 
     * 
     * @param array $attach attachment data (filename, mime, [data], [path])
     * @return void
     */
    protected function _setAttachment($attach)
    {
        if (!is_array($attach)) return;
        if (isset($attach['filename'], $attach['path'], $attach['mime']))
        {
            $this->_attachments[] = $attach; 
        }
    }

    /**
     * Has attachments
     * 
     * @return  bool
     */
    public function hasAttachments()
    {
        return !empty($this->_attachments);
    }

    /**
     * Return all attachments
     * 
     * @return array
     */
    protected function _getAttachments()
    {
        $this->parseBody();
        return $this->_attachments;
    }

    /**
     * Return all headers
     * 
     * @return array
     */
    protected function _getHeaders()
    {
        return $this->_headers;
    }

    /**
     * Normalize encoded words  (via. RFC2047)
     * 
     * @param string $words 
     * @return string decoded string
     */
    private function _normalizeEncodedWords($words)
    {
        $words = trim($words);
        $charset = "";
        $encoding = "";
        if (preg_match('/=\?(.+?)\?(B|Q)\?(.+?)\?=/i', $words, $m))
        {
            $charset = $m[1];
            $encoding = strtoupper($m[2]);
            if (strtolower($charset) == 'iso-2022-jp')
            {
                $words = preg_replace('/\?ISO-2022-JP\?/i', '?ISO-2022-JP-MS?', $words);
            }
        }
        if ($encoding != "") { $words = mb_decode_mimeheader($words); }
        return $words;
    }

    /**
     * Normalize Datatime fields (via. RFC5322-3.3)
     * 
     * @param string $datetime 
     * @return int unixtimestamp
     */
    private function _normalizeDateTime($datetime)
    {
        if (!is_numeric($datetime))
        {
            $datetime = strtotime($datetime);
            if ($datetime === false) { return null; }
        }

        return $datetime;
    }

    /**
     * Normalize address (via. RFC5322-3.4)
     * 
     * @param string $address 
     * @return array
     */
    private function _normalizeAddress($address)
    {
        $ret = array('name'=>'','mail'=>'');
        if (is_array($address))
        {
            if (1 < count($address))
            {
                $ret['name'] = $this->_normalizeEncodedWords(isset($address['name']) ? $address['name'] : array_shift($address));
                $ret['mail'] = isset($address['mail']) ? $address['mail'] : array_shift($address);
            }
            else if (count($address) == 1) { $address = $address[0]; }
        }
        if (is_string($address))
        {
            if (preg_match('/^(.+?)[\s\t]*\<(.*?)\>$/', trim($address), $m))
            {
                $ret['name'] = $this->_normalizeEncodedWords(trim($m[1], "\r\n\s\t\""));
                $ret['mail'] = $m[2];
            }
            else if (preg_match('/^\<(.+?)\>$/', trim($address), $m))
            {
                $ret['mail'] = $m[1];
            }
            else if (preg_match(self::REGEXP_EMAIL, trim($address)))
            {
                $ret['mail'] = trim($address);
            }
            else
            {
                $ret['name'] = trim($address);
            }
        }

        return $ret;
    }

    /**
     * _Normalize addresses (via. RFC5322-3.4)
     * 
     * @param string||array $addresses 
     * @return array
     */
    private function _normalizeAddresses($addresses)
    {
        if (is_string($addresses)) { $addresses = explode(',', $addresses); }
        if (!is_array($addresses)) { return array(); }
        $ret = array();
        if (isset($addresses['name'], $addresses['mail']))
        {
            $ret[] = $this->_normalizeAddress($addresses);
        }
        else
        {
            foreach ($addresses as $address)
            {
                $ret[] = $this->_normalizeAddress($address);
            }
        }
        return $ret;
    }

    /**
     * Normalize content-type header
     * 
     * @param string $content_type 
     * @return array
     */
    private function _normalizeContentType($content_type)
    {
        $ret = array();
        $sections = explode(';', $content_type);
        foreach ($sections as $section)
        {
            if (preg_match('/^\"?charset=(.+?)(\(.+)?\"?$/', trim($section), $m))
            {
                $charset = trim($m[1], "\r\n\s\t\"");
                if (strtolower($charset) == 'iso-2022-jp') { $charset = 'ISO-2022-JP-MS'; }
                $ret['charset'] = $charset;
                continue;
            }
            if (preg_match('/^\"?boundary=(.+?)(\(.+)?\"?$/', trim($section), $m))
            {
                $ret['boundary'] = trim($m[1], "\r\n\s\t\"");
                continue;
            }
            if (preg_match('/^\"?name=(.+?)(\(.+)?\"?$/', trim($section), $m))
            {
                $ret['name'] = $this->_normalizeEncodedWords(trim($m[1], "\r\n\t\s\""));
                continue;
            }
            if (preg_match('/^(.+?)\/(.+?)$/', trim($section), $m))
            {
                $ret['mime'] = trim($m[1]) . '/' . trim($m[2], "\r\n\s\t\"");
                continue;
            }
        }
        return $ret;
    }

    /**
     * Normalize Content-Disposition  header (via. RFC2183)
     * 
     * @param string $disposition 
     * @return array
     */
    private function _normalizeContentDisposition($disposition)
    {
        $ret = array('type' => '');
        $sections = explode(';', $disposition);
        foreach ($sections as $section)
        {
            if (strpos($section, '=') === false) { $ret['type'] = trim($section); continue;}
            if (preg_match('/^\"?filename=(.+?)(\(.+)?\"?$/', trim($section), $m))
            {
                $ret['filename'] = $this->_normalizeEncodedWords(trim($m[1], "\r\n\t\s\""));
                continue;
            }
        }
        return $ret;
    }

    /**
     * Set MIME-Version (via. RFC2045)
     * 
     * @param string $mime_version 
     * @return void
     */
    private function _setMimeVersion($mime_version)
    {
        $this->_headers['mime_version'] = $mime_version;
    }

    /**
     * Return MIME-Version (via. RFC2045)
     * 
     * @return string
     */
    private function _getMimeVersion()
    {
        return isset($this->_headers['mime_version']) ? $this->_headers['mime_version'] : '';
    }

    /**
     * Set Content-Type Field (via. RFC2045)
     * 
     * @param string $content_type 
     * @return void
     */
    private function _setContentType($content_type)
    {
        $this->_headers['content_type'] = $this->_normalizeContentType($content_type);
    }

    /**
     * Return Content-Type Field (via. RFC2045)
     * 
     * @return array
     */
    private function _getContentType()
    {
        return isset($this->_headers['content_type']) ? $this->_headers['content_type'] : array('mime' => 'text/plain');
    }

    /**
     * Set Content-Transfer-Encoding Field (via. RFC2045)
     * 
     * @param mixed $content_transfer_encoding 
     * @return void
     */
    private function _setContentTransferEncoding($content_transfer_encoding)
    {
        $this->_headers['content_transfer_encoding'] = trim($content_transfer_encoding);
    }

    /**
     * Return Content-Transfer-Encoding Field (via. RFC2045)
     * 
     * @return string
     */
    private function _getContentTransferEncoding()
    {
        return isset($this->_headers['content_transfer_encoding']) ? $this->_headers['content_transfer_encoding'] : '';
    }

    /**
     * Set Content-ID Field (RFC2045)
     * 
     * @param mixed $content_id 
     * @return void
     */
    private function _setContentId($content_id)
    {
        $this->_headers['content_id'] = $content_id;
    }

    /**
     * Return Content-ID Field (RFC2045)
     * 
     * @return string
     */
    private function _getContentId()
    {
        return isset($this->_headers['content_id']) ? $this->_headers['content_id'] : '';
    }

    /**
     * Set Content-Description (via. RFC2045)
     * 
     * @param mixed $content_description 
     * @return void
     */
    private function _setContentDescription($content_description)
    {
        $this->_headers['content_description'] = $this->_normalizeEncodedWords($content_description);
    }

    /**
     * Return Content-Description (via. RFC2045)
     * 
     * @return array
     */
    private function _getContentDescription()
    {
        return isset($this->_headers['content_description']) ? $this->_headers['content_description'] : array();
    }

    /**
     * Set Date Field (RFC5322)
     * 
     * @param mixed $datetime_string 
     * @return void
     */
    private function _setDate($datetime_string)
    {
        $this->_headers['date'] = $this->_normalizeDateTime($datetime_string);
    }

    /**
     * Return Date Field (RFC5322)
     * 
     * @return int unixtimestamp
     */
    private function _getDate()
    {
        return isset($this->_headers['date']) ? $this->_headers['date'] : "";
    }

    /**
     * Set Sender Field (via. RFC5322)
     * 
     * @param mixed $sender 
     * @return void
     */
    private function _setSender($sender)
    {
        $this->_headers['sender'] = $this->_normalizeAddress($sender);
    }

    /**
     * Return Sender Field (via. RFC5322)
     * 
     * @return void
     */
    private function _getSender()
    {
        return isset($this->_headers['sender']) ? $this->_headers['sender'] : "";
    }

    private function _setFrom($from)
    {
        if (!isset($this->_headers['from'])) $this->_headers['from'] = array();
        $this->_headers['from'] = array_merge($this->_headers['from'], $this->_normalizeAddresses($from));
    }

    private function _getFrom()
    {
        return isset($this->_headers['from']) ? $this->_headers['from'] : array();
    }

    private function _setReplyTo($reply_to)
    {
        if (!isset($this->_headers['reply_to'])) $this->_headers['reply_to'] = array();
        $this->_headers['reply_to'] = array_merge($this->_headers['reply_to'], $this->_normalizeAddresses($reply_to));
    }

    private function _getReplyTo()
    {
        return isset($this->_headers['reply_to']) ? $this->_headers['reply_to'] : array();
    }
    
    // ==================================
    //  3.6.3 Destination Address Fields
    // ==================================
    private function _setTo($addresses)
    {
        if (!isset($this->_headers['to'])) $this->_headers['to'] = array();
        $this->_headers['to'] = array_merge($this->_headers['to'], $this->_normalizeAddresses($addresses));
    }

    private function _getTo()
    {
        return isset($this->_headers['to']) ? $this->_headers['to'] : array();
    }

    private function _setCc($addresses)
    {
        if (!isset($this->_headers['cc'])) $this->_headers['cc'] = array();
        $this->_headers['cc'] = array_merge($this->_headers['cc'], $this->_normalizeAddresses($addresses));
    }

    private function _getCc()
    {
        return isset($this->_headers['cc']) ? $this->_headers['cc'] : array();
    }

    private function _setBcc($addresses)
    {
        if (!isset($this->_headers['bcc'])) $this->_headers['bcc'] = array();
        $this->_headers['bcc'] = array_merge($this->_headers['bcc'], $this->_normalizeAddresses($addresses));
    }

    private function _getBcc()
    {
        return isset($this->_headers['bcc']) ? $this->_headers['bcc'] : array();
    }

    // =============================
    //  3.6.4 Identification Fields
    // =============================
    private function _setMessageId($message_id = null)
    {
        if ($message_id === null)
        {
            $host = $this->getOption('host') ? $this->getOption('host') : get_uname('n');
            $message_id = md5(time() . uniqid(rand(), true)) . '@' . $host;
        }
        $this->_headers['message_id'] = $message_id;
    }

    private function _getMessageId()
    {
        return isset($this->_headers['message_id']) ? $this->_headers['message_id'] : "";
    }

    private function _setInReplyTo($in_reply_to)
    {
        if (!isset($this->_headers['in_reply_to'])) $this->_headers['in_reply_to'] = array();
        $this->_headers['in_reply_to'] = array_merge($this->_headers['in_reply_to'], $this->_normalizeAddresses($in_reply_to));
    }

    private function _getInReplyTo()
    {
        return isset($this->_headers['in_reply_to']) ? $this->_headers['in_reply_to'] : "";
    }

    private function _setReferences($references)
    {
        if (!isset($this->_headers['references'])) $this->_headers['references'] = array();
        if (is_string($references)) { $references = explode(',', $references); }
        $this->_headers['references'] = array_merge($this->_headers['references'], (array)$references);
    }

    private function _getReferences()
    {
        return isset($this->_headers['references']) ? $this->_headers['references'] : array();
    }

    // ==========================
    //  3.6.5 Information Fields
    // ==========================
    private function _setSubject($subject)
    {
        $this->_headers['subject'] = $this->_normalizeEncodedWords($subject);
    }

    private function _getSubject()
    {
        return isset($this->_headers['subject']) ? $this->_headers['subject'] : '';
    }

    private function _setComments($comments)
    {
        $this->_headers['comments'] = $this->_normalizeEncodedWords($subject);
    }

    private function _getComments()
    {
        return isset($this->_headers['comments']) ? $this->_headers['comments'] : '';
    }

    private function _setKeywords($keywords)
    {
        if (!isset($this->_headers['keywords'])) $this->_headers['keywords'] = array();
        $this->_headers['keywords'] = array_merge($this->_headers['keywords'], (array)$keywords);
    }

    private function _getKeywords()
    {
        return isset($this->_headers['keywords']) ? $this->_headers['keywords'] : array();
    }

    /**
     * Set Resent-Date Field (via. RFC5322)
     * 
     * @param mixed $resent_date
     * @return void
     */
    private function _setResentDate($resent_date)
    {
        $this->_headers['resent_date'] = $this->_normalizeDateTime($resent_date);
    }

    /**
     * Return Resent-Date Field (via. RFC5322)
     * 
     * @return void
     */
    private function _getResentDate()
    {
        return isset($this->_headers['resent_date']) ? $this->_headers['resent_date'] : '';
    }

    /**
     * Set Resent-From Field (via. RFC5322)
     * 
     * @param mixed $resent_from
     * @return void
     */
    private function _setResentFrom($resent_from)
    {
        if (!isset($this->_headers['resent_from'])) $this->_headers['resent_from'] = array();
        $this->_headers['resent_from'] = array_merge($this->_headers['resent_from'], $this->_normalizeAddresses($resent_from));
    }

    /**
     * Return Resent-From Field (via. RFC5322)
     * 
     * @return array
     */
    private function _getResentFrom()
    {
        return isset($this->_headers['resent_from']) ? $this->_headers['resent_from'] : array();
    }

    /**
     * Set Resent-Sender Field (via. RFC5322)
     * 
     * @param mixed $resent_sender
     * @return void
     */
    private function _setResentSender($resent_sender)
    {
        $this->_headers['resent_sender'] = $this->_normalizeAddress($resent_sender);
    }

    /**
     * Return Resent-Sender Field (via. RFC5322)
     * 
     * @return string
     */
    private function _getResentSender()
    {
        return isset($this->_headers['resent_sender']) ? $this->_headers['resent_sender'] : '';
    }

    /**
     * Set Resent-To Field (via. RFC5322)
     * 
     * @param mixed $resent_to
     * @return void
     */
    private function _setResentTo($resent_to)
    {
        if (!isset($this->_headers['resent_to'])) $this->_headers['resent_to'] = array();
        $this->_headers['resent_to'] = array_merge($this->_headers['resent_to'], $this->_normalizeAddresses($resent_to));
    }

    /**
     * Return Resent-To Field (via. RFC5322)
     * 
     * @return array
     */
    private function _getResentTo()
    {
        return isset($this->_headers['resent_to']) ? $this->_headers['resent_to'] : array();
    }

    /**
     * Set Resent-Cc  Field (via. RFC5322)
     * 
     * @param mixed $resent_cc 
     * @return void
     */
    private function _setResentCc($resent_cc)
    {
        if (!isset($this->_headers['resent_cc'])) $this->_headers['resent_cc'] = array();
        $this->_headers['resent_cc'] = array_merge($this->_headers['resent_cc'], $this->_normalizeAddresses($resent_cc));
    }

    /**
     * Return Resent-Cc  Field (via. RFC5322)
     * 
     * @return array
     */
    private function _getResentCc()
    {
        return isset($this->_headers['resent_cc']) ? $this->_headers['resent_cc'] : array();
    }

    /**
     * Set Resent-Bcc  Field (via. RFC5322)
     * 
     * @param mixed $resent_bcc 
     * @return void
     */
    private function _setResentBcc($resent_bcc)
    {
        if (!isset($this->_headers['resent_bcc'])) $this->_headers['resent_bcc'] = array();
        $this->_headers['resent_bcc'] = array_merge($this->_headers['resent_bcc'], $this->_normalizeAddresses($resent_bcc));
    }

    /**
     * Return Resent-Bcc  (via.RFC5322)
     * 
     * @return array
     */
    private function _getResentBcc()
    {
        return isset($this->_headers['resent_bcc']) ? $this->_headers['resent_bcc'] : array();
    }

    /**
     * Set Resent-Message-ID (via. RFC5322) 
     * 
     * @param mixed $msg_id 
     * @return void
     */
    private function _setResentMessageId($msg_id)
    {
        $this->_headers['resent_message_id'] = $msg_id;
    }

    /**
     * Return ResentMessage-ID (via. RFC5322) 
     * 
     * @return string
     */
    private function _getResentMessageId()
    {
        return isset($this->_headers['resent_message_id']) ? $this->_headers['resent_message_id'] : '';
    }

    /**
     * Set Return-Path Field (via. RFC5322 3.6.7)
     * 
     * @param mixed $return_path 
     * @return void
     */
    private function _setReturnPath($return_path)
    {
        $this->_headers['return_path'] = $this->_normalizeAddress($return_path);
    }

    /**
     * Return Return-Path Field (via. RFC5322 3.6.7)
     * 
     * @return string
     */
    private function _getReturnPath()
    {
        return isset($this->_headers['return_path']) ? $this->_headers['return_path'] : '';
    }

    /**
     * Set Received Field (via. RFC5322 3.6.7) 
     * 
     * @param mixed $received 
     * @return void
     */
    private function _setReceived($received)
    {
        if (!isset($this->_headers['received'])) $this->_headers['received'] = array();
        if (is_string($received)) { $received = array($received); }
        foreach ($received as $data)
        {
            $this->_headers['received'][] = $data;
        }
    }

    /**
     * Return Received Field (via. RFC5322 3.6.7) 
     * 
     * @return array
     */
    private function _getReceived()
    {
        return isset($this->_headers['received']) ? $this->_headers['received'] : array();
    }

    /**
     * Set OptionalField
     * 
     * @param string $name 
     * @param mixed $value 
     * @return void
     */
    private function _setOptionalField($name, $value)
    {
        $key = strtolower(trim($name));
        $this->_headers[$key] = $value;
    }

    /**
     * Return OptionalField
     * 
     * @param string $name 
     * @return mixed
     */
    private function _getOptionalField($name)
    {
        $key = strtolower(trim($name));
        return isset($this->_headers[$key]) ? $this->_headers[$key] : "";
    }


    /**
     * Magic method (getter)
     * 
     * @param string $name 
     * @return void
     */
    public  function __get($name)
    {
        $name = join('', array_map('ucfirst', explode('-', strtolower($name))));
        $name = join('', array_map('ucfirst', explode('_', strtolower($name))));
        $method = '_get' . $name;
        if (!method_exists($this, $method))
        {
            return $this->_getOptionalField($name);
        }
        return $this->{$method}();
    }

    /**
     * Magic method (setter)
     * 
     * @param string $name 
     * @param mixed $value 
     * @return void
     */
    public  function __set($name, $value)
    {
        $capital = join('', array_map('ucfirst', explode('-', strtolower($name))));
        $capital = join('', array_map('ucfirst', explode('_', strtolower($capital))));
        $method = '_set' . $capital;
        if (!method_exists($this, $method))
        {
            $this->_setOptionalField($name, $value);
        }
        else
        {
            $this->{$method}($value);
        }
    }
}
