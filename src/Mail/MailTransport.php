<?php
declare(strict_types=1);

/**
 * How a queued e-mail leaves StreamOrg: a provider's API. send() gets an
 * email_outbox row plus "sender" (Mailer::sender()) and "unsubscribe" (the
 * one-click link for notification copies, or null).
 */
interface MailTransport
{
    /**
     * @return array{ok:bool, id?:?string, error?:string, permanent?:bool} permanent: the address refused it for good
     */
    public function send(array $message): array;
}
