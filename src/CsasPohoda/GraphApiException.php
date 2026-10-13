<?php

declare(strict_types=1);

/**
 * This file is part of the CsasPohoda package
 *
 * https://github.com/Spoje-NET/csas-pohoda
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\CsasPohoda;

/**
 * Failed Microsoft Graph request; the code is the HTTP status.
 */
class GraphApiException extends \RuntimeException
{
    public function __construct(string $message, int $httpCode = 0, private readonly ?string $responseBody = null)
    {
        parent::__construct($message, $httpCode);
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
