<?php
declare(strict_types=1);

/**
 * An error whose message is written for the user and safe to show them.
 *
 * Controllers show the message of a UserError and nothing else: any other
 * exception (a PDOException is also a RuntimeException) is logged and
 * answered with a generic message, so database or file-system detail never
 * reaches the browser.
 */
final class UserError extends RuntimeException
{
}
