<?php

require_once dirname(__DIR__) . "/vendor/autoload.php";

use fkooman\Rest\Service;
use fkooman\Http\Request;
use fkooman\Http\JsonResponse;
use fkooman\Http\IncomingRequest;

use fkooman\OAuth\ResourceServer\ResourceServer;
use fkooman\OAuth\ResourceServer\ResourceServerException;

use fkooman\grades\ApiException;

use fkooman\Config\Config;

use Guzzle\Http\Client;

try {
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

    $request = Request::fromIncomingRequest(
        new IncomingRequest()
    );
    $service = new Service($request);

    $service->match(
        'GET',
        '/user_info',
        function () use ($request, $resourceServer) {
            $resourceServer->setAuthorizationHeader($request->getHeader("Authorization"));
            $tokenIntrospection = $resourceServer->verifyToken();
            $info = array();
            if (hasEntitlement($tokenIntrospection->getToken(), "urn:x-oauth:entitlement:administration")) {
                $info["admin"] = true;
            } else {
                $info["admin"] = false;
            }

            $response = new JsonResponse(200);
            $response->setContent($info);

            return $response;
        }
    );
    $service->match(
        'GET',
        '/grades/',
        function () use ($request, $resourceServer, $grades) {
            $resourceServer->setAuthorizationHeader($request->getHeader("Authorization"));
            $introspection = $resourceServer->verifyToken();
            requireScope($introspection->getScope(), "grades");
            requireEntitlement($introspection->getToken(), "urn:x-oauth:entitlement:administration");
            $studentList = array();
            foreach (array_keys($grades) as $k) {
                array_push($studentList, array("id" => $k));
            }

            $response = new JsonResponse(200);
            $response->setContent($studentList);

            return $response;
        }
    );

    $service->match(
        'GET',
        '/grades/:id',
        function ($id) use ($request, $resourceServer, $grades) {
            $resourceServer->setAuthorizationHeader($request->getHeader("Authorization"));
            $introspection = $resourceServer->verifyToken();
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

            $response = new JsonResponse();
            $response->setContent($grades[$id]);

            return $response;
        }
    );
    $service->run()->sendResponse();
} catch (ResourceServerException $e) {
    // when there is a problem with the OAuth authorization
    $response = new JsonResponse($e->getStatusCode());
    $response->setHeader("WWW-Authenticate", $e->getAuthenticateHeader());
    $response->setContent(
        array(
            "error" => $e->getMessage(),
            "error_description" => $e->getDescription(),
            "code" => $e->getStatusCode()
        )
    );
    $response->sendResponse();
} catch (ApiException $e) {
    // when there is a problem with the OAuth authorization
    $response = new JsonResponse($e->getStatusCode());
    $response->setContent($e->getResponseAsArray());
    $response->sendResponse();
} catch (Exception $e) {
    // in all other cases...
    $response = new JsonResponse(500);
    $response->setContent(
        array(
            "code" => 500,
            "error" => "internal_server_error",
            "error_description" => $e->getMessage()
        )
    );
    $response->sendResponse();
}

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
