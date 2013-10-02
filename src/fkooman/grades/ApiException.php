<?php

namespace fkooman\grades;

class ApiException extends \Exception
{
    private $description;

    public function __construct($message, $description, $code = 0, Exception $previous = null)
    {
        $this->description = $description;
        parent::__construct($message, $code, $previous);
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getResponseCode()
    {
        switch ($this->message) {
            case "not_found":
                return 404;
            case "invalid_request":
                return 400;
            case "forbidden":
                return 403;
            default:
                return 400;
        }
    }

    public function getLogMessage($includeTrace = false)
    {
        $msg = 'Message    : ' . $this->getMessage() . PHP_EOL .
               'Description: ' . $this->getDescription() . PHP_EOL;
        if ($includeTrace) {
            $msg .= 'Trace      : ' . PHP_EOL . $this->getTraceAsString() . PHP_EOL;
        }

        return $msg;
    }

    public function getResponseAsArray()
    {
        return array(
            "code" => $this->getResponseCode(),
            "error" => $this->message,
            "error_description" => $this->description
        );
    }
}
