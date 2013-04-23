<?php

require_once '../lib/SplClassLoader.php';
require_once '../lib/ApiException.php';

$c1 = new SplClassLoader("RestService", "../extlib/php-rest-service/lib");
$c1->register();
$c2 = new SplClassLoader("OAuth", "../extlib/php-oauth-lib-rs/lib");
$c2->register();

use \RestService\Utils\Config as Config;
use \RestService\Http\HttpRequest as HttpRequest;
use \RestService\Http\HttpResponse as HttpResponse;
use \RestService\Http\IncomingHttpRequest as IncomingHttpRequest;
use \RestService\Utils\Logger as Logger;
use \OAuth\RemoteResourceServer as RemoteResourceServer;

$logger = NULL;
$request = NULL;
$response = NULL;

try {
    $config = new Config(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "rs.ini");
    $logger = new Logger($config->getSectionValue('Log', 'logLevel'), $config->getValue('serviceName'), $config->getSectionValue('Log', 'logFile'), $config->getSectionValue('Log', 'logMail', FALSE));

    $grades = json_decode(file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "grades.json"), TRUE);

    $rs = new RemoteResourceServer($config->getSectionValues("OAuth"));
    $introspection = $rs->verifyAndHandleRequest();

    $request = HttpRequest::fromIncomingHttpRequest(new IncomingHttpRequest());

    $response = new HttpResponse();
    $response->setHeader("Content-Type", "application/json");

    $request->matchRest("GET", "/grades/", function() use ($introspection, $response, $grades) {
        $introspection->requireScope("grades");
        $introspection->requireEntitlement("urn:x-oauth:entitlement:administration");
        $studentList = array();
        foreach (array_keys($grades) as $k) {
            array_push($studentList, array("id" => $k));
        }
        $response->setContent(json_encode($studentList));
    });

    $request->matchRest("GET", "/grades/:id", function($id) use ($introspection, $response, $grades) {
        $introspection->requireScope("grades");
        $uid = $introspection->getSub();
        if (!$introspection->hasEntitlement("urn:x-oauth:entitlement:administration") && $id !== $uid[0] && "@me" !== $id) {
            throw new ApiException("forbidden", "resource does not belong to authenticated user");
        }
        if ("@me" === $id) {
            $id = $uid;
        }
        if (!array_key_exists($id, $grades)) {
            throw new ApiException("not_found", "student does not have any grades");
        }
        $response->setContent(json_encode($grades[$id]));
    });

    $request->matchRestDefault(function($methodMatch, $patternMatch) use ($request, $response) {
        if (in_array($request->getRequestMethod(), $methodMatch)) {
            if (!$patternMatch) {
                throw new ApiException("not_found", "resource not found");
            }
        } else {
            throw new ApiException("method_not_allowed", "request method not allowed");
        }
    });

} catch (ApiException $e) {
    $response = new HttpResponse();
    $response->setStatusCode($e->getResponseCode());
    $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
    if (NULL !== $logger) {
        $logger->logFatal($e->getLogMessage(TRUE) . PHP_EOL . $request . PHP_EOL . $response);
    }
} catch (Exception $e) {
    $response = new HttpResponse();
    $response->setStatusCode(500);
    $response->setContent(json_encode(array("error" => "internal_server_error", "error_description" => $e->getMessage())));
    if (NULL !== $logger) {
        $logger->logFatal($e->getMessage() . PHP_EOL . $request . PHP_EOL . $response);
    }
}

if (NULL !== $logger) {
    $logger->logDebug($request);
}
if (NULL !== $logger) {
    $logger->logDebug($response);
}
if (NULL !== $response) {
    $response->sendResponse();
}
