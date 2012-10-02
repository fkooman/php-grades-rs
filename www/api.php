<?php

$configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "rs.ini";
if(!file_exists($configFile) || !is_file($configFile) || !is_readable($configFile)) {
    throw new ConfigException("configuration file '$configFile' not found");
}
$configValues = parse_ini_file($configFile, TRUE);

require_once $configValues["phpOAuthPath"] . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "SplClassLoader.php";
$s = new SplClassLoader("Tuxed", $configValues["phpOAuthPath"] . DIRECTORY_SEPARATOR . "lib");
$s->register();

use \Tuxed\Config as Config;

$config = new Config($configFile);
$oauthConfig = new Config($config->getValue("phpOAuthPath") . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "oauth.ini");

use \Tuxed\Http\HttpRequest as HttpRequest;
use \Tuxed\Http\HttpResponse as HttpResponse;
use \Tuxed\Http\IncomingHttpRequest as IncomingHttpRequest;
use \Tuxed\OAuth\ResourceServer as ResourceServer;
use \Tuxed\OAuth\ResourceServerException as ResourceServerException;
use \Tuxed\OAuth\ApiException as ApiException;
use \Tuxed\Logger as Logger;

$logger = NULL;
$request = NULL;
$response = NULL;

try {

    $grades = json_decode(file_get_contents("../data/grades.json"), TRUE);

    $response = new HttpResponse();
    $request = HttpRequest::fromIncomingHttpRequest(new IncomingHttpRequest());

    $logger = new Logger($config->getValue('logLevel'), $config->getValue('serviceName'), $config->getValue('logFile'), $config->getValue('logMail', FALSE));
    $logger->logDebug($request);

    $oauthStorageBackend = '\\Tuxed\\OAuth\\' . $oauthConfig->getValue('storageBackend');
    $storage = new $oauthStorageBackend($oauthConfig);

    $rs = new ResourceServer($storage);

    $authorizationHeader = $request->getHeader("Authorization");
    if(NULL === $authorizationHeader) {
        throw new ResourceServerException("invalid_token", "no token provided");
    }
    $rs->verifyAuthorizationHeader($authorizationHeader);

    $response->setHeader("Content-Type", "application/json");

    $request->matchRest("GET", "/grades/", function() use ($rs, $response, $grades) {
        $rs->requireScope("grades");
        $rs->requireEntitlement("urn:vnd:grades:administration");
        $response->setContent(json_encode(array_keys($grades)));
    });

    $request->matchRest("GET", "/grades/:id", function($id) use ($rs, $response, $grades) {
        $rs->requireScope("grades");
        $uid = $rs->getAttribute("uid");
        if(!$rs->hasEntitlement("urn:vnd:grades:administration") && $id !== $uid[0] && "@me" !== $id) {
            throw new ApiException("forbidden", "resource does not belong to authenticated user");
        }
        if("@me" === $id) {
            $id = $uid[0];
        }
        if(!array_key_exists($id, $grades)) {
            throw new ApiException("not_found", "student does not have any grades");
        }
        $response->setContent(json_encode($grades[$id], JSON_FORCE_OBJECT));
    });

    $request->matchRestDefault(function($methodMatch, $patternMatch) use ($request, $response) {
        if(in_array($request->getRequestMethod(), $methodMatch)) {
            if(!$patternMatch) {
                throw new ApiException("not_found", "resource not found");
            }
        } else {
            throw new ApiException("method_not_allowed", "request method not allowed");
        }
    });

} catch (ResourceServerException $e) {
    $response = new HttpResponse();
    $response->setStatusCode($e->getResponseCode());
    $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server",error="%s",error_description="%s"', $e->getMessage(), $e->getDescription()));
    $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
    if(NULL !== $logger) {
        $logger->logFatal($e->getLogMessage(TRUE) . PHP_EOL . $request . PHP_EOL . $response);
    }
} catch (ApiException $e) {
    $response = new HttpResponse();
    $response->setStatusCode($e->getResponseCode());
    $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
    if(NULL !== $logger) {
        $logger->logFatal($e->getLogMessage(TRUE) . PHP_EOL . $request . PHP_EOL . $response);
    }
} catch (Exception $e) {
    // any other error thrown by any of the modules, assume internal server error
    $response = new HttpResponse();
    $response->setStatusCode(500);
    $response->setContent(json_encode(array("error" => "internal_server_error", "error_description" => $e->getMessage())));
    if(NULL !== $logger) {
        $logger->logFatal($e->getMessage() . PHP_EOL . $request . PHP_EOL . $response);
    }
}

if(NULL !== $logger) {
    $logger->logDebug($response);
}
if(NULL !== $response) {
    $response->sendResponse();
}
