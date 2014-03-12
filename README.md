lwmail
======

Light Weight Mail

This library is used to perform the e-mail transmission easily in PHP.
Protocol currently supported are as follows.
 - POP3 (+SSL)
 - SMTP (+SSL,TLS and STARTTLS)
 - SMTP-AUTH (PLAIN, LOGIN, CRAM-MD5)

It is made for use in simple and lightweight in a Japanese environment.


Install
--------

  `composer.phar install simpraight/lwmail`

  Then, it describes the following file.

    <?php
    require 'vendor/autoload.php';


Usage
------

###POP3 Sample code

    <?php
    use \LWMail\Protocol\POP3;
    use \LWMail\MailMessage;

    $pop3 = new POP3(array(
        'protocol' => 'ssl',
        'host' => 'smtp.gmail.com',
        'port' => 987,
        'pop3.user' => 'xxxxxxx',
        'pop3.pass' => 'xxxxxxx',
    ));

    $mails = array();
    foreach ($pop3->getList() as $msg)
    {
        $mails[] = MailMessage::parse($pop3->getRetr($msg['id']));
    }


###SMTP Sample code

    use \LWMail\Protocol\SMTP;
    use \LWMail\MailMessage;

    $message = MailMessage::create();
    $message->from = 'Myname <me@sample.com>';  // or array('name' => 'Myname', 'mail' => 'me@sample.com');
    $message->to = 'Yourname <you@sample,com>';
    $message->cc = 'someone1@sample.com';
    $message->cc = 'someone2@sample.com';  // Add to CC, not overwrite.
    $message->subject = "Mail Subject";
    $message->body = "Message \n Body";
    $message->attachment = array('filename' => 'test1.pdf', 'path' => '/tmp/test1.pdf', 'mime' => 'application/pdf');
    $message->attachment = array('filename' => 'test2.pdf', 'path' => '/tmp/test2.pdf', 'mime' => 'application/pdf'); // Add attachment.

    $smtp = new SMTP(array(
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'smtp.auth' => true,
        'smtp.user' => 'xxxxxx',
        'smtp.pass' => 'xxxxxx',
        'smtp.starttls' => true,
    ));

    $smtp->send($message);


License
--------

MIT License
