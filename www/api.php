<?php

require_once "../data/grades.php";

$configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "summerschool.ini";
if(!file_exists($configFile) || !is_file($configFile) || !is_readable($configFile)) {
    throw new ConfigException("configuration file '$configFile' not found");
}
$configValues = parse_ini_file($configFile, TRUE);

require_once $configValues["phpOAuthPath"] . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "SplClassLoader.php";
$s = new SplClassLoader("Tuxed", $configValues["phpOAuthPath"] . DIRECTORY_SEPARATOR . "lib");
$s->register();

use \Tuxed\Http\HttpRequest as HttpRequest;
use \Tuxed\Http\HttpResponse as HttpResponse;
use \Tuxed\Http\IncomingHttpRequest as IncomingHttpRequest;
use \Tuxed\OAuth\ResourceServer as ResourceServer;
use \Tuxed\OAuth\VerifyException as VerifyException;
use \Tuxed\OAuth\ApiException as ApiException;

$response = new HttpResponse();

try {

    $request = HttpRequest::fromIncomingHttpRequest(new IncomingHttpRequest());

    $rs = new ResourceServer();

    $authorizationHeader = $request->getHeader("HTTP_AUTHORIZATION");
    if(NULL === $authorizationHeader) {
        throw new VerifyException("invalid_token", "no token provided");
    }
    $rs->verifyAuthorizationHeader($authorizationHeader);

    $response->setHeader("Content-Type", "application/json");

    $request->matchRest("GET", "/grades/:id", function($id) use ($rs, $response, $grades) {
        $rs->requireScope("grades");
        if($id !== $rs->getResourceOwnerId() && $id !== "@me") {
            throw new ApiException("forbidden", "resource does not belong to authenticated user");
        }
        if(!array_key_exists($rs->getResourceOwnerId(), $grades)) {
            throw new ApiException("not_found", "student does not have any grades");
        }
        $response->setContent(json_encode($grades[$rs->getResourceOwnerId()], JSON_FORCE_OBJECT));
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

} catch (VerifyException $e) {
    $response = new HttpResponse();
    $response->setStatusCode($e->getResponseCode());
    $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server",error="%s",error_description="%s"', $e->getMessage(), $e->getDescription()));
    $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
    error_log($e->getLogMessage());
} catch (ApiException $e) {
    $response = new HttpResponse();
    $response->setStatusCode($e->getResponseCode());
    $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
    error_log($e->getLogMessage());
} catch (Exception $e) {
    // any other error thrown by any of the modules, assume internal server error
    $response = new HttpResponse();
    $response->setStatusCode(500);
    $response->setContent(json_encode(array("error" => "internal_server_error", "error_description" => $e->getMessage())));
    error_log($e->getMessage());
}

$response->sendResponse();

?>
