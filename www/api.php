<?php

require_once "../lib/Config.php";
require_once "../lib/Http/Uri.php";
require_once "../lib/Http/HttpRequest.php";
require_once "../lib/Http/HttpResponse.php";
require_once "../lib/Http/IncomingHttpRequest.php";
require_once "../lib/OAuth/RemoteResourceServer.php";
require_once "../lib/OAuth/ApiException.php";
require_once "../data/grades.php";

$response = new HttpResponse();
$response->setHeader("Content-Type", "application/json");

try { 
    $config = new Config(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "summerschool.ini");

    $request = HttpRequest::fromIncomingHttpRequest(new IncomingHttpRequest());

    $rs = new RemoteResourceServer($config->getValue("oauthTokenEndpoint"));

    $authorizationHeader = $request->getHeader("HTTP_AUTHORIZATION");
    if(NULL === $authorizationHeader) {
        throw new VerifyException("invalid_token", "no token provided");
    }
    $token = $rs->verify($authorizationHeader);

    // verify the scope permissions
    if(in_array($request->getCollection(), array ("grades"))) {
        $grantedScope = explode(" ", $token['scope']);
        if(!in_array($request->getCollection(), $grantedScope)) {
            throw new VerifyException("insufficient_scope", "no permission for this call with granted scope");
        }
    }

    if($request->matchRest("GET", "grades", TRUE)) {
        if($request->getResource() !== $token['resource_owner_id'] && "@me" !== $request->getResource()) {
            throw new ApiException("forbidden", "resource does not belong to authenticated user");
        }
        if(!array_key_exists($token['resource_owner_id'], $grades)) {
            throw new ApiException("not_found", "student does not have any grades");
        }
        $response->setContent(json_encode($grades[$token['resource_owner_id']], JSON_FORCE_OBJECT));
    } else {
        throw new ApiException("invalid_request", "unsupported collection or resource request");
    }

} catch (Exception $e) {
    switch(get_class($e)) {
        case "VerifyException":
            $response->setStatusCode($e->getResponseCode());
            $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server",error="%s",error_description="%s"', $e->getMessage(), $e->getDescription()));
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
            error_log($e->getLogMessage());
            break;

        case "ApiException":
            $response->setStatusCode($e->getResponseCode());
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription())));
            error_log($e->getLogMessage());
            break;

        default:
            // any other error thrown by any of the modules, assume internal server error
            $response->setStatusCode(500);
            $response->setContent(json_encode(array("error" => "internal_server_error", "error_description" => $e->getMessage())));
            error_log($e->getMessage());
            break;
    }

}

$response->sendResponse();

?>
