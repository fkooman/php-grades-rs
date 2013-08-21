<?php

require_once dirname(__DIR__) . "/vendor/autoload.php";

use fkooman\oauth\rs\ResourceServer;
use fkooman\oauth\rs\ResourceServerException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use fkooman\grades\ApiException;
use fkooman\Config\Config;
use Guzzle\Http\Client;

$config = Config::fromIniFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "rs.ini");

$introspectionUrl = $config->s("OAuth")->l("introspectionEndpoint");
$client = new Client(
    $introspectionUrl,
    array(
        "request.options" => array(
            "ssl.certificate_authority" => !(bool) $config->s("OAuth")->l("disableCertCheck")
        )
    )
);
$resourceServer = new ResourceServer($client);

$grades = json_decode(
    file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "grades.json"
    ),
    true
);

$app = new Silex\Application();
$app['debug'] = true;

$app->get('/grades/', function(Request $request) use ($app, $resourceServer, $grades) {
    $introspection = $resourceServer->verifyRequest($request->headers->get("Authorization"), $request->get('access_token'));
    if (!$introspection->getActive()) {
        throw new ResourceServerException("invalid_token", "the token provided is not valid");
    }
    requireScope($introspection->getScope(), "grades");
    requireEntitlement($introspection->getToken(), "urn:x-oauth:entitlement:administration");
    $studentList = array();
    foreach (array_keys($grades) as $k) {
        array_push($studentList, array("id" => $k));
    }

    return $app->json($studentList);
});

$app->get('/grades/{id}', function(Request $request, $id) use ($app, $resourceServer, $grades) {
    $introspection = $resourceServer->verifyRequest($request->headers->get("Authorization"), $request->get('access_token'));
    if (!$introspection->getActive()) {
        throw new ResourceServerException("invalid_token", "the token provided is not valid");
    }
    requireScope($introspection->getScope(), "grades");
    $uid = $introspection->getSub();
    if ("@me" === $id) {
        $id = $uid;
    }

    if ($id !== $uid) {
        if (!hasEntitlement($introspection->getToken(), "urn:x-oauth:entitlement:administration")) {
            throw new ApiException("forbidden", "resource does not belong to authenticated user");
        }
    }

    if (!array_key_exists($id, $grades)) {
        throw new ApiException("not_found", "student does not have any grades");
    }

    return $app->json($grades[$id]);
});

$app->error(function (ResourceServerException $e, $code) {
    return new JsonResponse(
        array(
            "error" => $e->getMessage(),
            "error_description" => $e->getDescription(),
            "code" => $e->getStatusCode()
        ),
        $e->getStatusCode(),
        array("WWW-Authenticate" => $e->getAuthenticateHeader())
    );
});
$app->error(function(ApiException $e, $code) {
    return new JsonResponse($e->getResponseAsArray(), $e->getResponseCode());
});

$app->error(function(Exception $e, $code) {
    return new JsonResponse(array("code" => $code, "error" => $e->getMessage()), $code);
});

$app->run();

// scope and (proprietary) entitlement helper functions, should probably be
// moved to the php-oauth-lib-rs library at some point
function requireScope($scopes, $s)
{
    $scopesArray = explode(" ", $scopes);
    if (!in_array($s, $scopesArray)) {
        throw new ResourceServerException("insufficient_scope", sprintf("scope '%s' required", $s));
    }
}

function hasEntitlement(array $token, $e)
{
    if (!isset($token['x-entitlement']) || !is_array($token['x-entitlement'])) {
        return false;
    }
    if (!in_array($e, $token['x-entitlement'])) {
        return false;
    }

    return true;
}

function requireEntitlement(array $token, $e)
{
    if (!hasEntitlement($token, $e)) {
        throw new ResourceServerException("insufficient_scope", sprintf("entitlement '%s' required", $e));
    }
}
