<?php

namespace Base\Estate\Exception;

/**
 * The official index could not be read (the service did not answer, a limit
 * reached, an answer not understood): the revision screen falls back on the
 * value typed by hand.
 */
final class IndexUnavailableException extends \RuntimeException
{
}
