<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace PulseMail;

use Swift_Message;
use Swift_Image;
use Swift_Plugins_DecoratorPlugin;
use Swift_Attachment;
use Swift_SwiftException;
use Swift_SmtpTransport;
use Swift_SendmailTransport;
use Swift_Mailer;

class Sender
{
    /**
     * Send mail under SMTP server.
     */
    const METHOD_SMTP = 2;

    /**
     * Disable mail, will return immediately after calling send method.
     */
    const METHOD_DISABLE = 3;

    public static function getMultiple( Array $configs )
    {
        $config = array();
        foreach ($configs as $config_key) {
            if( defined($config_key) ){
                $config[$config_key] = constant($config_key);
            }else{
                $config[$config_key] = false;
            }
        }
        return $config;
    }

    public static function send(
        $templateHtml,
        $subject,
        $to,
        $toName = null,
        $from = null,
        $fromName = null,
        $fileAttachment = null,
        $mode_smtp = null,
        $die = false,
        $bcc = null,
        $replyTo = null,
        $replyToName = null
    ) {

        $configuration = self::getMultiple(
            [
                'PULSE_SITE_EMAIL',
                'PULSE_MAIL_METHOD',
                'PULSE_MAIL_SERVER',
                'PULSE_MAIL_USER',
                'PULSE_MAIL_PASSWD',
                'PULSE_SITE_NAME',
                'PULSE_MAIL_SMTP_ENCRYPTION',
                'PULSE_MAIL_SMTP_PORT'
            ]
        );

        // Returns immediately if emails are deactivated
        if ($configuration['PULSE_MAIL_METHOD'] == self::METHOD_DISABLE) {
            return true;
        }

        if (!isset($configuration['PULSE_MAIL_SMTP_ENCRYPTION']) ||
            self::strtolower($configuration['PULSE_MAIL_SMTP_ENCRYPTION']) === 'off'
        ) {
            $configuration['PULSE_MAIL_SMTP_ENCRYPTION'] = false;
        }

        if (!isset($configuration['PULSE_MAIL_SMTP_PORT'])) {
            $configuration['PULSE_MAIL_SMTP_PORT'] = 'default';
        }

        /*
         * Sending an e-mail can be of vital importance for the merchant, when his password
         * is lost for example, so we must not die but do our best to send the e-mail.
         */
        if (!isset($from)) {
            $from = $configuration['PULSE_SITE_EMAIL'];
        }

        // $from_name is not that important, no need to die if it is not valid
        if (!isset($fromName)) {
            $fromName = $configuration['PULSE_SITE_NAME'];
        }

        /* Construct multiple recipients list if needed */
        $message = new Swift_Message();

        if (is_array($to) && isset($to)) {
            foreach ($to as $key => $addr) {
                $addr = trim($addr);

                if (is_array($toName) && isset($toName[$key])) {
                    $addrName = $toName[$key];
                } else {
                    $addrName = $toName;
                }

                $addrName = ($addrName == null || $addrName == $addr) ?
                          '' :
                          self::mimeEncode($addrName);
                $message->addTo(self::toPunycode($addr), $addrName);
            }
            $toPlugin = $to[0];
        } else {
            /* Simple recipient, one address */
            $toPlugin = $to;
            $toName = (($toName == null || $toName == $to) ? '' : self::mimeEncode($toName));
            $message->addTo(self::toPunycode($to), $toName);
        }

        if (isset($bcc) && is_array($bcc)) {
            foreach ($bcc as $addr) {
                $addr = trim($addr);
                $message->addBcc(self::toPunycode($addr));
            }
        } elseif (isset($bcc)) {
            $message->addBcc(self::toPunycode($bcc));
        }

        try {
            /* Connect with the appropriate configuration */
            if ($configuration['PULSE_MAIL_METHOD'] == self::METHOD_SMTP) {
                if (empty($configuration['PULSE_MAIL_SERVER']) || empty($configuration['PULSE_MAIL_SMTP_PORT'])) {
                    self::dieOrLog($die, 'Error: invalid SMTP server or SMTP port');
                    return false;
                }

                $connection = (new Swift_SmtpTransport(
                    $configuration['PULSE_MAIL_SERVER'],
                    $configuration['PULSE_MAIL_SMTP_PORT'],
                    $configuration['PULSE_MAIL_SMTP_ENCRYPTION']
                ))
                    ->setUsername($configuration['PULSE_MAIL_USER'])
                    ->setPassword($configuration['PULSE_MAIL_PASSWD']);
            } else {
                $connection = new Swift_SendmailTransport();
            }

            if (!$connection) {
                return false;
            }

            $swift = new Swift_Mailer($connection);

            /* Create mail and attach differents parts */
            $message->setSubject($subject);

            $message->setCharset('utf-8');

            /* Set Message-ID - getmypid() is blocked on some hosting */
            $message->setId(Sender::generateId());

            if (!($replyTo)) {
                $replyTo = $from;
            }

            if (isset($replyTo) && $replyTo) {
                $message->setReplyTo($replyTo, ($replyToName !== '' ? $replyToName : null));
            }

            $message->addPart($templateHtml, 'text/html', 'utf-8');

            if ($fileAttachment && !empty($fileAttachment)) {
                foreach ($fileAttachment as $attachment) {
                    $message->attach( \Swift_Attachment::fromPath($attachment) );
                }
            }

            /* Send mail */
            $message->setFrom([$from => $fromName]);
            $send = $swift->send($message);

            return $send;
        } catch (Swift_SwiftException $e) {
            self::dieOrLog($die, $e->getMessage());
            return false;
        }
    }

    /* Rewrite of Swift_Message::generateId() without getmypid() */
    protected static function generateId($idstring = null)
    {
        $midparams = [
            'utctime' => gmstrftime('%Y%m%d%H%M%S'),
            'randint' => mt_rand(),
            'customstr' => (preg_match('/^(?<!\\.)[a-z0-9\\.]+(?!\\.)$/iD', $idstring) ? $idstring : 'swift'),
            'hostname' => !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : php_uname('n'),
        ];

        return vsprintf('%s.%d.%s@%s', $midparams);
    }

    /**
     * Check if a multibyte character set is used for the data.
     *
     * @param string $data Data
     *
     * @return bool Whether the string uses a multibyte character set
     */
    public static function isMultibyte($data)
    {
        $length = self::strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            if (ord(($data[$i])) > 128) {
                return true;
            }
        }

        return false;
    }

    /**
     * MIME encode the string.
     *
     * @param string $string The string to encode
     * @param string $charset The character set to use
     * @param string $newline The newline character(s)
     *
     * @return mixed|string MIME encoded string
     */
    public static function mimeEncode($string, $charset = 'UTF-8', $newline = "\r\n")
    {
        if (!self::isMultibyte($string) && self::strlen($string) < 75) {
            return $string;
        }

        $charset = self::strtoupper($charset);
        $start = '=?' . $charset . '?B?';
        $end = '?=';
        $sep = $end . $newline . ' ' . $start;
        $length = 75 - self::strlen($start) - self::strlen($end);
        $length = $length - ($length % 4);

        if ($charset === 'UTF-8') {
            $parts = [];
            $maxchars = floor(($length * 3) / 4);
            $stringLength = self::strlen($string);

            while ($stringLength > $maxchars) {
                $i = (int) $maxchars;
                $result = ord($string[$i]);

                while ($result >= 128 && $result <= 191) {
                    $result = ord($string[--$i]);
                }

                $parts[] = base64_encode(self::substr($string, 0, $i));
                $string = self::substr($string, $i);
                $stringLength = self::strlen($string);
            }

            $parts[] = base64_encode($string);
            $string = implode($sep, $parts);
        } else {
            $string = chunk_split(base64_encode($string), $length, $sep);
            $string = preg_replace('/' . preg_quote($sep) . '$/', '', $string);
        }

        return $start . $string . $end;
    }

    public static function toPunycode($to)
    {
        $address = explode('@', $to);
        if (empty($address[0]) || empty($address[1])) {
            return $to;
        }

        if (defined('INTL_IDNA_VARIANT_UTS46')) {
            return $address[0] . '@' . idn_to_ascii($address[1], 0, INTL_IDNA_VARIANT_UTS46);
        }

        /*
         * INTL_IDNA_VARIANT_2003 const will be removed in PHP 8.
         * See https://wiki.php.net/rfc/deprecate-and-remove-intl_idna_variant_2003
         */
        if (defined('INTL_IDNA_VARIANT_2003')) {
            return $address[0] . '@' . idn_to_ascii($address[1], 0, INTL_IDNA_VARIANT_2003);
        }

        return $address[0] . '@' . idn_to_ascii($address[1]);
    }

    public static function substr($str, $start, $length = false, $encoding = 'utf-8')
    {
        if (is_array($str)) {
            return false;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($str, (int) $start, ($length === false ? self::strlen($str) : (int) $length), $encoding);
        }

        return substr($str, $start, ($length === false ? self::strlen($str) : (int) $length));
    }

    public static function strtoupper($str)
    {
        if (is_array($str)) {
            return false;
        }
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($str, 'utf-8');
        }

        return strtoupper($str);
    }

    public static function strlen($str, $encoding = 'UTF-8')
    {
        if (is_array($str)) {
            return false;
        }
        $str = html_entity_decode($str, ENT_COMPAT, 'UTF-8');
        if (function_exists('mb_strlen')) {
            return mb_strlen($str, $encoding);
        }

        return strlen($str);
    }

    public static function strtolower($str)
    {
        if (is_array($str)) {
            return false;
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($str, 'utf-8');
        }

        return strtolower($str);
    }

    /**
     * Generic function to dieOrLog with translations.
     *
     * @param bool $die Should die
     * @param string $message Message
     * @param array $templates Templates list
     * @param string $domain Translation domain
     */
    protected static function dieOrLog(
        $die,
        $message
    ) {
        echo($message);
        if($die){
          die();
        }else{

        }

    }
}
