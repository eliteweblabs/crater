<?php

namespace Crater\Support;

/**
 * @deprecated Use ReaveBrandColors::logoEmailUrl()
 */
class ReaveBrandLogo
{
    public static function emailHeaderUrl(): ?string
    {
        return ReaveBrandColors::logoEmailUrl();
    }
}
