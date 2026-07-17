<?php
if (!defined('_PS_VERSION_')) { exit; }
class HesabfaRateLimitException extends Exception
{
    protected $retryAfter;
    public function __construct($message, $retryAfter = 60)
    {
        parent::__construct($message, 429);
        $this->retryAfter = max(1, (int) $retryAfter);
    }
    public function getRetryAfter() { return $this->retryAfter; }
}
