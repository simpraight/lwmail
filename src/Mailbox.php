<?php

namespace LWMail;

class Mailbox
{
    private $indexes = array();
    private $total = 0;
    private $storage = null;

    static private $_instance = null;

    private function __construct()
    {
        $this->indexes = array();
        $this->storage = tmpfile();
        $this->total = 0;
    }

    public static function getInstance()
    {
        if (self::$_instance == null)
        {
            self::$_instance = new Mailbox();
        }

        return self::$_instance;
    }

    public static function add($raw_mail)
    {
        if ($raw_mail == null) return false;
        if (is_array($raw_mail)) $raw_mail = join(PHP_EOL, $raw_mail);
        if (!is_string($raw_mail)) return false;

        $box = self::getInstance();
        
        fseek($box->storage, $box->total);
        if (($written = @fwrite($box->storage, $raw_mail)) === false)
        {
            return false;
        }
        
        $index = array('offset' => $box->total, 'length' => $written, 'id' => count($box->indexes));
        $box->indexes[] = $index;
        $box->total += $written;

        return $index;
    }

    public static function retrieve($id)
    {
        $box = self::getInstance();
        $id = intval($id);
        if (!isset($box->indexes[$id]))
        {
            return null;
        }

        fseek($box->storage, $box->indexes[$id]['offset']);
        if (($mail = @fread($box->storage, $box->indexes[$id]['length'])) === false)
        {
            return false;
        }

        return MailMessage::parse($mail);
    }
}
