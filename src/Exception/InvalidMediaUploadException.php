<?php

declare(strict_types=1);

namespace Kmergen\MediaBundle\Exception;

/**
 * Thrown when an upload request is rejected (wrong file type, too large, invalid parameters).
 * The controller answers with 400 and the message.
 */
final class InvalidMediaUploadException extends \RuntimeException
{
}
