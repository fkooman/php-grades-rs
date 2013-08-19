<?php

require_once dirname(__DIR__) . "/vendor/autoload.php";

use fkooman\oauth\rs\RemoteResourceServer;
use fkooman\oauth\rs\RemoteResourceServerException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use fkooman\grades\ApiException;
use fkooman\Config\Config;

$config = Config::fromIniFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "rs.ini");
$remoteResourceServer = new RemoteResourceServer($config->s("OAuth")->toArray());

$grades = json_decode(
    file_get_contents(
        dirname(__DIR__) . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "grades.json"
    ),
    true
);

$app = new Silex\Application();
$app['debug'] = true;

$app->get('/grades/', function(Request $request) use ($app, $remoteResourceServer, $grades) {
    $introspection = $remoteResourceServer->verifyRequest($request->headers->get("Authorization"), $request->get('access_token'));
    $introspection->requireScope("grades");
    $introspection->requireEntitlement("urn:x-oauth:entitlement:administration");
    $studentList = array();
    foreach (array_keys($grades) as $k) {
        array_push($studentList, array("id" => $k));
    }

    return $app->json($studentList);
});

$app->get('/grades/{id}', function(Request $request, $id) use ($app, $remoteResourceServer, $grades) {
    $introspection = $remoteResourceServer->verifyRequest($request->headers->get("Authorization"), $request->get('access_token'));
    $introspection->requireScope("grades");
    $uid = $introspection->getSub();
    if (!$introspection->hasEntitlement("urn:x-oauth:entitlement:administration") && $id !== $uid && "@me" !== $id) {
        throw new ApiException("forbidden", "resource does not belong to authenticated user");
    }
    if ("@me" === $id) {
        $id = $uid;
    }
    if (!array_key_exists($id, $grades)) {
        throw new ApiException("not_found", "student does not have any grades");
    }

    return $app->json($grades[$id]);
});

$app->error(function (RemoteResourceServerException $e, $code) {
    return new JsonResponse($e->getResponseAsArray(), $e->getResponseCode(), array("WWW-Authenticate" => $e->getAuthenticateHeader()));
});

$app->error(function(ApiException $e, $code) {
    return new JsonResponse($e->getResponseAsArray(), $e->getResponseCode());
});

$app->error(function(\Exception $e, $code) {
    return new JsonResponse(array("code" => $code, "error" => $e->getMessage()), $code);
});

$app->run();
