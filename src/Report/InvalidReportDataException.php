<?php
declare(strict_types=1);

namespace Pringuin\OpenDxpHealthBundle\Report;

use UnexpectedValueException;

/**
 * Thrown when a stored report cannot be restored, e.g. because it was written by an
 * incompatible bundle version or modified by hand.
 */
final class InvalidReportDataException extends UnexpectedValueException
{
}
